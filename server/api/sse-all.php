<?php
/**
 * Multi-device SSE stream
 * Pushes new data with separate lastId for each device
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('X-Accel-Buffering: no');

if (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/../config.php';

$allDevices = getAllowedDeviceList();
$maxRuntime = 60;
$startTime  = time();
$lastIds    = [];

try {
    $pdo = getDB();
    foreach ($allDevices as $mac) {
        $stmt = $pdo->prepare('SELECT id FROM heart_rate_logs WHERE device_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$mac]);
        $row = $stmt->fetch();
        $lastIds[$mac] = $row ? (int)$row['id'] : 0;
    }
} catch (Exception $e) {
    sendSSE('error', ['message' => $e->getMessage()]);
    exit;
}

sendSSE('connected', [
    'devices' => $allDevices,
    'time'    => date('H:i:s'),
]);

while (true) {
    if ((time() - $startTime) >= $maxRuntime) {
        sendSSE('timeout', ['message' => 'Reconnect required']);
        break;
    }
    if (connection_aborted()) break;

    try {
        foreach ($lastIds as $mac => $lastId) {
            $stmt = $pdo->prepare(
                'SELECT id, heart_rate, battery_level, sensor_contact, recorded_at,
                        TIMESTAMPDIFF(SECOND, recorded_at, NOW()) as seconds_ago
                 FROM heart_rate_logs
                 WHERE device_id = ? AND id > ?
                 ORDER BY id ASC LIMIT 5'
            );
            $stmt->execute([$mac, $lastId]);
            $rows = $stmt->fetchAll();

            if (!empty($rows)) {
                $latest = end($rows);
                $lastIds[$mac] = (int)$latest['id'];
                // Device artifact filter: fixed HR value sent during connection loss
                $artifactHR = getDeviceArtifactHR($mac);
                $isPvsArtifact = $artifactHR !== null
                    && (int)$latest['heart_rate'] === $artifactHR;
                if ($isPvsArtifact) {
                    sendSSE('device_artifact', [
                        'mac'        => $mac,
                        'short_name' => getDeviceShortName($mac),
                        'message'    => 'Connection loss (flat HR=30)',
                    ]);
                } else {
                    sendSSE('heartbeat', [
                        'mac'            => $mac,
                        'type'           => getDeviceType($mac),
                        'short_name'     => getDeviceShortName($mac),
                        'heart_rate'     => (int)$latest['heart_rate'],
                        'battery_level'  => $latest['battery_level'] !== null ? (int)$latest['battery_level'] : null,
                        'sensor_contact' => (bool)$latest['sensor_contact'],
                        'recorded_at'    => $latest['recorded_at'],
                        'seconds_ago'    => (int)$latest['seconds_ago'],
                        'time'           => date('H:i:s', strtotime($latest['recorded_at'])),
                        'device_id'      => $mac,
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        try { $pdo = getDB(); } catch (Exception $e2) {
            sendSSE('error', ['message' => 'DB connection lost']);
            break;
        }
    }
    sleep(1);
}

function sendSSE(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}
