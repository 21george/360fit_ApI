<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Per-request authenticated user context.
 *
 * Populated by CoachMiddleware / ClientMiddleware after JWT validation
 * and read by RlsScope / ScopedCollection. State is process-wide static
 * by design (PHP request model), but bootstrap/app.php guarantees a
 * clear() after every dispatch so PHP-FPM workers cannot leak state
 * between requests.
 */
class RequestContext
{
    private static ?array $auth = null;

    public static function setAuth(array $auth): void
    {
        self::$auth = $auth;
    }

    public static function getAuth(): ?array
    {
        return self::$auth;
    }

    public static function getRole(): ?string
    {
        return self::$auth['role'] ?? null;
    }

    public static function getUserId(): ?string
    {
        return self::$auth['sub'] ?? null;
    }

    public static function getCoachId(): ?string
    {
        return self::$auth['coach_id'] ?? null;
    }

    public static function clear(): void
    {
        self::$auth = null;
    }
}
