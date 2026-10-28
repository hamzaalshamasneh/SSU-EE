<?php
// Return readable JSON responses and prevent caching.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Handle database errors using exceptions.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Send a response, optionally including decoded values.
function respond($status, $message, $details = array()) {
    http_response_code($status);

    $response = array_merge(
        array('message' => $message),
        $details
    );

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Accept GET, POST, and PUT.
$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, array('GET', 'POST', 'PUT'), true)) {
    header('Allow: GET, POST, PUT');
    respond(405, 'Use GET, POST, or PUT to send readings.');
}

// Start with parameters supplied in the URL.
$input = $_GET;

// Accept POST form fields.
// Body fields take priority over matching URL parameters.
if ($method === 'POST') {
    $input = array_replace($input, $_POST);
}

// Read URL-encoded PUT body fields.
if ($method === 'PUT') {
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false) {
        respond(400, 'Unable to read the request body.');
    }

    if (trim($rawBody) !== '') {
        $contentType = strtolower(trim(explode(
            ';',
            $_SERVER['CONTENT_TYPE'] ?? ''
        )[0]));

        if ($contentType !== 'application/x-www-form-urlencoded') {
            respond(
                415,
                'For a PUT body, select x-www-form-urlencoded in Postman.'
            );
        }

        $putFields = array();
        parse_str($rawBody, $putFields);
        $input = array_replace($input, $putFields);
    }
}

// Detect an encoded message.
// Supported formats:
// insert.php?data=BASE64_MESSAGE
// insert.php?BASE64_MESSAGE
$encodedMessage = null;
$decodedMessage = null;

if (array_key_exists('data', $input)) {
    if (!is_string($input['data'])) {
        respond(400, 'The encoded message must be a string.');
    }

    $encodedMessage = trim($input['data']);

} elseif ($method === 'GET') {
    // Read the raw query so Base64 padding and plus signs are preserved.
    $rawQuery = rawurldecode(
        $_SERVER['QUERY_STRING'] ?? ''
    );

    if (preg_match(
        '/\A[A-Za-z0-9+\/]+={0,2}\z/',
        $rawQuery
    )) {
        $encodedMessage = $rawQuery;
    }
}

// Decode Base64 and extract its parameter names and values.
if ($encodedMessage !== null) {
    if ($encodedMessage === '') {
        respond(400, 'The encoded message is empty.');
    }

    // Strict mode rejects invalid Base64 characters.
    $decodedMessage = base64_decode($encodedMessage, true);

    if ($decodedMessage === false) {
        respond(400, 'Invalid Base64 message.');
    }

    // Convert the decoded query string into an array.
    $decodedFields = array();
    parse_str($decodedMessage, $decodedFields);

    // Validate and insert values from the decoded message.
    $input = $decodedFields;

    // time inside the encoded message.
    if (
        !isset($input['timeReceived']) ||
        !is_string($input['timeReceived']) ||
        trim($input['timeReceived']) === ''
    ) {
        respond(
            400,
            'Base64 messages must include timeReceived.'
        );
    }
}

// Require node name, temperature, and humidity.
foreach (array('nodeId', 'nodeTemp', 'nodeHumidity') as $field) {
    if (
        !isset($input[$field]) ||
        !is_string($input[$field]) ||
        trim($input[$field]) === ''
    ) {
        respond(400, 'Missing or invalid parameter: ' . $field);
    }
}

// Read the submitted values.
$nodeName = trim($input['nodeId']);
$temperatureText = trim($input['nodeTemp']);
$humidityText = trim($input['nodeHumidity']);

// Match the node_name column's maximum length.
if (strlen($nodeName) > 10) {
    respond(400, 'Node name must contain no more than 10 characters.');
}

// Require numeric readings.
if (!is_numeric($temperatureText) || !is_numeric($humidityText)) {
    respond(400, 'Temperature and humidity must be numbers.');
}

$temperature = (float) $temperatureText;
$humidity = (float) $humidityText;

// Validate temperature.
if (
    !is_finite($temperature) ||
    $temperature < -10 ||
    $temperature > 100
) {
    respond(
        400,
        'Temperature must be between -10 and 100 degrees Celsius.'
    );
}

// Validate humidity.
if (!is_finite($humidity) || $humidity < 0 || $humidity > 100) {
    respond(400, 'Humidity must be between 0 and 100 percent.');
}

// Ordinary, unencoded requests can omit the timestamp.
// The database supplies its current time in that case.
$timeReceived = null;

if (array_key_exists('timeReceived', $input)) {
    if (!is_string($input['timeReceived'])) {
        respond(400, 'Invalid timestamp.');
    }

    $timeReceived = trim($input['timeReceived']);

    // Require YYYY-MM-DD HH:MM:SS.
    if (!preg_match(
        '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',
        $timeReceived
    )) {
        respond(400, 'Use timestamp format YYYY-MM-DD HH:MM:SS.');
    }

    // Reject invalid calendar dates and times.
    $date = DateTime::createFromFormat(
        '!Y-m-d H:i:s',
        $timeReceived
    );

    if (
        !$date ||
        $date->format('Y-m-d H:i:s') !== $timeReceived ||
        (int) substr($timeReceived, 0, 4) < 1000
    ) {
        respond(400, 'Invalid calendar date or time.');
    }
}

// Include the decoded message and variables in Base64 responses.
$details = array();

if ($decodedMessage !== null) {
    $details = array(
        'decoded_message' => $decodedMessage,
        'decoded_parameters' => array(
            'nodeId' => $nodeName,
            'nodeTemp' => $temperature,
            'nodeHumidity' => $humidity,
            'timeReceived' => $timeReceived
        )
    );
}

try {
    // Connect to database.
    $conn = new mysqli(
        '',
        '',
        '',
        ''
    );

    $conn->set_charset('utf8mb4');

    // Verify that the transmitting node is registered.
    $check = $conn->prepare(
        "SELECT node_name
         FROM sensor_register
         WHERE node_name = ?"
    );

    $check->bind_param('s', $nodeName);
    $check->execute();
    $check->store_result();

    $isRegistered = $check->num_rows > 0;
    $check->close();

    if (!$isRegistered) {
        $conn->close();

        respond(
            400,
            'Rejected: this node is not registered.',
            $details
        );
    }

    // Insert the validated reading.
    // Missing ordinary timestamps use the database's current time.
    $insert = $conn->prepare(
        "INSERT INTO sensor_data
            (node_name, time_received, temperature, humidity)
         VALUES (?, COALESCE(?, CURRENT_TIMESTAMP), ?, ?)"
    );

    $insert->bind_param(
        'ssdd',
        $nodeName,
        $timeReceived,
        $temperature,
        $humidity
    );

    $insert->execute();
    $insert->close();
    $conn->close();

    respond(
        201,
        'Reading inserted successfully for ' . $nodeName . '.',
        $details
    );

} catch (mysqli_sql_exception $error) {
    // Preserve duplicate-reading protection.
    if ((int) $error->getCode() === 1062) {
        respond(
            409,
            'Rejected: this node already has a reading at that timestamp.',
            $details
        );
    }

    // Preserve foreign-key protection.
    if ((int) $error->getCode() === 1452) {
        respond(
            400,
            'Rejected: this node is not registered.',
            $details
        );
    }

    // Log unexpected errors privately.
    error_log($error->getMessage());

    respond(
        500,
        'Unable to insert the reading. Check the database connection and server error log.'
    );
}
