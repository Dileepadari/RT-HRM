<?php
/**
 * Returns latest HR data of all active devices
 * GET /hr2/api/latest-all.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';

try {
    $devices = getAllActiveDevices();
    echo json_encode([
        'success' => true,
        'devices' => $devices,
        'count'   => count($devices),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
