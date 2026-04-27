<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Stricter rate limiting for auth endpoints (login, register, refresh).
 * Defaults to 10 attempts per 15-minute window.
 */
class AuthRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct()
    {
        parent::__construct(
            maxRequests:   (int) ($_ENV['AUTH_RATE_LIMIT_REQUESTS'] ?? 10),
            windowSeconds: (int) ($_ENV['AUTH_RATE_LIMIT_WINDOW']   ?? 900)
        );
    }
}
