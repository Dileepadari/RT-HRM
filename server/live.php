<?php
/**
 * Heart Rate Live Dashboard
 * Real-time heart rate monitor dashboard + History
 */

require_once __DIR__ . '/config.php';

/*
 * Dashboard sign-in.
 *
 * Everything below, and every endpoint this page fetches, is one person's
 * heart rate: live, historical, and the arrhythmia episodes. It used to be
 * readable by anyone who knew the URL.
 *
 * The API key doubles as the dashboard password - there is one user and it is
 * already the shared secret with the Android app, so a second credential would
 * be one more thing to lose. The session is what the read endpoints check.
 *
 * Set HR_PUBLIC_READ=1 in .env to skip this entirely and go back to the old
 * behaviour.
 */
if (!PUBLIC_READ) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $loginError = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['hr_key'])) {
        if (hash_equals(API_KEY, (string)$_POST['hr_key'])) {
            session_regenerate_id(true);
            $_SESSION['hr_auth'] = true;
            // Redirect so a reload does not resubmit the key.
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
            exit;
        }
        $loginError = 'Incorrect key.';
    }

    if (empty($_SESSION['hr_auth'])) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Heart Rate Monitor</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               background: #0f1115; color: #e6e6e6;
               font-family: system-ui, -apple-system, sans-serif; }
        form { display: grid; gap: 12px; width: min(320px, 90vw); }
        h1 { font-size: 1.1rem; font-weight: 600; margin: 0 0 4px; }
        p { margin: 0; font-size: .85rem; color: #9aa0a6; }
        input { padding: 10px 12px; border-radius: 8px; border: 1px solid #2a2f3a;
                background: #171a21; color: inherit; font-size: 1rem; }
        button { padding: 10px 12px; border-radius: 8px; border: 0;
                 background: #e5484d; color: #fff; font-size: 1rem; cursor: pointer; }
        .error { color: #e5484d; }
    </style>
</head>
<body>
    <form method="post" autocomplete="off">
        <h1>Heart Rate Monitor</h1>
        <p>This dashboard shows health data. Enter the API key to continue.</p>
        <input type="password" name="hr_key" placeholder="API key" autofocus required>
        <button type="submit">Sign in</button>
        <?php if ($loginError !== ''): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    </form>
</body>
</html><?php
        exit;
    }
}

// Localization
$lang = $_GET['lang'] ?? $_COOKIE['hr_lang'] ?? 'en';
if (!in_array($lang, ['en', 'hi'])) $lang = 'en';
setcookie('hr_lang', $lang, time() + 86400 * 30, '/');

$translations = [
    'en' => [
        'Live' => 'Live',
        'History' => 'History',
        'Arrhythmias' => 'Arrhythmias',
        'Episodes' => 'Episodes',
        'Battery' => 'Battery',
        'Contact' => 'Contact',
        'Today' => 'Today',
        'Records' => 'Records',
        'Average' => 'Average',
        'Heart Rate' => 'Heart Rate',
        'Select device' => 'Select device',
        'Connected' => 'Connected',
        'Disconnected' => 'Disconnected',
        'No Data' => 'No Data',
        'Time' => 'Time',
        'Date' => 'Date',
        'Loading...' => 'Loading...',
        'Error' => 'Error',
        'Unknown Device' => 'Unknown Device',
        'Last 5 Minutes' => 'Last 5 Minutes',
        'Hourly Trend' => 'Hourly Trend',
        'Last 24 Hours' => 'Last 24 Hours'
    ],
    'hi' => [
        'Live' => 'लाइव',
        'History' => 'इतिहास',
        'Arrhythmias' => 'अतालता (Arrhythmias)',
        'Episodes' => 'एपिसोड',
        'Battery' => 'बैटरी',
        'Contact' => 'संपर्क',
        'Today' => 'आज',
        'Records' => 'रिकॉर्ड्स',
        'Average' => 'औसत',
        'Heart Rate' => 'हार्ट रेट',
        'Select device' => 'डिवाइस चुनें',
        'Connected' => 'जुड़ा हुआ',
        'Disconnected' => 'संपर्क टूटा',
        'No Data' => 'कोई डेटा नहीं',
        'Time' => 'समय',
        'Date' => 'तारीख',
        'Loading...' => 'लोड हो रहा है...',
        'Error' => 'त्रुटि',
        'Unknown Device' => 'अज्ञात डिवाइस',
        'Last 5 Minutes' => 'पिछले 5 मिनट',
        'Hourly Trend' => 'घंटेवार रुझान',
        'Last 24 Hours' => 'पिछले 24 घंटे'
    ]
];

function __($key) {
    global $lang, $translations;
    return $translations[$lang][$key] ?? $key;
}


// v2: Primary device selection — always prefer active device
$allActiveDevices = getAllActiveDevices();
$activeMacs = array_column($allActiveDevices, 'mac');
$primaryMac = $_COOKIE['hr_primary_mac'] ?? $_GET['device'] ?? null;

if (!$primaryMac || !in_array($primaryMac, getAllowedDeviceList())) {
    $primaryMac = null;
}

// If the device in Cookie/query is offline, switch to the active device
if ($primaryMac && !in_array($primaryMac, $activeMacs) && count($activeMacs) > 0) {
    $primaryMac = $activeMacs[0];
}

// If no selection, active device or default
if (!$primaryMac) {
    $primaryMac = $activeMacs[0] ?? getDefaultDevice();
}

$activeTab = $_GET['tab'] ?? 'live';
if (!in_array($activeTab, ['live', 'history', 'anomalies', 'episodes'])) $activeTab = 'live';
if ($activeTab === 'anomalies') $activeTab = 'anomalies'; // backward compat
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

// Sorting parameters
$sortBy = $_GET['sort'] ?? 'recorded_at';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');

// Security: only allowed columns
$allowedSorts = ['recorded_at', 'heart_rate'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'recorded_at';
if (!in_array($sortDir, ['ASC', 'DESC'])) $sortDir = 'DESC';

// Fetch latest data
function getLatestData(): ?array {
    try {
        $pdo = getDB();
        $sql = "SELECT device_id, heart_rate, rr_intervals, sensor_contact,
                       battery_level, recorded_at,
                       TIMESTAMPDIFF(SECOND, recorded_at, NOW()) as seconds_ago
                FROM heart_rate_logs
                WHERE device_id = ?
                ORDER BY recorded_at DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        global $primaryMac;
        $stmt->execute([$primaryMac]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Last X minutes data (for chart)
function getRecentData(int $minutes = 5): array {
    try {
        $pdo = getDB();
        $sql = "SELECT heart_rate, recorded_at
                FROM heart_rate_logs
                WHERE device_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                " . getArtifactFilter() . "
                ORDER BY recorded_at ASC";
        $stmt = $pdo->prepare($sql);
        global $primaryMac;
        $stmt->execute([$primaryMac, $minutes]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Statistics
function getStats(): array {
    try {
        $pdo = getDB();
        $sql = "SELECT
                    COUNT(*) as total_readings,
                    AVG(heart_rate) as avg_hr,
                    MIN(heart_rate) as min_hr,
                    MAX(heart_rate) as max_hr,
                    MIN(recorded_at) as first_reading,
                    MAX(recorded_at) as last_reading
                FROM heart_rate_logs
                WHERE device_id = ? AND recorded_at >= CURDATE() AND recorded_at < CURDATE() + INTERVAL 1 DAY
                " . getArtifactFilter();
        $stmt = $pdo->prepare($sql);
        global $primaryMac;
        $stmt->execute([$primaryMac]);
        return $stmt->fetch() ?: [];
    } catch (Exception $e) {
        return [];
    }
}

// History data (with pagination and sorting)
function getHistoryData(int $page, int $perPage, string $sortBy, string $sortDir): array {
    try {
        $pdo = getDB();
        $offset = ($page - 1) * $perPage;

        // Approximate total rows (TABLE_ROWS - instead of instant COUNT(*))
        $countSql = "SELECT TABLE_ROWS FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'heart_rate_logs'";
        $total = (int)$pdo->query($countSql)->fetchColumn();

        // Data (with sorting)
        $sql = "SELECT id, heart_rate, rr_intervals, sensor_contact, battery_level, recorded_at
                FROM heart_rate_logs
                WHERE device_id = ?
                " . getArtifactFilter() . "
                ORDER BY {$sortBy} {$sortDir}
                LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        global $primaryMac;
        $stmt->execute([$primaryMac, $perPage, $offset]);
        $data = $stmt->fetchAll();

        return [
            'data' => $data,
            'total' => $total,
            'pages' => ceil($total / $perPage),
            'current' => $page
        ];
    } catch (Exception $e) {
        return ['data' => [], 'total' => 0, 'pages' => 0, 'current' => 1];
    }
}

// Hourly summary
function getHourlySummary(): array {
    try {
        $pdo = getDB();
        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $sql = "SELECT
                    DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00') as hour,
                    COUNT(*) as readings,
                    ROUND(AVG(heart_rate)) as avg_hr,
                    MIN(heart_rate) as min_hr,
                    MAX(heart_rate) as max_hr
                FROM heart_rate_logs
                WHERE device_id = ? AND recorded_at >= ?
                " . getArtifactFilter() . "
                GROUP BY hour
                ORDER BY hour DESC";
        $stmt = $pdo->prepare($sql);
        global $primaryMac;
        $stmt->execute([$primaryMac, $since]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Always fetch latest data for Header
$latest = getLatestData();

// Fetch data only for active tab
$recentData = [];
$stats = [];
$history = ['data' => [], 'total' => 0, 'pages' => 0, 'current' => 1];
$hourlySummary = [];

if ($activeTab === 'live') {
    $recentData = getRecentData(5);
    $stats = getStats();
} elseif ($activeTab === 'history') {
    $history = getHistoryData($page, $perPage, $sortBy, $sortDir);
    $hourlySummary = getHourlySummary();
}

// Sort direction toggle function (returns to page 1 on sort change)
function getSortUrl($column, $currentSort, $currentDir) {
    $newDir = ($currentSort === $column && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    return "?tab=history&sort={$column}&dir={$newDir}&page=1";
}

function getSortIcon($column, $currentSort, $currentDir) {
    if ($currentSort !== $column) return '↕';
    return $currentDir === 'DESC' ? '↓' : '↑';
}

// Chart data
$chartLabels = [];
$chartData = [];
foreach ($recentData as $row) {
    $chartLabels[] = date('H:i:s', strtotime($row['recorded_at']));
    $chartData[] = (int)$row['heart_rate'];
}

// Hourly Chart data
$hourlyLabels = [];
$hourlyData = [];
foreach (array_reverse($hourlySummary) as $row) {
    $hourlyLabels[] = date('H:i', strtotime($row['hour']));
    $hourlyData[] = (int)$row['avg_hr'];
}
?>
<!DOCTYPE html>
<html lang="en"><!-- lang will be updated by JS -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Auto refresh is now handled by JavaScript -->
    <title>Heart Rate <?= $activeTab === 'live' ? 'Live' : 'History' ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- noUiSlider for Range Selection -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/nouislider@15.7.1/dist/nouislider.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/nouislider@15.7.1/dist/nouislider.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            color: #1f2937;
            padding: 20px;
            padding-bottom: 100px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .header h1 {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #4b5563;
            margin: 0;
        }
        .header h1 .h1-accent {
            color: #ef4444;
            font-weight: 800;
        }

        /* Tab Navigation */
        .tabs {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #4b5563;
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
        }

        .tab-btn:hover {
            background: #e5e7eb;
            color: #1f2937;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #1f2937;
            border-color: transparent;
        }

        .status-container {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .status-online {
            background: rgba(46, 213, 115, 0.15);
            color: #2ed573;
            border: 1px solid rgba(46, 213, 115, 0.3);
        }

        .status-offline {
            background: rgba(255, 71, 87, 0.15);
            color: #ff4757;
            border: 1px solid rgba(255, 71, 87, 0.3);
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .status-online .status-dot { background: #2ed573; animation: pulse 2s infinite; }
        .status-offline .status-dot { background: #ff4757; }

        .device-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 6px;
            letter-spacing: 0.3px;
        }
        .device-badge.device-pvs {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .device-badge.device-wahoo {
            background: rgba(251, 146, 60, 0.2);
            color: #fb923c;
            border: 1px solid rgba(251, 146, 60, 0.3);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }

        /* HR Card */
        .hr-card {
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-radius: 24px;
            padding: 30px 36px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hr-card-left {
            flex: 1;
            text-align: center;
            min-width: 0;
        }
        .hr-card-right {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
            text-align: right;
        }

        .heart-icon {
            font-size: 40px;
            animation: heartbeat 1s ease-in-out infinite;
            display: inline-block;
        }

        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            15% { transform: scale(1.15); }
            30% { transform: scale(1); }
            45% { transform: scale(1.1); }
        }

        .hr-value {
            font-size: 96px;
            font-weight: 700;
            line-height: 1;
            margin: 8px 0 4px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hr-unit {
            font-size: 20px;
            opacity: 0.7;
            font-weight: 300;
        }

        .hr-status {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin-top: 10px;
        }

        @media (max-width: 560px) {
            .hr-card {
                flex-direction: column;
                text-align: center;
                padding: 28px 20px;
            }
            .hr-card-right {
                align-items: center;
                text-align: center;
                width: 100%;
            }
            .hr-value { font-size: 72px; }
        }

        .hr-normal { background: rgba(46, 213, 115, 0.2); color: #2ed573; }
        .hr-low { background: rgba(52, 152, 219, 0.2); color: #3498db; }
        .hr-high { background: rgba(255, 165, 2, 0.2); color: #ffa502; }
        .hr-offline { background: #f3f4f6; color: #6b7280; }

        /* Rhythm Status - Right Side of HR Card */
        .rhythm-status { margin: 0; }
        .rhythm-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-radius: 16px;
            font-size: 0.88em;
            animation: arrFadeIn 0.4s ease;
        }
        .rhythm-badge svg { width: 22px; height: 22px; flex-shrink: 0; }
        .rhythm-badge .rhythm-label { font-weight: 600; }
        .rhythm-badge .rhythm-sub { font-size: 0.8em; opacity: 0.7; }
        .rhythm-badge.rhythm-normal {
            background: rgba(46,204,113,0.15);
            border: 1px solid rgba(46,204,113,0.35);
            color: #2ecc71;
        }
        .rhythm-badge.rhythm-critical {
            background: rgba(231,76,60,0.2);
            border: 1px solid rgba(231,76,60,0.5);
            color: #e74c3c;
            animation: arrFadeIn 0.4s ease, arrPulse 2s ease infinite;
        }
        .rhythm-badge.rhythm-warning {
            background: rgba(243,156,18,0.2);
            border: 1px solid rgba(243,156,18,0.5);
            color: #f39c12;
        }
        .rhythm-badge.rhythm-info {
            background: rgba(52,152,219,0.15);
            border: 1px solid rgba(52,152,219,0.4);
            color: #3498db;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        @media (min-width: 600px) {
            .info-grid { grid-template-columns: repeat(4, 1fr); }
        }

        .info-card {
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            backdrop-filter: blur(10px);
            border: 1px solid #e5e7eb;
        }

        .info-card .icon { font-size: 24px; margin-bottom: 8px; }
        .info-card .value { font-size: 24px; font-weight: 600; margin-bottom: 4px; }
        .info-card .label { font-size: 12px; opacity: 0.6; text-transform: uppercase; letter-spacing: 1px; }

        /* Cards */
        .card {
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid #e5e7eb;
        }

        .card h3 {
            font-size: 14px;
            opacity: 0.7;
            margin-bottom: 15px;
            font-weight: 400;
        }

        /* RR Intervals */
        .rr-values {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rr-chip {
            background: rgba(155, 89, 182, 0.2);
            color: #9b59b6;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        /* Chart */
        .chart-container {
            position: relative;
            height: 200px;
        }

        @media (min-width: 600px) {
            .chart-container { height: 250px; }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .stat-item { text-align: center; }
        .stat-item .value { font-size: 28px; font-weight: 600; }
        .stat-item .label { font-size: 11px; opacity: 0.5; text-transform: uppercase; letter-spacing: 1px; }
        .stat-min .value { color: #3498db; }
        .stat-avg .value { color: #2ed573; }
        .stat-max .value { color: #ef4444; }

        /* History Table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th,
        .history-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #f3f4f6;
        }

        .history-table th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.5;
            font-weight: 500;
        }

        .history-table td {
            font-size: 14px;
        }

        .history-table tr:hover {
            background: #f9fafb;
        }

        .hr-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
        }

        .hr-badge-normal { background: rgba(46, 213, 115, 0.2); color: #2ed573; }
        .hr-badge-low { background: rgba(52, 152, 219, 0.2); color: #3498db; }
        .hr-badge-high { background: rgba(255, 165, 2, 0.2); color: #ffa502; }

        /* Sort Links */
        .sort-link {
            color: #6b7280;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .sort-link:hover {
            color: #1f2937;
            background: #e5e7eb;
        }

        .sort-link.active {
            color: #ef4444;
        }

        /* DateTime Cell */
        .datetime-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .datetime-cell .date {
            font-weight: 500;
        }

        .datetime-cell .time {
            font-size: 12px;
            opacity: 0.6;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }

        .pagination a {
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            color: #374151;
            border: 1px solid #e5e7eb;
        }

        .pagination a:hover {
            background: #e5e7eb;
            color: #1f2937;
        }

        .pagination .active {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #1f2937;
            border: none;
        }

        .pagination .disabled {
            opacity: 0.3;
            pointer-events: none;
        }

        /* Hourly Summary */
        .hourly-table {
            width: 100%;
            border-collapse: collapse;
        }

        .hourly-table th,
        .hourly-table td {
            padding: 10px 8px;
            text-align: center;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }

        .hourly-table th {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.5;
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            opacity: 0.5;
        }

        .no-data .icon { font-size: 60px; margin-bottom: 20px; }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            opacity: 0.4;
        }

        .last-update {
            font-size: 11px;
            opacity: 0.5;
            margin-top: 6px;
        }

        /* Chart Header & Time Selector */
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .chart-header h3 {
            margin: 0;
        }

        .time-selector {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .time-btn {
            padding: 8px 14px;
            border: 1px solid #d1d5db;
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            color: #374151;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .time-btn:hover {
            background: #e5e7eb;
            color: #1f2937;
        }

        .time-btn.active {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #1f2937;
            border-color: transparent;
        }

        .pause-btn {
            font-size: 14px;
        }

        .pause-btn.paused {
            background: linear-gradient(135deg, #ffa502, #ff7f50);
            color: #1f2937;
            border-color: transparent;
            animation: pulse-pause 1.5s infinite;
        }

        @keyframes pulse-pause {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Time Indicator (Swipe) */
        .time-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 10px 15px;
            background: linear-gradient(135deg, rgba(255, 165, 2, 0.2), rgba(255, 127, 80, 0.2));
            border: 1px solid rgba(255, 165, 2, 0.3);
            border-radius: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .time-indicator .time-text {
            font-size: 14px;
            font-weight: 600;
            color: #ffa502;
        }

        .go-live-btn {
            padding: 6px 14px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #1f2937;
            border: none;
            border-radius: 15px;
            font-size: 12px;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .go-live-btn:hover {
            transform: scale(1.05);
        }

        .swipe-hint {
            font-size: 11px;
            opacity: 0.5;
            margin-left: auto;
        }

        /* Chart card cursor for swipe */
        .card:has(#hrChart) {
            cursor: grab;
            user-select: none;
            touch-action: pan-y;
        }

        .card:has(#hrChart):active {
            cursor: grabbing;
        }

        /* Custom Time Picker */
        .custom-time-picker {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .time-picker-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .time-picker-row label {
            font-size: 13px;
            opacity: 0.8;
        }

        .time-picker-row select,
        .time-picker-row input[type="time"] {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #e5e7eb;
            color: #1f2937;
            font-size: 13px;
        }

        .time-picker-row input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }

        .time-range-preview {
            font-size: 12px;
            opacity: 0.6;
            padding: 6px 12px;
            background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-radius: 6px;
        }

        .apply-btn {
            padding: 8px 16px;
            background: linear-gradient(135deg, #2ed573, #1abc9c);
            color: #1f2937;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .apply-btn:hover {
            transform: scale(1.05);
        }

        /* Chart Stats */
        .chart-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            padding: 10px 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            flex-wrap: wrap;
        }

        .chart-stat {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .chart-stat .label {
            font-size: 11px;
            opacity: 0.6;
            text-transform: uppercase;
        }

        .chart-stat .value {
            font-size: 14px;
            font-weight: 600;
        }

        .chart-stat:nth-child(1) .value { color: #3498db; }
        .chart-stat:nth-child(2) .value { color: #2ed573; }
        .chart-stat:nth-child(3) .value { color: #ef4444; }
        .chart-stat:nth-child(4) .value { color: #9b59b6; }

        /* Minute Stats */
        .minute-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 12px;
            padding: 8px 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            flex-wrap: wrap;
        }

        /* EKG Container */
        .ekg-container {
            background: rgba(0, 50, 0, 0.3);
            border-radius: 8px;
            padding: 10px;
        }

        /* Range Slider Container */
        .range-slider-container {
            margin: 20px 0 10px;
            padding: 18px 24px 14px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(167, 139, 250, 0.1));
            border: 1px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .range-slider-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .range-slider-header h4 {
            font-size: 13px;
            opacity: 0.8;
            margin: 0;
        }

        .range-slider-times {
            display: flex;
            gap: 15px;
            font-size: 12px;
        }

        .range-slider-times span {
            padding: 4px 10px;
            background: #e5e7eb;
            border-radius: 6px;
        }

        .range-slider-times .start-time { color: #667eea; }
        .range-slider-times .end-time { color: #a78bfa; }

        /* noUiSlider Modern Style - Full Override */
        .range-slider {
            height: 6px !important;
            margin: 20px 15px 25px !important;
        }

        .noUi-target {
            background: #f3f4f6 !important;
            border: none !important;
            border-radius: 3px !important;
            box-shadow: none !important;
            height: 6px !important;
        }

        .noUi-base, .noUi-connects {
            height: 6px !important;
        }

        .noUi-connect {
            background: linear-gradient(90deg, #667eea, #a78bfa) !important;
            border-radius: 3px !important;
        }

        /* Handle - fully round, centered */
        .noUi-horizontal .noUi-handle {
            width: 16px !important;
            height: 16px !important;
            border-radius: 50% !important;
            background: #fff !important;
            border: none !important;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3) !important;
            cursor: grab !important;
            top: -5px !important;
            right: -8px !important;
        }

        .noUi-handle:before,
        .noUi-handle:after {
            display: none !important;
        }

        .noUi-handle:hover {
            transform: scale(1.2);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.5) !important;
        }

        .noUi-handle:active {
            cursor: grabbing !important;
        }

        .noUi-handle:focus {
            outline: none !important;
        }

        /* Touch area */
        .noUi-touch-area {
            height: 100% !important;
            width: 100% !important;
        }

        /* Slider Duration Display */
        .slider-duration {
            text-align: center;
            font-size: 12px;
            color: #a78bfa;
            margin-top: 8px;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 500px) {
            .history-table { font-size: 12px; }
            .history-table th, .history-table td { padding: 8px 4px; }
        }

        /* Language Switcher */
        .lang-switch {
            display: flex;
            gap: 3px;
        }
        .lang-btn {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            color: #9ca3af;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.5px;
        }
        .lang-btn:hover {
            background: #e5e7eb;
            color: #1f2937;
        }
        .lang-btn.active {
            background: #d1d5db;
            color: #1f2937;
            border-color: #9ca3af;
        }
        /* === Anomaliler Tab === */
        .ep-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        .ep-stat {
            text-align: center;
            padding: 12px 8px;
            background: #f9fafb;
            border-radius: 10px;
        }
        .ep-stat .value {
            font-size: 22px;
            font-weight: 600;
            color: #3498db;
        }
        .ep-stat .label {
            font-size: 10px;
            opacity: 0.5;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 4px;
        }
        #episodesBody tr {
            cursor: pointer;
            transition: background 0.2s;
        }
        #episodesBody tr:hover {
            background: rgba(52, 152, 219, 0.15) !important;
        }
        #episodesBody tr.ep-selected {
            background: rgba(52, 152, 219, 0.25) !important;
        }
        .ep-status-active {
            color: #ef4444;
            font-weight: 600;
            animation: arrPulse 2s infinite;
        }
        .ep-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
        }
        .ep-badge-low { background: rgba(52, 152, 219, 0.2); color: #3498db; }
        .ep-badge-recovery { background: rgba(46, 213, 115, 0.2); color: #2ed573; }
        .ep-no-data {
            text-align: center;
            padding: 40px 20px;
            opacity: 0.5;
        }
        .ep-no-data .icon { font-size: 48px; margin-bottom: 10px; }
        .ep-chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .ep-date-input {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            color: #1f2937;
            padding: 6px 10px;
            font-size: 13px;
            color-scheme: dark;
        }
        .ep-date-input:focus { border-color: #d1d5db; outline: none; }
        .ep-filters {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            margin-bottom: 8px;
            background: #f9fafb;
            border-radius: 8px;
            flex-wrap: wrap;
        }
        .ep-filters label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #6b7280;
        }
        .ep-filters input[type="number"] {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            color: #1f2937;
            padding: 3px 6px;
            font-size: 12px;
            -moz-appearance: textfield;
        }
        .ep-filters input[type="number"]::-webkit-outer-spin-button,
        .ep-filters input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; }
        .ep-filters input[type="number"]:focus {
            outline: none;
            border-color: #ef4444;
        }
        .ep-sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 18px !important;
        }
        .ep-sortable:hover { color: #ef4444; }
        .ep-sortable::after {
            content: '⇅';
            position: absolute;
            right: 2px;
            opacity: 0.3;
            font-size: 11px;
        }
        .ep-sortable.ep-sort-asc::after { content: '▲'; opacity: 0.8; }
        .ep-sortable.ep-sort-desc::after { content: '▼'; opacity: 0.8; }
        .ep-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            padding: 10px 0 4px;
        }
        .ep-nav-btn {
            min-width: 32px;
            padding: 4px 8px !important;
            font-size: 14px;
        }
        .ep-nav-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        @media (max-width: 600px) {
            .ep-summary { grid-template-columns: repeat(2, 1fr); }
        }

        /* === Aritmi === */
        @keyframes arrFadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes arrPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        /* === v2: Multi-device === */
        .device-selector {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .device-select-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 14px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0.45;
            border: 1px solid transparent;
            letter-spacing: 0.3px;
        }
        .device-select-btn:hover { opacity: 0.75; }
        .device-select-btn.active { opacity: 1; }
        .device-select-btn.dev-pvs {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border-color: rgba(59, 130, 246, 0.3);
        }
        .device-select-btn.dev-wahoo {
            background: rgba(251, 146, 60, 0.2);
            color: #fb923c;
            border-color: rgba(251, 146, 60, 0.3);
        }

        .secondary-device-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f9fafb;
            border-radius: 10px;
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #1f2937;
        }
        .secondary-device-badge:hover {
            background: #e5e7eb;
            border-color: #d1d5db;
        }
        .secondary-device-badge.offline { opacity: 0.4; }
        .secondary-device-badge .sec-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .secondary-device-badge .sec-badge.dev-pvs {
            background: rgba(59, 130, 246, 0.25);
            color: #60a5fa;
        }
        .secondary-device-badge .sec-badge.dev-wahoo {
            background: rgba(251, 146, 60, 0.25);
            color: #fb923c;
        }
        .secondary-device-badge .sec-hr {
            font-size: 18px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .secondary-device-badge .sec-meta {
            font-size: 11px;
            opacity: 0.5;
        }
        }
    </style>
    <script>
    // ===== i18n: Multi-language Support =====
    const translations = {
        hi: {
            // Header
            title: 'हार्ट रेट मॉनिटर',
            tab_live: 'लाइव',
            tab_history: 'इतिहास',
            status_live: 'लाइव कनेक्शन',
            status_offline_min: 'कोई कनेक्शन नहीं ({min} मिनट पहले)',
            status_waiting: 'डेटा की प्रतीक्षा',
            dev_connected: 'जुड़ा हुआ',
            dev_disconnected: 'संपर्क टूटा',
            dev_signal_loss: 'सिग्नल लॉस',
            dev_no_connection: 'कोई कनेक्शन नहीं',
            dev_last_data: 'अंतिम डेटा {time} पहले',
            dev_no_data: 'कोई डेटा नहीं',
            // HR Card
            bpm: 'BPM',
            low_hr: 'कम हार्ट रेट',
            high_hr: 'उच्च हार्ट रेट',
            normal_hr: 'सामान्य',
            last_update: '{time} पहले अपडेट किया गया',
            // Info grid
            battery: 'बैटरी',
            contact: 'संपर्क',
            today: 'आज',
            ago: 'पहले',
            yes: 'हाँ',
            no: 'नहीं',
            // Chart
            chart_title: 'हार्ट रेट और EKG ग्राफ',
            ekg_title: 'EKG ग्राफ (अंतिम 10s)',
            min_label: 'न्यूनतम:',
            avg_label: 'औसत:',
            max_label: 'अधिकतम:',
            records_label: 'रिकॉर्ड्स:',
            // Time buttons
            sec_10: '10s',
            sec_30: '30s',
            min_1: '1m',
            min_3: '3m',
            min_5: '5m',
            min_15: '15m',
            min_60: '60m',
            custom: 'कस्टम',
            pause_title: 'लाइव अपडेट रोकें',
            resume_title: 'लाइव अपडेट फिर से शुरू करें',
            // Custom time picker
            hours_ago_label: 'अवधि:',
            hour_1_ago: '1 घंटा',
            hour_2_ago: '2 घंटे',
            hour_3_ago: '3 घंटे',
            hour_4_ago: '4 घंटे',
            hour_6_ago: '6 घंटे',
            hour_12_ago: '12 घंटे',
            hour_24_ago: '24 घंटे',
            end_time_label: 'समाप्ति समय:',
            end_time_now: 'अभी',
            apply: 'लागू करें',
            time_range_preview: '{start} - {end} ({hours} घंटे)',
            // Time indicator
            go_live: '🔴 लाइव जाएँ',
            swipe_hint: '← अभी | अतीत →',
            // Range slider
            range_title: '🎚️ समय सीमा चयन',
            selected_duration: 'चयनित: {duration}',
            duration_sec: '{s} सेकंड',
            duration_min_sec: '{m} मिनट {s} सेकंड',
            duration_min: '{m} मिनट',
            duration_hour_min: '{h} घंटे {m} मिनट',
            duration_hour: '{h} घंटे',
            // Swipe time ago
            time_sec_ago: '{s}s पहले',
            time_min_sec_ago: '{m}m {s}s पहले',
            time_min_ago: '{m}m पहले',
            time_hour_min_ago: '{h}h {m}m पहले',
            time_hour_ago: '{h}h पहले',
            // Daily stats
            today_stats: 'आज के आँकड़े',
            // No data
            waiting_data: 'डेटा की प्रतीक्षा',
            waiting_data_desc: 'HR सेंसर कनेक्ट होने पर डेटा यहाँ दिखाई देगा',
            // History tab
            minute_avg: 'मिनट का औसत',
            h_1: '1h', h_4: '4h', h_8: '8h', h_12: '12h', h_24: '24h', h_72: '72h',
            minutes_label: 'मिनट:',
            last_24h: 'पिछले 24 घंटे (प्रति घंटा औसत)',
            hourly_summary: 'प्रति घंटा सारांश',
            th_hour: 'घंटा',
            th_readings: 'रीडिंग्स',
            all_records: 'सभी रिकॉर्ड्स ({count} रिकॉर्ड्स)',
            th_datetime: 'दिनांक/समय',
            th_heart_rate: 'हार्ट रेट',
            th_battery: 'बैटरी',
            no_records: 'अभी तक कोई रिकॉर्ड नहीं',
            // Chart dataset labels
            avg_bpm: 'औसत BPM',
            tooltip_avg: 'औसत',
            tooltip_min: 'न्यूनतम',
            tooltip_max: 'अधिकतम',
            r_peak: 'R-Peak',
            // Footer
            footer_note: 'लाइव मोड (10s) में चार्ट हर 5 सेकंड में अपडेट होते हैं',
            // RR
            rr_title: 'RR अंतराल (HRV)',
            // Anomalies tab
            tab_anomalies: 'हार्ट रेट विसंगतियाँ',
            anomalies_title: 'हार्ट रेट विसंगतियाँ',
            anomalies_desc: 'अवधियाँ जहाँ 5+ सेकंड के लिए HR < 50 BPM',
            ep_no_data: 'इस समय सीमा में कोई विसंगति नहीं मिली',
            ep_th_start: 'शुरुआत',
            ep_th_end: 'अंत',
            ep_th_duration: 'अवधि',
            ep_th_min_hr: 'न्यूनतम HR',
            ep_th_avg_hr: 'औसत HR',
            ep_th_recovery: 'वसूली',
            ep_active: '⏳ सक्रिय',
            ep_seconds: '{s}s',
            ep_min_sec: '{m}m {s}s',
            ep_summary_total: 'कुल विसंगतियाँ',
            ep_summary_duration: 'कुल अवधि',
            ep_summary_min: 'सबसे कम HR',
            ep_summary_longest: 'सबसे लंबी',
            ep_chart_title: 'विसंगति #{id}',
            ep_chart_close: 'बंद करें',
            ep_1w: '1 सप्ताह',
            ep_15d: '15 दिन',
            ep_1m: '1 महीना',
            ep_filter_dur: 'अवधि ≥',
            ep_filter_minhr: 'न्यूनतम HR ≤',
            ep_filter_avghr: 'औसत HR ≤',
            ep_filter_clear: 'साफ़ करें'
        },
        en: {
            title: 'Heart Rate Monitor',
            tab_live: 'Live',
            tab_history: 'History',
            status_live: 'Live Connection',
            status_offline_min: 'No Connection ({min} min ago)',
            status_waiting: 'Waiting for Data',
            dev_connected: 'Connected',
            dev_disconnected: 'Disconnected',
            dev_signal_loss: 'Signal Loss',
            dev_no_connection: 'No Connection',
            dev_last_data: 'Last data {time} ago',
            dev_no_data: 'No data',
            bpm: 'BPM',
            low_hr: 'Low Heart Rate',
            high_hr: 'High Heart Rate',
            normal_hr: 'Normal',
            last_update: 'Updated {time} ago',
            battery: 'Battery',
            contact: 'Contact',
            today: 'Today',
            ago: 'Ago',
            yes: 'Yes',
            no: 'No',
            chart_title: 'Heart Rate & EKG Chart',
            ekg_title: 'EKG Chart (last 10s)',
            min_label: 'Min:',
            avg_label: 'Avg:',
            max_label: 'Max:',
            records_label: 'Records:',
            sec_10: '10s',
            sec_30: '30s',
            min_1: '1m',
            min_3: '3m',
            min_5: '5m',
            min_15: '15m',
            min_60: '60m',
            custom: 'Custom',
            pause_title: 'Pause live updates',
            resume_title: 'Resume live updates',
            hours_ago_label: 'Duration:',
            hour_1_ago: '1 hour',
            hour_2_ago: '2 hours',
            hour_3_ago: '3 hours',
            hour_4_ago: '4 hours',
            hour_6_ago: '6 hours',
            hour_12_ago: '12 hours',
            hour_24_ago: '24 hours',
            end_time_label: 'End time:',
            end_time_now: 'Now',
            apply: 'Apply',
            time_range_preview: '{start} - {end} ({hours} hours)',
            go_live: '🔴 Go Live',
            swipe_hint: '← Now | Past →',
            range_title: '🎚️ Time Range Selection',
            selected_duration: 'Selected: {duration}',
            duration_sec: '{s} seconds',
            duration_min_sec: '{m} min {s} sec',
            duration_min: '{m} minutes',
            duration_hour_min: '{h} hr {m} min',
            duration_hour: '{h} hours',
            time_sec_ago: '{s}s ago',
            time_min_sec_ago: '{m}m {s}s ago',
            time_min_ago: '{m}m ago',
            time_hour_min_ago: '{h}h {m}m ago',
            time_hour_ago: '{h}h ago',
            today_stats: 'Today\'s Statistics',
            waiting_data: 'Waiting for Data',
            waiting_data_desc: 'Data will appear here when HR sensor connects',
            minute_avg: 'Minute Average',
            h_1: '1h', h_4: '4h', h_8: '8h', h_12: '12h', h_24: '24h', h_72: '72h',
            minutes_label: 'Minutes:',
            last_24h: 'Last 24 Hours (Hourly Average)',
            hourly_summary: 'Hourly Summary',
            th_hour: 'Hour',
            th_readings: 'Readings',
            all_records: 'All Records ({count} records)',
            th_datetime: 'Date/Time',
            th_heart_rate: 'Heart Rate',
            th_battery: 'Battery',
            no_records: 'No records yet',
            avg_bpm: 'Average BPM',
            tooltip_avg: 'Avg',
            tooltip_min: 'Min',
            tooltip_max: 'Max',
            r_peak: 'R-Peak',
            footer_note: 'In live mode (10s) charts update every 5 seconds',
            rr_title: 'RR Intervals (HRV)',
            // Anomalies tab
            tab_anomalies: 'Anomalies',
            anomalies_title: 'HR Anomalies',
            anomalies_desc: 'Periods where HR < 50 BPM for 5+ seconds',
            ep_no_data: 'No anomalies detected in this time range',
            ep_th_start: 'Start',
            ep_th_end: 'End',
            ep_th_duration: 'Duration',
            ep_th_min_hr: 'Min HR',
            ep_th_avg_hr: 'Avg HR',
            ep_th_recovery: 'Recovery',
            ep_active: '⏳ Active',
            ep_seconds: '{s}s',
            ep_min_sec: '{m}m {s}s',
            ep_summary_total: 'Total Anomalies',
            ep_summary_duration: 'Total Duration',
            ep_summary_min: 'Lowest HR',
            ep_summary_longest: 'Longest',
            ep_chart_title: 'Anomaly #{id}',
            ep_chart_close: 'Close',
            ep_1w: '1 Week',
            ep_15d: '15 Days',
            ep_1m: '1 Month',
            ep_filter_dur: 'Duration ≥',
            ep_filter_minhr: 'Min HR ≤',
            ep_filter_avghr: 'Avg HR ≤',
            ep_filter_clear: 'Clear'
        }
    };

    let currentLang = 'en';

    function getCookie(name) {
        const v = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return v ? v.pop() : null;
    }
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    function detectLanguage() {
        const cookie = getCookie('hr_lang');
        if (cookie && translations[cookie]) return cookie;
        const nav = (navigator.language || '').toLowerCase();
        return nav.startsWith('hi') ? 'hi' : 'en';
    }

    function t(key, params) {
        let str = (translations[currentLang] && translations[currentLang][key]) || translations['en'][key] || key;
        if (params) {
            Object.keys(params).forEach(function(k) {
                str = str.replace('{' + k + '}', params[k]);
            });
        }
        return str;
    }

    function applyLanguage(lang) {
        currentLang = lang;
        setCookie('hr_lang', lang, 365);
        document.documentElement.lang = lang === 'hi' ? 'hi' : 'en';

        // Update data-i18n elements
        document.querySelectorAll('[data-i18n]').forEach(function(el) {
            var key = el.getAttribute('data-i18n');
            var p = el.getAttribute('data-i18n-params');
            el.textContent = t(key, p ? JSON.parse(p) : null);
        });

        // Update data-i18n-title elements
        document.querySelectorAll('[data-i18n-title]').forEach(function(el) {
            el.title = t(el.getAttribute('data-i18n-title'));
        });

        // Update data-i18n-placeholder elements
        document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el) {
            el.placeholder = t(el.getAttribute('data-i18n-placeholder'));
        });

        // Update lang buttons
        document.querySelectorAll('.lang-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-lang') === lang);
        });

        // Update dynamic content
        if (typeof updateDynamicTexts === 'function') updateDynamicTexts();
    }

    // Initialize language on page load (before DOM renders text)
    currentLang = detectLanguage();
    </script>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-top">

<div style="margin-top: 10px; display: flex; justify-content: center; gap: 10px;">
    <a href="?lang=en" style="color: <?= $lang == 'en' ? '#fff' : '#6b7280' ?>; text-decoration: none;">EN</a> | 
    <a href="?lang=hi" style="color: <?= $lang == 'hi' ? '#fff' : '#6b7280' ?>; text-decoration: none;">हिन्दी</a>
</div>

                <h1><span class="h1-accent">&#9829;</span> Heart Rate Monitor</h1>
                <div class="lang-switch">
                    <button class="lang-btn" data-lang="hi" onclick="applyLanguage('hi')">HI</button>
                    <button class="lang-btn" data-lang="en" onclick="applyLanguage('en')">EN</button>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tabs">
                <a href="?tab=live" class="tab-btn <?= $activeTab === 'live' ? 'active' : '' ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h4l3-9 5 18 3-9h5"/></svg> <span data-i18n="tab_live">Live</span></a>
                <a href="?tab=history" class="tab-btn <?= $activeTab === 'history' ? 'active' : '' ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg> <span data-i18n="tab_history">History</span></a>
                <a href="?tab=anomalies" class="tab-btn <?= $activeTab === 'anomalies' ? 'active' : '' ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> <span data-i18n="tab_anomalies">HR Anomalies</span></a>
            </div>

            <div class="status-container" id="statusContainer">
                <?php
                // Find active status for each device
                $activeMap = [];
                foreach ($allActiveDevices as $ad) {
                    $activeMap[$ad['mac']] = $ad;
                }
                foreach (getAllowedDeviceList() as $mac):
                    $dShort = getDeviceShortName($mac);
                    $isDevOnline = isset($activeMap[$mac]) && $activeMap[$mac]['seconds_ago'] < 10;
                ?>
                <div class="status-badge <?= $isDevOnline ? 'status-online' : 'status-offline' ?>"
                     id="statusBadge_<?= str_replace(':', '', $mac) ?>" data-mac="<?= $mac ?>">
                    <span class="status-dot"></span>
                    <span class="status-label"><?= $dShort ?></span>
                    <span class="status-text"><?= $isDevOnline ? 'Connected' : 'Disconnected' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- v2: Device Selector -->
            <div class="device-selector" id="deviceSelector">
                <?php foreach (getAllowedDeviceList() as $mac):
                    $dType = getDeviceType($mac);
                    $dShort = getDeviceShortName($mac);
                    $isActive = ($mac === $primaryMac);
                ?>
                <span class="device-select-btn dev-<?= $dType ?> <?= $isActive ? 'active' : '' ?>"
                      data-mac="<?= $mac ?>" data-type="<?= $dType ?>"
                      onclick="window._setPrimary('<?= $mac ?>')">
                    <?= $dType === 'pvs' ? '🔵' : '🟠' ?> <?= $dShort ?> <?= $isActive ? '●' : '○' ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($activeTab === 'live'): ?>
            <!-- ========== LIVE TAB ========== -->
            <?php if ($latest): ?>
                <!-- Main HR Card (2 columns) -->
                <div class="hr-card">
                    <div class="hr-card-left">
                        <div class="heart-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></div>
                        <div class="hr-value"><?= (int)$latest['heart_rate'] ?></div>
                        <div class="hr-unit" data-i18n="bpm">BPM</div>
                        <?php
                        $hr = (int)$latest['heart_rate'];
                        if ($hr < 50) { $statusClass = 'hr-low'; $statusKey = 'low_hr'; $statusText = 'Low Heart Rate'; }
                        elseif ($hr > 120) { $statusClass = 'hr-high'; $statusKey = 'high_hr'; $statusText = 'High Heart Rate'; }
                        else { $statusClass = 'hr-normal'; $statusKey = 'normal_hr'; $statusText = 'Normal'; }
                        ?>
                        <div class="hr-status <?= $statusClass ?>" data-i18n="<?= $statusKey ?>"><?= $statusText ?></div>
                    </div>
                    <div class="hr-card-right">
                        <div id="rhythmStatus" class="rhythm-status"></div>
                        <div id="secondaryBadge"></div>
                        <div class="last-update"><script>document.write(t('last_update', {time: '<?= $latest['seconds_ago'] ?>s'}))</script></div>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="info-grid">
                    <div class="info-card">
                        <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="10" x="2" y="7" rx="2" ry="2"/><line x1="22" x2="22" y1="11" y2="13"/></svg></div>
                        <div class="value"><?= $latest['battery_level'] ? $latest['battery_level'] . '%' : '--' ?></div>
                        <div class="label" data-i18n="battery">Pil</div>
                    </div>
                    <div class="info-card">
                        <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h4l3-9 5 18 3-9h5"/></svg></div>
                        <div class="value"><script>document.write(<?= $latest['sensor_contact'] ? 'true' : 'false' ?> ? t('yes') : t('no'))</script></div>
                        <div class="label" data-i18n="contact">Contact</div>
                    </div>
                    <div class="info-card">
                        <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg></div>
                        <div class="value"><?= number_format($stats['total_readings'] ?? 0) ?></div>
                        <div class="label" data-i18n="today">Today</div>
                    </div>
                    <div class="info-card">
                        <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                        <div class="value"><?= $latest['seconds_ago'] < 60 ? $latest['seconds_ago'] . 's' : floor($latest['seconds_ago']/60) . 'm' ?></div>
                        <div class="label" data-i18n="ago">Ago</div>
                    </div>
                </div>

                <!-- v2: Secondary Device Badge moved inside hr-card -->

                <!-- RR Intervals -->
                <?php if ($latest['rr_intervals']): ?>
                    <?php $rrIntervals = json_decode($latest['rr_intervals'], true); ?>
                    <?php if (!empty($rrIntervals)): ?>
                        <div class="card">
                            <h3 data-i18n="rr_title">RR Intervals (HRV)</h3>
                            <div class="rr-values">
                                <?php foreach ($rrIntervals as $rr): ?>
                                    <span class="rr-chip"><?= $rr ?> ms</span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Arrhythmia Timeline (outside HR card, History only) -->
                <div id="arrhythmiaTimeline" style="display:none;"></div>

                <!-- Time Picker + Charts -->
                <div class="card">
                    <div class="chart-header">
                        <h3 data-i18n="chart_title">Heart Rate &amp; EKG Chart</h3>
                        <div class="time-selector">
                            <button class="time-btn active" data-seconds="10" data-i18n="sec_10">10sn</button>
                            <button class="time-btn" data-seconds="30" data-i18n="sec_30">30sn</button>
                            <button class="time-btn" data-minutes="1" data-i18n="min_1">1dk</button>
                            <button class="time-btn" data-minutes="3" data-i18n="min_3">3dk</button>
                            <button class="time-btn" data-minutes="5" data-i18n="min_5">5dk</button>
                            <button class="time-btn" data-minutes="15" data-i18n="min_15">15dk</button>
                            <button class="time-btn" data-minutes="60" data-i18n="min_60">60dk</button>
                            <button class="time-btn" data-minutes="custom" id="customTimeBtn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg> <span data-i18n="custom">Custom</span></button>
                            <button class="time-btn pause-btn" id="pauseBtn" data-i18n-title="pause_title" title="Pause live updates"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg></button>
                        </div>
                    </div>

                    <!-- Custom Time Picker (hidden) -->
                    <div id="customTimePicker" class="custom-time-picker" style="display: none;">
                        <div class="time-picker-row">
                            <label data-i18n="hours_ago_label">Duration:</label>
                            <select id="hoursAgo">
                                <option value="1" data-i18n="hour_1_ago">1 saat</option>
                                <option value="2" data-i18n="hour_2_ago">2 saat</option>
                                <option value="3" data-i18n="hour_3_ago">3 saat</option>
                                <option value="4" data-i18n="hour_4_ago">4 saat</option>
                                <option value="6" data-i18n="hour_6_ago">6 saat</option>
                                <option value="12" data-i18n="hour_12_ago">12 saat</option>
                                <option value="24" data-i18n="hour_24_ago">24 saat</option>
                            </select>
                            <label data-i18n="end_time_label">End time:</label>
                            <input type="time" id="endTimeInput" placeholder="--:--">
                            <span class="time-range-preview" id="timeRangePreview"></span>
                            <button class="apply-btn" id="applyCustomTime" data-i18n="apply">Uygula</button>
                        </div>
                    </div>

                    <!-- Time Indicator (for scrolling) -->
                    <div id="timeIndicator" class="time-indicator" style="display: none;">
                        <span class="time-text"></span>
                        <button id="goLiveBtn" class="go-live-btn" data-i18n="go_live">🔴 Go Live</button>
                        <span class="swipe-hint" data-i18n="swipe_hint">← Now | Past →</span>
                    </div>

                    <!-- Grafik Statisticsi -->
                    <div class="chart-stats" id="chartStats">
                        <div class="chart-stat">
                            <span class="label" data-i18n="min_label">Min:</span>
                            <span class="value" id="statMin">--</span>
                        </div>
                        <div class="chart-stat">
                            <span class="label" data-i18n="avg_label">Ort:</span>
                            <span class="value" id="statAvg">--</span>
                        </div>
                        <div class="chart-stat">
                            <span class="label" data-i18n="max_label">Max:</span>
                            <span class="value" id="statMax">--</span>
                        </div>
                        <div class="chart-stat">
                            <span class="label" data-i18n="records_label">Records:</span>
                            <span class="value" id="statCount">--</span>
                        </div>
                    </div>

                    <!-- Heart Rate Chart -->
                    <div class="chart-container" style="height: 200px;">
                        <canvas id="hrChart"></canvas>
                    </div>

                    <!-- EKG Chart -->
                    <h4 style="margin: 20px 0 10px; font-size: 13px; opacity: 0.7;" data-i18n="ekg_title">EKG Chart (last 10s)</h4>
                    <div class="chart-container ekg-container" style="height: 120px;">
                        <canvas id="ekgChart"></canvas>
                    </div>

                    <!-- Range Slider (for large time ranges) -->
                    <div id="rangeSliderContainer" class="range-slider-container" style="display: none;">
                        <div class="range-slider-header">
                            <h4 data-i18n="range_title">🎚️ Time Range Selection</h4>
                            <div class="range-slider-times">
                                <span class="start-time" id="sliderStartTime">--:--</span>
                                <span>→</span>
                                <span class="end-time" id="sliderEndTime">--:--</span>
                            </div>
                        </div>
                        <div id="rangeSlider" class="range-slider"></div>
                        <div class="slider-duration" id="sliderDuration"></div>
                    </div>
                </div>

                <!-- Daily Statistics -->
                <?php if (!empty($stats) && $stats['total_readings'] > 0): ?>
                    <div class="card">
                        <h3 data-i18n="today_stats">Today's Statistics</h3>
                        <div class="stats-grid">
                            <div class="stat-item stat-min">
                                <div class="value" id="todayStatMin"><?= (int)$stats['min_hr'] ?></div>
                                <div class="label">Min</div>
                            </div>
                            <div class="stat-item stat-avg">
                                <div class="value" id="todayStatAvg"><?= round($stats['avg_hr']) ?></div>
                                <div class="label"><script>document.write(t('avg_label').replace(':',''))</script></div>
                            </div>
                            <div class="stat-item stat-max">
                                <div class="value" id="todayStatMax"><?= (int)$stats['max_hr'] ?></div>
                                <div class="label">Max</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-data">
                    <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M12 5v14"/></svg></div>
                    <h2 data-i18n="waiting_data">Dileepadari Heart Rate Monitoring</h2>
                    <p data-i18n="waiting_data_desc">
                        Dileepadari Heart Rate Monitoring is a real-time heart rate monitoring system that uses BLE (Bluetooth Low Energy) sensors to monitor heart rate in real-time. It uses PHP and MySQL to store and display heart rate data. It also uses Chart.js to display heart rate data in real-time. 
                    </p>
                </div>
            <?php endif; ?>

        <?php elseif ($activeTab === 'history'): ?>
            <!-- ========== HISTORY TAB ========== -->

            <!-- Per-Minute Average Chart -->
            <div class="card">
                <div class="chart-header">
                    <h3 data-i18n="minute_avg">Minute Average</h3>
                    <div class="time-selector">
                        <button class="time-btn minute-range-btn active" data-hours="1" data-i18n="h_1">1sa</button>
                        <button class="time-btn minute-range-btn" data-hours="4" data-i18n="h_4">4sa</button>
                        <button class="time-btn minute-range-btn" data-hours="8" data-i18n="h_8">8sa</button>
                        <button class="time-btn minute-range-btn" data-hours="12" data-i18n="h_12">12sa</button>
                        <button class="time-btn minute-range-btn" data-hours="24" data-i18n="h_24">24sa</button>
                        <button class="time-btn minute-range-btn" data-hours="72" data-i18n="h_72">72sa</button>
                    </div>
                </div>
                <div class="minute-stats" id="minuteStats">
                    <div class="chart-stat">
                        <span class="label" data-i18n="min_label">Min:</span>
                        <span class="value" id="minuteStatMin" style="color: #3498db;">--</span>
                    </div>
                    <div class="chart-stat">
                        <span class="label" data-i18n="avg_label">Ort:</span>
                        <span class="value" id="minuteStatAvg" style="color: #667eea;">--</span>
                    </div>
                    <div class="chart-stat">
                        <span class="label" data-i18n="max_label">Max:</span>
                        <span class="value" id="minuteStatMax" style="color: #ff6b6b;">--</span>
                    </div>
                    <div class="chart-stat">
                        <span class="label" data-i18n="minutes_label">Minute:</span>
                        <span class="value" id="minuteStatCount" style="color: #a78bfa;">--</span>
                    </div>
                </div>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="minuteChart"></canvas>
                </div>
            </div>

            <!-- Last 24 Hours Chart (Hourly) -->
            <?php if (!empty($hourlyData)): ?>
                <div class="card">
                    <h3 data-i18n="last_24h">Last 24 Hours (Saatlik Average)</h3>
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Hourly summary -->
            <?php if (!empty($hourlySummary)): ?>
                <div class="card">
                    <h3 data-i18n="hourly_summary">Hourly summary</h3>
                    <div style="overflow-x: auto;">
                        <table class="hourly-table">
                            <thead>
                                <tr>
                                    <th data-i18n="th_hour">Saat</th>
                                    <th data-i18n="th_readings">Okuma</th>
                                    <th>Min</th>
                                    <th><script>document.write(t('avg_label').replace(':',''))</script></th>
                                    <th>Max</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hourlySummary as $row): ?>
                                    <tr>
                                        <td><?= date('d.m H:i', strtotime($row['hour'])) ?></td>
                                        <td><?= $row['readings'] ?></td>
                                        <td style="color: #3498db;"><?= $row['min_hr'] ?></td>
                                        <td style="color: #2ed573;"><?= $row['avg_hr'] ?></td>
                                        <td style="color: #ff6b6b;"><?= $row['max_hr'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- All Records -->
            <div class="card">
                <h3 id="allRecordsTitle"><script>document.write(t('all_records', {count: '<?= number_format($history['total']) ?>'}))</script></h3>

                <?php if (!empty($history['data'])): ?>
                    <div style="overflow-x: auto;">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>
                                        <a href="<?= getSortUrl('recorded_at', $sortBy, $sortDir) ?>" class="sort-link <?= $sortBy === 'recorded_at' ? 'active' : '' ?>">
                                            <span data-i18n="th_datetime">Date/Saat</span> <?= getSortIcon('recorded_at', $sortBy, $sortDir) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="<?= getSortUrl('heart_rate', $sortBy, $sortDir) ?>" class="sort-link <?= $sortBy === 'heart_rate' ? 'active' : '' ?>">
                                            <span data-i18n="th_heart_rate">Heart Rate</span> <?= getSortIcon('heart_rate', $sortBy, $sortDir) ?>
                                        </a>
                                    </th>
                                    <th data-i18n="th_battery">Pil</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history['data'] as $row): ?>
                                    <?php
                                    $hr = (int)$row['heart_rate'];
                                    if ($hr < 50) $badgeClass = 'hr-badge-low';
                                    elseif ($hr > 120) $badgeClass = 'hr-badge-high';
                                    else $badgeClass = 'hr-badge-normal';

                                    ?>
                                    <tr>
                                        <td>
                                            <div class="datetime-cell">
                                                <span class="date"><?= date('d.m.Y', strtotime($row['recorded_at'])) ?></span>
                                                <?php
                                                // Show including milliseconds
                                                $dt = new DateTime($row['recorded_at']);
                                                $ms = $dt->format('v'); // milisaniye
                                                ?>
                                                <span class="time"><?= $dt->format('H:i:s') ?>.<?= $ms ?></span>
                                            </div>
                                        </td>
                                        <td><span class="hr-badge <?= $badgeClass ?>"><?= $hr ?></span></td>
                                        <td><?= $row['battery_level'] ? $row['battery_level'] . '%' : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($history['pages'] > 1): ?>
                        <?php $sortParams = "&sort={$sortBy}&dir={$sortDir}"; ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?tab=history&page=1<?= $sortParams ?>">««</a>
                                <a href="?tab=history&page=<?= $page - 1 ?><?= $sortParams ?>">«</a>
                            <?php else: ?>
                                <span class="disabled">««</span>
                                <span class="disabled">«</span>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($history['pages'], $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="active"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?tab=history&page=<?= $i ?><?= $sortParams ?>"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $history['pages']): ?>
                                <a href="?tab=history&page=<?= $page + 1 ?><?= $sortParams ?>">»</a>
                                <a href="?tab=history&page=<?= $history['pages'] ?><?= $sortParams ?>">»»</a>
                            <?php else: ?>
                                <span class="disabled">»</span>
                                <span class="disabled">»»</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="no-data">
                        <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
                        <p data-i18n="no_records">No Records yet</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($activeTab === 'anomalies'): ?>
            <!-- ========== ANOMALIES TAB ========== -->
            <div class="card">
                <h3 data-i18n="anomalies_title">Heart Rate Anomalies</h3>
                <p style="opacity:0.4; font-size:12px; margin-bottom:12px;" data-i18n="anomalies_desc">HR < 50 BPM, periods lasting 5+ seconds</p>

                <!-- Time Range Selector -->
                <div class="time-selector">
                    <div class="time-buttons">
                        <button class="time-btn ep-range-btn" data-hours="1" data-i18n="h_1">1h</button>
                        <button class="time-btn ep-range-btn" data-hours="4" data-i18n="h_4">4h</button>
                        <button class="time-btn ep-range-btn" data-hours="8" data-i18n="h_8">8h</button>
                        <button class="time-btn ep-range-btn active" data-hours="24" data-i18n="h_24">24h</button>
                        <button class="time-btn ep-range-btn" data-hours="72" data-i18n="h_72">72h</button>
                        <button class="time-btn ep-range-btn" data-hours="168" data-i18n="ep_1w">1 Week</button>
                        <button class="time-btn ep-range-btn" data-hours="720" data-i18n="ep_1m">1 Month</button>
                        <button class="time-btn ep-range-btn" id="epDateRangeToggle" onclick="toggleEpDateRange()"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg></button>
                    </div>
                    <div id="epDateRange" style="display:none; margin-top:8px; gap:8px; align-items:center; flex-wrap:wrap;">
                        <input type="date" id="epDateFrom" class="ep-date-input" max="<?= date('Y-m-d') ?>">
                        <span style="color: #6b7280;">—</span>
                        <input type="date" id="epDateTo" class="ep-date-input" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                        <button class="time-btn" onclick="loadEpisodesByDate()" style="padding:6px 16px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg></button>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div class="ep-summary" id="epSummary">
                    <div class="ep-stat">
                        <div class="value" id="epTotalCount">--</div>
                        <div class="label" data-i18n="ep_summary_total">Total Anomalies</div>
                    </div>
                    <div class="ep-stat">
                        <div class="value" id="epTotalDuration">--</div>
                        <div class="label" data-i18n="ep_summary_duration">Total Duration</div>
                    </div>
                    <div class="ep-stat">
                        <div class="value" id="epMinHR">--</div>
                        <div class="label" data-i18n="ep_summary_min">Lowest HR</div>
                    </div>
                    <div class="ep-stat">
                        <div class="value" id="epLongest">--</div>
                        <div class="label" data-i18n="ep_summary_longest">Longest</div>
                    </div>
                </div>
            </div>

            <!-- Anomalies Filter & Table -->
            <div class="card">
                <div class="ep-filters" id="epFilters">
                    <div style="position:relative; display:inline-flex; align-items:center;">
                        <svg style="position:absolute; left:8px; color:#9ca3af; pointer-events:none;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
                        <input type="text" id="epSearch" placeholder="ID, Date, HR..." oninput="epFilterChanged()" style="width:160px; background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; color:#1f2937; padding:6px 10px 6px 28px; font-size:12px;">
                    </div>
                    <label>
                        <span data-i18n="ep_filter_dur">Duration ≥</span>
                        <input type="number" id="epFilterDuration" min="0" placeholder="s" style="width:60px;" oninput="epFilterChanged()">
                    </label>
                    <label>
                        <span data-i18n="ep_filter_minhr">Min HR ≤</span>
                        <input type="number" id="epFilterMinHR" min="0" max="50" placeholder="BPM" style="width:60px;" oninput="epFilterChanged()">
                    </label>
                    <label>
                        <span data-i18n="ep_filter_avghr">Avg HR ≤</span>
                        <input type="number" id="epFilterAvgHR" min="0" max="50" placeholder="BPM" style="width:60px;" oninput="epFilterChanged()">
                    </label>
                    <span id="epFilterCount" style="color: #6b7280; font-size:12px; margin-left:auto;"></span>
                    <button class="time-btn" id="epFilterClear" onclick="clearEpFilters()" style="font-size:11px; display:none;" data-i18n="ep_filter_clear">Clear</button>
                </div>
                <div style="overflow-x: auto;">
                    <table class="history-table" id="episodesTable">
                        <thead>
                            <tr>
                                <th data-sort="id" class="ep-sortable">ID</th>
                                <th data-i18n="ep_th_start" data-sort="started_at" class="ep-sortable ep-sort-desc">Start</th>
                                <th data-i18n="ep_th_end" data-sort="ended_at" class="ep-sortable">End</th>
                                <th data-i18n="ep_th_duration" data-sort="duration_seconds" class="ep-sortable">Duration</th>
                                <th data-i18n="ep_th_min_hr" data-sort="min_hr" class="ep-sortable">Min HR</th>
                                <th data-i18n="ep_th_avg_hr" data-sort="avg_hr" class="ep-sortable">Avg HR</th>
                                <th data-i18n="ep_th_recovery" data-sort="recovery_hr" class="ep-sortable">Recovery</th>
                            </tr>
                        </thead>
                        <tbody id="episodesBody">
                        </tbody>
                    </table>
                </div>
                <div class="ep-no-data" id="episodesNoData" style="display:none;">
                    <div class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
                    <p data-i18n="ep_no_data">No anomalies detected in this time range</p>
                </div>

                <!-- Pagination -->
                <div class="ep-pagination" id="epPagination" style="display:none;">
                    <button class="time-btn" id="epPagePrev" onclick="epChangePage(-1)">&#9664;</button>
                    <span id="epPageInfo" style="color:#6b7280; font-size:13px;"></span>
                    <button class="time-btn" id="epPageNext" onclick="epChangePage(1)">&#9654;</button>
                </div>
                <!-- Load More (auto-triggered) -->
                <div id="epLoadMoreWrap" style="display:none;"></div>
            </div>

            <!-- Anomaly Chart (hidden, shown on click) -->
            <div class="card" id="episodeChartCard" style="display:none;">
                <div class="ep-chart-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button class="time-btn ep-nav-btn" id="epPrevBtn" onclick="navigateEpisode(-1)" title="Previous">&#9664;</button>
                        <h4 id="episodeChartTitle" style="margin:0;">Anomali</h4>
                        <button class="time-btn ep-nav-btn" id="epNextBtn" onclick="navigateEpisode(1)" title="Next">&#9654;</button>
                    </div>
                    <button class="time-btn" onclick="closeEpisodeChart()" data-i18n="ep_chart_close">Kapat</button>
                </div>
                <div class="chart-container" style="height: 250px; position: relative;">
                    <canvas id="episodeChart"></canvas>
                </div>
            </div>

        <?php endif; ?>

        <div class="footer">
            <?php if ($activeTab === 'live'): ?><span data-i18n="footer_note">In live mode (10s), charts are updated every 5 seconds</span><?php endif; ?>
        </div>
    </div>

    <!-- v2: Device selector (works on all tabs) -->
    <?php if ($activeTab !== 'live'): ?>
    <script>
    window._setPrimary = function(mac) {
        localStorage.setItem('hr_primary_mac', mac);
        document.cookie = 'hr_primary_mac=' + mac + ';path=/;max-age=31536000;SameSite=Lax';
        // page reloads on history/anomalies tabs
        location.reload();
    };
    </script>
    <?php endif; ?>

    <!-- Charts -->
    <?php if ($activeTab === 'live'): ?>
    <script>
    // Chart.js Crosshair Plugin (inline)
    const crosshairPlugin = {
        id: 'crosshair',
        afterDraw: (chart) => {
            if (chart.tooltip?._active?.length) {
                const ctx = chart.ctx;
                const activePoint = chart.tooltip._active[0];
                const x = activePoint.element.x;
                const y = activePoint.element.y;
                const topY = chart.scales.y.top;
                const bottomY = chart.scales.y.bottom;
                const leftX = chart.scales.x.left;
                const rightX = chart.scales.x.right;

                ctx.save();
                ctx.setLineDash([5, 5]);
                ctx.lineWidth = 1;

                // Vertical line
                ctx.strokeStyle = '#6b7280';
                ctx.beginPath();
                ctx.moveTo(x, topY);
                ctx.lineTo(x, bottomY);
                ctx.stroke();

                // Horizontal line
                ctx.strokeStyle = 'rgba(255, 107, 107, 0.7)';
                ctx.beginPath();
                ctx.moveTo(leftX, y);
                ctx.lineTo(rightX, y);
                ctx.stroke();

                ctx.restore();
            }
        }
    };
    Chart.register(crosshairPlugin);

    // Threshold Line Plugin (y=50 line)
    const thresholdPlugin = {
        id: 'thresholdLine',
        afterDraw: (chart) => {
            if (chart.canvas.id !== 'hrChart') return;
            const yScale = chart.scales.y;
            if (!yScale) return;
            const yPixel = yScale.getPixelForValue(50);
            if (yPixel < yScale.top || yPixel > yScale.bottom) return;
            const ctx = chart.ctx;
            ctx.save();
            ctx.strokeStyle = '#6b7280';
            ctx.lineWidth = 1;
            ctx.setLineDash([]);
            ctx.beginPath();
            ctx.moveTo(chart.scales.x.left, yPixel);
            ctx.lineTo(chart.scales.x.right, yPixel);
            ctx.stroke();
            // Label
            ctx.fillStyle = '#6b7280';
            ctx.font = '10px sans-serif';
            ctx.fillText('50', chart.scales.x.left + 4, yPixel - 4);
            ctx.restore();
        }
    };
    Chart.register(thresholdPlugin);

    // Global variables
    let hrChart = null;
    let ekgChart = null;
    let selectedSeconds = 10;  // Total selected duration for HR chart (seconds)
    let selectedTimeAgo = 0;  // How many seconds ago (0 = now) - for swipe
    let customStart = null;
    let customEnd = null;
    let isPaused = false;  // Is live update paused
    const EKG_WINDOW = 10;  // EKG always shows last 10 seconds
    let stepSize = 10;  // Scroll step (seconds) - varies with selected duration

    // Polling variables
    let pollTimer = null;
    let lastPollId = 0;

    // Range Slider variables
    let rangeSlider = null;
    let fullDataTimestamps = [];  // All data timestamps (for slider)
    let fullDataHeartRate = [];   // All HR values
    let fullDataLabels = [];      // All data labels
    let sliderMinTs = 0;          // Slider minimum timestamp (ms)
    let sliderMaxTs = 0;          // Slider maximum timestamp (ms)
    let sliderStartTs = 0;        // Slider selected start (ms)
    let sliderEndTs = 0;          // Slider selected end (ms)

    // Swipe variables
    let touchStartX = 0;
    let touchStartY = 0;
    let isSwiping = false;

    // Create charts
    function initCharts() {
        const hrCtx = document.getElementById('hrChart')?.getContext('2d');
        const ekgCtx = document.getElementById('ekgChart')?.getContext('2d');

        if (!hrCtx || !ekgCtx) return;

        const hrGradient = hrCtx.createLinearGradient(0, 0, 0, 200);
        hrGradient.addColorStop(0, 'rgba(255, 107, 107, 0.4)');
        hrGradient.addColorStop(1, 'rgba(255, 107, 107, 0.0)');

        // Heart Rate Chart
        hrChart = new Chart(hrCtx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: t('bpm'), data: [], borderColor: '#ff6b6b', backgroundColor: hrGradient, borderWidth: 2, fill: true, tension: 0.3, pointRadius: 1, pointHoverRadius: 8, pointHoverBackgroundColor: '#ff6b6b' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: { size: 14 },
                        bodyFont: { size: 16, weight: 'bold' },
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            title: (items) => items[0]?.label || '',
                            label: (item) => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg> ${item.raw} ${t('bpm')}`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: '#e5e7eb', drawBorder: false },
                        ticks: { color: '#6b7280', maxTicksLimit: 8, maxRotation: 0 }
                    },
                    y: {
                        grid: { color: '#e5e7eb', drawBorder: false },
                        ticks: { color: '#6b7280' }
                    }
                }
            }
        });

        // EKG Chart - PQRST sinus wave (real time intervals)
        ekgChart = new Chart(ekgCtx, {
            type: 'line',
            data: { datasets: [{
                label: t('r_peak'),
                data: [],  // format: {x: ms, y: value}
                borderColor: '#00ff00',
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                fill: false,
                tension: 0.2,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointHoverBackgroundColor: '#00ff00'
            }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 50, 0, 0.9)',
                        titleFont: { size: 14 },
                        bodyFont: { size: 12 },
                        padding: 10,
                        displayColors: false,
                        // Show tooltip only at R-peak points
                        filter: (item) => {
                            // y === 1.0 olan noktalar R peak
                            return item.parsed.y === 1.0;
                        },
                        callbacks: {
                            title: (items) => {
                                if (!items[0]) return '';
                                const ms = items[0].parsed.x;
                                const d = new Date(ms);
                                const h = d.getHours().toString().padStart(2, '0');
                                const m = d.getMinutes().toString().padStart(2, '0');
                                const s = d.getSeconds().toString().padStart(2, '0');
                                const mil = d.getMilliseconds().toString().padStart(3, '0');
                                return `${h}:${m}:${s}.${mil}`;
                            },
                            label: (item) => {
                                return t('r_peak');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        grid: { color: 'rgba(0, 255, 0, 0.12)', drawBorder: false },
                        ticks: {
                            color: 'rgba(0, 255, 0, 0.6)',
                            maxTicksLimit: 5,
                            callback: (val) => {
                                // Unix timestamp (ms) → HH:mm:ss.mmm format
                                const d = new Date(val);
                                const h = d.getHours().toString().padStart(2, '0');
                                const m = d.getMinutes().toString().padStart(2, '0');
                                const s = d.getSeconds().toString().padStart(2, '0');
                                const ms = d.getMilliseconds().toString().padStart(3, '0');
                                return `${h}:${m}:${s}.${ms}`;
                            }
                        },
                        title: { display: false }
                    },
                    y: {
                        min: -0.4,
                        max: 1.2,
                        grid: { color: 'rgba(0, 255, 0, 0.12)', drawBorder: false },
                        ticks: { display: false }
                    }
                }
            }
        });

        loadChartData();
    }

    // Chart update throttle (SSE may send 1 data point per second)
    let lastChartLoad = 0;
    const CHART_THROTTLE = 2000; // Update chart at most every 2 seconds

    // Load data
    async function loadChartData() {
        const now = Date.now();
        if (now - lastChartLoad < CHART_THROTTLE) return;
        lastChartLoad = now;
        let url = '/hr2/api/chart-data.php?device=' + encodeURIComponent(_primaryMac) + '&';

        if (customStart && customEnd) {
            // Custom time range (Unix timestamp)
            url += `start=${customStart}&end=${customEnd}`;
        } else {
            // Heart Rate: show full selected duration (e.g. 15m = last 15 minutes)
            // EKG: always shows last 10 seconds (filtered inside updateCharts)
            const now = Math.floor(Date.now() / 1000);
            const end = now - selectedTimeAgo;
            const start = end - selectedSeconds;
            url += `start=${start}&end=${end}`;
        }

        try {
            const response = await fetch(url);
            const json = await response.json();

            if (json.success && json.data) {
                updateCharts(json.data);
            }
        } catch (error) {
            console.error('Data loading error:', error);
        }
    }

    // Update charts
    function updateCharts(data) {
        const { labels, heartRate, timestamps, stats } = data;

        // Store all data (for slider)
        fullDataTimestamps = timestamps || [];
        fullDataHeartRate = heartRate || [];
        fullDataLabels = labels || [];

        // Update stats (for all data)
        if (stats) {
            document.getElementById('statMin').textContent = stats.min || '--';
            document.getElementById('statAvg').textContent = stats.avg || '--';
            document.getElementById('statMax').textContent = stats.max || '--';
            document.getElementById('statCount').textContent = stats.count || '--';
        }

        // Slider control: show slider for 3 min or more time range
        const sliderContainer = document.getElementById('rangeSliderContainer');
        if (selectedSeconds >= 180 && fullDataTimestamps.length > 0) {
            sliderContainer.style.display = 'block';
            initRangeSlider();
        } else {
            sliderContainer.style.display = 'none';
            // No slider, show all data
            renderCharts(fullDataLabels, fullDataHeartRate, fullDataTimestamps);
        }
    }

    // Initialize Range Slider
    function initRangeSlider() {
        const sliderEl = document.getElementById('rangeSlider');
        if (!sliderEl || fullDataTimestamps.length === 0) return;

        sliderMinTs = fullDataTimestamps[0];
        sliderMaxTs = fullDataTimestamps[fullDataTimestamps.length - 1];

        // Initially the full range is selected
        sliderStartTs = sliderMinTs;
        sliderEndTs = sliderMaxTs;

        // If slider already exists, destroy it
        if (rangeSlider) {
            rangeSlider.destroy();
        }

        // Create noUiSlider
        rangeSlider = noUiSlider.create(sliderEl, {
            start: [sliderMinTs, sliderMaxTs],
            connect: true,
            range: {
                'min': sliderMinTs,
                'max': sliderMaxTs
            },
            step: 1000, // 1 second
            behaviour: 'drag-tap',
            tooltips: false
        });

        // Slider change event
        rangeSlider.on('update', function(values) {
            sliderStartTs = Math.round(values[0]);
            sliderEndTs = Math.round(values[1]);
            updateSliderDisplay();
        });

        rangeSlider.on('change', function(values) {
            sliderStartTs = Math.round(values[0]);
            sliderEndTs = Math.round(values[1]);
            filterAndRenderCharts();
        });

        // Initial render
        updateSliderDisplay();
        filterAndRenderCharts();
    }

    // Update slider display
    function updateSliderDisplay() {
        const startDate = new Date(sliderStartTs);
        const endDate = new Date(sliderEndTs);

        const fmt = (d) => {
            const h = d.getHours().toString().padStart(2, '0');
            const m = d.getMinutes().toString().padStart(2, '0');
            const s = d.getSeconds().toString().padStart(2, '0');
            return `${h}:${m}:${s}`;
        };

        document.getElementById('sliderStartTime').textContent = fmt(startDate);
        document.getElementById('sliderEndTime').textContent = fmt(endDate);

        // Calculate duration
        const durationMs = sliderEndTs - sliderStartTs;
        const durationSec = Math.round(durationMs / 1000);
        let durationText = '';
        if (durationSec < 60) {
            durationText = t('duration_sec', {s: durationSec});
        } else if (durationSec < 3600) {
            const min = Math.floor(durationSec / 60);
            const sec = durationSec % 60;
            durationText = sec > 0 ? t('duration_min_sec', {m: min, s: sec}) : t('duration_min', {m: min});
        } else {
            const hr = Math.floor(durationSec / 3600);
            const min = Math.floor((durationSec % 3600) / 60);
            durationText = min > 0 ? t('duration_hour_min', {h: hr, m: min}) : t('duration_hour', {h: hr});
        }
        document.getElementById('sliderDuration').textContent = t('selected_duration', {duration: durationText});
    }

    // Filter and render charts based on slider range
    function filterAndRenderCharts() {
        // Filter data in selected range
        const filteredIndices = [];
        for (let i = 0; i < fullDataTimestamps.length; i++) {
            if (fullDataTimestamps[i] >= sliderStartTs && fullDataTimestamps[i] <= sliderEndTs) {
                filteredIndices.push(i);
            }
        }

        const filteredLabels = filteredIndices.map(i => fullDataLabels[i]);
        const filteredHR = filteredIndices.map(i => fullDataHeartRate[i]);
        const filteredTS = filteredIndices.map(i => fullDataTimestamps[i]);

        // Filtered Statistics
        if (filteredHR.length > 0) {
            document.getElementById('statMin').textContent = Math.min(...filteredHR);
            document.getElementById('statAvg').textContent = Math.round(filteredHR.reduce((a, b) => a + b, 0) / filteredHR.length);
            document.getElementById('statMax').textContent = Math.max(...filteredHR);
            document.getElementById('statCount').textContent = filteredHR.length;
        }

        renderCharts(filteredLabels, filteredHR, filteredTS);
    }

    // Grafikleri render et (HR ve EKG)
    function renderCharts(labels, heartRate, timestamps) {
        // Heart Rate chart
        if (hrChart) {
            hrChart.data.labels = labels;
            hrChart.data.datasets[0].data = heartRate;
            if (heartRate.length > 0) {
                hrChart.options.scales.y.min = Math.min(...heartRate) - 5;
                hrChart.options.scales.y.max = Math.max(...heartRate) + 5;
            }
            hrChart.update('none');
        }

        // EKG chart - LAST 10 seconds of selected range
        if (ekgChart && timestamps.length > 0) {
            const lastTimestamp = timestamps[timestamps.length - 1];
            const ekgWindowStart = lastTimestamp - (EKG_WINDOW * 1000);
            const ekgTimestamps = timestamps.filter(ts => ts >= ekgWindowStart);

            const ekgData = [];

            // PQRST dalga formu
            const pqrstWave = [
                { offset: -200, y: 0 },
                { offset: -160, y: 0.03 },
                { offset: -130, y: 0.12 },
                { offset: -100, y: 0.03 },
                { offset: -70, y: 0 },
                { offset: -40, y: -0.05 },
                { offset: 0, y: 1.0 },       // R PEAK
                { offset: 40, y: -0.12 },
                { offset: 80, y: 0 },
                { offset: 140, y: 0.05 },
                { offset: 180, y: 0.18 },
                { offset: 220, y: 0.05 },
                { offset: 260, y: 0 }
            ];

            for (let i = 0; i < ekgTimestamps.length; i++) {
                const beatTime = ekgTimestamps[i];
                pqrstWave.forEach(point => {
                    ekgData.push({ x: beatTime + point.offset, y: point.y });
                });

                if (i < ekgTimestamps.length - 1) {
                    const nextBeatTime = ekgTimestamps[i + 1];
                    const gapStart = beatTime + 260;
                    const gapEnd = nextBeatTime - 200;
                    if (gapEnd > gapStart + 50) {
                        ekgData.push({ x: gapStart + 20, y: 0 });
                        ekgData.push({ x: gapEnd - 20, y: 0 });
                    }
                }
            }

            ekgData.sort((a, b) => a.x - b.x);

            if (ekgTimestamps.length > 0) {
                ekgChart.options.scales.x.min = ekgTimestamps[0] - 300;
                ekgChart.options.scales.x.max = ekgTimestamps[ekgTimestamps.length - 1] + 350;
            }

            ekgChart.data.datasets[0].data = ekgData;
            ekgChart.update('none');
        }
    }

    // Time picker events
    document.querySelectorAll('.time-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const minutes = this.dataset.minutes;
            const seconds = this.dataset.seconds;

            // Active class
            document.querySelectorAll('.time-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            if (minutes === 'custom') {
                document.getElementById('customTimePicker').style.display = 'block';
                updateTimePreview();
            } else {
                document.getElementById('customTimePicker').style.display = 'none';
                customStart = null;
                customEnd = null;

                // Heart Rate chart: show full selected duration
                // EKG chart: always shows last 10 seconds
                if (seconds) {
                    selectedSeconds = parseInt(seconds);
                } else if (minutes) {
                    selectedSeconds = parseInt(minutes) * 60;
                }

                // stepSize: scroll step (10% of selected duration, min 10 sec)
                stepSize = Math.max(10, Math.floor(selectedSeconds / 10));

                // Return to live mode (selectedTimeAgo = 0)
                selectedTimeAgo = 0;
                isPaused = false;
                updatePauseButton();
                updateTimeIndicator();
                loadChartData();
            }
        });
    });

    // Custom time preview - show selected time range
    function getCustomEndTime() {
        const endTimeVal = document.getElementById('endTimeInput').value;
        if (!endTimeVal) return new Date(); // If empty, use now
        const [h, m] = endTimeVal.split(':').map(Number);
        const end = new Date();
        end.setHours(h, m, 0, 0);
        // If selected time is in the future, assume yesterday
        if (end > new Date()) {
            end.setDate(end.getDate() - 1);
        }
        return end;
    }

    function updateTimePreview() {
        const hoursAgo = parseInt(document.getElementById('hoursAgo').value);
        const end = getCustomEndTime();
        const start = new Date(end.getTime() - hoursAgo * 60 * 60 * 1000);

        const locale = currentLang === 'hi' ? 'hi-IN' : 'en-US';
        const fmt = (d) => d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
        document.getElementById('timeRangePreview').textContent = t('time_range_preview', {start: fmt(start), end: fmt(end), hours: hoursAgo});
    }

    document.getElementById('hoursAgo')?.addEventListener('change', updateTimePreview);
    document.getElementById('endTimeInput')?.addEventListener('change', updateTimePreview);

    // Apply custom time - show the full selected time range
    document.getElementById('applyCustomTime')?.addEventListener('click', function() {
        const hoursAgo = parseInt(document.getElementById('hoursAgo').value);
        const endTime = getCustomEndTime();
        const startTime = new Date(endTime.getTime() - hoursAgo * 60 * 60 * 1000);

        customStart = Math.floor(startTime.getTime() / 1000);
        customEnd = Math.floor(endTime.getTime() / 1000);
        selectedSeconds = hoursAgo * 3600; // for EKG filtering
        loadChartData();
    });

    // ===== v2: Multi-device SSE =====

    var _devices = {};
    var _primaryMac = localStorage.getItem('hr_primary_mac') || '<?= addslashes($primaryMac) ?>';
    var _deviceProfiles = <?php
        $profiles = [];
        foreach (getAllowedDeviceList() as $mac) {
            $p = getDeviceProfile($mac);
            $profiles[$mac] = ['type' => $p['type'], 'short' => $p['short'], 'name' => $p['name']];
        }
        echo json_encode($profiles, JSON_UNESCAPED_UNICODE);
    ?>;
    var _evtSource = null;
    var _reconnectTimer = null;
    var _secondsTimer = null;

    function startPolling() {
        // Fetch initial data first, then connect to SSE
        fetch('/hr2/api/latest-all.php')
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.devices) {
                    json.devices.forEach(function(d) {
                        _devices[d.mac] = d;
                    });
                    // If primary device is offline, switch to active device
                    if (!_devices[_primaryMac] && json.devices.length > 0) {
                        _primaryMac = json.devices[0].mac;
                    }
                    // Update HR card
                    if (_devices[_primaryMac]) {
                        updateHRCardFromSSE(_devices[_primaryMac]);
                    } else {
                        showHRCardOffline(null);
                    }
                    _renderDeviceSelector();
                    _renderSecondaryBadge();
                    _updateDeviceStatuses();
                }
            })
            .catch(function() {});
        _connectSSE();
        _startSecondsTimer();
    }

    function stopPolling() {
        if (_evtSource) { _evtSource.close(); _evtSource = null; }
        if (_reconnectTimer) { clearTimeout(_reconnectTimer); _reconnectTimer = null; }
        if (_secondsTimer) { clearInterval(_secondsTimer); _secondsTimer = null; }
    }

    function _connectSSE() {
        if (_evtSource) { _evtSource.close(); _evtSource = null; }

        _evtSource = new EventSource('/hr2/api/sse-all.php');

        _evtSource.addEventListener('connected', function(e) {
            var data = JSON.parse(e.data);
            var cnt = data.devices ? data.devices.length : 0;
            _updateDeviceStatuses();
        });

        _evtSource.addEventListener('heartbeat', function(e) {
            var data = JSON.parse(e.data);
            _devices[data.mac] = data;

            // If primary device not set yet, use the first one that arrives
            // (don't override if user explicitly selected an offline device)
            if (!_primaryMac) {
                _setPrimary(data.mac);
            }

            // Update HR card if data is from primary device
            if (data.mac === _primaryMac) {
                updateHRCardFromSSE(data);
                // Update charts (in Live mode)
                if (!isPaused && selectedTimeAgo === 0 && !customStart && !customEnd) {
                    loadChartData();
                }
            }

            _renderDeviceSelector();
            _renderSecondaryBadge();

            _updateDeviceStatuses();
        });

        _evtSource.addEventListener('device_artifact', function(e) {
            var data = JSON.parse(e.data);
            if (_devices[data.mac]) {
                _devices[data.mac]._artifact = true;
            }
            _renderSecondaryBadge();
            _updateDeviceStatuses();
        });

        _evtSource.addEventListener('timeout', function() {
            _evtSource.close(); _evtSource = null;
            _updateDeviceStatuses();
            _reconnectTimer = setTimeout(_connectSSE, 3000);
        });

        _evtSource.onerror = function() {
            _evtSource.close(); _evtSource = null;
            _updateDeviceStatuses();
            _reconnectTimer = setTimeout(_connectSSE, 3000);
        };
    }

    function _updateDeviceStatuses() {
        var allowed = <?= json_encode(getAllowedDeviceList()) ?>;
        allowed.forEach(function(mac) {
            var elId = 'statusBadge_' + mac.replace(/:/g, '');
            var badge = document.getElementById(elId);
            if (!badge) return;
            var d = _devices[mac];
            var isOn = d && d.seconds_ago < 10 && !d._artifact;
            var p = _deviceProfiles[mac] || {short: '???'};
            badge.className = 'status-badge ' + (isOn ? 'status-online' : 'status-offline');
            var statusText = isOn ? t('dev_connected') : (d && d._artifact ? t('dev_signal_loss') : t('dev_disconnected'));
            badge.innerHTML = '<span class="status-dot"></span>'
                + '<span class="status-label">' + p.short + '</span>'
                + '<span class="status-text">' + statusText + '</span>';
        });
    }

    window._setPrimary = function(mac) {
        _primaryMac = mac;
        localStorage.setItem('hr_primary_mac', mac);
        document.cookie = 'hr_primary_mac=' + mac + ';path=/;max-age=31536000;SameSite=Lax';
        _renderDeviceSelector();
        _renderSecondaryBadge();

        var d = _devices[mac];
        var isOffline = !d || d.seconds_ago > 10 || d._artifact;

        if (d) updateHRCardFromSSE(d);
        else showHRCardOffline(null);

        // Reload charts and stats for the new device
        lastChartLoad = 0;
        loadChartData();
        _loadTodayCount(mac);

        if (!isOffline) {
            checkArrhythmias();
        } else {
            // Offline cihaz — ritim badge'ini gizle
            var rhythmEl = document.getElementById('rhythmStatus');
            if (rhythmEl) rhythmEl.style.display = 'none';
        }
    };

    function _loadTodayCount(mac) {
        fetch('/hr2/api/today-count.php?device=' + encodeURIComponent(mac))
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success) {
                    var el = document.querySelector('.info-card:nth-child(3) .value');
                    if (el) el.textContent = json.count.toLocaleString();
                    // Update today's stats card
                    var minEl = document.getElementById('todayStatMin');
                    var avgEl = document.getElementById('todayStatAvg');
                    var maxEl = document.getElementById('todayStatMax');
                    if (minEl) minEl.textContent = json.min_hr !== null ? json.min_hr : '--';
                    if (avgEl) avgEl.textContent = json.avg_hr !== null ? json.avg_hr : '--';
                    if (maxEl) maxEl.textContent = json.max_hr !== null ? json.max_hr : '--';
                }
            })
            .catch(function() {});
    }

    function _renderDeviceSelector() {
        var el = document.getElementById('deviceSelector');
        if (!el) return;
        var allowed = <?= json_encode(getAllowedDeviceList()) ?>;
        var html = '';
        allowed.forEach(function(mac) {
            var d = _devices[mac];
            var p = _deviceProfiles[mac] || {type:'unknown',short:'???'};
            var type = p.type;
            var shortName = p.short;
            var icon = type === 'pvs' ? '🔵' : '🟠';
            var isActive = (mac === _primaryMac);
            var offlineClass = (d && d.seconds_ago > 10) ? ' offline' : '';
            html += '<span class="device-select-btn dev-' + type + (isActive ? ' active' : '') + offlineClass + '"'
                  + ' data-mac="' + mac + '" onclick="window._setPrimary(\'' + mac + '\')">'
                  + icon + ' ' + shortName + ' ' + (isActive ? '●' : '○')
                  + '</span>';
        });
        el.innerHTML = html;
    }

    function _renderSecondaryBadge() {
        var el = document.getElementById('secondaryBadge');
        if (!el) return;
        var macs = Object.keys(_devices);
        var secMac = null;
        for (var i = 0; i < macs.length; i++) {
            if (macs[i] !== _primaryMac) { secMac = macs[i]; break; }
        }
        if (!secMac || !_devices[secMac]) {
            el.innerHTML = '';
            return;
        }
        var d = _devices[secMac];
        var p = _deviceProfiles[secMac] || {type:'unknown',short:'???'};
        var isOff = d.seconds_ago > 10;
        var isArtifact = !!d._artifact;
        var hrColor = d.heart_rate > 120 ? '#ff6b6b' : (d.heart_rate < 50 ? '#3498db' : '#fff');
        var hrText = (isOff || isArtifact) ? '--' : d.heart_rate;
        var metaText = (isOff || isArtifact)
            ? t('dev_disconnected')
            : (d.battery_level !== null ? d.battery_level + '% ' : '') + d.seconds_ago + 's';

        el.innerHTML = '<div class="secondary-device-badge' + (isOff || isArtifact ? ' offline' : '') + '"'
            + ' onclick="window._setPrimary(\'' + secMac + '\')">'
            + '<span class="sec-badge dev-' + p.type + '">' + p.short + '</span>'
            + '<span class="sec-hr" style="color:' + hrColor + '">' + hrText + '</span>'
            + '<span class="sec-meta">' + metaText + '</span>'
            + '</div>';
    }

    function _startSecondsTimer() {
        if (_secondsTimer) clearInterval(_secondsTimer);
        _secondsTimer = setInterval(function() {
            var changed = false;
            Object.keys(_devices).forEach(function(mac) {
                if (_devices[mac].seconds_ago !== undefined) {
                    _devices[mac].seconds_ago++;
                    changed = true;
                }
            });
            if (changed) {
                _renderSecondaryBadge();
                _updateDeviceStatuses();
                // Update HR card if primary device goes offline
                var pd = _devices[_primaryMac];
                if (pd && pd.seconds_ago > 10) {
                    updateHRCardFromSSE(pd);
                }
            }
        }, 1000);
    }

    // ===== Rhythm Status (Inside HR Card) =====
    const ARR_SVG = {
        normal: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>`,
        sinus_bradycardia: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h4l2 4 3-8 2 4h7" opacity="0.4"/><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>`,
        sinus_tachycardia: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/><path d="M8 10l1.5-3 1.5 6 1.5-6 1.5 3" stroke-width="2.5"/></svg>`,
        af_suspect: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12h2l1.5-4 2 7 1.5-5 2 9 1.5-7 2 5 1-3h2.5"/><circle cx="19" cy="5" r="3.5" fill="currentColor" opacity="0.25" stroke="none"/><text x="19" y="7" text-anchor="middle" font-size="6" font-weight="bold" fill="currentColor" stroke="none">?</text></svg>`,
        svt_suspect: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10" fill="currentColor" opacity="0.15"/><polygon points="13 2 3 14 12 14 11 22 21 10 12 10"/></svg>`,
        flutter_suspect: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12h1.5l1-4 1 4 1-4 1 4 1-4 1 4 1-4 1 4 1-4 1 4 1-4 1 4 1-4 1 4H23"/></svg>`
    };
    const ARR_NAMES = {
        sinus_bradycardia: 'Sinus Bradycardia', sinus_tachycardia: 'Sinus Tachycardia',
        af_suspect: 'AF Suspect', svt_suspect: 'SVT Suspect',
        flutter_suspect: 'Flutter Suspect'
    };
    let arrCheckInterval = null;

    function startArrhythmiaPolling() {
        checkArrhythmias();
        arrCheckInterval = setInterval(checkArrhythmias, 10000);
    }

    async function checkArrhythmias() {
        try {
            const response = await fetch('/hr2/api/arrhythmia.php?hours=1&limit=5&device_id=' + encodeURIComponent(_primaryMac));
            const json = await response.json();
            if (!json.success) return;
            renderRhythmStatus(json.events || []);
        } catch (e) {
            console.log('[Arrhythmia] Hata:', e.message);
        }
    }

    function renderRhythmStatus(events) {
        const el = document.getElementById('rhythmStatus');
        if (!el) return;

        if (events.length === 0) {
            el.innerHTML = `
                <div class="rhythm-badge rhythm-normal">
                    ${ARR_SVG.normal}
                    <span class="rhythm-label">Normal Sinus Rhythm</span>
                </div>`;
            return;
        }

        // Show the most recent event
        const e = events[0];
        el.innerHTML = `
            <div class="rhythm-badge rhythm-${e.severity}">
                ${ARR_SVG[e.event_type] || ARR_SVG.normal}
                <span class="rhythm-label">${ARR_NAMES[e.event_type] || e.event_type}</span>
                <span class="rhythm-sub">%${Math.round(e.confidence)}</span>
            </div>`;
    }

    // Start rhythm polling when page loads
    if (document.getElementById('rhythmStatus')) {
        startArrhythmiaPolling();
    }

    // Update HR card with data from SSE
    function updateHRCardFromSSE(d) {
        var isOffline = !d || d.seconds_ago > 10 || d._artifact;

        if (isOffline) {
            showHRCardOffline(d);
            return;
        }

        // Show BPM in tab title
        document.title = `\u2764\uFE0F ${d.heart_rate} BPM`;

        // HR value
        const hrValueEl = document.querySelector('.hr-value');
        if (hrValueEl) { hrValueEl.textContent = d.heart_rate; hrValueEl.style.opacity = '1'; }

        // HR durumu
        const hrStatusEl = document.querySelector('.hr-status');
        if (hrStatusEl) {
            hrStatusEl.classList.remove('hr-low', 'hr-normal', 'hr-high', 'hr-offline');
            if (d.heart_rate < 50) {
                hrStatusEl.classList.add('hr-low');
                hrStatusEl.textContent = t('low_hr');
            } else if (d.heart_rate > 120) {
                hrStatusEl.classList.add('hr-high');
                hrStatusEl.textContent = t('high_hr');
            } else {
                hrStatusEl.classList.add('hr-normal');
                hrStatusEl.textContent = t('normal_hr');
            }
        }

        // Last update — how many seconds ago
        const lastUpdateEl = document.querySelector('.last-update');
        if (lastUpdateEl) {
            var ago = d.seconds_ago || 0;
            var agoStr = ago < 60 ? ago + 's' : Math.floor(ago / 60) + 'm ' + (ago % 60) + 's';
            lastUpdateEl.textContent = t('last_update', {time: agoStr});
        }

        // Pil seviyesi
        const batteryEl = document.querySelector('.info-card:nth-child(1) .value');
        if (batteryEl && d.battery_level) batteryEl.textContent = d.battery_level + '%';

        // Sensor Contact
        const contactEl = document.querySelector('.info-card:nth-child(2) .value');
        if (contactEl) contactEl.textContent = d.sensor_contact ? t('yes') : t('no');

        // How long ago
        const agoEl = document.querySelector('.info-card:nth-child(4) .value');
        if (agoEl) {
            agoEl.textContent = d.seconds_ago < 60 ? d.seconds_ago + 's' : Math.floor(d.seconds_ago / 60) + 'm';
        }

        // Show rhythm badge
        const rhythmEl = document.getElementById('rhythmStatus');
        if (rhythmEl) rhythmEl.style.display = '';
    }

    function showHRCardOffline(d) {
        document.title = '⏸ ' + t('dev_no_connection');

        const hrValueEl = document.querySelector('.hr-value');
        if (hrValueEl) { hrValueEl.textContent = '--'; hrValueEl.style.opacity = '0.3'; }

        const hrStatusEl = document.querySelector('.hr-status');
        if (hrStatusEl) {
            hrStatusEl.classList.remove('hr-low', 'hr-normal', 'hr-high');
            hrStatusEl.classList.add('hr-offline');
            hrStatusEl.textContent = t('dev_no_connection');
        }

        const lastUpdateEl = document.querySelector('.last-update');
        if (lastUpdateEl) {
            if (d && d.seconds_ago !== undefined) {
                var ago = d.seconds_ago;
                var agoStr = ago < 60 ? ago + 's' : Math.floor(ago / 60) + 'm ' + (ago % 60) + 's';
                lastUpdateEl.textContent = t('dev_last_data', {time: agoStr});
            } else {
                lastUpdateEl.textContent = t('dev_no_data');
            }
        }

        const batteryEl = document.querySelector('.info-card:nth-child(1) .value');
        if (batteryEl) batteryEl.textContent = d && d.battery_level ? d.battery_level + '%' : '--';

        const contactEl = document.querySelector('.info-card:nth-child(2) .value');
        if (contactEl) contactEl.textContent = '--';

        const agoEl = document.querySelector('.info-card:nth-child(4) .value');
        if (agoEl) agoEl.textContent = d ? (d.seconds_ago < 60 ? d.seconds_ago + 's' : Math.floor(d.seconds_ago / 60) + 'm') : '--';

        // Ritim badge'ini gizle
        const rhythmEl = document.getElementById('rhythmStatus');
        if (rhythmEl) rhythmEl.style.display = 'none';
    }

    // Pause/Play toggle
    function togglePause() {
        isPaused = !isPaused;
        const btn = document.getElementById('pauseBtn');
        if (isPaused) {
            btn.textContent = '▶️';
            btn.classList.add('paused');
            btn.title = t('resume_title');
        } else {
            btn.textContent = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
            btn.classList.remove('paused');
            btn.title = t('pause_title');
            // Immediately update when resuming
            if (selectedTimeAgo === 0 && !customStart && !customEnd) {
                loadChartData();
            }
        }
    }

    // Pause butonu event listener
    document.getElementById('pauseBtn')?.addEventListener('click', togglePause);

    // Swipe/Scroll functions
    function setupSwipeHandlers() {
        const chartCard = document.querySelector('.card:has(#hrChart)');
        if (!chartCard) return;

        // Touch events
        chartCard.addEventListener('touchstart', handleTouchStart, { passive: true });
        chartCard.addEventListener('touchmove', handleTouchMove, { passive: false });
        chartCard.addEventListener('touchend', handleTouchEnd, { passive: true });

        // Mouse events (for desktop)
        chartCard.addEventListener('mousedown', handleMouseDown);
        chartCard.addEventListener('mousemove', handleMouseMove);
        chartCard.addEventListener('mouseup', handleMouseUp);
        chartCard.addEventListener('mouseleave', handleMouseUp);
    }

    function handleTouchStart(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        isSwiping = true;
    }

    function handleTouchMove(e) {
        if (!isSwiping) return;
        const deltaX = e.touches[0].clientX - touchStartX;
        const deltaY = e.touches[0].clientY - touchStartY;
        // If horizontal swipe, prevent page scroll
        if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
            e.preventDefault();
        }
    }

    function handleTouchEnd(e) {
        if (!isSwiping) return;
        isSwiping = false;
        const deltaX = e.changedTouches[0].clientX - touchStartX;
        const deltaY = e.changedTouches[0].clientY - touchStartY;
        processSwipe(deltaX, deltaY);
    }

    let mouseStartX = 0;
    let isMouseDown = false;

    function handleMouseDown(e) {
        mouseStartX = e.clientX;
        isMouseDown = true;
        e.target.style.cursor = 'grabbing';
    }

    function handleMouseMove(e) {
        if (!isMouseDown) return;
    }

    function handleMouseUp(e) {
        if (!isMouseDown) return;
        isMouseDown = false;
        e.target.style.cursor = '';
        const deltaX = e.clientX - mouseStartX;
        processSwipe(deltaX, 0);
    }

    function processSwipe(deltaX, deltaY) {
        // Only handle horizontal swipes (don't block vertical scroll)
        if (Math.abs(deltaY) > Math.abs(deltaX)) return;

        const minSwipeDistance = 50; // Minimum swipe mesafesi (px)
        if (Math.abs(deltaX) < minSwipeDistance) return;

        // Disable swipe when custom time is selected
        if (customStart && customEnd) return;

        // Scroll logic (dragging content):
        // Swipe right (finger left to right) → go to History
        // Swipe left (finger right to left) → come back to Present
        if (deltaX > 0) {
            // Swipe right → go to History
            selectedTimeAgo += stepSize;
            isPaused = true;
            updatePauseButton();
        } else {
            // Swipe left → come back toward Present
            selectedTimeAgo = Math.max(0, selectedTimeAgo - stepSize);
            if (selectedTimeAgo === 0) {
                isPaused = false;
                updatePauseButton();
            }
        }

        updateTimeIndicator();
        loadChartData();
    }

    function updatePauseButton() {
        const btn = document.getElementById('pauseBtn');
        if (isPaused) {
            btn.textContent = '▶️';
            btn.classList.add('paused');
            btn.title = t('resume_title');
        } else {
            btn.textContent = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
            btn.classList.remove('paused');
            btn.title = t('pause_title');
        }
    }

    function updateTimeIndicator() {
        const indicator = document.getElementById('timeIndicator');
        if (!indicator) return;

        if (selectedTimeAgo === 0 && !customStart) {
            indicator.style.display = 'none';
        } else {
            indicator.style.display = 'flex';
            let timeText = '';
            if (customStart && customEnd) {
                const startDate = new Date(customStart * 1000);
                const endDate = new Date(customEnd * 1000);
                const loc = currentLang === 'hi' ? 'hi-IN' : 'en-US';
                timeText = `${startDate.toLocaleTimeString(loc, {hour:'2-digit', minute:'2-digit'})} - ${endDate.toLocaleTimeString(loc, {hour:'2-digit', minute:'2-digit'})}`;
            } else if (selectedTimeAgo > 0) {
                // Viewing History via swipe
                if (selectedTimeAgo < 60) {
                    timeText = t('time_sec_ago', {s: selectedTimeAgo});
                } else if (selectedTimeAgo < 3600) {
                    const min = Math.floor(selectedTimeAgo / 60);
                    const sec = selectedTimeAgo % 60;
                    timeText = sec > 0 ? t('time_min_sec_ago', {m: min, s: sec}) : t('time_min_ago', {m: min});
                } else {
                    const hour = Math.floor(selectedTimeAgo / 3600);
                    const min = Math.floor((selectedTimeAgo % 3600) / 60);
                    timeText = min > 0 ? t('time_hour_min_ago', {h: hour, m: min}) : t('time_hour_ago', {h: hour});
                }
            }
            indicator.querySelector('.time-text').textContent = timeText;
        }
    }

    function goToLive() {
        selectedTimeAgo = 0;
        selectedSeconds = 10;  // Default 10 seconds
        customStart = null;
        customEnd = null;
        isPaused = false;

        // 10sn butonunu aktif yap
        document.querySelectorAll('.time-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('.time-btn[data-seconds="10"]')?.classList.add('active');

        updatePauseButton();
        updateTimeIndicator();
        document.getElementById('customTimePicker').style.display = 'none';
        loadChartData();
    }

    // Go Live button
    document.getElementById('goLiveBtn')?.addEventListener('click', goToLive);

    // Update dynamic text (when language changes)
    function updateDynamicTexts() {
        // HR chart dataset labels
        if (typeof hrChart !== 'undefined' && hrChart) {
            hrChart.data.datasets[0].label = t('bpm');
            hrChart.update('none');
        }
        // EKG chart
        if (typeof ekgChart !== 'undefined' && ekgChart) {
            ekgChart.data.datasets[0].label = t('r_peak');
            ekgChart.update('none');
        }
        // Minute chart (history tab)
        if (typeof minuteChart !== 'undefined' && minuteChart) {
            minuteChart.data.datasets[0].label = t('avg_bpm');
            minuteChart.data.datasets[1].label = t('tooltip_min');
            minuteChart.data.datasets[2].label = t('tooltip_max');
            minuteChart.update('none');
        }
        // Pause button title
        if (typeof updatePauseButton === 'function') updatePauseButton();
        // Time indicator
        if (typeof updateTimeIndicator === 'function') updateTimeIndicator();
        // Slider display
        if (typeof updateSliderDisplay === 'function') updateSliderDisplay();
    }

    // When page loads
    document.addEventListener('DOMContentLoaded', function() {
        applyLanguage(currentLang);
        initCharts();
        startPolling();  // Polling updates (2 second interval)
        setupSwipeHandlers();
    });
    </script>
    <?php endif; ?>

    <?php if ($activeTab === 'history'): ?>
    <script>
        // ===== Per-Minute Average Chart =====
        let minuteChart = null;

        function initMinuteChart() {
            const ctx = document.getElementById('minuteChart')?.getContext('2d');
            if (!ctx) return;

            const gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(52, 152, 219, 0.35)');
            gradient.addColorStop(1, 'rgba(52, 152, 219, 0.0)');

            minuteChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: t('avg_bpm'),
                            data: [],
                            borderColor: 'rgba(102, 126, 234, 0.5)',
                            borderWidth: 1.5,
                            borderDash: [3, 3],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0
                        },
                        {
                            label: t('tooltip_min'),
                            data: [],
                            borderColor: '#3498db',
                            backgroundColor: gradient,
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#3498db'
                        },
                        {
                            label: t('tooltip_max'),
                            data: [],
                            borderColor: 'rgba(255, 107, 107, 0.5)',
                            borderWidth: 1.5,
                            borderDash: [3, 3],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            align: 'end',
                            labels: {
                                color: '#4b5563',
                                usePointStyle: true,
                                pointStyle: 'line',
                                padding: 15,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 10,
                            displayColors: true,
                            callbacks: {
                                title: (items) => items[0]?.label || '',
                                label: (item) => {
                                    const names = [t('tooltip_avg'), t('tooltip_min'), t('tooltip_max')];
                                    return ` ${names[item.datasetIndex]}: ${item.raw} BPM`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: '#9ca3af',
                                maxTicksLimit: 15,
                                maxRotation: 0
                            }
                        },
                        y: {
                            grid: { color: '#e5e7eb' },
                            ticks: { color: '#9ca3af' }
                        }
                    }
                }
            });
        }

        async function loadMinuteData(hours) {
            try {
                const _device = localStorage.getItem('hr_primary_mac') || '<?= addslashes($primaryMac) ?>';
                const response = await fetch(`/hr2/api/minute-summary.php?hours=${hours}&device=${encodeURIComponent(_device)}`);
                const json = await response.json();

                if (json.success && json.data && minuteChart) {
                    const d = json.data;

                    minuteChart.data.labels = d.labels;
                    minuteChart.data.datasets[0].data = d.avgHR;
                    minuteChart.data.datasets[1].data = d.minHR;
                    minuteChart.data.datasets[2].data = d.maxHR;

                    // Hide points if too much data, show if few
                    minuteChart.data.datasets[0].pointRadius = d.totalMinutes > 120 ? 0 : 2;

                    // Y ekseni otomatik ayarla
                    if (d.minHR.length > 0) {
                        minuteChart.options.scales.y.min = Math.min(...d.minHR) - 5;
                        minuteChart.options.scales.y.max = Math.max(...d.maxHR) + 5;
                    }

                    minuteChart.update('none');

                    // Statistics
                    if (d.avgHR.length > 0) {
                        document.getElementById('minuteStatMin').textContent = Math.min(...d.minHR);
                        document.getElementById('minuteStatAvg').textContent = Math.round(d.avgHR.reduce((a, b) => a + b, 0) / d.avgHR.length);
                        document.getElementById('minuteStatMax').textContent = Math.max(...d.maxHR);
                        document.getElementById('minuteStatCount').textContent = d.totalMinutes;
                    }
                }
            } catch (error) {
                console.error('Per-minute data loading error:', error);
            }
        }

        // Time buttons
        document.querySelectorAll('.minute-range-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.minute-range-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                loadMinuteData(parseInt(this.dataset.hours));
            });
        });

        // Update dynamic text (when language changes - history tab)
        function updateDynamicTexts() {
            if (typeof minuteChart !== 'undefined' && minuteChart) {
                minuteChart.data.datasets[0].label = t('avg_bpm');
                minuteChart.data.datasets[1].label = t('tooltip_min');
                minuteChart.data.datasets[2].label = t('tooltip_max');
                minuteChart.update('none');
            }
        }

        // When page loads
        document.addEventListener('DOMContentLoaded', function() {
            applyLanguage(currentLang);
            initMinuteChart();
            loadMinuteData(1); // Default: last 1 hour
        });

        // ===== Hourly Average Chart =====
        <?php if (!empty($hourlyData)): ?>
        (function() {
            const ctx2 = document.getElementById('hourlyChart')?.getContext('2d');
            if (!ctx2) return;
            const gradient2 = ctx2.createLinearGradient(0, 0, 0, 200);
            gradient2.addColorStop(0, 'rgba(46, 213, 115, 0.4)');
            gradient2.addColorStop(1, 'rgba(46, 213, 115, 0.0)');

            new Chart(ctx2, {
                type: 'line',
                data: {
                    labels: <?= json_encode($hourlyLabels) ?>,
                    datasets: [{
                        label: t('avg_bpm'),
                        data: <?= json_encode($hourlyData) ?>,
                        borderColor: '#2ed573',
                        backgroundColor: gradient2,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3,
                        pointBackgroundColor: '#2ed573'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#9ca3af', maxTicksLimit: 12 } },
                        y: {
                            grid: { color: '#e5e7eb' },
                            ticks: { color: '#9ca3af' }
                        }
                    }
                }
            });
        })();
        <?php endif; ?>
    </script>
    <?php endif; ?>

    <?php if ($activeTab === 'anomalies'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // ===== Anomaliler Tab =====
    let episodeChart = null;
    let currentEpHours = 24;
    let allEpisodes = [];        // API'den gelen ham liste
    let currentEpisodes = [];   // filtered + sorted list
    let currentEpIndex = -1;    // index of selected episode
    let epSortField = 'started_at';
    let epSortDir = 'desc';     // desc = yeniden eskiye

    // Threshold plugin (y=50 line) - for episodes chart
    const epThresholdPlugin = {
        id: 'epThreshold',
        afterDraw: (chart) => {
            if (chart.canvas.id !== 'episodeChart') return;
            const yScale = chart.scales.y;
            if (!yScale) return;
            const yPixel = yScale.getPixelForValue(50);
            if (yPixel < yScale.top || yPixel > yScale.bottom) return;
            const ctx = chart.ctx;
            ctx.save();
            ctx.strokeStyle = '#6b7280';
            ctx.lineWidth = 1;
            ctx.setLineDash([]);
            ctx.beginPath();
            ctx.moveTo(chart.scales.x.left, yPixel);
            ctx.lineTo(chart.scales.x.right, yPixel);
            ctx.stroke();
            ctx.fillStyle = '#6b7280';
            ctx.font = '10px sans-serif';
            ctx.fillText('50', chart.scales.x.left + 4, yPixel - 4);
            ctx.restore();
        }
    };
    Chart.register(epThresholdPlugin);

    const EP_PER_PAGE = 50;
    const EP_API_LIMIT = 200;
    let epCurrentPage = 0;
    let epHasMore = false;
    let epLoadingMore = false;
    let epTotalCount = 0;

    let epDateMode = false; // true = date range mode, false = hours mode
    let epDateFrom = '';
    let epDateTo = '';

    function toggleEpDateRange() {
        const el = document.getElementById('epDateRange');
        const isHidden = el.style.display === 'none';
        el.style.display = isHidden ? 'flex' : 'none';

        // Toggle button active state
        const btn = document.getElementById('epDateRangeToggle');
        if (isHidden) {
            document.querySelectorAll('.ep-range-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }
    }

    function loadEpisodesByDate() {
        const from = document.getElementById('epDateFrom').value;
        const to = document.getElementById('epDateTo').value;
        if (!from) return;

        epDateMode = true;
        epDateFrom = from;
        epDateTo = to || from;

        // Remove active from time buttons, activate date button
        document.querySelectorAll('.ep-range-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('epDateRangeToggle').classList.add('active');

        _loadEpisodesFromAPI();
    }

    async function loadEpisodes(hours) {
        currentEpHours = hours;
        epDateMode = false;
        epDateFrom = '';
        epDateTo = '';

        // Date panelini gizle
        document.getElementById('epDateRange').style.display = 'none';

        _loadEpisodesFromAPI();
    }

    let epSearchTimer = null;

    function _buildEpUrl(offset) {
        const _epDevice = localStorage.getItem('hr_primary_mac') || '<?= addslashes($primaryMac) ?>';
        let url = `/hr2/api/episodes.php?min_duration=5&limit=${EP_API_LIMIT}&offset=${offset}&device_id=${encodeURIComponent(_epDevice)}`;
        url += `&sort=${epSortField}&sort_dir=${epSortDir}`;
        if (epDateMode) {
            url += `&date_from=${epDateFrom}&date_to=${epDateTo}`;
        } else {
            url += `&hours=${currentEpHours}`;
        }
        const q = (document.getElementById('epSearch').value || '').trim();
        if (q) url += `&search=${encodeURIComponent(q)}`;
        const fd = parseInt(document.getElementById('epFilterDuration').value) || 0;
        const fm = parseInt(document.getElementById('epFilterMinHR').value) || 0;
        const fa = parseFloat(document.getElementById('epFilterAvgHR').value) || 0;
        if (fd > 0) url += `&filter_dur=${fd}`;
        if (fm > 0) url += `&filter_min_hr=${fm}`;
        if (fa > 0) url += `&filter_avg_hr=${fa}`;
        return url;
    }

    async function _loadEpisodesFromAPI() {
        epCurrentPage = 0;
        allEpisodes = [];
        epHasMore = false;
        try {
            const response = await fetch(_buildEpUrl(0));
            const json = await response.json();

            if (!json.success) return;

            allEpisodes = json.episodes;
            epHasMore = json.has_more || false;
            epTotalCount = json.total || json.count;
            currentEpIndex = -1;
            applyEpFilters();
            updateEpSummary(json.summary);

        } catch (error) {
            console.error('Episodes loading error:', error);
        }
    }

    async function loadMoreEpisodes() {
        if (epLoadingMore || !epHasMore) return;
        epLoadingMore = true;
        try {
            const response = await fetch(_buildEpUrl(allEpisodes.length));
            const json = await response.json();
            if (json.success && json.episodes.length > 0) {
                allEpisodes = allEpisodes.concat(json.episodes);
                epHasMore = json.has_more || false;
                epTotalCount = json.total || allEpisodes.length;
                applyEpFilters();
            } else {
                epHasMore = false;
            }
        } catch (error) {
            console.error('Load more error:', error);
        }
        epLoadingMore = false;
    }

    // Re-fetch from server when filter/search changes (debounce)
    function epFilterChanged() {
        clearTimeout(epSearchTimer);
        epSearchTimer = setTimeout(() => _loadEpisodesFromAPI(), 400);
    }

    // Render after data arrives from API (no more client-side filtering)
    function applyEpFilters() {
        const durMin = parseInt(document.getElementById('epFilterDuration').value) || 0;
        const minHrMax = parseInt(document.getElementById('epFilterMinHR').value) || 0;
        const avgHrMax = parseFloat(document.getElementById('epFilterAvgHR').value) || 0;
        const searchQ = (document.getElementById('epSearch').value || '').trim();

        currentEpisodes = allEpisodes;
        currentEpIndex = -1;
        epCurrentPage = 0;

        const hasFilter = durMin > 0 || minHrMax > 0 || avgHrMax > 0 || searchQ;
        const clearBtn = document.getElementById('epFilterClear');
        const countEl = document.getElementById('epFilterCount');
        clearBtn.style.display = hasFilter ? '' : 'none';
        countEl.textContent = hasFilter ? `${currentEpisodes.length} / ${epTotalCount}` : '';

        const noData = document.getElementById('episodesNoData');
        const table = document.getElementById('episodesTable');

        if (currentEpisodes.length === 0) {
            document.getElementById('episodesBody').innerHTML = '';
            noData.style.display = 'block';
            table.style.display = 'none';
            document.getElementById('epPagination').style.display = 'none';
            var lmw = document.getElementById('epLoadMoreWrap');
            if (lmw) lmw.style.display = 'none';
            return;
        }

        noData.style.display = 'none';
        table.style.display = '';
        renderEpPage();
    }

    function clearEpFilters() {
        document.getElementById('epSearch').value = '';
        document.getElementById('epFilterDuration').value = '';
        document.getElementById('epFilterMinHR').value = '';
        document.getElementById('epFilterAvgHR').value = '';
        applyEpFilters();
    }

    function sortEpisodes(field) {
        if (epSortField === field) {
            epSortDir = epSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            epSortField = field;
            epSortDir = (field === 'started_at' || field === 'ended_at') ? 'desc' : 'asc';
        }

        // Update header classes
        document.querySelectorAll('.ep-sortable').forEach(th => {
            th.classList.remove('ep-sort-asc', 'ep-sort-desc');
            if (th.dataset.sort === field) {
                th.classList.add(epSortDir === 'asc' ? 'ep-sort-asc' : 'ep-sort-desc');
            }
        });

        closeEpisodeChart();
        // Server-side sort: re-fetch from API
        _loadEpisodesFromAPI();
    }

    function renderEpPage() {
        const totalPages = Math.max(1, Math.ceil(currentEpisodes.length / EP_PER_PAGE));
        // Clamp page to valid range
        epCurrentPage = Math.max(0, Math.min(epCurrentPage, totalPages - 1));
        const start = epCurrentPage * EP_PER_PAGE;
        const end = Math.min(start + EP_PER_PAGE, currentEpisodes.length);
        const pageEps = currentEpisodes.slice(start, end);

        const tbody = document.getElementById('episodesBody');
        tbody.innerHTML = pageEps.map((ep, i) => {
            const globalIdx = start + i;
            const isActive = ep.status === 'active';
            const startDt = new Date(ep.started_at.replace(' ', 'T'));
            const endDt = ep.ended_at ? new Date(ep.ended_at.replace(' ', 'T')) : null;

            return `<tr onclick="showEpisodeByIndex(${globalIdx})" title="${t('ep_chart_title').replace('{id}', ep.id)}" ${globalIdx === currentEpIndex ? 'class="ep-selected"' : ''}>
                <td style="opacity:0.5; font-size:11px;">#${ep.id}</td>
                <td>${epFmtDt(startDt)}</td>
                <td>${isActive ? '<span class="ep-status-active">' + t('ep_active') + '</span>' : epFmtDt(endDt)}</td>
                <td>${isActive ? '--' : epFmtDuration(ep.duration_seconds)}</td>
                <td><span class="ep-badge ep-badge-low">${ep.min_hr}</span></td>
                <td>${parseFloat(ep.avg_hr).toFixed(1)}</td>
                <td>${ep.recovery_hr ? '<span class="ep-badge ep-badge-recovery">' + ep.recovery_hr + '</span>' : '--'}</td>
            </tr>`;
        }).join('');

        // Pagination controls
        const pag = document.getElementById('epPagination');
        if (totalPages <= 1) {
            pag.style.display = 'none';
            return;
        }
        pag.style.display = 'flex';
        document.getElementById('epPageInfo').textContent =
            `${start + 1}-${end} / ${currentEpisodes.length}` + (epHasMore ? '+' : '');
        document.getElementById('epPagePrev').disabled = epCurrentPage === 0;
        document.getElementById('epPageNext').disabled = epCurrentPage >= totalPages - 1 && !epHasMore;

    }

    let epPageChanging = false;
    async function epChangePage(dir) {
        if (epPageChanging) return;

        const totalPages = Math.ceil(currentEpisodes.length / EP_PER_PAGE);
        const nextPage = epCurrentPage + dir;

        // Forward clicked on last page and more data on server → auto-load
        if (dir > 0 && nextPage >= totalPages && epHasMore) {
            epPageChanging = true;
            await loadMoreEpisodes();
            epCurrentPage = nextPage;
            renderEpPage();
            epPageChanging = false;
            return;
        }

        epCurrentPage = Math.max(0, Math.min(totalPages - 1, nextPage));
        renderEpPage();
    }

    function showEpisodeByIndex(idx) {
        if (idx < 0 || idx >= currentEpisodes.length) return;
        currentEpIndex = idx;
        const ep = currentEpisodes[idx];
        showEpisodeChart(ep.id, ep.started_at, ep.ended_at || '');
        // Highlight row in current page
        const start = epCurrentPage * EP_PER_PAGE;
        document.querySelectorAll('#episodesBody tr').forEach((r, i) => {
            r.classList.toggle('ep-selected', (start + i) === idx);
        });
        updateEpNavButtons();
    }

    function updateEpSummary(summary) {
        if (!summary) {
            document.getElementById('epTotalCount').textContent = '0';
            document.getElementById('epTotalDuration').textContent = '--';
            document.getElementById('epMinHR').textContent = '--';
            document.getElementById('epLongest').textContent = '--';
            return;
        }
        document.getElementById('epTotalCount').textContent = summary.total_episodes;
        document.getElementById('epTotalDuration').textContent = epFmtDuration(summary.total_duration_seconds);
        document.getElementById('epMinHR').textContent = summary.overall_min_hr || '--';
        document.getElementById('epLongest').textContent = epFmtDuration(summary.longest_episode_seconds);
    }

    async function showEpisodeChart(episodeId, startedAt, endedAt) {
        // ±30sn padding
        const startTs = Math.floor(new Date(startedAt.replace(' ', 'T')).getTime() / 1000) - 30;
        const endTs = endedAt
            ? Math.floor(new Date(endedAt.replace(' ', 'T')).getTime() / 1000) + 30
            : Math.floor(Date.now() / 1000) + 10;

        try {
            const response = await fetch(`/hr2/api/chart-data.php?start=${startTs}&end=${endTs}`);
            const json = await response.json();
            if (!json.success || !json.data) return;

            const d = json.data;
            document.getElementById('episodeChartCard').style.display = 'block';
            const epDate = new Date(startedAt.replace(' ', 'T'));
            const dateStr = `${String(epDate.getDate()).padStart(2,'0')}.${String(epDate.getMonth()+1).padStart(2,'0')}.${epDate.getFullYear()} ${String(epDate.getHours()).padStart(2,'0')}:${String(epDate.getMinutes()).padStart(2,'0')}`;
            document.getElementById('episodeChartTitle').textContent =
                t('ep_chart_title').replace('{id}', episodeId) + ` — ${dateStr}`;

            updateEpNavButtons();

            if (episodeChart) episodeChart.destroy();

            const ctx = document.getElementById('episodeChart').getContext('2d');
            const pointColors = d.heartRate.map(hr => hr < 50 ? '#ff6b6b' : '#2ed573');

            episodeChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: d.labels,
                    datasets: [{
                        label: 'BPM',
                        data: d.heartRate,
                        borderColor: '#ff6b6b',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: pointColors,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: pointColors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            bodyFont: { size: 14, weight: 'bold' },
                            padding: 10,
                            displayColors: false,
                            callbacks: {
                                label: (item) => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg> ${item.raw} BPM`
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#e5e7eb' },
                            ticks: { color: '#9ca3af', maxTicksLimit: 10, maxRotation: 0 }
                        },
                        y: {
                            grid: { color: '#e5e7eb' },
                            ticks: { color: '#9ca3af' },
                            suggestedMin: Math.min(...d.heartRate) - 5,
                            suggestedMax: Math.max(...d.heartRate) + 10
                        }
                    }
                }
            });

            document.getElementById('episodeChartCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        } catch (error) {
            console.error('Episode chart error:', error);
        }
    }

    function closeEpisodeChart() {
        document.getElementById('episodeChartCard').style.display = 'none';
        document.querySelectorAll('#episodesBody tr').forEach(r => r.classList.remove('ep-selected'));
        currentEpIndex = -1;
        if (episodeChart) {
            episodeChart.destroy();
            episodeChart = null;
        }
    }

    function navigateEpisode(dir) {
        // dir: -1 = newer (prev in list), +1 = older (next in list)
        // episodes are sorted DESC so index 0 = newest
        const newIdx = currentEpIndex + dir;
        if (newIdx < 0 || newIdx >= currentEpisodes.length) return;

        // If target is on a different page, switch page
        const targetPage = Math.floor(newIdx / EP_PER_PAGE);
        if (targetPage !== epCurrentPage) {
            epCurrentPage = targetPage;
            renderEpPage();
        }
        showEpisodeByIndex(newIdx);
    }

    function updateEpNavButtons() {
        document.getElementById('epPrevBtn').disabled = currentEpIndex <= 0;
        document.getElementById('epNextBtn').disabled = currentEpIndex >= currentEpisodes.length - 1;
    }

    function epFmtDuration(seconds) {
        if (!seconds || seconds <= 0) return '--';
        if (seconds < 60) return t('ep_seconds').replace('{s}', seconds);
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return t('ep_min_sec').replace('{m}', m).replace('{s}', s);
    }

    function epFmtDt(dt) {
        if (!dt) return '--';
        const pad = n => String(n).padStart(2, '0');
        return `${pad(dt.getDate())}.${pad(dt.getMonth() + 1)} ${pad(dt.getHours())}:${pad(dt.getMinutes())}:${pad(dt.getSeconds())}`;
    }

    // Sort headers
    document.querySelectorAll('.ep-sortable').forEach(th => {
        th.addEventListener('click', function() {
            sortEpisodes(this.dataset.sort);
        });
    });

    // Time range buttons
    document.querySelectorAll('.ep-range-btn[data-hours]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.ep-range-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            closeEpisodeChart();
            // Reset sort to default
            epSortField = 'started_at';
            epSortDir = 'desc';
            document.querySelectorAll('.ep-sortable').forEach(h => h.classList.remove('ep-sort-asc', 'ep-sort-desc'));
            document.querySelector('.ep-sortable[data-sort="started_at"]').classList.add('ep-sort-desc');
            loadEpisodes(parseInt(this.dataset.hours));
        });
    });

    // Filter inputs - debounced
    let epFilterTimer = null;
    document.querySelectorAll('#epFilters input').forEach(inp => {
        inp.addEventListener('input', function() {
            clearTimeout(epFilterTimer);
            epFilterTimer = setTimeout(applyEpFilters, 300);
        });
    });

    // Init
    document.addEventListener('DOMContentLoaded', function() {
        applyLanguage(currentLang);
        loadEpisodes(24);
    });
    </script>
    <?php endif; ?>
</body>
</html>
