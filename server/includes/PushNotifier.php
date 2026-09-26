<?php
/**
 * PushNotifier - Firebase Cloud Messaging (FCM) HTTP v1 API
 *
 * Sends push notifications when an arrhythmia is detected.
 * Uses FCM HTTP v1 API (OAuth2 Service Account auth).
 */

class PushNotifier
{
    private PDO $pdo;
    private ?string $projectId;
    private ?string $serviceAccountPath;

    // Severity -> notification priority mapping
    private const PRIORITY_MAP = [
        'info'     => 'default',
        'warning'  => 'high',
        'critical' => 'high',
    ];

    // Severity -> Notification title
    private const TITLE_MAP = [
        'info'     => 'जानकारी',
        'warning'  => 'चेतावनी',
        'critical' => 'गंभीर चेतावनी',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->projectId = getenv('HR_FCM_PROJECT_ID') ?: null;
        $this->serviceAccountPath = getenv('HR_FCM_SERVICE_ACCOUNT') ?: null;
    }

    /**
     * Send push notification to all active tokens
     */
    public function send(string $message, string $eventType, string $severity, ?int $heartRate = null): int
    {
        if (!$this->projectId || !$this->serviceAccountPath) {
            return 0;
        }

        $tokens = $this->getActiveTokens();
        if (empty($tokens)) {
            return 0;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            error_log("PushNotifier: Failed to retrieve OAuth2 access token");
            return 0;
        }

        $sent = 0;
        foreach ($tokens as $token) {
            $success = $this->sendToToken($accessToken, $token['fcm_token'], $message, $eventType, $severity, $heartRate);
            if ($success) {
                $sent++;
            } else {
                // Deactivate invalid tokens
                $this->deactivateToken($token['fcm_token']);
            }
        }

        return $sent;
    }

    private function sendToToken(string $accessToken, string $fcmToken, string $message, string $eventType, string $severity, ?int $heartRate): bool
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $title = self::TITLE_MAP[$severity] ?? 'Bildirim';

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $message,
                ],
                'data' => [
                    'event_type' => $eventType,
                    'severity'   => $severity,
                    'heart_rate' => (string)($heartRate ?? ''),
                    'timestamp'  => (string)time(),
                ],
                'android' => [
                    'priority' => self::PRIORITY_MAP[$severity] ?? 'default',
                    'notification' => [
                        'channel_id'       => 'heart_alert_channel_v3',
                        'default_sound'    => ($severity === 'critical'),
                        'notification_priority' => ($severity === 'critical') ? 'PRIORITY_MAX' : 'PRIORITY_HIGH',
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return true;
        }

        error_log("PushNotifier: FCM sending error (HTTP $httpCode): $response");

        // 404 or UNREGISTERED -> token is no longer valid
        if ($httpCode === 404 || str_contains($response, 'UNREGISTERED')) {
            return false;
        }

        return true; // Do not delete token for other errors
    }

    /**
     * Get access token using Google OAuth2 Service Account
     */
    private function getAccessToken(): ?string
    {
        if (!file_exists($this->serviceAccountPath)) {
            error_log("PushNotifier: Service account file not found: {$this->serviceAccountPath}");
            return null;
        }

        $sa = json_decode(file_get_contents($this->serviceAccountPath), true);
        if (!$sa || !isset($sa['client_email'], $sa['private_key'], $sa['token_uri'])) {
            error_log("PushNotifier: Invalid service account file");
            return null;
        }

        // Generate JWT
        $now = time();
        $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = base64url_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $sa['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signatureInput = "$header.$claim";
        $signature = '';
        $privateKey = openssl_pkey_get_private($sa['private_key']);
        if (!$privateKey) {
            error_log("PushNotifier: Failed to parse private key");
            return null;
        }
        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $jwt = $signatureInput . '.' . base64url_encode($signature);

        // Token exchange
        $ch = curl_init($sa['token_uri']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $response['access_token'] ?? null;
    }

    private function getActiveTokens(): array
    {
        $stmt = $this->pdo->prepare("SELECT fcm_token FROM push_tokens WHERE is_active = 1");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function deactivateToken(string $fcmToken): void
    {
        $stmt = $this->pdo->prepare("UPDATE push_tokens SET is_active = 0 WHERE fcm_token = ?");
        $stmt->execute([$fcmToken]);
    }
}

/**
 * URL-safe base64 encoding (for JWT)
 */
if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
