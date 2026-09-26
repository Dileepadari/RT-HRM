<?php
/**
 * GET /hr/api/episodes.php
 * Fetch Bradycardia Episodes (HR < 50 BPM, 5+ seconds)
 *
 * Query params:
 *   hours        - How many hours to look back (default: 24, max: 8760)
 *   date_from    - Start Date (YYYY-MM-DD)
 *   date_to      - End Date (YYYY-MM-DD)
 *   min_duration - Minimum duration filter in seconds (default: 5)
 *   limit        - Result limit (default: 500, max: 5000)
 *   offset       - Pagination offset (default: 0)
 *   sort         - Sort field (id, started_at, ended_at, duration_seconds, min_hr, avg_hr, recovery_hr)
 *   sort_dir     - Sort direction (asc, desc)
 *   search       - Search term (ID, Date, HR values)
 */

require_once __DIR__ . '/../config.php';

requireReadAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$deviceId    = $_GET['device_id'] ?? getDefaultDevice();
$dateFrom    = $_GET['date_from'] ?? null;
$dateTo      = $_GET['date_to'] ?? null;
$minDuration = max(1, (int)($_GET['min_duration'] ?? 5));
$limit       = min(5000, max(1, (int)($_GET['limit'] ?? 500)));
$offset      = max(0, (int)($_GET['offset'] ?? 0));
$search      = trim($_GET['search'] ?? '');

try {
    $pdo = getDB();

    // --- Common WHERE conditions ---

    // 1. Date range
    if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateCondition = "started_at >= ?";
        $dateParams = [$dateFrom . ' 00:00:00'];
        if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateCondition = "started_at >= ? AND started_at < DATE_ADD(?, INTERVAL 1 DAY)";
            $dateParams = [$dateFrom . ' 00:00:00', $dateTo . ' 00:00:00'];
        }
    } else {
        $hours = min(8760, max(1, (int)($_GET['hours'] ?? 24)));
        $dateCondition = "started_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $dateParams = [$hours];
    }

    // 2. Base WHERE (common for all queries)
    $baseWhere = "$dateCondition
              AND ((status = 'completed' AND duration_seconds >= ?) OR status = 'active')";
    $baseParams = array_merge($dateParams, [$minDuration]);

    // 3. Device filter
    $extraWhere = '';
    $extraParams = [];
    if ($deviceId) {
        $extraWhere .= " AND device_id = ?";
        $extraParams[] = strtoupper($deviceId);
    }

    // 4. Client filters (duration, min HR, avg HR)
    $filterDur = (int)($_GET['filter_dur'] ?? 0);
    $filterMinHR = (int)($_GET['filter_min_hr'] ?? 0);
    $filterAvgHR = (float)($_GET['filter_avg_hr'] ?? 0);

    if ($filterDur > 0) {
        $extraWhere .= " AND duration_seconds >= ?";
        $extraParams[] = $filterDur;
    }
    if ($filterMinHR > 0) {
        $extraWhere .= " AND min_hr <= ?";
        $extraParams[] = $filterMinHR;
    }
    if ($filterAvgHR > 0) {
        $extraWhere .= " AND avg_hr <= ?";
        $extraParams[] = $filterAvgHR;
    }

    // 5. Search filter
    if ($search !== '') {
        $searchNum = preg_replace('/[^0-9.]/', '', $search);
        $sConds = [];
        $sParams = [];
        // ID
        if ($searchNum !== '' && ctype_digit($searchNum)) {
            $sConds[] = "CAST(id AS CHAR) LIKE ?";
            $sParams[] = "%$searchNum%";
        }
        // Date
        $sConds[] = "started_at LIKE ?";
        $sParams[] = "%$search%";
        $sConds[] = "ended_at LIKE ?";
        $sParams[] = "%$search%";
        // HR values
        if ($searchNum !== '') {
            $sConds[] = "CAST(min_hr AS CHAR) = ?";
            $sParams[] = $searchNum;
            $sConds[] = "CAST(avg_hr AS CHAR) LIKE ?";
            $sParams[] = "$searchNum%";
            $sConds[] = "CAST(recovery_hr AS CHAR) = ?";
            $sParams[] = $searchNum;
            $sConds[] = "CAST(duration_seconds AS CHAR) = ?";
            $sParams[] = $searchNum;
        }
        $extraWhere .= " AND (" . implode(' OR ', $sConds) . ")";
        $extraParams = array_merge($extraParams, $sParams);
    }

    // --- Main query ---
    $sql = "SELECT id, device_id, status, started_at, ended_at, duration_seconds,
                   reading_count, min_hr, max_hr, avg_hr, recovery_hr, recovery_at
            FROM bradycardia_episodes
            WHERE $baseWhere $extraWhere";
    $params = array_merge($baseParams, $extraParams);

    // Sorting
    $allowedSorts = ['id', 'started_at', 'ended_at', 'duration_seconds', 'min_hr', 'avg_hr', 'recovery_hr'];
    $sortField = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'started_at';
    $sortDir = ($_GET['sort_dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $sql .= " ORDER BY $sortField $sortDir LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $episodes = $stmt->fetchAll();

    foreach ($episodes as &$ep) {
        $ep['id'] = (int)$ep['id'];
        $ep['duration_seconds'] = $ep['duration_seconds'] !== null ? (int)$ep['duration_seconds'] : null;
        $ep['reading_count'] = (int)$ep['reading_count'];
        $ep['min_hr'] = (int)$ep['min_hr'];
        $ep['max_hr'] = (int)$ep['max_hr'];
        $ep['avg_hr'] = (float)$ep['avg_hr'];
        $ep['recovery_hr'] = $ep['recovery_hr'] !== null ? (int)$ep['recovery_hr'] : null;
    }
    unset($ep);

    // --- Total Record count (same WHERE, no LIMIT) ---
    $sqlCount = "SELECT COUNT(*) FROM bradycardia_episodes WHERE $baseWhere $extraWhere";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute(array_merge($baseParams, $extraParams));
    $totalEpisodes = (int)$stmtCount->fetchColumn();

    // --- Summary Statistics (includes search, only completed) ---
    $summaryWhere = str_replace(
        "((status = 'completed' AND duration_seconds >= ?) OR status = 'active')",
        "status = 'completed' AND duration_seconds >= ?",
        "$baseWhere $extraWhere"
    );
    $sqlSummary = "SELECT
                       COUNT(*) as total_episodes,
                       COALESCE(SUM(duration_seconds), 0) as total_duration_seconds,
                       COALESCE(MIN(min_hr), 0) as overall_min_hr,
                       COALESCE(MAX(duration_seconds), 0) as longest_episode_seconds,
                       COALESCE(ROUND(AVG(duration_seconds)), 0) as avg_episode_duration
                   FROM bradycardia_episodes
                   WHERE $summaryWhere";

    $stmtSummary = $pdo->prepare($sqlSummary);
    $stmtSummary->execute(array_merge($baseParams, $extraParams));
    $summary = $stmtSummary->fetch();

    $summary['total_episodes'] = (int)$summary['total_episodes'];
    $summary['total_duration_seconds'] = (int)$summary['total_duration_seconds'];
    $summary['overall_min_hr'] = (int)$summary['overall_min_hr'];
    $summary['longest_episode_seconds'] = (int)$summary['longest_episode_seconds'];
    $summary['avg_episode_duration'] = (int)$summary['avg_episode_duration'];

    jsonResponse([
        'success'   => true,
        'count'     => count($episodes),
        'total'     => $totalEpisodes,
        'offset'    => $offset,
        'limit'     => $limit,
        'has_more'  => ($offset + count($episodes)) < $totalEpisodes,
        'episodes'  => $episodes,
        'summary'   => $summary,
    ]);

} catch (PDOException $e) {
    error_log("Episodes API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Database error'], 500);
}
