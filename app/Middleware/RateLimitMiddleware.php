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
        $this->maxRequests   = (int) ($_ENV['RATE_LIMIT_REQUESTS'] ?? $maxRequests);
        $this->windowSeconds = (int) ($_ENV['RATE_LIMIT_WINDOW']   ?? $windowSeconds);
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
        // Trust only REMOTE_ADDR directly to prevent X-Forwarded-For spoofing.
        // If behind a trusted reverse proxy, use the last IP in X-Forwarded-For
        // (the one closest to the server), not the first (which can be forged).
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            // Use the last IP added by the closest proxy
            $ip = filter_var(end($ips), FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}
