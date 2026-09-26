<?php
/**
 * Latest HR data API
 * Returns the latest record from all allowed devices
 */

header("Content-Type: application/json");
header("Cache-Control: no-cache");

require_once __DIR__ . "/../config.php";

requireReadAccess();

try {
    $pdo = getDB();
    $devices = getAllowedDeviceList();
    $placeholders = getAllowedDevicePlaceholders();

    $artifactFilter = getArtifactFilter();
    $sql = "SELECT device_id, heart_rate, battery_level, sensor_contact, recorded_at,
                   TIMESTAMPDIFF(SECOND, recorded_at, NOW()) as seconds_ago
            FROM heart_rate_logs
            WHERE device_id IN ($placeholders)
            $artifactFilter
            ORDER BY recorded_at DESC
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($devices);
    $row = $stmt->fetch();

    if ($row) {
        echo json_encode([
            "success" => true,
            "data" => [
                "heart_rate" => (int)$row["heart_rate"],
                "battery_level" => $row["battery_level"],
                "sensor_contact" => (bool)$row["sensor_contact"],
                "recorded_at" => $row["recorded_at"],
                "seconds_ago" => (int)$row["seconds_ago"],
                "time" => date("H:i:s", strtotime($row["recorded_at"])),
                "device_id" => $row["device_id"]
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "error" => "No data"]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
