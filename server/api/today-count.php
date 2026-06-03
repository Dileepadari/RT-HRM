<?php
/**
 * GET /hr2/api/today-count.php?device=MAC
 * Return today's reading count and statistics of a specific device
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';

$device = $_GET['device'] ?? getDefaultDevice();
$allowed = getAllowedDeviceList();
if (!in_array($device, $allowed)) {
    $device = getDefaultDevice();
}

try {
    $pdo = getDB();
    $sql = "SELECT COUNT(*) as total_readings,
                   AVG(heart_rate) as avg_hr,
                   MIN(heart_rate) as min_hr,
                   MAX(heart_rate) as max_hr
            FROM heart_rate_logs
            WHERE device_id = ? AND recorded_at >= CURDATE() AND recorded_at < CURDATE() + INTERVAL 1 DAY
            " . getArtifactFilter();
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$device]);
    $row = $stmt->fetch();
    echo json_encode([
        'success' => true,
        'count' => (int)$row['total_readings'],
        'min_hr' => $row['min_hr'] !== null ? (int)$row['min_hr'] : null,
        'avg_hr' => $row['avg_hr'] !== null ? (int)round($row['avg_hr']) : null,
        'max_hr' => $row['max_hr'] !== null ? (int)$row['max_hr'] : null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
