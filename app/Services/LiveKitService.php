<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;

/**
 * Minimal LiveKit token generator.
 *
 * Requires env vars:
 *   LIVEKIT_API_KEY    – the LiveKit project API key
 *   LIVEKIT_API_SECRET – the LiveKit project API secret
 *   LIVEKIT_URL        – the LiveKit server URL (returned to clients)
 *
 * Throws \RuntimeException when credentials are missing so callers
 * can gracefully degrade (e.g. return 503).
 */
class LiveKitService
{
    private static ?self $instance = null;

    private string $apiKey;
    private string $apiSecret;

    private function __construct()
    {
        $this->apiKey    = $_ENV['LIVEKIT_API_KEY']    ?? '';
        $this->apiSecret = $_ENV['LIVEKIT_API_SECRET'] ?? '';

        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new \RuntimeException('LIVEKIT_API_KEY and LIVEKIT_API_SECRET must be set');
        }
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate a deterministic room name for a coaching session.
     */
    public static function roomName(string $sessionId): string
    {
        return 'coaching-session-' . $sessionId;
    }

    /**
     * Create a LiveKit participant token.
     *
     * @param string $identity     Unique participant ID (e.g. "coach_abc123")
     * @param string $name         Display name shown in the room
     * @param string $room          Room name (from roomName())
     * @param bool   $canPublish   Allow camera/mic publishing
     * @param bool   $canSubscribe Allow receiving remote tracks
     * @param int    $ttlSeconds   Token lifetime (default 24 h)
     */
    public static function createToken(
        string $identity,
        string $name,
        string $room,
        bool $canPublish = true,
        bool $canSubscribe = true,
        int $ttlSeconds = 86400
    ): string {
        $self = self::getInstance();

        $payload = [
            'iss' => $self->apiKey,
            'sub' => $identity,
            'nbf' => time(),
            'exp' => time() + $ttlSeconds,
            'name' => $name,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => $canSubscribe,
            ],
        ];

        return JWT::encode($payload, $self->apiSecret, 'HS256');
    }
}
