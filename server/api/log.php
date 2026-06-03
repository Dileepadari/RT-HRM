<?php
/**
 * POST /hr/api/log.php
 * Save heart rate data
 *
 * Body (JSON):
 * {
 *   "device_id": "AA:BB:CC:DD:EE:FF",
 *   "heart_rate": 72,
 *   "rr_intervals": [820, 833],
 *   "sensor_contact": true,
 *   "battery_level": 85,
 *   "recorded_at": 1738764123456  // Unix timestamp (ms) or ISO string supported
 * }
 *
 * recorded_at formats:
 * - Unix timestamp (milliseconds): 1738764123456 (13-digit Long)
 * - ISO 8601 string: "2024-02-04T15:32:07.461" (legacy format, backwards compatible)
 */

require_once __DIR__ . '/../config.php';

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    exit(0);
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Validate API key
validateApiKey();

// JSON body parse
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['error' => 'Invalid JSON body'], 400);
}

// Required fields
$deviceId = $input['device_id'] ?? null;
$heartRate = $input['heart_rate'] ?? null;

if (!$deviceId || !$heartRate) {
    jsonResponse(['error' => 'device_id and heart_rate are required'], 400);
}

// Validate device
validateDevice($deviceId);

// Heart rate validation
$heartRate = (int)$heartRate;
if ($heartRate < 20 || $heartRate > 250) {
    jsonResponse(['error' => 'Invalid heart_rate (20-250)'], 400);
}

// Optional fields
$rrIntervals = $input['rr_intervals'] ?? null;
$sensorContact = isset($input['sensor_contact']) ? (int)$input['sensor_contact'] : 1;
$batteryLevel = isset($input['battery_level']) ? (int)$input['battery_level'] : null;
$recordedAtInput = $input['recorded_at'] ?? null;

// Timestamp parse - supports both Unix timestamp (ms) and ISO 8601 string
if ($recordedAtInput === null) {
    // Current time if not sent
    $recordedAt = date('Y-m-d H:i:s') . '.' . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
    $recordedAtUnix = time();
} elseif (is_numeric($recordedAtInput)) {
    // Unix timestamp (milliseconds) - 13 digit Long
    // use floatval - safer for large integers
    $timestampMs = floatval($recordedAtInput);
    $timestampSec = (int)floor($timestampMs / 1000);
    $milliseconds = (int)($timestampMs % 1000);
    $recordedAt = date('Y-m-d H:i:s', $timestampSec) . '.' . sprintf('%03d', $milliseconds);
    $recordedAtUnix = $timestampSec;

    // Debug log
    error_log("HR API: timestamp_ms=$timestampMs, sec=$timestampSec, ms=$milliseconds, formatted=$recordedAt");
} else {
    // ISO 8601 string (old format - backwards compatibility)
    $recordedAt = str_replace('T', ' ', $recordedAtInput);
    $recordedAtUnix = strtotime($recordedAt);
}

try {
    $pdo = getDB();

    // Main log record
    $sql = "INSERT INTO heart_rate_logs
            (device_id, heart_rate, rr_intervals, sensor_contact, battery_level, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        strtoupper($deviceId),
        $heartRate,
        $rrIntervals ? json_encode($rrIntervals) : null,
        $sensorContact,
        $batteryLevel,
        $recordedAt,
    ]);

    $logId = $pdo->lastInsertId();

    // Update hourly statistics
    $statDate = date('Y-m-d', $recordedAtUnix);
    $statHour = (int)date('H', $recordedAtUnix);

    $sqlStats = "INSERT INTO heart_rate_stats
                 (device_id, stat_date, stat_hour, min_hr, max_hr, avg_hr, sample_count)
                 VALUES (?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                 min_hr = LEAST(min_hr, VALUES(min_hr)),
                 max_hr = GREATEST(max_hr, VALUES(max_hr)),
                 avg_hr = ((avg_hr * sample_count) + VALUES(avg_hr)) / (sample_count + 1),
                 sample_count = sample_count + 1";

    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute([
        strtoupper($deviceId),
        $statDate,
        $statHour,
        $heartRate,
        $heartRate,
        $heartRate,
    ]);

    // Alert check
    $alertType = null;
    $alertMessage = null;

    if ($heartRate < ALERT_LOW_HR) {
        $alertType = 'low';
        $alertMessage = "Heart rate too low: {$heartRate} BPM";
    } elseif ($heartRate > ALERT_HIGH_HR) {
        $alertType = 'high';
        $alertMessage = "Heart rate too high: {$heartRate} BPM";
    }

    if ($alertType) {
        // Check if same alert type exists in last 5 minutes (spam prevention)
        $sqlAlertCheck = "SELECT id FROM heart_rate_alerts
                          WHERE device_id = ? AND alert_type = ?
                          AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                          LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlAlertCheck);
        $stmtCheck->execute([strtoupper($deviceId), $alertType]);

        if (!$stmtCheck->fetch()) {
            // Create new alarm
            $sqlAlert = "INSERT INTO heart_rate_alerts (device_id, alert_type, heart_rate, message) VALUES (?, ?, ?, ?)";
            $stmtAlert = $pdo->prepare($sqlAlert);
            $stmtAlert->execute([strtoupper($deviceId), $alertType, $heartRate, $alertMessage]);
        }
    }

    // Device artifact check (e.g. PVS flat HR=30 connection loss)
    $artifactHR = getDeviceArtifactHR($deviceId);
    $isArtifact = ($artifactHR !== null && $heartRate === $artifactHR);

    // Arrhythmia analysis (only if sensor contact is active and not an artifact)
    $arrhythmiaEvents = [];
    if ($sensorContact && !$isArtifact) {
        try {
            require_once __DIR__ . '/../includes/ArrhythmiaDetector.php';
            $detector = new ArrhythmiaDetector($pdo);
            $arrhythmiaEvents = $detector->analyze(strtoupper($deviceId));

            // Send push notification if critical arrhythmia detected
            if (!empty($arrhythmiaEvents)) {
                $pushFile = __DIR__ . '/../includes/PushNotifier.php';
                if (file_exists($pushFile)) {
                    require_once $pushFile;
                    $notifier = new PushNotifier($pdo);
                    foreach ($arrhythmiaEvents as $event) {
                        if ($event['severity'] !== 'info') {
                            $notifier->send(
                                $event['message'],
                                $event['event_type'],
                                $event['severity'],
                                $event['heart_rate'] ?? null
                            );
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Arrhythmia Detector Error: " . $e->getMessage());
        }
    }

    // Bradycardia episode detection (if not artifact)
    $bradyEpisode = null;
    if ($sensorContact && !$isArtifact) {
        try {
            require_once __DIR__ . '/../includes/BradycardiaDetector.php';
            $bradyDetector = new BradycardiaDetector($pdo);
            $bradyEpisode = $bradyDetector->processReading(
                strtoupper($deviceId), $heartRate, $recordedAt
            );
        } catch (Exception $e) {
            error_log("BradycardiaDetector Error: " . $e->getMessage());
        }
    }

    jsonResponse([
        'success' => true,
        'log_id' => (int)$logId,
        'heart_rate' => $heartRate,
        'recorded_at' => $recordedAt,
        'alert' => $alertType,
        'arrhythmia_events' => $arrhythmiaEvents,
        'brady_episode' => $bradyEpisode,
    ]);

} catch (PDOException $e) {
    error_log("HR API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Database error'], 500);
}
