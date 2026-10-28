<?php
// Return JSON and prevent cached responses.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Handle database errors using exceptions.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Select a node from the URL, using node_1 by default.
$nodeName = $_GET['node'] ?? 'node_1';

// Validate the supplied node-name parameter.
if (
    !is_string($nodeName) ||
    trim($nodeName) === '' ||
    strlen($nodeName) > 10
) {
    http_response_code(400);

    echo json_encode(array(
        'error' => 'Provide a valid node name.'
    ));

    exit;
}

$nodeName = trim($nodeName);

try {
    // Connect to database.
    $conn = new mysqli(
        '',
        '',
        '',
        ''
    );

    $conn->set_charset('utf8mb4');

    // Retrieve only the requested node's readings.
    $statement = $conn->prepare(
        "SELECT node_name, time_received, temperature, humidity
         FROM sensor_data
         WHERE node_name = ?
         ORDER BY time_received ASC"
    );

    $statement->bind_param('s', $nodeName);
    $statement->execute();

    $result = $statement->get_result();

    // Collect the actual database rows.
    $readings = $result->fetch_all(MYSQLI_ASSOC);

    // Release resources and close the connection.
    $result->free();
    $statement->close();
    $conn->close();

    // Convert the database rows into readable JSON.
    echo json_encode($readings, JSON_PRETTY_PRINT);

} catch (mysqli_sql_exception $error) {
    // Record detailed errors privately.
    error_log($error->getMessage());
    http_response_code(500);

    echo json_encode(array(
        'error' => 'Unable to retrieve sensor data.'
    ));
}
