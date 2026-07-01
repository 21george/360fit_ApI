<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Stores the authenticated user context for the current request.
 * Set by CoachMiddleware / ClientMiddleware after JWT validation.
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

    public static function clear(): void
    {
        self::$auth = null;
    }
}
