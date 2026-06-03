<?php
/**
 * GET /hr/api/chart-data.php
 * Returns heart rate data for chart (all allowed devices)
 *
 * Parameters:
 * - minutes: Last X minutes (default: 3)
 * - start: Custom start time (Unix timestamp or Y-m-d H:i:s)
 * - end: Custom end time (Unix timestamp or Y-m-d H:i:s)
 */

require_once __DIR__ . "/../config.php";

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

// v2: filter only that device if device parameter is present
$requestedDevice = $_GET["device"] ?? null;
$allowedDevices = getAllowedDeviceList();
if ($requestedDevice && in_array($requestedDevice, $allowedDevices)) {
    $devices = [$requestedDevice];
} else {
    $devices = $allowedDevices;
}
$placeholders = implode(',', array_fill(0, count($devices), '?'));

$seconds = isset($_GET["seconds"]) ? (int)$_GET["seconds"] : null;
$minutes = isset($_GET["minutes"]) ? (int)$_GET["minutes"] : null;
$startTime = $_GET["start"] ?? null;
$endTime = $_GET["end"] ?? null;

if ($seconds) {
    $totalSeconds = $seconds;
} elseif ($minutes) {
    $totalSeconds = $minutes * 60;
} else {
    $totalSeconds = 10;
}

try {
    $pdo = getDB();

    // fetch rr_intervals for intervals under 60s (for ECG), above only hr+time (covering index)
    $needRR = $totalSeconds <= 60;
    $cols = $needRR ? "heart_rate, recorded_at, rr_intervals" : "heart_rate, recorded_at";

    if ($startTime && $endTime) {
        if (is_numeric($startTime)) {
            $startTime = date("Y-m-d H:i:s", (int)$startTime);
        }
        if (is_numeric($endTime)) {
            $endTime = date("Y-m-d H:i:s", (int)$endTime);
        }

        // calculate interval if start/end are used
        $rangeSeconds = strtotime($endTime) - strtotime($startTime);
        if ($rangeSeconds > 60) {
            $cols = "heart_rate, recorded_at";
            $needRR = false;
        }

        $sql = "SELECT $cols
                FROM heart_rate_logs
                WHERE device_id IN ($placeholders)
                  AND recorded_at >= ?
                  AND recorded_at <= ?
                " . getArtifactFilter() . "
                ORDER BY recorded_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($devices, [$startTime, $endTime]));
    } else {
        $sql = "SELECT $cols
                FROM heart_rate_logs
                WHERE device_id IN ($placeholders)
                  AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                " . getArtifactFilter() . "
                ORDER BY recorded_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($devices, [$totalSeconds]));
    }

    $rows = $stmt->fetchAll();

    $hrData = [];
    $ekgData = [];
    $labels = [];
    $timestamps = [];

    // Stats: calculate from all data (before downsampling)
    $allHR = array_map(fn($r) => (int)$r["heart_rate"], $rows);
    $stats = [];
    if (!empty($allHR)) {
        $stats = [
            "min" => min($allHR),
            "max" => max($allHR),
            "avg" => round(array_sum($allHR) / count($allHR)),
            "count" => count($allHR)
        ];
    }

    // Downsample: max 500 points (if >500 rows, step through)
    $maxPoints = 500;
    $totalRows = count($rows);
    $step = $totalRows > $maxPoints ? (int)ceil($totalRows / $maxPoints) : 1;

    for ($i = 0; $i < $totalRows; $i += $step) {
        $row = $rows[$i];
        $dt = new DateTime($row["recorded_at"]);
        $timestampMs = (int)($dt->getTimestamp() * 1000) + (int)$dt->format("v");

        $timestamps[] = $timestampMs;
        $labels[] = $dt->format("H:i:s.v");
        $hrData[] = (int)$row["heart_rate"];

        if ($needRR) {
            $ekgData[] = [
                "x" => $timestampMs,
                "y" => 1
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "timestamps" => $timestamps,
            "labels" => $labels,
            "heartRate" => $hrData,
            "ekg" => $needRR ? $ekgData : [],
            "stats" => $stats
        ],
        "range" => [
            "start" => !empty($timestamps) ? min($timestamps) : null,
            "end" => !empty($timestamps) ? max($timestamps) : null,
            "seconds" => $totalSeconds
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Chart API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Database error"]);
}
