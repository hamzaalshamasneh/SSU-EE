/*
 * Project/Program Name : EE470 ESP8266 Distance and Light Monitor
 * File Name            : main.cpp
 * Author               : Hamza Alshamasneh
 * Date                 : 09/11/2026
 * Version              : 1.0
 *
 * Purpose:
 * Capture two sensors on a button/tilt activation and deliver
 * the original measurement with acknowledgement and retries.
 *
 * Inputs:
 * D5 button, D6 tilt, D2 ultrasonic Echo, A0 light, Serial commands.
 *
 * Outputs:
 * D1 ultrasonic Trigger, HTTPS GET requests, Serial diagnostics.
 *
 * Example Application:
 * Send distance and light readings to Database.
 *
 * Dependencies:
 * NodeMCU ESP-12E, Arduino framework, ArduinoJson, Wi-Fi,
 * timeapi.io/NTP and the matching esp_insert.php receiver.
 */

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <ArduinoJson.h>
#include <Ticker.h>
#include <time.h>
#include <sys/time.h>



const char WIFI_SSID[] = "";
const char WIFI_PASSWORD[] = "";

// This is a NEW receiver we will create, not the old insert.php.
const char SERVER_URL[] =
    "https://hamzaalshamasneh.com/Chartjs/esp_insert.php";

// ---------- Hardware connections ----------

constexpr uint8_t TRIG_PIN = D1;
constexpr uint8_t ECHO_PIN = D2;
constexpr uint8_t BUTTON_PIN = D5;
constexpr uint8_t TILT_PIN = D6;

// Change to HIGH if testing shows your tilt module activates HIGH.
constexpr uint8_t TILT_ACTIVE_LEVEL = LOW;

// ---------- Timing and buffer settings ----------

constexpr uint32_t RETRY_MS = 30000;
constexpr uint32_t DEBOUNCE_MS = 60;
constexpr uint32_t RELEASE_MS = 150;
constexpr uint32_t MENU_MS = 15000;

constexpr uint8_t MESSAGE_CAPACITY = 16;
constexpr uint8_t EVENT_CAPACITY = 32;

// ---------- Time zones ----------

struct Zone {
  const char *label;
  const char *iana;
  const char *posix;
};

const Zone ZONES[] = {
    {
        "Eastern / New York",
        "America/New_York",
        "EST5EDT,M3.2.0/2,M11.1.0/2"
    },
    {
        "Central / Chicago",
        "America/Chicago",
        "CST6CDT,M3.2.0/2,M11.1.0/2"
    },
    {
        "Mountain / Denver",
        "America/Denver",
        "MST7MDT,M3.2.0/2,M11.1.0/2"
    },
    {
        "Pacific / Los Angeles",
        "America/Los_Angeles",
        "PST8PDT,M3.2.0/2,M11.1.0/2"
    },
    {
        "Alaska / Anchorage",
        "America/Anchorage",
        "AKST9AKDT,M3.2.0/2,M11.1.0/2"
    },
    {
        "Hawaii / Honolulu",
        "Pacific/Honolulu",
        "HST10"
    },
    {
        "Atlantic / San Juan",
        "America/Puerto_Rico",
        "AST4"
    }
};

uint8_t zoneIndex = 3; // Pacific is the default.

bool menuClosed = false;
bool readyPrinted = false;
bool apiRequested = true;
bool wasConnected = false;

uint32_t bootMs = 0;
uint32_t lastApiMs = 0;
uint32_t lastReconnectMs = 0;

// ---------- Pending measurements ----------

struct Message {
  bool used = false;
  bool attempted = false;

  uint8_t node = 0;

  char timestamp[32] = {};
  char zone[32] = {};
  char offset[8] = {};

  float distanceCm = 0;
  uint16_t lightRaw = 0;

  uint32_t lastAttemptMs = 0;
};

Message messages[MESSAGE_CAPACITY];

// ---------- Switch monitoring ----------

// The timer only checks/debounces switches and queues node numbers.
// It does not read sensors, perform HTTP, allocate Strings,
// print to Serial, or call delay().

struct SwitchState {
  uint8_t pin;
  uint8_t node;
  uint8_t activeLevel;

  bool raw = HIGH;
  bool armed = false;

  uint32_t changedMs = 0;
};

SwitchState button = {BUTTON_PIN, 1, LOW};
SwitchState tilt = {TILT_PIN, 2, TILT_ACTIVE_LEVEL};

volatile bool captureEnabled = false;

volatile uint8_t events[EVENT_CAPACITY];
volatile uint8_t eventRead = 0;
volatile uint8_t eventWrite = 0;

volatile uint32_t droppedEvents = 0;

Ticker switchTimer;

// ---------- Clock and queue helpers ----------

bool clockValid() {
  // Do not create measurements with a 1970/unset timestamp.
  return time(nullptr) >= 1704067200;
}

uint8_t pendingCount() {
  uint8_t count = 0;

  for (const auto &m : messages) {
    if (m.used) {
      ++count;
    }
  }

  return count;
}

// Encode spaces, colons, slashes and other URL characters.
String urlEncode(const String &input) {
  const char hex[] = "0123456789ABCDEF";

  String output;
  output.reserve(input.length() * 3);

  for (size_t i = 0; i < input.length(); ++i) {
    uint8_t c = input[i];

    if ((c >= 'a' && c <= 'z') ||
        (c >= 'A' && c <= 'Z') ||
        (c >= '0' && c <= '9') ||
        c == '-' || c == '_' || c == '.' || c == '~') {
      output += static_cast<char>(c);
    } else {
      output += '%';
      output += hex[c >> 4];
      output += hex[c & 15];
    }
  }

  return output;
}

// ---------- HTTPS communication ----------

int httpsGet(const String &url, String &body) {
  BearSSL::WiFiClientSecure client;

  // Lab setting: traffic is encrypted, but the server's certificate
  // is NOT verified. Certificate validation can be added later.
  client.setInsecure();
  client.setTimeout(4000);

  HTTPClient http;

  http.setTimeout(4000);
  http.setReuse(false);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);

  if (!http.begin(client, url)) {
    return -100;
  }

  int code = http.GET();

  if (code > 0) {
    if (http.getSize() > 4096) {
      body = "Response too large; acknowledgement rejected.";
    } else {
      body = http.getString();
    }
  }

  http.end();

  return code;
}

// ---------- USB Serial interface ----------

void printMenu() {
  Serial.println("\nTIME ZONES:");

  for (uint8_t i = 0; i < 7; ++i) {
    Serial.printf("%u) %s\n", i + 1, ZONES[i].label);
  }

  Serial.println("Select Your Time Zone (default PT: 4).");
  Serial.println("At boot: no selection for 15 seconds selects Pacific.");
  Serial.println("Later: 1-7 changes zone; m=menu; s=status; r=RSSI.");
}

void printStatus() {
  Serial.printf(
    "Button D5 = %d | Tilt D6 = %d\n",
    digitalRead(BUTTON_PIN),
    digitalRead(TILT_PIN)
);
  Serial.printf(
      "\nZone: %s | pending: %u/%u\n",
      ZONES[zoneIndex].iana,
      pendingCount(),
      MESSAGE_CAPACITY
  );

  Serial.printf(
      "Wi-Fi: %s\n",
      WiFi.status() == WL_CONNECTED ? "connected" : "offline"
  );

  Serial.print("MAC: ");
  Serial.println(WiFi.macAddress());

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("IP: ");
    Serial.println(WiFi.localIP());

    Serial.printf("RSSI: %d dBm\n", WiFi.RSSI());
  }

  if (clockValid()) {
    time_t now = time(nullptr);

    struct tm local{};
    localtime_r(&now, &local);

    char text[48];

    strftime(
        text,
        sizeof(text),
        "%Y-%m-%d %H:%M:%S %Z %z",
        &local
    );

    Serial.print("Local clock: ");
    Serial.println(text);
  } else {
    Serial.println("Clock: not synchronized yet.");
  }

  Serial.printf(
      "Free heap: %u bytes | sketch: %u bytes\n",
      ESP.getFreeHeap(),
      ESP.getSketchSize()
  );
}

void selectZone(uint8_t index) {
  zoneIndex = index;

  // SNTP maintains UTC. The POSIX rule converts UTC to the selected
  // local time and handles daylight saving where applicable.
  // Existing queued messages retain their original timestamps.
  configTime(
      ZONES[index].posix,
      "pool.ntp.org",
      "time.nist.gov"
  );

  menuClosed = true;
  apiRequested = true;

  // Allow the selected-zone API request immediately.
  lastApiMs = millis() - RETRY_MS;

  Serial.printf("Selected: %s\n", ZONES[index].iana);
}

void serviceSerial() {
  while (Serial.available()) {
    char command = Serial.read();

    if (command >= '1' && command <= '7') {
      selectZone(command - '1');
    } else if (command == 'm' || command == 'M') {
      printMenu();
    } else if (command == 's' || command == 'S') {
      printStatus();
    } else if (command == 'r' || command == 'R') {
      if (WiFi.status() == WL_CONNECTED) {
        Serial.printf("RSSI: %d dBm\n", WiFi.RSSI());
      } else {
        Serial.println("RSSI unavailable: Wi-Fi is offline.");
      }
    }
  }

  if (!menuClosed && millis() - bootMs >= MENU_MS) {
    selectZone(3);
  }
}

// ---------- Wi-Fi connection management ----------

void serviceWiFi() {
  bool connected = WiFi.status() == WL_CONNECTED;

  if (connected && !wasConnected) {
    Serial.println("\nWi-Fi connected.");
    printStatus();
  } else if (!connected && wasConnected) {
    Serial.println(
        "Wi-Fi lost. Measurements remain queued in RAM."
    );
  }

  wasConnected = connected;

  if (!connected && millis() - lastReconnectMs >= 15000) {
    lastReconnectMs = millis();
    WiFi.reconnect();
  }
}

// ---------- Obtain current time ----------

void serviceClock() {
  // Request the selected IANA timezone from timeapi.io.
  // NTP also runs, providing a fallback and maintaining the clock.
  // Avoid delaying pending deliveries with an additional API request.
  if (!menuClosed ||
      WiFi.status() != WL_CONNECTED ||
      !apiRequested ||
      pendingCount() != 0 ||
      millis() - lastApiMs < RETRY_MS) {
    return;
  }

  lastApiMs = millis();

  String body;

  String url =
      "https://timeapi.io/api/Time/current/zone?timeZone=";

  url += urlEncode(ZONES[zoneIndex].iana);

  int code = httpsGet(url, body);

  JsonDocument doc;

  if (code != 200 ||
      deserializeJson(doc, body) ||
      String(doc["timeZone"] | "") != ZONES[zoneIndex].iana ||
      !doc["dateTime"].is<const char *>()) {
    Serial.printf(
        "Time API unavailable (HTTP %d). "
        "Will retry in 30 s; NTP is also running.\n",
        code
    );

    return;
  }

  Serial.print("Time API: ");
  Serial.println(doc["dateTime"].as<const char *>());

  // If NTP has not synchronized yet, initialize the running clock
  // from the API response.
  if (!clockValid()) {
    struct tm local{};

    int y, mo, d, h, mi, s;

    if (sscanf(
            doc["dateTime"].as<const char *>(),
            "%d-%d-%dT%d:%d:%d",
            &y, &mo, &d, &h, &mi, &s
        ) != 6 ||
        y < 2024 || y > 2099 ||
        mo < 1 || mo > 12 ||
        d < 1 || d > 31 ||
        h < 0 || h > 23 ||
        mi < 0 || mi > 59 ||
        s < 0 || s > 59) {
      return;
    }

    local.tm_year = y - 1900;
    local.tm_mon = mo - 1;
    local.tm_mday = d;

    local.tm_hour = h;
    local.tm_min = mi;
    local.tm_sec = s;

    local.tm_isdst = doc["dstActive"] | false;

    // Convert the selected zone's local time to UTC.
    time_t utc = mktime(&local);

    if (utc < 1704067200) {
      return;
    }

    int ms = doc["milliSeconds"] | 0;

    struct timeval tv{};

    tv.tv_sec = utc;
    tv.tv_usec = (ms >= 0 && ms <= 999 ? ms : 0) * 1000;

    settimeofday(&tv, nullptr);
  }

  apiRequested = false;

  printStatus();
}

// ---------- Debounce and detect new activations ----------

void queueActivation(uint8_t node) {
  uint8_t next = (eventWrite + 1) % EVENT_CAPACITY;

  if (next == eventRead) {
    ++droppedEvents;
    return;
  }

  events[eventWrite] = node;
  eventWrite = next;
}

void pollOneSwitch(SwitchState &sw, uint32_t now) {
  bool raw = digitalRead(sw.pin);

  if (!captureEnabled) {
    sw.raw = raw;
    sw.changedMs = now;
    sw.armed = false;
    return;
  }

  if (raw != sw.raw) {
    sw.raw = raw;
    sw.changedMs = now;
  }

  uint32_t stableFor = now - sw.changedMs;

  // Require a stable release/reset before accepting an activation.
  if (raw != sw.activeLevel && stableFor >= RELEASE_MS) {
    sw.armed = true;
  }

  // A held button/tilt cannot repeatedly queue measurements.
  if (raw == sw.activeLevel &&
      stableFor >= DEBOUNCE_MS &&
      sw.armed) {
    sw.armed = false;
    queueActivation(sw.node);
  }
}

void pollSwitches() {
  uint32_t now = millis();

  pollOneSwitch(button, now);
  pollOneSwitch(tilt, now);
}

// ---------- Sensor measurements ----------

bool readDistance(float &cm) {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);

  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);

  digitalWrite(TRIG_PIN, LOW);

  unsigned long duration =
      pulseIn(ECHO_PIN, HIGH, 30000UL);

  if (duration == 0) {
    return false;
  }

  cm = duration * 0.0343f / 2.0f;

  return cm >= 2.0f && cm <= 400.0f;
}

void captureMeasurement() {
  static uint32_t lastSensorMs = 0;

  if (!captureEnabled ||
      eventRead == eventWrite ||
      millis() - lastSensorMs < 70) {
    return;
  }

  int slot = -1;

  for (uint8_t i = 0; i < MESSAGE_CAPACITY; ++i) {
    if (!messages[i].used) {
      slot = i;
      break;
    }
  }

  // Never overwrite a measurement awaiting delivery.
  if (slot < 0) {
    return;
  }

  noInterrupts();

  uint8_t node = events[eventRead];
  eventRead = (eventRead + 1) % EVENT_CAPACITY;

  interrupts();

  Message m;
  m.node = node;

  // Record when the measurement is taken, not its later retry time.
  struct timeval taken{};
  gettimeofday(&taken, nullptr);

  // Leave at least 70 ms between ultrasonic bursts.
  lastSensorMs = millis();

  if (!readDistance(m.distanceCm)) {
    Serial.printf(
        "node_%u: no valid 2-400 cm echo. "
        "No message created.\n",
        node
    );

    return;
  }

  // Read the actual photoresistor signal only for this activation.
  m.lightRaw = analogRead(A0);

  struct tm local{};
  localtime_r(&taken.tv_sec, &local);

  char seconds[24];

  strftime(
      seconds,
      sizeof(seconds),
      "%Y-%m-%d %H:%M:%S",
      &local
  );

  // Preserve fractional seconds for distinct rapid measurements.
  snprintf(
      m.timestamp,
      sizeof(m.timestamp),
      "%s.%06ld",
      seconds,
      (long)taken.tv_usec
  );

  strftime(
      m.offset,
      sizeof(m.offset),
      "%z",
      &local
  );

  snprintf(
      m.zone,
      sizeof(m.zone),
      "%s",
      ZONES[zoneIndex].iana
  );

  m.used = true;
  messages[slot] = m;

  Serial.printf(
      "Captured node_%u | %s %s | %.2f cm | light=%u/1023\n",
      node,
      m.timestamp,
      m.offset,
      m.distanceCm,
      m.lightRaw
  );
}

// ---------- Build the measurement request ----------

String messageUrl(const Message &m) {
  String url = SERVER_URL;

  url += "?nodeId=node_";
  url += m.node;

  url += "&timeReceived=";
  url += urlEncode(m.timestamp);

  url += "&distanceCm=";
  url += String(m.distanceCm, 2);

  url += "&lightRaw=";
  url += m.lightRaw;

  url += "&timeZone=";
  url += urlEncode(m.zone);

  url += "&utcOffset=";
  url += urlEncode(m.offset);

  return url;
}

// ---------- Check server acknowledgement ----------

bool acknowledged(
    int code,
    const String &body,
    const Message &m
) {
  JsonDocument doc;

  if (deserializeJson(doc, body)) {
    return false;
  }

  String expectedNode = "node_";
  expectedNode += m.node;

  // The acknowledgement must identify this exact measurement.
  if (String(doc["nodeId"] | "") != expectedNode ||
      String(doc["timeReceived"] | "") != m.timestamp) {
    return false;
  }

  String status = doc["status"] | "";

  bool successCode = code >= 200 && code < 300;

  return
      (successCode && status == "stored") ||
      (
          (successCode || code == 409) &&
          status == "already_stored"
      );
}

// ---------- Send new messages and retry failed deliveries ----------

void serviceDelivery() {
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  static uint8_t cursor = 0;

  for (uint8_t step = 0; step < MESSAGE_CAPACITY; ++step) {
    uint8_t i = (cursor + step) % MESSAGE_CAPACITY;

    Message &m = messages[i];

    if (!m.used ||
        (
            m.attempted &&
            millis() - m.lastAttemptMs < RETRY_MS
        )) {
      continue;
    }

    Serial.printf(
        "%s node_%u, original time %s\n",
        m.attempted ? "Retrying" : "Sending",
        m.node,
        m.timestamp
    );

    m.attempted = true;

    // Retry interval is measured from the attempt's start.
    m.lastAttemptMs = millis();

    String body;

    int code = httpsGet(messageUrl(m), body);

    cursor = (i + 1) % MESSAGE_CAPACITY;

    Serial.printf("HTTP %d\n", code);

    if (acknowledged(code, body, m)) {
      m.used = false;

      Serial.println(
          "Server confirmed delivery. "
          "Message removed from RAM queue."
      );
    } else {
      Serial.println(
          "Not confirmed; preserving the SAME values "
          "for retry in 30 s."
      );

      Serial.println(body.substring(0, 240));
    }

    // One request per loop pass.
    // Other messages retain their own retry timers.
    return;
  }
}

// ---------- Enable capture only after a valid time is available ----------

void updateReadiness() {
  captureEnabled = menuClosed && clockValid();

  if (captureEnabled && !readyPrinted) {
    readyPrinted = true;

    Serial.println(
        "\nREADY. Release button/reset tilt first, "
        "then activate either switch."
    );

    printStatus();
  }

  static uint32_t reportedDrops = 0;

  if (droppedEvents != reportedDrops) {
    reportedDrops = droppedEvents;

    Serial.printf(
        "WARNING: activation buffer full; "
        "%lu activations lost.\n",
        (unsigned long)reportedDrops
    );
  }
}

// ---------- Initialization ----------

void setup() {
  Serial.begin(115200);
  delay(200);

  pinMode(TRIG_PIN, OUTPUT);
  digitalWrite(TRIG_PIN, LOW);

  pinMode(ECHO_PIN, INPUT);

  // The two-terminal button connects D5 to GND when pressed.
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  pinMode(TILT_PIN, INPUT_PULLUP);

  Serial.println(
      "\nEE470 ESP8266 Distance + Light, version 1.0"
  );

  Serial.println(
      "Lab TLS: server certificate verification disabled."
  );

  printMenu();

  bootMs = millis();

  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  // Monitor switches independently of the main loop's HTTP requests.
  switchTimer.attach_ms(10, pollSwitches);
}

// ---------- Main program ----------

void loop() {
  serviceSerial();
  serviceWiFi();
  updateReadiness();
  captureMeasurement();
  serviceDelivery();
  serviceClock();

  delay(1);
}
