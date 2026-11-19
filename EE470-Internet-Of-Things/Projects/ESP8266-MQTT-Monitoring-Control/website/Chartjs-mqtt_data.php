<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);

    echo json_encode([
        'ok' => false,
        'error' => 'Use GET for this endpoint.',
    ]);

    exit;
}

$pdo = null;

try {
    $config = require __DIR__ . '/mqtt_config.php';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("SET time_zone = '+00:00'");

    $deviceId = 'ee470-node-7k39';

    $pdo->beginTransaction();

    $select = "
        SELECT
            id,
            reading_type,
            reading_value,
            DATE_FORMAT(
                received_at,
                '%Y-%m-%dT%H:%i:%s.%fZ'
            ) AS received_at
        FROM mqtt_readings
    ";

    $potStatement = $pdo->prepare(
        $select . "
        WHERE device_id = ?
          AND reading_type = 'pot'
        ORDER BY id DESC
        LIMIT 300
        "
    );

    $potStatement->execute([$deviceId]);

    // Reverse the newest 300 samples into chronological order.
    $readings = array_reverse(
        $potStatement->fetchAll()
    );

    $eventStatement = $pdo->prepare(
        $select . "
        WHERE device_id = ?
        ORDER BY id DESC
        LIMIT 20
        "
    );

    $eventStatement->execute([$deviceId]);
    $events = $eventStatement->fetchAll();

    $countStatement = $pdo->prepare("
        SELECT COUNT(*)
        FROM mqtt_readings
        WHERE device_id = ?
          AND reading_type = 'pot'
    ");

    $countStatement->execute([$deviceId]);

    $potCount = (int) $countStatement->fetchColumn();

    $pdo->commit();

    $normalize = static function (array $rows): array {
        return array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];

                $row['reading_value'] =
                    (int) $row['reading_value'];

                return $row;
            },
            $rows
        );
    };

    echo json_encode(
        [
            'ok' => true,
            'device_id' => $deviceId,
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'pot_count' => $potCount,
            'readings' => $normalize($readings),
            'events' => $normalize($events),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'MQTT dashboard database error: ' .
        $error->getMessage()
    );

    http_response_code(503);

    echo json_encode([
        'ok' => false,
        'error' => (
            'Database unavailable. Check mqtt_config.php, ' .
            'the database password and mqtt_readings table.'
        ),
    ]);
}
