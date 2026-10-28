<?php
// Retrieve fresh database results whenever the page loads.
header('Cache-Control: no-store');

// Handle database errors using exceptions.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// database settings.
$servername = '';
$username = '';
$password = '';
$dbname = '';

try {
    // Connect to existing database.
    $conn = new mysqli(
        $servername,
        $username,
        $password,
        $dbname
    );

    $conn->set_charset('utf8mb4');

    // Retrieve registered sensors for the table and dropdown.
    $registerResult = $conn->query(
        "SELECT node_name, manufacturer, longitude, Latitude
         FROM sensor_register
         ORDER BY node_name ASC"
    );

    $registeredSensors = $registerResult->fetch_all(MYSQLI_ASSOC);

    // Extract the registered node names.
    $registeredNodeNames = array_column(
        $registeredSensors,
        'node_name'
    );

    // Initially select the first registered node.
    // After a dropdown selection, read the chosen node from the URL.
    $selectedNode = $_GET['node']
        ?? ($registeredNodeNames[0] ?? '');

    // Accept only a node that exists in sensor_register.
    if (
        !is_string($selectedNode) ||
        (
            $selectedNode !== '' &&
            !in_array($selectedNode, $registeredNodeNames, true)
        ) ||
        (
            $selectedNode === '' &&
            count($registeredNodeNames) > 0
        )
    ) {
        http_response_code(400);
        exit('Invalid selection. Please select a registered sensor node.');
    }

    // Retrieve every reading for the main readings table.
    $dataResult = $conn->query(
        "SELECT node_name, time_received, temperature, humidity
         FROM sensor_data
         ORDER BY node_name ASC, time_received ASC"
    );

    $sensorReadings = $dataResult->fetch_all(MYSQLI_ASSOC);

    // Calculate statistics only for the selected node.
    $statsStatement = $conn->prepare(
        "SELECT
            COUNT(*) AS reading_count,
            AVG(temperature) AS average_temperature,
            AVG(humidity) AS average_humidity,
            MAX(temperature) AS maximum_temperature,
            MIN(humidity) AS minimum_humidity
         FROM sensor_data
         WHERE node_name = ?"
    );

    $statsStatement->bind_param('s', $selectedNode);
    $statsStatement->execute();

    $statsResult = $statsStatement->get_result();
    $statistics = $statsResult->fetch_assoc();

    // Collect the selected node's readings for the graph.
    // Readings are already sorted by timestamp within each node.
    $chartTimes = array();
    $chartTemperatures = array();

    foreach ($sensorReadings as $reading) {
        if ($reading['node_name'] === $selectedNode) {
            $chartTimes[] = $reading['time_received'];
            $chartTemperatures[] = (float) $reading['temperature'];
        }
    }

    // Prepare the selected node's data for JavaScript.
    $chartData = array(
        'node' => $selectedNode,
        'times' => $chartTimes,
        'temperatures' => $chartTemperatures
    );

    // Release database resources.
    $registerResult->free();
    $dataResult->free();
    $statsResult->free();
    $statsStatement->close();
    $conn->close();

} catch (mysqli_sql_exception $error) {
    // Log detailed errors privately on the server.
    error_log($error->getMessage());
    http_response_code(500);
    exit('Unable to load sensor information from the database.');
}

// Escape database values before displaying them in HTML.
function h($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Format statistics with two decimal places.
// Show N/A when the selected node has no readings.
function formatStatistic($value) {
    return $value === null
        ? 'N/A'
        : number_format((float) $value, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>EE470 IoT — Sensor Database and Graph</title>

    <style>
        /* General page appearance. */
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #222;
            margin: 0;
            padding: 24px 16px;
        }

        main {
            max-width: 1000px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 28px;
        }

        section {
            background: white;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        h2 {
            margin-top: 0;
        }

        /* Keep wide tables usable on smaller screens. */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #205781;
            color: white;
        }

        tbody tr:nth-child(even) {
            background: #edf3f8;
        }

        /* Style the sensor-selection controls. */
        .node-selector {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        select,
        button {
            font: inherit;
            padding: 10px 14px;
            border-radius: 5px;
            border: 1px solid #aaa;
        }

        button {
            background: #205781;
            color: white;
            border-color: #205781;
            cursor: pointer;
        }

        /* Display the four statistics together. */
        .statistics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin: 20px 0;
        }

        .statistic {
            background: #edf3f8;
            padding: 16px;
            border-radius: 6px;
        }

        .statistic strong {
            display: block;
            margin-bottom: 8px;
        }

        .statistic span {
            font-size: 22px;
        }

        /* Give Chart.js a dedicated container. */
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }

        @media (max-width: 600px) {
            .statistics {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<main>
    <header>
        <h1>Welcome to SSU IoT Lab</h1>
        <p>Hamza Alshamasneh — EE470 Internet of Things</p>
    </header>

    <!-- Display every registered sensor. -->
    <section>
        <h2>Registered Sensor Nodes</h2>

        <p>
            Total registered nodes:
            <?php echo count($registeredSensors); ?>
        </p>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Node Name</th>
                        <th scope="col">Manufacturer</th>
                        <th scope="col">Longitude</th>
                        <th scope="col">Latitude</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($registeredSensors as $sensor): ?>
                        <tr>
                            <td><?php echo h($sensor['node_name']); ?></td>
                            <td><?php echo h($sensor['manufacturer']); ?></td>
                            <td><?php echo h($sensor['longitude']); ?></td>
                            <td><?php echo h($sensor['Latitude']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Display every reading from every node. -->
    <section>
        <h2>Data Received</h2>

        <p>Total readings: <?php echo count($sensorReadings); ?></p>
        <p>Sorted by node name and then time received.</p>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Node Name</th>
                        <th scope="col">Time Received</th>
                        <th scope="col">Temperature (°C)</th>
                        <th scope="col">Humidity (%)</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($sensorReadings as $reading): ?>
                        <tr>
                            <td><?php echo h($reading['node_name']); ?></td>
                            <td><?php echo h($reading['time_received']); ?></td>
                            <td><?php echo h($reading['temperature']); ?></td>
                            <td><?php echo h($reading['humidity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- The dropdown controls this graph and all four statistics. -->
    <section id="sensor-dashboard">
        <h2>Sensor Data Visualization</h2>

        <?php if (count($registeredNodeNames) === 0): ?>

            <p>No sensor nodes are registered yet.</p>

        <?php else: ?>

            <!-- Submit the selected node and return to this section. -->
            <form
                method="get"
                action="display.php#sensor-dashboard"
                class="node-selector">

                <label for="node">
                    <strong>Select Sensor Node:</strong>
                </label>

                <select
                    name="node"
                    id="node"
                    onchange="this.form.submit()">

                    <?php foreach ($registeredNodeNames as $nodeName): ?>
                        <option
                            value="<?php echo h($nodeName); ?>"
                            <?php
                            if ($nodeName === $selectedNode) {
                                echo 'selected';
                            }
                            ?>>
                            <?php echo h($nodeName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">View</button>
            </form>

            <h3>Statistics for <?php echo h($selectedNode); ?></h3>

            <p>
                Readings for this node:
                <?php echo h($statistics['reading_count']); ?>
            </p>

            <div class="statistics">
                <div class="statistic">
                    <strong>Average Temperature</strong>
                    <span>
                        <?php echo h(formatStatistic(
                            $statistics['average_temperature']
                        )); ?> °C
                    </span>
                </div>

                <div class="statistic">
                    <strong>Average Humidity</strong>
                    <span>
                        <?php echo h(formatStatistic(
                            $statistics['average_humidity']
                        )); ?> %
                    </span>
                </div>

                <div class="statistic">
                    <strong>Maximum Temperature</strong>
                    <span>
                        <?php echo h(formatStatistic(
                            $statistics['maximum_temperature']
                        )); ?> °C
                    </span>
                </div>

                <div class="statistic">
                    <strong>Minimum Humidity</strong>
                    <span>
                        <?php echo h(formatStatistic(
                            $statistics['minimum_humidity']
                        )); ?> %
                    </span>
                </div>
            </div>

            <?php if (count($chartTimes) === 0): ?>
                <p>No readings have been received for this node yet.</p>
            <?php endif; ?>

            <div class="chart-container">
                <canvas
                    id="temperatureChart"
                    role="img"
                    aria-label="Temperature readings over time for <?php
                        echo h($selectedNode);
                    ?>">
                </canvas>
            </div>

        <?php endif; ?>
    </section>
</main>

<!-- Pass the selected node's database readings to JavaScript. -->
<script type="application/json" id="sensor-chart-data">
<?php
echo json_encode(
    $chartData,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
</script>

<!-- Load Chart.js. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.1/chart.min.js"></script>

<script>
    // Read the selected node's data prepared by PHP.
    const sensorData = JSON.parse(
        document.getElementById("sensor-chart-data").textContent
    );

    const canvas = document.getElementById("temperatureChart");

    // Draw a chart when a registered node is available.
    if (canvas) {
        new Chart(canvas, {
            type: "line",

            data: {
                labels: sensorData.times,

                datasets: [{
                label: "Temperature (°C)",
                data: sensorData.temperatures,
            
                // Use green for the line and its data points.
                backgroundColor: "rgba(0, 128, 0, 0.15)",
                borderColor: "rgba(0, 128, 0, 1)",
                pointBackgroundColor: "rgba(0, 128, 0, 1)",
                pointBorderColor: "rgba(0, 128, 0, 1)",
            
                // Make the line and points clearly visible.
                borderWidth: 2,
                pointRadius: 4,
            
                // Draw straight connections without shading beneath the line.
                fill: false,
                tension: 0
            }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    title: {
                        display: true,
                        text: "Sensor Node " + sensorData.node,
                        font: {
                            size: 18
                        }
                    }
                },

                scales: {
                    x: {
                        title: {
                            display: true,
                            text: "Time Received"
                        },

                        ticks: {
                            autoSkip: true,
                            maxRotation: 60,
                            minRotation: 30
                        }
                    },

                    y: {
                        beginAtZero: true,

                        title: {
                            display: true,
                            text: "Temperature (°C)"
                        }
                    }
                }
            }
        });
    }
</script>
</body>
</html>
