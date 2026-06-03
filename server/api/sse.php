<?php
/**
 * Heart Rate SSE (Server-Sent Events)
 * Pushes to client when new data arrives from all allowed devices
 */

header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("Access-Control-Allow-Origin: *");
header("X-Accel-Buffering: no");

if (ob_get_level()) ob_end_clean();

require_once __DIR__ . "/../config.php";

$devices = getAllowedDeviceList();
$placeholders = getAllowedDevicePlaceholders();
$lastId = 0;
$lastArrhythmiaId = 0;
$maxRuntime = 60;
$startTime = time();
$checkInterval = 1;

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM heart_rate_logs WHERE device_id IN ($placeholders) ORDER BY id DESC LIMIT 1");
    $stmt->execute($devices);
    $row = $stmt->fetch();
    $lastId = $row ? (int)$row["id"] : 0;

    $stmtArr = $pdo->prepare("SELECT id FROM arrhythmia_events WHERE device_id IN ($placeholders) ORDER BY id DESC LIMIT 1");
    $stmtArr->execute($devices);
    $rowArr = $stmtArr->fetch();
    $lastArrhythmiaId = $rowArr ? (int)$rowArr["id"] : 0;
} catch (Exception $e) {
    sendSSE("error", ["message" => $e->getMessage()]);
    exit;
}

sendSSE("connected", ["lastId" => $lastId, "time" => date("H:i:s")]);

while (true) {
    if ((time() - $startTime) >= $maxRuntime) {
        sendSSE("timeout", ["message" => "Reconnect required"]);
        break;
    }

    if (connection_aborted()) break;

    try {
        $stmt = $pdo->prepare(
            "SELECT id, device_id, heart_rate, battery_level, sensor_contact, recorded_at,
                    TIMESTAMPDIFF(SECOND, recorded_at, NOW()) as seconds_ago
             FROM heart_rate_logs
             WHERE device_id IN ($placeholders) AND id > ?
             ORDER BY id ASC"
        );
        $params = array_merge($devices, [$lastId]);
        $stmt->execute($params);
        $newRows = $stmt->fetchAll();

        if (!empty($newRows)) {
            $latest = end($newRows);
            $lastId = (int)$latest["id"];

            sendSSE("heartbeat", [
                "heart_rate"     => (int)$latest["heart_rate"],
                "battery_level"  => $latest["battery_level"],
                "sensor_contact" => (bool)$latest["sensor_contact"],
                "recorded_at"    => $latest["recorded_at"],
                "seconds_ago"    => (int)$latest["seconds_ago"],
                "time"           => date("H:i:s", strtotime($latest["recorded_at"])),
                "new_count"      => count($newRows),
                "last_id"        => $lastId,
                "device_id"      => $latest["device_id"]
            ]);
        }

        $stmtArr = $pdo->prepare(
            "SELECT id, event_type, severity, confidence, heart_rate, message, created_at
             FROM arrhythmia_events
             WHERE device_id IN ($placeholders) AND id > ?
             ORDER BY id ASC"
        );
        $paramsArr = array_merge($devices, [$lastArrhythmiaId]);
        $stmtArr->execute($paramsArr);
        $newArrhythmias = $stmtArr->fetchAll();

        foreach ($newArrhythmias as $arr) {
            $lastArrhythmiaId = (int)$arr["id"];
            sendSSE("arrhythmia", [
                "id"         => (int)$arr["id"],
                "event_type" => $arr["event_type"],
                "severity"   => $arr["severity"],
                "confidence" => (float)$arr["confidence"],
                "heart_rate" => $arr["heart_rate"] ? (int)$arr["heart_rate"] : null,
                "message"    => $arr["message"],
                "created_at" => $arr["created_at"],
            ]);
        }
    } catch (Exception $e) {
        try {
            $pdo = getDB();
        } catch (Exception $e2) {
            sendSSE("error", ["message" => "DB connection lost"]);
            break;
        }
    }

    sleep($checkInterval);
}

function sendSSE(string $event, array $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}
