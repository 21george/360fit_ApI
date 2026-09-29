<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Shared Redis hot-cache + timestamp helpers for cache-aside services.
 */
trait CacheAsideTrait
{
    private ?\Redis $redis = null;

    private function initRedis(string $driverEnvVar, string $defaultDriver = 'mongo'): void
    {
        $driver = $_ENV[$driverEnvVar] ?? $defaultDriver;
        $url    = $_ENV['REDIS_URL'] ?? '';

        if ($driver !== 'redis' || $url === '' || !class_exists(\Redis::class)) {
            $this->redis = null;
            return;
        }

        try {
            $parts = parse_url($url);
            if (!$parts || empty($parts['host'])) {
                $this->redis = null;
                return;
            }
            $redis = new \Redis();
            $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379), 1.0);
            if (!empty($parts['pass'])) {
                $redis->auth($parts['pass']);
            }
            $this->redis = $redis;
        } catch (\Throwable $e) {
            error_log('Redis unavailable, falling back to Mongo cache: ' . $e->getMessage());
            $this->redis = null;
        }
    }

    private function writeHotCache(string $key, array $data, int $ttl): void
    {
        if (!$this->redis) {
            return;
        }
        try {
            $this->redis->setex($key, $ttl, json_encode($data));
        } catch (\Throwable $e) {
            error_log('Redis write failed: ' . $e->getMessage());
        }
    }

    private function safeRedisGet(string $key): ?array
    {
        if (!$this->redis) {
            return null;
        }
        try {
            $raw = $this->redis->get($key);
            if ($raw === false || $raw === null) {
                return null;
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            error_log('Redis read failed, falling back to Mongo: ' . $e->getMessage());
            return null;
        }
    }

    private function toTimestamp($value): ?int
    {
        return $value instanceof \MongoDB\BSON\UTCDateTime
            ? (int) $value->toDateTime()->getTimestamp()
            : null;
    }
}
