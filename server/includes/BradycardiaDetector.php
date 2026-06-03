<?php
/**
 * BradycardiaDetector - Bradycardia episode detection system
 *
 * Groups consecutive readings with HR < 50 BPM as an "episode".
 * Called on every new HR reading (from log.php).
 *
 * Episode: The continuous time period where HR remains below 50 BPM.
 * Episodes lasting a minimum of 5 seconds are reported (API filtering).
 *
 * Usage:
 *   $detector = new BradycardiaDetector($pdo);
 *   $result = $detector->processReading($deviceId, $heartRate, $recordedAt);
 */

class BradycardiaDetector
{
    private PDO $pdo;

    private const THRESHOLD = 50;        // HR threshold value (BPM)
    private const STALE_GAP = 60;        // Active episode closes if no data for this many seconds

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Process new HR reading
     *
     * @param string $deviceId Device MAC address
     * @param int $heartRate Heart Rate value
     * @param string $recordedAt Measurement time (datetime string, ms precision)
     * @return array|null Episode info or null
     */
    public function processReading(string $deviceId, int $heartRate, string $recordedAt): ?array
    {
        // Is there an active episode?
        $active = $this->getActiveEpisode($deviceId);

        // Stale check: if active episode exists but no data for a long time, close it
        if ($active && $this->isStale($active, $recordedAt)) {
            $this->closeEpisode($active['id'], null, null);
            $active = null;
        }

        if ($heartRate < self::THRESHOLD) {
            // HR low — start or expand episode
            if ($active) {
                return $this->extendEpisode($active, $heartRate, $recordedAt);
            } else {
                return $this->startEpisode($deviceId, $heartRate, $recordedAt);
            }
        } else {
            // HR normal — close active episode if exists
            if ($active) {
                return $this->completeEpisode($active, $heartRate, $recordedAt);
            }
            return null;
        }
    }

    /**
     * Get active episode for this device
     */
    private function getActiveEpisode(string $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bradycardia_episodes WHERE device_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Is the active episode stale? (60+ seconds between last update and new reading)
     */
    private function isStale(array $episode, string $recordedAt): bool
    {
        $lastTime = $episode['ended_at'] ?? $episode['started_at'];
        $lastTs = strtotime($lastTime);
        $newTs = strtotime($recordedAt);
        return ($newTs - $lastTs) > self::STALE_GAP;
    }

    /**
     * Start a new episode
     */
    private function startEpisode(string $deviceId, int $heartRate, string $recordedAt): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bradycardia_episodes
             (device_id, status, started_at, ended_at, reading_count, min_hr, max_hr, avg_hr, hr_sum)
             VALUES (?, 'active', ?, ?, 1, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $deviceId,
            $recordedAt,
            $recordedAt,
            $heartRate,
            $heartRate,
            $heartRate,
            $heartRate
        ]);

        return [
            'action' => 'started',
            'episode_id' => (int) $this->pdo->lastInsertId(),
            'heart_rate' => $heartRate,
        ];
    }

    /**
     * Extend current episode
     */
    private function extendEpisode(array $episode, int $heartRate, string $recordedAt): array
    {
        $newCount = $episode['reading_count'] + 1;
        $newSum = $episode['hr_sum'] + $heartRate;

        $stmt = $this->pdo->prepare(
            "UPDATE bradycardia_episodes SET
                started_at = LEAST(started_at, ?),
                ended_at = GREATEST(ended_at, ?),
                reading_count = ?,
                min_hr = LEAST(min_hr, ?),
                max_hr = GREATEST(max_hr, ?),
                hr_sum = ?,
                avg_hr = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $recordedAt,
            $recordedAt,
            $newCount,
            $heartRate,
            $heartRate,
            $newSum,
            round($newSum / $newCount, 2),
            $episode['id']
        ]);

        return [
            'action' => 'extended',
            'episode_id' => (int) $episode['id'],
            'reading_count' => $newCount,
            'heart_rate' => $heartRate,
        ];
    }

    /**
     * Complete the episode (HR returned to normal)
     */
    private function completeEpisode(array $episode, int $recoveryHr, string $recoveryAt): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE bradycardia_episodes SET
                status = 'completed',
                duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, ended_at)),
                recovery_hr = ?,
                recovery_at = ?
             WHERE id = ?"
        );
        $stmt->execute([$recoveryHr, $recoveryAt, $episode['id']]);

        // calculate duration
        $duration = max(0, strtotime($episode['ended_at'] ?? $episode['started_at']) - strtotime($episode['started_at']));

        return [
            'action' => 'completed',
            'episode_id' => (int) $episode['id'],
            'duration_seconds' => $duration,
            'reading_count' => (int) $episode['reading_count'],
            'min_hr' => (int) $episode['min_hr'],
            'recovery_hr' => $recoveryHr,
        ];
    }

    /**
     * Close stale episode (due to no data)
     */
    private function closeEpisode(int $episodeId, ?int $recoveryHr, ?string $recoveryAt): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE bradycardia_episodes SET
                status = 'completed',
                duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, started_at))),
                recovery_hr = ?,
                recovery_at = ?
             WHERE id = ?"
        );
        $stmt->execute([$recoveryHr, $recoveryAt, $episodeId]);
    }
}
