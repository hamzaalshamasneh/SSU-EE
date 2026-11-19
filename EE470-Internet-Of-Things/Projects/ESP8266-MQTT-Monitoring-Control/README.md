# EE470 — Introduction to MQTT

ESP8266 NodeMCU project for potentiometer monitoring, pushbutton
events, remote LED control, and database visualization.

## Project links

- Semester page: https://hamzaalshamasneh.com/IoT.html
- MQTT dashboard:
  https://hamzaalshamasneh.com/Chartjs/mqtt_display.php

## Hardware

- ESP8266 NodeMCU
- Potentiometer connected to A0 through the circuit's voltage divider
- External LED connected to D1
- Pushbutton connected between D2 and GND
- USB battery bank for the demonstration

## How it works

The ESP publishes potentiometer readings and switch events to
HiveMQ. It subscribes to an LED command topic.

A Python relay running on a laptop subscribes to the ESP's output
topics and saves accepted readings to a Hostinger MySQL database.

The website reads saved data through a PHP JSON endpoint and
displays the potentiometer readings using Chart.js. It also
provides MQTT LED controls.

## Files

| File | Purpose |
|---|---|
| src/main.cpp | Combined ESP firmware |
| include/hivemq_ca.h | Public CA certificate for ESP TLS verification |
| platformio.ini | Board, framework, library, and serial settings |
| relay/mqtt_relay.py | MQTT subscriber and MySQL relay |
| relay/requirements.txt | Python dependencies |
| database/mqtt_setup.sql | Creates the MQTT readings table |
| website/Chartjs/mqtt_config.php | Server-side database configuration |
| website/Chartjs/mqtt_data.php | Database-to-JSON endpoint |
| website/Chartjs/mqtt_display.php | Graph, event table, and LED controls |

## MQTT topics

Broker: broker.hivemq.com

| Topic | Purpose |
|---|---|
| testtopic/temp/inTopic | Publish 1 or 0 to control the LED |
| testtopic/temp/outTopic/ee470-node-7k39 | Potentiometer ADC readings |
| testtopic/temp/outTopic/ee470-node-7k39/switch | Switch pulse: 1, then 0 after five seconds |
| testtopic/temp/outTopic/ee470-node-7k39/ledState | ESP-reported LED state |

## Connections

| Client | Port | Transport |
|---|---|---|
| ESP | 8883 | MQTT over TLS |
| Phone app | 8883 | MQTT over TLS |
| Browser dashboard | 8884 | Secure WebSocket |
| Python relay | 8000 | WebSocket |
| Python to Hostinger MySQL | 3306 | MySQL |

Use a different client ID for each MQTT client.

## Timing and storage

The ESP publishes a potentiometer reading approximately every
15 seconds.

The relay saves the first potentiometer reading after startup.
Subsequent potentiometer readings are saved only when they differ
from the last successfully saved value.

A one-count ADC difference counts as a change. Switch and
LED-state events are saved independently of that filter.
Old retained snapshots are skipped by the relay.

Database receipt timestamps are stored in UTC. The dashboard
converts timestamps to the selected display timezone.

## Setup

1. Enter local Wi-Fi credentials in src/main.cpp.
2. Build and upload the firmware using PlatformIO.
3. Run database/mqtt_setup.sql in the existing Hostinger database.
4. Authorize the laptop's public IP in Hostinger Remote MySQL.
5. Configure the remote database host and credentials in the relay.
6. Configure the local database connection in mqtt_config.php.
7. Upload the three website PHP files to public_html/Chartjs.
8. Install the Python packages and start the relay:

   ```powershell
   cd relay
   py -m pip install -r requirements.txt
   py mqtt_relay.py
   ```

9. Open the hosted MQTT dashboard.

Passwords in this repository are placeholders. Enter actual
credentials only in private working copies.
