"""
EE470 - Introduction to MQTT
Python Relay for MQTT Data Logging

File: mqtt_relay.py

This program runs on a laptop and receives potentiometer,
switch, and LED-state messages from the HiveMQ broker.
It prints the received values and saves them to a MySQL database.

The first potentiometer reading is saved after startup.
Later potentiometer readings are saved only when their value
differs from the last successfully saved value.

Switch and LED-state events are saved separately.
The database records the time when each insert is received.

Stopping this program stops new database inserts. The ESP
can still communicate with the MQTT app through HiveMQ.

Enter your database details below before running a private copy.
"""

import re
import time
import uuid
from queue import Queue, Empty, Full
from datetime import datetime, timezone

import mysql.connector
import paho.mqtt.client as mqtt


# Database details are placeholders for the GitHub version.
# Use the remote MySQL host provided by your hosting service.
DB_HOST = "YOUR_REMOTE_MYSQL_HOST"
DB_USER = "YOUR_DATABASE_USERNAME"
DB_PASSWORD = "YOUR_DATABASE_PASSWORD"
DB_NAME = "YOUR_DATABASE_NAME"

# This ID matches the device ID used in the ESP program.
DEVICE_ID = "ee470-node-7k39"

BASE_TOPIC = f"testtopic/temp/outTopic/{DEVICE_ID}"

# Match each subscribed topic to the reading type stored in MySQL.
TOPICS = {
    BASE_TOPIC: "pot",
    BASE_TOPIC + "/switch": "switch",
    BASE_TOPIC + "/ledState": "led_state",
}

# Incoming messages wait here while the main loop saves them.
# This lets MQTT reception continue during database operations.
EVENTS = Queue(maxsize=1000)

# Use parameters to pass values into the SQL statement.
# Each event has a unique ID, so retrying the same insert
# does not create another copy of that record.
INSERT_SQL = """
INSERT INTO mqtt_readings
    (event_id, device_id, reading_type, topic, reading_value)
VALUES (%s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)
"""


def open_database():
    """Connect to MySQL and check that the required table exists."""

    connection = mysql.connector.connect(
        host=DB_HOST,
        port=3306,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        connection_timeout=10,
        read_timeout=10,
        write_timeout=10,
        autocommit=True,
    )

    try:
        with connection.cursor() as cursor:
            # Use UTC for timestamps created by this connection.
            cursor.execute("SET time_zone = '+00:00'")

            # Check the table without reading its stored records.
            cursor.execute("SELECT id FROM mqtt_readings LIMIT 0")
            cursor.fetchall()

        return connection

    except Exception:
        # Close the connection if the initial checks fail.
        connection.close()
        raise


def on_connect(client, userdata, flags, reason_code, properties):
    """Subscribe to the output topics after connecting to HiveMQ."""

    if reason_code != 0:
        print(
            f"MQTT connection rejected: {reason_code}",
            flush=True,
        )
        return

    # Subscribe again after reconnection because this client
    # uses a clean MQTT session.
    client.subscribe([(topic, 0) for topic in TOPICS])

    print(
        "MQTT connected; subscriptions requested:",
        flush=True,
    )

    for topic in TOPICS:
        print("  " + topic, flush=True)


def on_subscribe(client, userdata, mid, reason_codes, properties):
    """Check whether the broker accepted the subscriptions."""

    if any(code.is_failure for code in reason_codes):
        print(
            f"Subscription rejected: {reason_codes}",
            flush=True,
        )
    else:
        print(
            "Subscriptions accepted. Waiting for new readings...",
            flush=True,
        )


def on_disconnect(client, userdata, flags, reason_code, properties):
    """Print the reason when the MQTT connection closes."""

    print(
        f"MQTT disconnected: {reason_code}",
        flush=True,
    )


def on_connect_fail(client, userdata):
    """Report a failed connection attempt."""

    print(
        "MQTT connection failed; automatically retrying...",
        flush=True,
    )


def on_message(client, userdata, message):
    """Check an incoming message and add valid readings to the queue."""

    kind = TOPICS.get(message.topic)

    # Ignore topics that are not part of this device's output.
    if kind is None:
        return

    # A retained message is a stored snapshot from the broker.
    # Skip it so it is not logged as a newly received device event.
    if message.retain:
        print(
            f"Retained snapshot ignored: {message.topic}",
            flush=True,
        )
        return

    try:
        # The ESP sends its readings as plain numeric text.
        text = message.payload.decode("ascii")

        if re.fullmatch(r"[0-9]{1,4}", text) is None:
            raise ValueError("Expected a short unsigned integer")

        value = int(text)

        # Potentiometer ADC readings range from 0 to 1023.
        # Switch and LED-state values must be 0 or 1.
        maximum = 1023 if kind == "pot" else 1

        if value > maximum:
            raise ValueError("Value outside the expected range")

    except (UnicodeDecodeError, ValueError) as error:
        print(
            f"Ignored invalid message: {error}",
            flush=True,
        )
        return

    # Show the laptop's UTC reception time in the terminal.
    # The database creates its own timestamp when saving the row.
    stamp = datetime.now(timezone.utc).isoformat(
        timespec="seconds"
    )

    print(
        f"{stamp} RX [{message.topic}] {value}",
        flush=True,
    )

    # Keep the fields in the same order as the SQL parameters:
    # event ID, device ID, reading type, topic, and value.
    event = (
        uuid.uuid4().hex,
        DEVICE_ID,
        kind,
        message.topic,
        value,
    )

    try:
        EVENTS.put_nowait(event)

    except Full:
        # Report dropped readings if the queue reaches its limit.
        print(
            "QUEUE FULL: this reading was not saved.",
            flush=True,
        )


def main():
    """Start the connections and process queued readings."""

    connection = None
    client = None

    # Save the first potentiometer reading after each startup.
    # Update this variable only after a successful database insert.
    last_saved_pot = None

    try:
        connection = open_database()

        print(
            "Database connected; mqtt_readings table found.",
            flush=True,
        )

        # Generate a separate client ID for this relay.
        # It must not use the same client ID as the ESP or phone.
        client = mqtt.Client(
            mqtt.CallbackAPIVersion.VERSION2,
            client_id="ee470-relay-" + uuid.uuid4().hex[:10],
            protocol=mqtt.MQTTv311,
            transport="websockets",
            clean_session=True,
        )

        client.ws_set_options(path="/mqtt")

        # Assign the functions used for MQTT connection events
        # and incoming messages.
        client.on_connect = on_connect
        client.on_subscribe = on_subscribe
        client.on_disconnect = on_disconnect
        client.on_connect_fail = on_connect_fail
        client.on_message = on_message

        # Increase the waiting time between failed reconnect attempts.
        client.reconnect_delay_set(
            min_delay=2,
            max_delay=30,
        )

        # Connect to the public broker using its WebSocket port.
        client.connect_async(
            "broker.hivemq.com",
            8000,
            keepalive=60,
        )

        # Handle MQTT traffic in a background thread while
        # the main thread performs database operations.
        client.loop_start()

        print(
            "Relay running. Press Ctrl+C to stop.",
            flush=True,
        )

        print(
            "Potentiometer: save the first reading, "
            "then save only changed values.",
            flush=True,
        )

        pending = None

        while True:
            # Get the next event unless an earlier insert
            # is still waiting to be retried.
            if pending is None:
                try:
                    pending = EVENTS.get(timeout=0.5)
                except Empty:
                    continue

            kind = pending[2]
            value = pending[4]

            # Only potentiometer readings use this comparison.
            # A one-count difference is enough to save a new reading.
            if kind == "pot" and value == last_saved_pot:
                print(
                    f"DB skipped: pot={value} "
                    "(same as the last saved value)",
                    flush=True,
                )

                EVENTS.task_done()
                pending = None
                continue

            try:
                # Reopen the database connection after a failure.
                if connection is None:
                    connection = open_database()

                with connection.cursor() as cursor:
                    cursor.execute(INSERT_SQL, pending)

                # Autocommit saves each insert immediately.
                # Remember the potentiometer value only after success.
                if kind == "pot":
                    last_saved_pot = value

                print(
                    f"DB saved: {kind}={value}",
                    flush=True,
                )

                EVENTS.task_done()
                pending = None

            except mysql.connector.Error as error:
                print(
                    f"Database error: {error}. "
                    "Retrying in 5 seconds...",
                    flush=True,
                )

                if connection is not None:
                    try:
                        connection.close()
                    except mysql.connector.Error:
                        pass

                connection = None

                # Keep the same pending event and event ID.
                # MQTT reception continues in the background.
                time.sleep(5)

    except KeyboardInterrupt:
        # Ctrl+C ends the relay. The queue is only stored in memory.
        print(
            "\nRelay stopped. "
            "Pending in-memory readings are discarded.",
            flush=True,
        )

    except mysql.connector.Error as error:
        print(
            f"Database startup failed: {error}",
            flush=True,
        )

        print(
            "Check DB_HOST, password, Remote MySQL access "
            "and SQL setup.",
            flush=True,
        )

        raise SystemExit(1)

    finally:
        # Close the connections before the program exits.
        if client is not None:
            client.disconnect()
            client.loop_stop()

        if connection is not None:
            connection.close()


# Start the relay when this file is run directly.
if __name__ == "__main__":
    main()
