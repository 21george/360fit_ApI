<?php
declare(strict_types=1);

namespace App\Middleware;

/**
 * @deprecated Client limits have been removed. All tiers now support unlimited clients.
 * This middleware is kept as a no-op pass-through so existing route references do not break.
 */
class SubscriptionMiddleware
{
    public function handle(array &$params): void
    {
        // No-op: unlimited clients for all tiers.
    }
}
