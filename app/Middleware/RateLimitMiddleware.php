<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;

class RateLimitMiddleware
{
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(int $maxRequests = 100, int $windowSeconds = 900)
    {
        // Use passed values as-is. Subclasses (e.g. AuthRateLimitMiddleware) read
        // their own env vars before calling parent::__construct, so we must not
        // override with RATE_LIMIT_REQUESTS here — that would silently discard the
        // subclass-specific limit and break auth brute-force protection.
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function handle(array &$params): void
    {
        $ip  = $this->getClientIp();
        $key = 'rate_limit:' . $ip;
        $now = time();

        try {
            $col    = Database::collection('rate_limits');
            $record = $col->findOne(['key' => $key]);

            if (!$record) {
                $col->insertOne([
                    'key'          => $key,
                    'requests'     => 1,
                    'window_start' => $now,
                    'expires_at'   => new \MongoDB\BSON\UTCDateTime(($now + $this->windowSeconds) * 1000),
                ]);
            } elseif ($now - $record['window_start'] > $this->windowSeconds) {
                $col->updateOne(['key' => $key], ['$set' => [
                    'requests'     => 1,
                    'window_start' => $now,
                    'expires_at'   => new \MongoDB\BSON\UTCDateTime(($now + $this->windowSeconds) * 1000),
                ]]);
            } elseif ($record['requests'] >= $this->maxRequests) {
                Response::json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                ], 429);
                exit;
            } else {
                $col->updateOne(['key' => $key], ['$inc' => ['requests' => 1]]);
            }
        } catch (\Exception $e) {
            error_log('Rate limiting failed: ' . $e->getMessage());
            Response::json([
                'success' => false,
                'message' => 'Service temporarily unavailable. Please try again later.',
            ], 429);
            exit;
        }
    }

    private function getClientIp(): string
    {
        $remoteAddr = filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null;
        $trustedProxies = array_filter(
            array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))),
            fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        );

        if (
            $remoteAddr
            && in_array($remoteAddr, $trustedProxies, true)
            && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        ) {
            $ips = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            $forwardedIp = filter_var(end($ips), FILTER_VALIDATE_IP);
            if ($forwardedIp !== false) {
                return $forwardedIp;
            }
        }

        return $remoteAddr ?: '0.0.0.0';
    }
}
