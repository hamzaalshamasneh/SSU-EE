/*
  EE470 - Introduction to MQTT
  ESP8266 Monitoring and LED Control

  This program reads a potentiometer and a pushbutton and sends
  their values to the HiveMQ broker. The LED can be controlled
  through the Serial Monitor or by receiving MQTT messages.

  Connections:
  - Potentiometer: A0
  - External LED: D1
  - Pushbutton: D2 to GND, using the internal pull-up resistor

  The potentiometer reading is published every 15 seconds.
  After a button press and release, the ESP publishes 1,
  followed by 0 after 5 seconds.

  A separate Python relay receives the published readings and
  saves them to MySQL. The ESP does not write to the database.
*/

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <WiFiClientSecureBearSSL.h>
#include <PubSubClient.h>
#include <time.h>
#include "hivemq_ca.h"

// Set this to false to test the local inputs and outputs without MQTT.
constexpr bool MQTT_ENABLED = true;

// Enter the Wi-Fi details in the working copy of this program.
const char WIFI_SSID[] = "";
const char WIFI_PASSWORD[] = "";

// Use the secure MQTT connection to the public HiveMQ broker.
const char BROKER_HOST[] = "broker.hivemq.com";
constexpr uint16_t BROKER_PORT = 8883;

// The device ID keeps this board's output topics separate.
const char DEVICE_ID[] = "ee470-node-7k39";

// Enter a classmate's device ID here when testing Part E.
const char FRIEND_ID[] = "";

// Publishing 1 or 0 to this topic controls the LED.
const char COMMAND_TOPIC[] = "testtopic/temp/inTopic";

// NodeMCU pins used by the circuit.
constexpr uint8_t LED_PIN = D1;
constexpr uint8_t BUTTON_PIN = D2;

// All timing values are in milliseconds.
constexpr unsigned long SERIAL_INTERVAL_MS = 500;
constexpr unsigned long POT_INTERVAL_MS = 15000;
constexpr unsigned long SWITCH_PULSE_MS = 5000;
constexpr unsigned long DEBOUNCE_MS = 30;
constexpr unsigned long RECONNECT_INTERVAL_MS = 5000;

// PubSubClient uses the secure Wi-Fi client to communicate with HiveMQ.
BearSSL::WiFiClientSecure wifiClient;
PubSubClient mqtt(wifiClient);

// These arrays hold the topics and the MQTT client ID.
char potTopic[96];
char switchTopic[96];
char stateTopic[96];
char friendSwitchTopic[96];
char clientId[32];

// Keep track of the LED and whether its state needs to be published.
bool ledOn = false;
bool ledStatePending = true;

// These variables help debounce the button and detect a full press/release.
bool rawButtonPressed = false;
bool stableButtonPressed = false;
bool sawButtonPress = false;
unsigned long buttonChangedAt = 0;

// Keep track of button events waiting to be published.
unsigned int pendingSwitchEvents = 0;
bool switchPulseActive = false;
unsigned long switchPulseStartedAt = 0;

// Store the previous times so the program can use millis() instead of delay().
unsigned long lastSerialReading = 0;
unsigned long lastPotPublish = 0;
unsigned long lastMqttAttempt = 0;
bool triedMqtt = false;


// Change the physical LED and mark its new state for publishing.
void setLed(bool turnOn) {
  ledOn = turnOn;
  digitalWrite(LED_PIN, ledOn ? HIGH : LOW);
  ledStatePending = true;

  Serial.printf("LED: %d\n", ledOn ? 1 : 0);
}


// Allow the LED to be controlled by typing 1 or 0 in the Serial Monitor.
void readSerialCommands() {
  while (Serial.available() > 0) {
    int command = Serial.read();

    if (command == '1' || command == '0') {
      setLed(command == '1');
    }
  }
}


// Read the button and ignore quick changes caused by contact bounce.
void checkPushbutton(unsigned long now) {
  // INPUT_PULLUP means a pressed button reads LOW.
  bool pressed = digitalRead(BUTTON_PIN) == LOW;

  if (pressed != rawButtonPressed) {
    rawButtonPressed = pressed;
    buttonChangedAt = now;
  }

  // Wait until the new reading stays stable for the debounce period.
  if (pressed == stableButtonPressed ||
      now - buttonChangedAt < DEBOUNCE_MS) {
    return;
  }

  stableButtonPressed = pressed;

  if (pressed) {
    sawButtonPress = true;
    Serial.println("Switch pressed: 1");
  } else {
    Serial.println("Switch released: 0");

    // Queue an event after a full press and release while MQTT is connected.
    if (sawButtonPress && MQTT_ENABLED && mqtt.connected()) {
      ++pendingSwitchEvents;
    }

    sawButtonPress = false;
  }
}


// This function runs when a message arrives on a subscribed topic.
void onMqttMessage(char* topic, byte* payload, unsigned int length) {
  // Only accept a single character containing 1 or 0.
  if (length != 1 || (payload[0] != '1' && payload[0] != '0')) {
    return;
  }

  Serial.printf("MQTT received [%s]: %c\n", topic, payload[0]);

  // This also handles the classmate's switch messages when Part E is enabled.
  setLed(payload[0] == '1');
}


// Connect to HiveMQ and restore subscriptions after a disconnection.
void connectMqtt(unsigned long now) {
  if (mqtt.connected()) {
    return;
  }

  // Leave some time between connection attempts.
  if (triedMqtt && now - lastMqttAttempt < RECONNECT_INTERVAL_MS) {
    return;
  }

  triedMqtt = true;
  lastMqttAttempt = now;

  // Wi-Fi must connect before MQTT can connect.
  if (WiFi.status() != WL_CONNECTED) {
    Serial.printf("Waiting for Wi-Fi, status=%d\n",
                  static_cast<int>(WiFi.status()));
    return;
  }

  // Wait for the clock because TLS certificate checks need the current time.
  time_t utcTime = time(nullptr);

  if (utcTime < 1700000000) {
    Serial.println("Wi-Fi connected. Waiting for clock sync for TLS...");
    return;
  }

  // Check that the broker hostname can be resolved.
  IPAddress brokerIp;

  if (!WiFi.hostByName(BROKER_HOST, brokerIp)) {
    Serial.printf("DNS lookup failed for %s\n", BROKER_HOST);
    lastMqttAttempt = millis();
    return;
  }

  // Print connection details to help with troubleshooting.
  Serial.printf("Wi-Fi IP: %s\n", WiFi.localIP().toString().c_str());
  Serial.printf("UTC epoch: %lu\n", static_cast<unsigned long>(utcTime));

  Serial.printf("Connecting to %s:%u (%s) as %s...\n",
                BROKER_HOST, static_cast<unsigned>(BROKER_PORT),
                brokerIp.toString().c_str(), clientId);

  // The server was configured with its hostname so TLS can verify its identity.
  if (!mqtt.connect(clientId)) {
    Serial.printf("MQTT connection failed, state=%d\n", mqtt.state());

    char tlsError[160] = {};
    int tlsCode = wifiClient.getLastSSLError(tlsError, sizeof(tlsError));

    if (tlsCode != 0) {
      Serial.printf("BearSSL error %d: %s\n", tlsCode, tlsError);
    } else {
      Serial.println("BearSSL reported no TLS error. Check TCP reachability.");
    }

    lastMqttAttempt = millis();
    return;
  }

  // Subscribe to the LED command topic.
  bool subscribed = mqtt.subscribe(COMMAND_TOPIC);

  // Also subscribe to a classmate's switch if FRIEND_ID was filled in.
  if (friendSwitchTopic[0] != '\0') {
    subscribed = mqtt.subscribe(friendSwitchTopic) && subscribed;
  }

  // A failed subscription request causes another connection attempt later.
  if (!subscribed) {
    Serial.println("Could not send subscription request. Retrying...");
    mqtt.disconnect();
    lastMqttAttempt = millis();
    return;
  }

  Serial.printf("MQTT connected. Command topic: %s\n", COMMAND_TOPIC);
  Serial.printf("Potentiometer topic: %s\n", potTopic);

  if (friendSwitchTopic[0] != '\0') {
    Serial.printf("Classmate switch topic: %s\n", friendSwitchTopic);
  }

  // Publish the current LED state after connecting.
  ledStatePending = true;
}


// Send the current potentiometer ADC reading every 15 seconds.
void publishPotentiometer(unsigned long now) {
  if (now - lastPotPublish < POT_INTERVAL_MS) {
    return;
  }

  lastPotPublish = now;

  // Convert the ADC number into text for the MQTT payload.
  char payload[8];
  snprintf(payload, sizeof(payload), "%d", analogRead(A0));

  if (mqtt.publish(potTopic, payload)) {
    Serial.printf("MQTT published [%s]: %s\n", potTopic, payload);
  } else {
    Serial.println("Potentiometer publish failed.");
  }
}


// Publish a button event as 1, then publish 0 after five seconds.
void publishSwitchEvent(unsigned long now) {
  if (switchPulseActive) {
    // Retry publishing 0 on later loops if the publish fails.
    if (now - switchPulseStartedAt >= SWITCH_PULSE_MS &&
        mqtt.publish(switchTopic, "0")) {
      switchPulseActive = false;
      Serial.printf("MQTT published [%s]: 0\n", switchTopic);
    }

    return;
  }

  // Start the next queued pulse once the previous one has finished.
  if (pendingSwitchEvents > 0 && mqtt.publish(switchTopic, "1")) {
    --pendingSwitchEvents;
    switchPulseActive = true;
    switchPulseStartedAt = millis();

    Serial.printf("MQTT published [%s]: 1\n", switchTopic);
  }
}


void setup() {
  Serial.begin(115200);

  // Set up the LED output and button input.
  pinMode(LED_PIN, OUTPUT);
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  setLed(false);

  // Local testing does not need Wi-Fi or MQTT.
  if (!MQTT_ENABLED) {
    Serial.println("Part A mode: turn the knob, press the button, type 1 or 0.");
    return;
  }

  // Build this device's output topics using its device ID.
  snprintf(potTopic, sizeof(potTopic),
           "testtopic/temp/outTopic/%s", DEVICE_ID);

  snprintf(switchTopic, sizeof(switchTopic),
           "testtopic/temp/outTopic/%s/switch", DEVICE_ID);

  snprintf(stateTopic, sizeof(stateTopic),
           "testtopic/temp/outTopic/%s/ledState", DEVICE_ID);

  // Build the optional classmate subscription topic.
  if (FRIEND_ID[0] != '\0') {
    snprintf(friendSwitchTopic, sizeof(friendSwitchTopic),
             "testtopic/temp/outTopic/%s/switch", FRIEND_ID);
  }

  // Use the ESP chip ID to give each board a different MQTT client ID.
  snprintf(clientId, sizeof(clientId), "ee470-%06lX",
           static_cast<unsigned long>(ESP.getChipId()));

  // Load the trusted CA certificate for the secure connection.
  // Static keeps the certificate object available after setup() finishes.
  static BearSSL::X509List rootCertificate(HIVEMQ_ROOT_CA);
  wifiClient.setTrustAnchors(&rootCertificate);

  // Configure MQTT and the function used for incoming messages.
  mqtt.setServer(BROKER_HOST, BROKER_PORT);
  mqtt.setCallback(onMqttMessage);
  mqtt.setSocketTimeout(5);

  // Start Wi-Fi and request the current time from NTP servers.
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");

  Serial.println("Connecting to Wi-Fi...");
}


void loop() {
  unsigned long now = millis();

  // Keep checking local controls on each loop.
  readSerialCommands();
  checkPushbutton(now);

  // Display the potentiometer reading in the Serial Monitor.
  if (now - lastSerialReading >= SERIAL_INTERVAL_MS) {
    lastSerialReading = now;
    Serial.printf("Potentiometer ADC: %d\n", analogRead(A0));
  }

  if (!MQTT_ENABLED) {
    return;
  }

  connectMqtt(now);

  // mqtt.loop() processes incoming messages and maintains the connection.
  if (!mqtt.connected() || !mqtt.loop()) {
    return;
  }

  // Refresh the time because connection attempts may take some time.
  now = millis();

  // Retain the LED state so a new subscriber can receive the latest report.
  if (ledStatePending && mqtt.publish(stateTopic, ledOn ? "1" : "0", true)) {
    ledStatePending = false;
  }

  publishSwitchEvent(now);
  publishPotentiometer(now);
}
