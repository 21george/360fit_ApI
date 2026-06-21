<?php
declare(strict_types=1);

namespace App\Services;

class FcmService
{
    private static ?string $cachedToken = null;
    private static int $tokenExpiry = 0;

    /**
     * Get OAuth2 access token from FCM service account JSON.
     */
    private static function getAccessToken(): string
    {
        if (self::$cachedToken && time() < self::$tokenExpiry) {
            return self::$cachedToken;
        }

        $saPath = $_ENV['FCM_SERVICE_ACCOUNT_PATH'] ?? '';
        if (empty($saPath) || !file_exists($saPath)) {
            throw new \RuntimeException('FCM_SERVICE_ACCOUNT_PATH not set or file not found');
        }

        $sa = json_decode(file_get_contents($saPath), true);
        if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new \RuntimeException('Invalid FCM service account JSON');
        }

        $now = time();
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = base64_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $toSign = "{$header}.{$claim}";
        openssl_sign($toSign, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = "{$toSign}." . base64_encode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException('Failed to obtain FCM access token');
        }

        $data = json_decode($result, true);
        self::$cachedToken = $data['access_token'];
        self::$tokenExpiry = $now + ($data['expires_in'] ?? 3500) - 60;

        return self::$cachedToken;
    }

    public static function send(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        $projectId = $_ENV['FCM_PROJECT_ID'] ?? '';
        $saPath = $_ENV['FCM_SERVICE_ACCOUNT_PATH'] ?? '';
        if (empty($projectId) || empty($fcmToken) || empty($saPath)) return false;

        try {
            $accessToken = self::getAccessToken();
        } catch (\RuntimeException $e) {
            error_log('FCM auth error: ' . $e->getMessage());
            return false;
        }

        $payload = json_encode([
            'message' => [
                'token'        => $fcmToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => array_map('strval', $data),
                'android'      => ['priority' => 'high'],
                'apns'         => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ]);

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$accessToken}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            error_log("FCM send failed (HTTP {$code}): {$result}");
        }

        return $code === 200;
    }

    public static function notifyNewMessage(string $fcmToken, string $coachName): void
    {
        self::send($fcmToken, 'New Message', "New message from {$coachName}", ['type' => 'message']);
    }

    public static function notifyCheckin(string $fcmToken, string $datetime): void
    {
        self::send($fcmToken, 'Meeting Scheduled', "Check-in scheduled for {$datetime}", ['type' => 'checkin']);
    }

    public static function notifyNewPlan(string $fcmToken): void
    {
        self::send($fcmToken, 'New Workout Plan', 'Your new workout plan is ready!', ['type' => 'workout_plan']);
    }
}
