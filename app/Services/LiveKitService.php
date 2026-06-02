<?php
declare(strict_types=1);

namespace App\Services;

/**
 * LiveKit JWT token generation for 1-on-1 coaching sessions.
 *
 * Required environment variables:
 *   LIVEKIT_URL        wss://your-host.livekit.cloud
 *   LIVEKIT_API_KEY    your_api_key
 *   LIVEKIT_API_SECRET your_api_secret
 *
 * LiveKit uses HMAC-SHA256 (HS256) signed JWTs.
 * Docs: https://docs.livekit.io/server/generating-tokens/
 */
class LiveKitService
{
    /** Token validity — long enough to cover any session (6 h). */
    private const TTL_SECONDS = 21_600;

    /**
     * Generate a LiveKit participant access token.
     *
     * @param string $identity  Unique participant ID (e.g. "coach_<id>")
     * @param string $name      Display name shown in the room
     * @param string $roomName  LiveKit room identifier
     * @param bool   $canPublish   Allow camera / microphone publishing
     * @param bool   $canSubscribe Allow receiving other participants' tracks
     */
    public static function createToken(
        string $identity,
        string $name,
        string $roomName,
        bool $canPublish   = true,
        bool $canSubscribe = true
    ): string {
        $apiKey    = $_ENV['LIVEKIT_API_KEY']    ?? '';
        $apiSecret = $_ENV['LIVEKIT_API_SECRET'] ?? '';

        if ($apiKey === '' || $apiSecret === '') {
            throw new \RuntimeException('LIVEKIT_API_KEY and LIVEKIT_API_SECRET must be set in .env');
        }

        $now = time();

        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $payload = self::b64url(json_encode([
            'iss'      => $apiKey,
            'sub'      => $identity,
            'iat'      => $now,
            'exp'      => $now + self::TTL_SECONDS,
            'jti'      => bin2hex(random_bytes(8)),
            'name'     => $name,
            'video'    => [
                'room'           => $roomName,
                'roomJoin'       => true,
                'canPublish'     => $canPublish,
                'canSubscribe'   => $canSubscribe,
                'canPublishData' => true,      // data channel for in-room chat
            ],
        ]));

        $signingInput = "{$header}.{$payload}";
        $signature    = self::b64url(hash_hmac('sha256', $signingInput, $apiSecret, true));

        return "{$signingInput}.{$signature}";
    }

    /**
     * Derive a deterministic room name from a coaching session ObjectId.
     * Prefixed so names are identifiable in the LiveKit dashboard.
     */
    public static function roomName(string $sessionId): string
    {
        return "coaching_{$sessionId}";
    }

    /** Base64url encoding (RFC 4648 §5), no padding. */
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
