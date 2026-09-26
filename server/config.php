<?php
/**
 * Heart Rate API Configuration
 */

// Server timezone - for all timestamp operations
date_default_timezone_set('Asia/Kolkata'); // IST timezone

// Load environment variables from .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            putenv(trim($line));
        }
    }
}

// Database (read from environment variables)
define('DB_HOST', getenv('HR_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('HR_DB_NAME') ?: 'heart_rate_db');
// The DSN hardcoded the default port, so a MySQL on any other port could not
// be reached without editing this file.
define('DB_PORT', getenv('HR_DB_PORT') ?: '3306');
define('DB_USER', getenv('HR_DB_USER') ?: 'root');
define('DB_PASS', getenv('HR_DB_PASS') ?: '');

// API Security (read from environment variables)
define('API_KEY', getenv('HR_API_KEY') ?: 'change-me');
$_hr_devices = getenv('HR_ALLOWED_DEVICES') ?: '';
define('ALLOWED_DEVICES', $_hr_devices ? explode(',', $_hr_devices) : []);

// Alert thresholds
define('ALERT_LOW_HR', 50);    // Alert below 50 BPM
define('ALERT_HIGH_HR', 120);  // Alert above 120 BPM
define('ALERT_NO_SIGNAL_MINUTES', 5);  // Alert if no data for 5 minutes

// Rate limiting
define('MAX_REQUESTS_PER_MINUTE', 120);  // Max requests per minute (2 per second)

// PDO connection
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

// JSON response helper
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Whether anonymous reads are allowed. Off unless explicitly turned on.
define('PUBLIC_READ', in_array(strtolower((string)getenv('HR_PUBLIC_READ')), ['1', 'true', 'yes'], true));

/**
 * Whether this request carries a valid API key.
 *
 * hash_equals, not !==: string comparison returns at the first differing byte,
 * and how long that takes is a measurement of how much of the key the caller
 * guessed.
 *
 * The header only. The key used to be accepted from $_GET as well, which puts
 * it in the web server's access log, in any Referer sent to a third party, and
 * in the browser history of anyone who opens the URL.
 */
function hasValidApiKey(): bool {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    return is_string($apiKey) && hash_equals(API_KEY, $apiKey);
}

// API key validation, for the write endpoints.
function validateApiKey(): void {
    if (!hasValidApiKey()) {
        jsonResponse(['error' => 'Invalid API key'], 401);
    }
}

/**
 * Gate for the read endpoints.
 *
 * These serve continuous heart rate, arrhythmia episodes and history - health
 * data about one identifiable person - and nine of them used to answer anyone
 * who knew the URL, with Access-Control-Allow-Origin: *, including the live SSE
 * streams. Only the write path was ever checked.
 *
 * Passes for a request carrying the API key (the Android app, scripts) or for a
 * browser that signed in to the dashboard, which is what live.php establishes.
 * Set HR_PUBLIC_READ=1 to go back to the old behaviour.
 */
function requireReadAccess(): void {
    if (PUBLIC_READ || hasValidApiKey()) return;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['hr_auth'])) return;

    jsonResponse(['error' => 'Unauthorized'], 401);
}

// Active device MAC address (device that sent data last)
function getDefaultDevice(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (count(ALLOWED_DEVICES) > 1) {
        try {
            $pdo = getDB();
            $ph = implode(',', array_fill(0, count(ALLOWED_DEVICES), '?'));
            $stmt = $pdo->prepare("SELECT device_id FROM heart_rate_logs WHERE device_id IN ($ph) ORDER BY recorded_at DESC LIMIT 1");
            $stmt->execute(array_map('strtoupper', ALLOWED_DEVICES));
            $row = $stmt->fetch();
            if ($row) {
                $cached = $row['device_id'];
                return $cached;
            }
        } catch (Exception $e) {
            // fallback
        }
    }

    $cached = ALLOWED_DEVICES[0] ?? '';
    return $cached;
}

// Return active device type
function getActiveDeviceType(): string {
    return getDeviceType(getDefaultDevice());
}

// Return active device name
function getActiveDeviceName(): string {
    return getDeviceName(getDefaultDevice());
}

// Device MAC address validation
function validateDevice(string $deviceId): void {
    if (!in_array(strtoupper($deviceId), ALLOWED_DEVICES)) {
        jsonResponse(['error' => 'Device not authorized'], 403);
    }
}

// Create SQL placeholder for all allowed devices
function getAllowedDevicePlaceholders(): string {
    return implode(",", array_fill(0, count(ALLOWED_DEVICES), "?"));
}

// List of all allowed device MACs (uppercase)
function getAllowedDeviceList(): array {
    return array_map("strtoupper", ALLOWED_DEVICES);
}

// ===== v2: Multi-device functions =====

/**
 * Device types and artifact rules.
 * Update ONLY this table when a new device is added.
 *
 * - prefix: Starting characters of MAC address (uppercase)
 * - type/name/short: device identity
 * - artifact_hr: fixed HR sent on connection loss (null = no artifact)
 */
const DEVICE_PROFILES = [
    [
        'prefix'      => 'F0:13:C3',
        'type'        => 'wahoo',
        'name'        => 'Wahoo TICKR Fit',
        'short'       => 'TICKR',
        'artifact_hr' => null,
    ],
];

/** Match MAC -> profile (prefix match) */
function getDeviceProfile(string $mac): array {
    $mac = strtoupper($mac);
    foreach (DEVICE_PROFILES as $p) {
        if (str_starts_with($mac, $p['prefix'])) return $p;
    }
    // Unknown device - safe default
    return ['prefix' => '', 'type' => 'unknown', 'name' => $mac, 'short' => '???', 'artifact_hr' => null];
}

/**
 * BLE device artifact filter (SQL).
 * Generates dynamic WHERE clause based on each profile's artifact_hr value.
 * @return string SQL WHERE clause (starts with AND), empty string if no artifacts
 */
function getArtifactFilter(): string {
    $conditions = [];
    foreach (DEVICE_PROFILES as $p) {
        if ($p['artifact_hr'] !== null) {
            $hr = (int)$p['artifact_hr'];
            $prefix = addslashes($p['prefix']);
            $conditions[] = "(heart_rate = {$hr} AND device_id LIKE '{$prefix}%')";
        }
    }
    if (empty($conditions)) return '';
    return " AND NOT (" . implode(' OR ', $conditions) . ")";
}

/**
 * Return artifact HR value for a specific device (null = no artifact)
 */
function getDeviceArtifactHR(string $mac): ?int {
    return getDeviceProfile($mac)['artifact_hr'];
}

function getDeviceType(string $mac): string {
    return getDeviceProfile($mac)['type'];
}

function getDeviceName(string $mac): string {
    return getDeviceProfile($mac)['name'];
}

function getDeviceShortName(string $mac): string {
    return getDeviceProfile($mac)['short'];
}

/**
 * Return latest record of all devices that sent data in last 5 minutes
 */
function getAllActiveDevices(): array {
    $pdo = getDB();
    $allowed = getAllowedDeviceList();
    if (empty($allowed)) return [];

    // ORDER BY id DESC LIMIT 1 for each device - PK lookup, instant
    $unions = [];
    $params = [];
    foreach ($allowed as $mac) {
        $unions[] = "(SELECT device_id, heart_rate, battery_level, sensor_contact, recorded_at,
                             TIMESTAMPDIFF(SECOND, recorded_at, NOW()) as seconds_ago
                      FROM heart_rate_logs WHERE device_id = ? ORDER BY recorded_at DESC LIMIT 1)";
        $params[] = $mac;
    }
    $sql = implode(" UNION ALL ", $unions);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Filter out devices older than 5 minutes
    $rows = array_filter($rows, fn($r) => $r['seconds_ago'] <= 300);

    $result = [];
    foreach ($rows as $row) {
        $mac = $row['device_id'];
        $result[] = [
            'mac'            => $mac,
            'type'           => getDeviceType($mac),
            'name'           => getDeviceName($mac),
            'short_name'     => getDeviceShortName($mac),
            'heart_rate'     => (int)$row['heart_rate'],
            'battery_level'  => $row['battery_level'] !== null ? (int)$row['battery_level'] : null,
            'sensor_contact' => (bool)$row['sensor_contact'],
            'recorded_at'    => $row['recorded_at'],
            'seconds_ago'    => (int)$row['seconds_ago'],
        ];
    }
    return $result;
}
