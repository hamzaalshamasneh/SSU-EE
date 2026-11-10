<?php
/*
 * Project: EE470 ESP8266 Distance and Light Monitor
 * File: esp_display.php
 * Author: Hamza Alshamasneh
 * Version: 1.1
 *
 * Purpose:
 * Display selected-node measurement counts, statistics and plots.
 *
 * Inputs:
 * Optional GET parameter: node.
 *
 * Outputs:
 * HTML dashboard and Chart.js plots using database records.
 *
 * Dependencies:
 * mysqli, Chart.js, sensor_register, sensor_activity, esp_sensor_data.
 */

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Escape database values before displaying them in HTML.
function h($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Show N/A when a node has no measurements.
function statValue($value, $decimals = 2) {
    return $value === null
        ? 'N/A'
        : number_format((float) $value, $decimals);
}

try {
    $conn = new mysqli(
        'localhost',
        '',
        '',
        ''
    );

    $conn->set_charset('utf8mb4');

    // Read counts, statistics and readings from one snapshot.
    $conn->begin_transaction(
        MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT
    );

    // Registered nodes supply the dropdown and measurement counts.
    $nodes = $conn->query(
        'SELECT r.node_name,
                COALESCE(a.message_count, 0) AS message_count
         FROM sensor_register AS r
         LEFT JOIN sensor_activity AS a
             ON a.node_name = r.node_name
         ORDER BY r.node_name'
    )->fetch_all(MYSQLI_ASSOC);

    $names = array_column($nodes, 'node_name');
    $selected = $_GET['node'] ?? ($names[0] ?? '');

    if (
        !is_string($selected) ||
        ($selected !== '' && !in_array($selected, $names, true)) ||
        ($selected === '' && count($names) > 0)
    ) {
        $conn->rollback();
        $conn->close();
        http_response_code(400);
        exit('Invalid node. Select a registered node from the dashboard.');
    }

    $selectedCount = 0;

    foreach ($nodes as $node) {
        if ($node['node_name'] === $selected) {
            $selectedCount = (int) $node['message_count'];
            break;
        }
    }

    // Calculate statistics for the selected node.
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS reading_count,
                AVG(distance_cm) AS avg_distance,
                MIN(distance_cm) AS min_distance,
                MAX(distance_cm) AS max_distance,
                AVG(light_raw) AS avg_light,
                MIN(light_raw) AS min_light,
                MAX(light_raw) AS max_light
         FROM esp_sensor_data
         WHERE node_name = ?'
    );

    $stmt->bind_param('s', $selected);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Retrieve only the selected node's measurements.
    $stmt = $conn->prepare(
        'SELECT node_name, time_received, time_utc, distance_cm,
                light_raw, time_zone, utc_offset
         FROM esp_sensor_data
         WHERE node_name = ?
         ORDER BY time_utc ASC, time_received ASC'
    );

    $stmt->bind_param('s', $selected);
    $stmt->execute();
    $readings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $conn->commit();
    $conn->close();

    // Prepare timestamps and sensor values for the charts.
    $plotRows = array();

    foreach ($readings as $row) {
        $utc = new DateTimeImmutable(
            $row['time_utc'],
            new DateTimeZone('UTC')
        );

        $plotRows[] = array(
            'x' => (float) $utc->format('U.u') * 1000,
            'distance' => (float) $row['distance_cm'],
            'light' => (int) $row['light_raw'],
            'localLabel' =>
                $row['time_received'] . ' ' . $row['utc_offset'] .
                ' (' . $row['time_zone'] . ')',
            'utcLabel' => $row['time_utc'] . ' UTC'
        );
    }

    $chartData = array(
        'node' => $selected,
        'rows' => $plotRows
    );

} catch (Exception $error) {
    error_log('EE470 ESP dashboard: ' . $error->getMessage());
    http_response_code(500);

    exit(
        'Unable to load the dashboard. ' .
        'Check the database settings and server error log.'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EE470 ESP8266 Sensor Dashboard</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px 16px;
            background: #f4f6f8;
            color: #222;
            font-family: Arial, sans-serif;
            line-height: 1.5;
        }

        main {
            max-width: 1100px;
            margin: auto;
        }

        header {
            text-align: center;
            margin-bottom: 24px;
        }

        h1 {
            margin-bottom: 6px;
            color: #205781;
        }

        h2, h3 {
            margin-top: 0;
        }

        a {
            color: #205781;
        }

        section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .controls {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        select, button {
            font: inherit;
            padding: 8px 12px;
            border: 1px solid #aaa;
            border-radius: 5px;
        }

        button {
            background: #205781;
            color: white;
            cursor: pointer;
        }

        .muted {
            color: #56616c;
            font-size: 14px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .stat {
            background: #edf3f8;
            padding: 16px;
            border-radius: 6px;
        }

        .stat strong {
            display: block;
        }

        .value {
            font-size: 23px;
            margin-top: 6px;
        }

        .scroll {
            overflow: auto;
            max-height: 420px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 9px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #205781;
            color: white;
            position: sticky;
            top: 0;
        }

        tbody tr:nth-child(even) {
            background: #edf3f8;
        }

        .charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .canvas-wrap {
            position: relative;
            height: 330px;
        }

        .notice {
            background: #fff4d6;
            padding: 12px;
            border-radius: 5px;
        }

        @media (max-width: 760px) {
            .charts {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 450px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<main>
    <header>
        <h1>ESP8266 Sensor Dashboard</h1>
        <p>Hamza Alshamasneh — EE470 Internet of Things</p>
        <a href="../IoT.html">Back to course page</a>

        <p class="muted">
            Page updated: <?= h(gmdate('Y-m-d H:i:s')) ?> UTC
        </p>
    </header>

    <section id="sensor-dashboard">
        <h2>Sensor Measurements</h2>

        <form
            method="get"
            action="esp_display.php#sensor-dashboard"
            class="controls">

            <label for="node"><strong>Select node:</strong></label>

            <select
                name="node"
                id="node"
                onchange="this.form.submit()"
                <?= !$nodes ? 'disabled' : '' ?>>

                <?php foreach ($names as $name): ?>
                    <option
                        value="<?= h($name) ?>"
                        <?= $name === $selected ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button" onclick="window.location.reload()">
                Refresh now
            </button>

            <label>
                <input type="checkbox" id="autoRefresh" checked>
                Refresh every 10 seconds
            </label>
        </form>

        <p>
            Selected node:
            <strong><?= h($selected ?: 'None') ?></strong>
            |
            Valid, unique measurements:
            <strong><?= h($selectedCount) ?></strong>
        </p>

        <p class="muted">
            Light is a raw ADC reading (0–1023), not lux.
            In this setup, higher readings indicate darker conditions.
        </p>

        <div class="stats">
            <div class="stat">
                <strong>Average Distance</strong>
                <div class="value">
                    <?= h(statValue($stats['avg_distance'])) ?> cm
                </div>
            </div>

            <div class="stat">
                <strong>Minimum Distance</strong>
                <div class="value">
                    <?= h(statValue($stats['min_distance'])) ?> cm
                </div>
            </div>

            <div class="stat">
                <strong>Maximum Distance</strong>
                <div class="value">
                    <?= h(statValue($stats['max_distance'])) ?> cm
                </div>
            </div>

            <div class="stat">
                <strong>Average Light Reading</strong>
                <div class="value">
                    <?= h(statValue($stats['avg_light'])) ?>
                </div>
            </div>

            <div class="stat">
                <strong>Minimum Light Reading</strong>
                <div class="value">
                    <?= h(statValue($stats['min_light'], 0)) ?>
                </div>
            </div>

            <div class="stat">
                <strong>Maximum Light Reading</strong>
                <div class="value">
                    <?= h(statValue($stats['max_light'], 0)) ?>
                </div>
            </div>
        </div>

        <?php if (!$readings): ?>
            <p class="notice">
                No ESP measurements for this node yet.
                Send a reading, then refresh.
            </p>
        <?php endif; ?>

        <?php if ($selectedCount !== (int) $stats['reading_count']): ?>
            <p class="notice">
                The activity counter does not match the stored row count.
                Check the counting trigger before using these totals.
            </p>
        <?php endif; ?>

        <p class="muted">
            Plots include only the selected node.
            Horizontal axes use UTC so measurements stay in chronological
            order when the ESP timezone changes.
            Hover over a point to see its original local timestamp.
        </p>

        <p id="chartError" class="notice" hidden></p>

        <div class="charts">
            <div>
                <h3>Distance over Time</h3>
                <div class="canvas-wrap">
                    <canvas
                        id="distanceChart"
                        role="img"
                        aria-label="Distance over time for the selected node">
                    </canvas>
                </div>
            </div>

            <div>
                <h3>Light Reading over Time</h3>
                <div class="canvas-wrap">
                    <canvas
                        id="lightChart"
                        role="img"
                        aria-label="Raw light readings over time for the selected node">
                    </canvas>
                </div>
            </div>
        </div>
    </section>

    <section>
        <h2>
            Stored Readings — <?= h($selected ?: 'No node selected') ?>
        </h2>

        <p class="muted">
            All readings for the selected node, ordered by measurement time.
        </p>

        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th>Node</th>
                        <th>Original Local Timestamp</th>
                        <th>Time Zone</th>
                        <th>UTC Offset</th>
                        <th>Distance (cm)</th>
                        <th>Light (raw ADC)</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($readings as $row): ?>
                    <tr>
                        <td><?= h($row['node_name']) ?></td>
                        <td><?= h($row['time_received']) ?></td>
                        <td><?= h($row['time_zone']) ?></td>
                        <td><?= h($row['utc_offset']) ?></td>
                        <td><?= h($row['distance_cm']) ?></td>
                        <td><?= h($row['light_raw']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$readings): ?>
                    <tr>
                        <td colspan="6">No stored measurements.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<!-- Pass actual database records safely to JavaScript. -->
<script type="application/json" id="chartData">
<?= json_encode(
    $chartData,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.1/chart.min.js"></script>

<script>
const data = JSON.parse(
    document.getElementById('chartData').textContent
);

// Both charts use the selected node's records.
function drawChart(id, field, label, color, maxValue) {
    new Chart(document.getElementById(id), {
        type: 'line',
        data: {
            datasets: [{
                label: label,
                data: data.rows.map(row => ({
                    x: row.x,
                    y: row[field]
                })),
                borderColor: color,
                backgroundColor: color,
                borderWidth: 2,
                pointRadius: 4,
                fill: false,
                tension: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Sensor Node ' + (data.node || 'None')
                },
                tooltip: {
                    callbacks: {
                        title: items => items.length
                            ? data.rows[items[0].dataIndex].localLabel
                            : '',
                        afterBody: items => items.length
                            ? data.rows[items[0].dataIndex].utcLabel
                            : ''
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    title: {
                        display: true,
                        text: 'Measurement Time (UTC)'
                    },
                    ticks: {
                        maxTicksLimit: 5,
                        maxRotation: 45,
                        callback: value => data.rows.length
                            ? new Date(value)
                                .toISOString()
                                .replace('T', ' ')
                                .slice(5, 19)
                            : ''
                    }
                },
                y: {
                    beginAtZero: true,
                    max: maxValue,
                    title: {
                        display: true,
                        text: label
                    }
                }
            }
        }
    });
}

if (typeof Chart !== 'undefined') {
    drawChart(
        'distanceChart',
        'distance',
        'Distance (cm)',
        '#008000',
        undefined
    );

    drawChart(
        'lightChart',
        'light',
        'Light Reading (raw ADC)',
        '#b36b00',
        1023
    );
} else {
    const error = document.getElementById('chartError');
    error.hidden = false;
    error.textContent =
        'Chart.js could not load. Check the internet connection. ' +
        'Tables and statistics remain available.';
}

// Remember the automatic-refresh setting.
const autoRefresh = document.getElementById('autoRefresh');

try {
    autoRefresh.checked =
        localStorage.getItem('ee470EspAutoRefresh') !== 'off';
} catch (error) {
    // Keep the default if browser storage is unavailable.
}

autoRefresh.addEventListener('change', () => {
    try {
        localStorage.setItem(
            'ee470EspAutoRefresh',
            autoRefresh.checked ? 'on' : 'off'
        );
    } catch (error) {
        // The checkbox still works for the current page.
    }
});

// Reloading the current URL preserves the selected node.
setInterval(() => {
    if (
        autoRefresh.checked &&
        document.activeElement !== document.getElementById('node')
    ) {
        window.location.reload();
    }
}, 10000);
</script>
</body>
</html>
