<?php
/**
 * POST /hr/api/push-token.php  - Save/update FCM token
 * DELETE /hr/api/push-token.php - Remove FCM token
 *
 * POST Body (JSON):
 * {
 *   "fcm_token": "dK3x...",
 *   "device_id": "AA:BB:CC:DD:EE:FF",  // optional
 *   "platform": "android"                // android, ios, web
 * }
 *
 * DELETE Body (JSON):
 * {
 *   "fcm_token": "dK3x..."
 * }
 */

require_once __DIR__ . '/../config.php';

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    exit(0);
}

validateApiKey();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['fcm_token'])) {
    jsonResponse(['error' => 'fcm_token is required'], 400);
}

$fcmToken = trim($input['fcm_token']);

try {
    $pdo = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $deviceId = isset($input['device_id']) ? strtoupper(trim($input['device_id'])) : null;
        $platform = $input['platform'] ?? 'android';

        // Upsert: update if exists, otherwise insert
        $sql = "INSERT INTO push_tokens (fcm_token, device_id, platform, is_active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                device_id = VALUES(device_id),
                platform = VALUES(platform),
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fcmToken, $deviceId, $platform]);

        jsonResponse([
            'success' => true,
            'message' => 'Token registered',
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $stmt = $pdo->prepare("UPDATE push_tokens SET is_active = 0 WHERE fcm_token = ?");
        $stmt->execute([$fcmToken]);

        jsonResponse([
            'success' => true,
            'message' => 'Token deactivated',
        ]);

    } else {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }

} catch (PDOException $e) {
    error_log("Push Token API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Database error'], 500);
}
