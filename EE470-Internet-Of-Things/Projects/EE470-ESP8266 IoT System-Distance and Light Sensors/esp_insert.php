<?php
/*
 * Project: EE470 ESP8266 Distance and Light Monitor
 * File: esp_insert.php
 * Author: Hamza Alshamasneh
 * Version: 1.0
 *
 * Purpose:
 * Validate ESP requests and store unique measurements.
 *
 * Inputs:
 * nodeId, timeReceived, distanceCm, lightRaw, timeZone, utcOffset.
 *
 * Outputs:
 * JSON acknowledgement and HTTP status.
 *
 * Dependencies:
 * mysqli, sensor_register, esp_sensor_data, sensor_activity,
 * and the AFTER INSERT counting trigger.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Return a consistent JSON response.
function reply($httpCode, $status, $message, $details = array()) {
    http_response_code($httpCode);

    echo json_encode(
        array_merge(
            array(
                'status' => $status,
                'message' => $message
            ),
            $details
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// The ESP firmware uses GET.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    reply(405, 'error', 'Use GET to submit ESP measurements.');
}

// Require all fields, including the original measurement timestamp.
$required = array(
    'nodeId',
    'timeReceived',
    'distanceCm',
    'lightRaw',
    'timeZone',
    'utcOffset'
);

foreach ($required as $field) {
    if (
        !isset($_GET[$field]) ||
        !is_string($_GET[$field]) ||
        trim($_GET[$field]) === ''
    ) {
        reply(400, 'error', 'Missing or invalid parameter: ' . $field);
    }
}

$node = trim($_GET['nodeId']);
$timestamp = trim($_GET['timeReceived']);
$distanceText = trim($_GET['distanceCm']);
$lightText = trim($_GET['lightRaw']);
$zone = trim($_GET['timeZone']);
$offset = trim($_GET['utcOffset']);

// Validate the node name.
if (!preg_match('/\A[A-Za-z0-9_-]{1,10}\z/', $node)) {
    reply(
        400,
        'error',
        'Invalid node name; use up to 10 letters, numbers, underscores or hyphens.'
    );
}

// Validate distance without silently rounding excessive precision.
if (!preg_match(
    '/\A[0-9]{1,3}(?:\.[0-9]{1,2})?\z/',
    $distanceText
)) {
    reply(
        400,
        'error',
        'Distance must be a decimal number with at most two decimal places.'
    );
}

$distance = (float) $distanceText;

if (!is_finite($distance) || $distance < 2 || $distance > 400) {
    reply(400, 'error', 'Distance must be between 2 and 400 cm.');
}

$distanceDb = number_format($distance, 2, '.', '');

// Light readings must be whole numbers from the ADC.
if (
    !preg_match('/\A[0-9]{1,4}\z/', $lightText) ||
    (int) $lightText > 1023
) {
    reply(
        400,
        'error',
        'Light must be a whole-number ADC reading from 0 to 1023.'
    );
}

$light = (int) $lightText;

// The firmware sends six fractional-second digits.
if (
    !preg_match(
        '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/',
        $timestamp
    ) ||
    (int) substr($timestamp, 0, 4) < 1000
) {
    reply(
        400,
        'error',
        'Use timestamp format YYYY-MM-DD HH:MM:SS.ffffff.'
    );
}

// Match the seven timezone options in the ESP firmware.
$allowedZones = array(
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Anchorage',
    'Pacific/Honolulu',
    'America/Puerto_Rico'
);

if (!in_array($zone, $allowedZones, true)) {
    reply(400, 'error', 'Select one of the seven supported time zones.');
}

if (!preg_match('/\A[+-]\d{4}\z/', $offset)) {
    reply(
        400,
        'error',
        'UTC offset must have the form -0800 or +0000.'
    );
}

// Parse the submitted time using its offset, not the server's timezone.
$submittedTime = $timestamp . ' ' . $offset;

$instant = DateTimeImmutable::createFromFormat(
    '!Y-m-d H:i:s.u O',
    $submittedTime
);

$dateErrors = DateTimeImmutable::getLastErrors();

// Reject invalid dates instead of accepting PHP's date normalization.
if (
    !$instant ||
    (
        $dateErrors !== false &&
        (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        )
    ) ||
    $instant->format('Y-m-d H:i:s.u O') !== $submittedTime
) {
    reply(400, 'error', 'Invalid calendar date, time or UTC offset.');
}

// Confirm that the supplied timezone agrees with the timestamp/offset.
// This accounts for daylight-saving time on the measurement date.
$zonedTime = $instant->setTimezone(new DateTimeZone($zone));

if ($zonedTime->format('Y-m-d H:i:s.u O') !== $submittedTime) {
    reply(
        400,
        'error',
        'The timestamp and UTC offset do not match the selected time zone.'
    );
}

// Save UTC separately for chronological ordering across time zones.
$utcTime = $instant
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s.u');

// The ESP checks nodeId and timeReceived in successful responses.
$details = array(
    'nodeId' => $node,
    'timeReceived' => $timestamp,
    'distanceCm' => $distance,
    'lightRaw' => $light,
    'timeZone' => $zone,
    'utcOffset' => $offset,
    'timeUtc' => $utcTime
);

try {
    // Enter existing database password here.
    $conn = new mysqli(
        'localhost',
        '',
        '',
        ''
    );

    $conn->set_charset('utf8mb4');

    // Only registered nodes may submit measurements.
    $check = $conn->prepare(
        'SELECT node_name
         FROM sensor_register
         WHERE node_name = ?'
    );

    $check->bind_param('s', $node);
    $check->execute();
    $check->store_result();

    $registered = $check->num_rows > 0;
    $check->close();

    if (!$registered) {
        reply(400, 'error', 'Rejected: node is not registered.');
    }

    // Insert a new measurement.
    $insert = $conn->prepare(
        'INSERT INTO esp_sensor_data
            (node_name, time_received, time_utc, distance_cm,
             light_raw, time_zone, utc_offset)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $insert->bind_param(
        'ssssiss',
        $node,
        $timestamp,
        $utcTime,
        $distanceDb,
        $light,
        $zone,
        $offset
    );

    try {
        $insert->execute();

    } catch (mysqli_sql_exception $error) {
        // Handle duplicate timestamps separately.
        if ((int) $error->getCode() !== 1062) {
            throw $error;
        }

        $insert->close();

        // A retry is successful only if the stored payload matches.
        $existing = $conn->prepare(
            'SELECT time_utc, distance_cm, light_raw,
                    time_zone, utc_offset
             FROM esp_sensor_data
             WHERE node_name = ? AND time_received = ?'
        );

        $existing->bind_param('ss', $node, $timestamp);
        $existing->execute();

        $row = $existing->get_result()->fetch_assoc();
        $existing->close();

        $matches = $row &&
            $row['time_utc'] === $utcTime &&
            number_format(
                (float) $row['distance_cm'],
                2,
                '.',
                ''
            ) === $distanceDb &&
            (int) $row['light_raw'] === $light &&
            $row['time_zone'] === $zone &&
            $row['utc_offset'] === $offset;

        if ($matches) {
            reply(
                200,
                'already_stored',
                'Matching measurement already stored; counter unchanged.',
                $details
            );
        }

        // Same node/timestamp with different data is not a valid retry.
        reply(
            409,
            'conflict',
            'This node and timestamp already exist with different data.',
            $details
        );
    }

    $insert->close();
    $conn->close();

    // The database trigger already increments sensor_activity.
    // Do not increment the counter again in PHP.
    reply(
        201,
        'stored',
        'Measurement stored successfully.',
        $details
    );

} catch (mysqli_sql_exception $error) {
    // Keep detailed database errors in the server log.
    error_log('EE470 ESP receiver: ' . $error->getMessage());

    if ((int) $error->getCode() === 1452) {
        reply(400, 'error', 'Rejected: node is not registered.');
    }

    reply(
        500,
        'error',
        'Database operation failed. Check credentials and the server error log.'
    );
}
