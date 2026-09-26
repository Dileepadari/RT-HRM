<?php
/**
 * GET /hr/api/arrhythmia.php
 * Fetch arrhythmia events
 *
 * Query params:
 *   device_id - Device MAC address (optional, otherwise default device)
 *   type      - Arrhythmia type filter (sinus_bradycardia, sinus_tachycardia, af_suspect, svt_suspect, flutter_suspect)
 *   severity  - Severity filter (info, warning, critical)
 *   hours     - How many hours to look back (default: 24, max: 168)
 *   limit     - Result limit (default: 100, max: 1000)
 */

require_once __DIR__ . '/../config.php';

requireReadAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Note: No API key check - readonly endpoint called by live.php dashboard via JS fetch

$deviceId = $_GET['device_id'] ?? getDefaultDevice();
$type     = $_GET['type'] ?? null;
$severity = $_GET['severity'] ?? null;
$hours    = min(168, max(1, (int)($_GET['hours'] ?? 24)));
$limit    = min(1000, max(1, (int)($_GET['limit'] ?? 100)));

try {
    $pdo = getDB();

    $sql = "SELECT id, device_id, event_type, severity, confidence, heart_rate,
                   metrics, rr_window, window_size, message, created_at
            FROM arrhythmia_events
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";
    $params = [$hours];

    if ($deviceId) {
        $sql .= " AND device_id = ?";
        $params[] = strtoupper($deviceId);
    }

    if ($type) {
        $sql .= " AND event_type = ?";
        $params[] = $type;
    }

    if ($severity) {
        $sql .= " AND severity = ?";
        $params[] = $severity;
    }

    // 50% and below reliability -> hide (high false positive risk, unnecessary stress)
    $sql .= " AND confidence > 50";

    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    // Decode JSON fields
    foreach ($events as &$event) {
        $event['metrics'] = json_decode($event['metrics'], true);
        $event['rr_window'] = json_decode($event['rr_window'], true);
        $event['confidence'] = (float)$event['confidence'];
        $event['heart_rate'] = $event['heart_rate'] ? (int)$event['heart_rate'] : null;
        $event['window_size'] = (int)$event['window_size'];
        $event['id'] = (int)$event['id'];
    }
    unset($event);

    // Summary Statistics
    $sqlStats = "SELECT event_type, severity, COUNT(*) as count
                 FROM arrhythmia_events
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";
    $statsParams = [$hours];

    if ($deviceId) {
        $sqlStats .= " AND device_id = ?";
        $statsParams[] = strtoupper($deviceId);
    }

    $sqlStats .= " AND confidence > 50";
    $sqlStats .= " GROUP BY event_type, severity ORDER BY count DESC";

    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute($statsParams);
    $summary = $stmtStats->fetchAll();

    jsonResponse([
        'success' => true,
        'count'   => count($events),
        'hours'   => $hours,
        'events'  => $events,
        'summary' => $summary,
    ]);

} catch (PDOException $e) {
    error_log("Arrhythmia API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Database error'], 500);
}
