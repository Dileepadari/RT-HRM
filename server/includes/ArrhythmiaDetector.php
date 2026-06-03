<?php
/**
 * ArrhythmiaDetector - HR (heart rate) based arrhythmia detection system
 *
 * HR data arrives from BLE sensor at 1Hz. Trend analysis on these values
 * detects arrhythmias without requiring true RR interval data.
 *
 * Supported detections:
 * - Sinus Bradycardia (HR < 60, regular)
 * - Sinus Tachycardia (HR > 100, regular)
 * - AF Suspect (Very high HR variation - irregular rhythm)
 * - SVT Suspect (Sudden HR jump > 150)
 * - Atrial Flutter Suspect (Constant HR ~150, very regular)
 *
 * Threshold references:
 * - Tateno & Glass 2001: CV-based AF detection (sens 86.6%, spec 84.3%)
 * - Dash et al. 2009: RMSSD + TPR + Shannon Entropy (sens 94.4%, spec 95.1%)
 * - Shaffer & Ginsberg 2017 (PMC5624990): HRV metrics and normal ranges
 * - Monfredi et al. 2014 (PMC4253727): HR<->RR domain conversion relationship
 * - Zhou et al. 2015 (PMC4573734): Shannon Entropy threshold
 * - StatPearls SVT: HR > 150 BPM clinical definition
 * - LITFL: Atrial Flutter 2:1 block = ~150 BPM
 *
 * Note: AF detection uses pseudo-RR (60000/HR ms) conversion.
 * This is not true beat-to-beat RR (due to 1Hz BLE polling),
 * thus AF/SVT/Flutter detections are reported with max 70% confidence.
 */

class ArrhythmiaDetector
{
    private PDO $pdo;

    // Minimum HR Records count
    private const MIN_HR_COUNT = 15;

    // Deduplication cooldown times (seconds)
    private const COOLDOWNS = [
        'sinus_bradycardia'   => 120,
        'sinus_tachycardia'   => 120,
        'af_suspect'          => 300,
        'svt_suspect'         => 300,
        'flutter_suspect'     => 300,
    ];

    // Arrhythmia names
    private const LABELS = [
        'sinus_bradycardia'   => 'साइनस ब्रैडीकार्डिया',
        'sinus_tachycardia'   => 'साइनस टैचीकार्डिया',
        'af_suspect'          => 'एएफ संदेह (अनियमित लय)',
        'svt_suspect'         => 'एसवीटी संदेह',
        'flutter_suspect'     => 'एट्रियल फ्लटर संदेह',
    ];

    // Max confidence for HR-derived detections (AF, SVT, Flutter)
    private const MAX_HR_CONFIDENCE = 70.0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Main analysis function
     * @return array Detected arrhythmia events
     */
    public function analyze(string $deviceId): array
    {
        // Fetch last 60 HR values from DB
        $hrWindow = $this->getHRWindow($deviceId, 60);
        if (count($hrWindow) < self::MIN_HR_COUNT) {
            return [];
        }

        // Run detection algorithms
        $detectors = [
            'sinus_bradycardia' => 'detectSinusBradycardia',
            'sinus_tachycardia' => 'detectSinusTachycardia',
            'af_suspect'        => 'detectAFSuspect',
            'svt_suspect'       => 'detectSVTSuspect',
            'flutter_suspect'   => 'detectFlutterSuspect',
        ];

        $events = [];
        foreach ($detectors as $type => $method) {
            $result = $this->$method($hrWindow);
            if ($result === null) {
                continue;
            }

            // Deduplication check
            if ($this->isDuplicate($deviceId, $type)) {
                continue;
            }

            // Save to DB
            $eventId = $this->saveEvent($deviceId, $type, $result, $hrWindow);
            $result['id'] = $eventId;
            $result['event_type'] = $type;
            $events[] = $result;
        }

        return $events;
    }

    // =========================================================================
    // HR Window Collection
    // =========================================================================

    /**
     * Fetch last N HR values from DB (where sensor_contact = 1)
     * @return array HR values (chronological order)
     */
    private function getHRWindow(string $deviceId, int $size): array
    {
        // Device artifact filter: exclude fixed HR value sent during connection loss
        $artifactFilter = '';
        $artifactHR = getDeviceArtifactHR($deviceId);
        if ($artifactHR !== null) {
            $artifactFilter = ' AND heart_rate != ' . (int)$artifactHR;
        }

        $sql = "SELECT heart_rate FROM heart_rate_logs
                WHERE device_id = ? AND sensor_contact = 1
                  AND recorded_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  {$artifactFilter}
                ORDER BY recorded_at DESC
                LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$deviceId, $size]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Convert to chronological order
        return array_reverse(array_map('intval', $rows));
    }

    // =========================================================================
    // Statistical Helpers
    // =========================================================================

    private function calcMean(array $arr): float
    {
        return array_sum($arr) / count($arr);
    }

    private function calcMedian(array $arr): float
    {
        $sorted = $arr;
        sort($sorted);
        $n = count($sorted);
        $mid = (int)floor($n / 2);
        return ($n % 2 === 0) ? ($sorted[$mid - 1] + $sorted[$mid]) / 2.0 : $sorted[$mid];
    }

    private function calcStdDev(array $arr): float
    {
        if (count($arr) < 2) return 0;
        $mean = $this->calcMean($arr);
        $sumSqDiff = 0.0;
        foreach ($arr as $v) {
            $sumSqDiff += ($v - $mean) ** 2;
        }
        return sqrt($sumSqDiff / (count($arr) - 1));
    }

    /** CV - Coefficient of Variation (%) */
    private function calcCV(array $arr): float
    {
        $mean = $this->calcMean($arr);
        return ($mean > 0) ? ($this->calcStdDev($arr) / $mean) * 100 : 0;
    }

    /** RMSSD of successive HR differences (HR-BPM domain) */
    private function calcSuccessiveDiffRMSSD(array $hr): float
    {
        if (count($hr) < 2) return 0;
        $diffs = [];
        for ($i = 1; $i < count($hr); $i++) {
            $diffs[] = ($hr[$i] - $hr[$i - 1]) ** 2;
        }
        return sqrt($this->calcMean($diffs));
    }

    /**
     * Convert HR values to pseudo-RR and calculate RMSSD (in ms)
     * Source: Monfredi et al. 2014 (PMC4253727) - Due to non-linear relationship
     * between HR and RR domains, calculating RMSSD in RR domain is more accurate.
     * pseudo-RR = 60000 / HR (ms)
     * This allows direct use of standard thresholds from literature (80-100ms).
     */
    private function calcRMSSD_pseudoRR(array $hr): float
    {
        if (count($hr) < 2) return 0;

        // HR -> pseudo-RR conversion (ms)
        $rr = [];
        foreach ($hr as $h) {
            if ($h > 0) $rr[] = 60000.0 / $h;
        }
        if (count($rr) < 2) return 0;

        // RMSSD of successive differences (in ms)
        $diffs = [];
        for ($i = 1; $i < count($rr); $i++) {
            $diffs[] = ($rr[$i] - $rr[$i - 1]) ** 2;
        }
        return sqrt($this->calcMean($diffs));
    }

    /** TPR - Turning Point Ratio */
    private function calcTPR(array $arr): float
    {
        if (count($arr) < 3) return 0;

        $turningPoints = 0;
        for ($i = 1; $i < count($arr) - 1; $i++) {
            if (($arr[$i] > $arr[$i - 1] && $arr[$i] > $arr[$i + 1]) ||
                ($arr[$i] < $arr[$i - 1] && $arr[$i] < $arr[$i + 1])) {
                $turningPoints++;
            }
        }
        return $turningPoints / (count($arr) - 2);
    }

    // =========================================================================
    // Detection Algorithms
    // =========================================================================

    /**
     * Sinus Bradycardia - HR < 50, regular rhythm
     * Reliability: HIGH (detected directly from HR)
     * Source: 2018 ACC/AHA/HRS Guideline (threshold 50 BPM), Tateno & Glass 2001, Shaffer & Ginsberg 2017
     * Note: Traditional threshold was 60 BPM; current guidelines revised to 50 BPM.
     *       50-60 BPM is a normal resting heart rate in a large population.
     */
    private function detectSinusBradycardia(array $hrValues): ?array
    {
        $window = array_slice($hrValues, -15);
        if (count($window) < 15) return null;

        $avgHR = $this->calcMean($window);
        $cv = $this->calcCV($window);

        // HR < 50 AND regular (CV < 10% - literature: normal sinus rhythm CV 2-8%)
        // 2018 ACC/AHA/HRS guideline: bradycardia threshold 50 BPM (old: 60)
        if ($avgHR >= 50 || $cv >= 10) return null;

        // False-positive filter: BLE device on connection loss / reconnect sends
        // a fixed (flat) HR value (e.g. flat 30 BPM, sometimes in transition [41,30,30,...]).
        // In true bradycardia, heartbeat shows natural variation (at least 4-5 unique values).
        // 3 or fewer unique values in 15 samples → device artifact.
        $uniqueCount = count(array_unique($window));
        if ($uniqueCount <= 3) return null;

        // Severity belirleme
        if ($avgHR < 35) {
            $severity = 'critical';
        } elseif ($avgHR < 42) {
            $severity = 'warning';
        } else {
            $severity = 'info';
        }

        $confidence = min(95, (50 - $avgHR) * 4 + 50);

        return [
            'severity'   => $severity,
            'confidence' => round($confidence, 2),
            'heart_rate' => round($avgHR),
            'metrics'    => [
                'avg_hr' => round($avgHR, 1),
                'min_hr' => min($window),
                'max_hr' => max($window),
                'cv'     => round($cv, 2),
            ],
            'message' => self::LABELS['sinus_bradycardia'] . " - " . round($avgHR) . " BPM",
        ];
    }

    /**
     * Sinus Tachycardia - HR > 100, regular rhythm
     * Reliability: HIGH (detected directly from HR)
     * Source: claude.md (CV < 10%), Tateno & Glass 2001, Shaffer & Ginsberg 2017
     */
    private function detectSinusTachycardia(array $hrValues): ?array
    {
        $window = array_slice($hrValues, -15);
        if (count($window) < 15) return null;

        $avgHR = $this->calcMean($window);
        $cv = $this->calcCV($window);

        // HR > 100 AND regular (CV < 10% - literature: normal sinus rhythm CV 2-8%)
        if ($avgHR <= 100 || $cv >= 10) return null;

        // Severity
        if ($avgHR > 150) {
            $severity = 'critical';
        } elseif ($avgHR > 120) {
            $severity = 'warning';
        } else {
            $severity = 'info';
        }

        $confidence = min(95, ($avgHR - 100) * 1.5 + 50);

        return [
            'severity'   => $severity,
            'confidence' => round($confidence, 2),
            'heart_rate' => round($avgHR),
            'metrics'    => [
                'avg_hr' => round($avgHR, 1),
                'min_hr' => min($window),
                'max_hr' => max($window),
                'cv'     => round($cv, 2),
            ],
            'message' => self::LABELS['sinus_tachycardia'] . " - " . round($avgHR) . " BPM",
        ];
    }

    /**
     * AF Suspect - very high HR variability (irregular rhythm)
     *
     * In normal sinus rhythm, consecutive HR values change gradually (62→63→64).
     * In AF, random jumps are observed (62→78→55→91).
     *
     * Metrics (Dash et al. 2009: 94.4% sensitivity, 95.1% specificity):
     * - CV > 15% (Tateno & Glass 2001 - dimensionless, transferable between HR/RR domains)
     * - pseudo-RR RMSSD > 80ms (claude.md + Shaffer 2017: normal 19-75ms, AF >100ms)
     * - TPR > 0.65 (Dash et al. 2009 - rank-based, domain-independent)
     *
     * Reliability: MAX MEDIUM (pseudo-RR from 1Hz HR data, not true beat-to-beat)
     */
    private function detectAFSuspect(array $hrValues): ?array
    {
        if (count($hrValues) < 30) return null;
        $window = array_slice($hrValues, -30);

        $avgHR = $this->calcMean($window);
        $cv = $this->calcCV($window);
        $rmssd_rr = $this->calcRMSSD_pseudoRR($window); // in ms
        $tpr = $this->calcTPR($window);

        // Score system: at least 2 of 3 metrics must be met (Dash et al. 2009)
        $score = 0;
        if ($cv > 15)        $score++;  // Tateno & Glass 2001: AF CV > %15-20
        if ($rmssd_rr > 80)  $score++;  // claude.md: RMSSD > 80ms, literature: AF >100ms
        if ($tpr > 0.65)     $score++;  // Dash 2009: random process TPR ≈ 0.667

        if ($score < 2) return null;

        // Avoid confusion with tachycardia or bradycardia
        // AF usually occurs in normal-high HR range
        if ($avgHR < 50 || $avgHR > 160) return null;

        $confidence = min(self::MAX_HR_CONFIDENCE, ($score / 3) * self::MAX_HR_CONFIDENCE);

        return [
            'severity'   => 'warning',
            'confidence' => round($confidence, 2),
            'heart_rate' => round($avgHR),
            'metrics'    => [
                'avg_hr'      => round($avgHR, 1),
                'cv'          => round($cv, 2),
                'rmssd_rr_ms' => round($rmssd_rr, 2),
                'tpr'         => round($tpr, 3),
                'score'       => "$score/3",
                'source'      => 'pseudo_rr',
            ],
            'message' => self::LABELS['af_suspect'] . " (विश्वसनीयता: " . round($confidence) . "%)",
        ];
    }

    /**
     * SVT Suspect - Sudden HR jump, very high and regular
     *
     * SVT: HR normal in previous 15s → suddenly >150 BPM + regular
     * Source: claude.md (sudden onset > 40 BPM), StatPearls SVT (HR > 150 BPM),
     *         ICD discrimination algorithms (Guidant/Ventak: 9% RR variation)
     *
     * Reliability: MAX MEDIUM
     */
    private function detectSVTSuspect(array $hrValues): ?array
    {
        if (count($hrValues) < 20) return null;

        // Last 10 values (~10 seconds)
        $recent = array_slice($hrValues, -10);
        $avgRecent = $this->calcMean($recent);
        $cvRecent = $this->calcCV($recent);

        // HR > 150 AND regular (CV < 8%) - claude.md + StatPearls
        if ($avgRecent <= 150 || $cvRecent >= 8) return null;

        // Previous 10 values (10-20 seconds ago)
        $prev = array_slice($hrValues, -20, 10);
        $avgPrev = $this->calcMean($prev);

        // Sudden Onset: at least 40 BPM sudden increase (claude.md: > 40 BPM)
        $hrJump = $avgRecent - $avgPrev;
        if ($hrJump < 40) return null;

        $confidence = min(self::MAX_HR_CONFIDENCE, $hrJump * 0.7 + 20);

        return [
            'severity'   => 'critical',
            'confidence' => round($confidence, 2),
            'heart_rate' => round($avgRecent),
            'metrics'    => [
                'avg_hr_recent' => round($avgRecent, 1),
                'avg_hr_prev'   => round($avgPrev, 1),
                'hr_jump'       => round($hrJump, 1),
                'cv_recent'     => round($cvRecent, 2),
                'source'        => 'hr_trend',
            ],
            'message' => self::LABELS['svt_suspect'] . " - " . round($avgRecent) . " BPM (sudden jump +" . round($hrJump) . ")",
        ];
    }

    /**
     * Atrial Flutter Suspect - HR 140-160, very regular
     *
     * 2:1 Flutter block typically gives ~150 BPM and is very regular.
     * Source: claude.md (CV < 5%), LITFL Atrial Flutter ECG Library,
     *         clinical definition: atrial rate 300 BPM, 2:1 block = ~150 BPM
     *
     * Reliability: MAX MEDIUM
     */
    private function detectFlutterSuspect(array $hrValues): ?array
    {
        if (count($hrValues) < 20) return null;
        $window = array_slice($hrValues, -20);

        $avgHR = $this->calcMean($window);
        $cv = $this->calcCV($window);

        // Flutter: 140-160 BPM AND very regular (CV < 5% - claude.md + LITFL)
        if ($avgHR < 140 || $avgHR > 160) return null;
        if ($cv >= 5) return null;

        $confidence = min(self::MAX_HR_CONFIDENCE, (5 - $cv) * 12 + 30);

        return [
            'severity'   => 'warning',
            'confidence' => round($confidence, 2),
            'heart_rate' => round($avgHR),
            'metrics'    => [
                'avg_hr' => round($avgHR, 1),
                'cv'     => round($cv, 2),
                'source' => 'hr_trend',
            ],
            'message' => self::LABELS['flutter_suspect'] . " - " . round($avgHR) . " BPM",
        ];
    }

    // =========================================================================
    // Records ve Deduplication
    // =========================================================================

    private function isDuplicate(string $deviceId, string $eventType): bool
    {
        $cooldown = self::COOLDOWNS[$eventType] ?? 300;

        $sql = "SELECT id FROM arrhythmia_events
                WHERE device_id = ? AND event_type = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$deviceId, $eventType, $cooldown]);

        return (bool)$stmt->fetch();
    }

    private function saveEvent(string $deviceId, string $eventType, array $result, array $hrWindow): int
    {
        $sql = "INSERT INTO arrhythmia_events
                (device_id, event_type, severity, confidence, heart_rate, metrics, rr_window, window_size, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $deviceId,
            $eventType,
            $result['severity'],
            $result['confidence'],
            $result['heart_rate'] ?? null,
            json_encode($result['metrics'] ?? []),
            json_encode($hrWindow),
            count($hrWindow),
            $result['message'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
