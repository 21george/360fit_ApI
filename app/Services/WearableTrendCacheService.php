<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\{ObjectId, UTCDateTime};

/**
 * Cache-aside layer for wearable trend summaries.
 *
 * NOTE ON SCOPE: the technical spec describes this feature against a
 * `wearable_connections` + `activity_samples` data model, and describes
 * modifying an "existing periodic Fitbit scheduled sync job" to dispatch
 * pulls for many users concurrently. Neither of those exist in this
 * codebase — wearable data actually lives in a single `wearable_data`
 * collection of per-client per-day summary documents, the per-provider
 * connection record is `wearable_tokens`, and Fitbit sync currently only
 * ever runs once, synchronously, for a single client right after OAuth
 * connect (there is no existing multi-user batch job to make concurrent).
 * Per explicit confirmation, this service adapts the caching architecture
 * to the real `wearable_data` model: it computes and caches trend
 * summaries (points + aggregate over a day window) for a given client and
 * metric, using the same cache-aside + stale-while-revalidate rules as
 * NutritionCacheService. `wearable_tokens.sync_status` is used in place of
 * the spec's `wearable_connections.sync_status` field. Building a new
 * multi-user concurrent Fitbit sync job is out of scope (no existing job
 * to modify) and is called out separately.
 */
class WearableTrendCacheService
{
    use CacheAsideTrait;

    public const ALLOWED_METRICS = [
        'steps', 'active_calories', 'distance_km', 'hydration_ml', 'resting_hr', 'sleep_minutes',
    ];

    private bool $asyncRefreshEnabled;
    private int $ttlSeconds;
    private int $staleGraceSeconds;

    public function __construct()
    {
        $this->asyncRefreshEnabled = filter_var($_ENV['WEARABLE_ASYNC_REFRESH_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->ttlSeconds = max(1, (int) ($_ENV['WEARABLE_TREND_CACHE_TTL_SECONDS'] ?? 900));
        $this->staleGraceSeconds = max(0, (int) ($_ENV['WEARABLE_TREND_STALE_GRACE_MINUTES'] ?? 60)) * 60;
        $this->initRedis('WEARABLE_CACHE_DRIVER');
    }

    /**
     * Get a trend summary for a client/metric/window, honoring cache-aside +
     * stale-while-revalidate rules (same semantics as NutritionCacheService).
     *
     * @return array{metric: string, window_days: int, points: list<array{date: string, value: float}>, average: float, total: float, min: float, max: float}
     */
    public function getTrend(ObjectId $clientId, string $metric, int $windowDays = 30): array
    {
        if (!in_array($metric, self::ALLOWED_METRICS, true)) {
            throw new \InvalidArgumentException('Unsupported metric: ' . $metric);
        }
        $windowDays = max(1, min(365, $windowDays));

        $hotKey = 'wearable:trend:' . (string) $clientId . ":{$metric}:{$windowDays}";
        if ($this->redis) {
            $hit = $this->safeRedisGet($hotKey);
            if ($hit !== null) {
                return $hit;
            }
        }

        $doc = Database::collection('wearable_trend_cache')->findOne([
            'client_id'   => $clientId,
            'metric'      => $metric,
            'window_days' => $windowDays,
        ]);

        if ($doc) {
            $data    = (array) $doc;
            $staleAt = $this->toTimestamp($data['stale_at'] ?? null);
            $now     = time();

            if ($staleAt !== null && $staleAt > $now) {
                $summary = (array) ($data['summary'] ?? []);
                $this->writeHotCache($hotKey, $summary);
                return $summary;
            }

            $graceExpiresAt = ($staleAt ?? 0) + $this->staleGraceSeconds;
            if ($graceExpiresAt > $now) {
                $this->enqueueRecompute($clientId, $metric, $windowDays);
                return (array) ($data['summary'] ?? []);
            }
            // Past grace: recompute synchronously below, keeping $doc as a fallback.
        }

        try {
            $summary = $this->computeAndCache($clientId, $metric, $windowDays);
            $this->writeHotCache($hotKey, $summary);
            return $summary;
        } catch (\Throwable $e) {
            if ($doc) {
                error_log('WearableTrendCacheService: recompute failed, serving stale summary: ' . $e->getMessage());
                return (array) ($data['summary'] ?? []);
            }
            throw $e;
        }
    }

    /**
     * Event-driven invalidation: call after new wearable_data is written
     * (HealthKit push or Fitbit sync) so the next read for this client is
     * treated as stale-in-grace (served immediately, refreshed in the
     * background) instead of waiting for the full TTL to expire.
     */
    public function invalidateForClient(ObjectId $clientId): void
    {
        Database::collection('wearable_trend_cache')->updateMany(
            ['client_id' => $clientId],
            ['$set' => ['stale_at' => new UTCDateTime()]]
        );
    }

    /**
     * Drain pending `wearable_sync_jobs` (type=trend_recompute), recomputing
     * each client/metric/window trend summary in the background. A failed
     * recompute never deletes/blanks the existing cached summary.
     *
     * @return array{processed: int, succeeded: int, failed: int}
     */
    public function processSyncQueue(int $limit = 50): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        $jobs = Database::collection('wearable_sync_jobs')->find(
            ['status' => 'pending', 'type' => 'trend_recompute'],
            ['sort' => ['requested_at' => 1], 'limit' => $limit]
        )->toArray();

        foreach ($jobs as $job) {
            $jobData = (array) $job;
            $jobId   = $jobData['_id'];
            $processed++;

            Database::collection('wearable_sync_jobs')->updateOne(
                ['_id' => $jobId],
                ['$set' => ['status' => 'processing'], '$inc' => ['attempts' => 1]]
            );

            try {
                $this->computeAndCache($jobData['client_id'], $jobData['metric'], (int) $jobData['window_days']);
                Database::collection('wearable_sync_jobs')->updateOne(
                    ['_id' => $jobId],
                    ['$set' => ['status' => 'done']]
                );
                $succeeded++;
            } catch (\Throwable $e) {
                Database::collection('wearable_sync_jobs')->updateOne(
                    ['_id' => $jobId],
                    ['$set' => ['status' => 'failed']]
                );
                $failed++;
                error_log('WearableTrendCacheService: recompute failed for job ' . (string) $jobId . ': ' . $e->getMessage());
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    // ─── Internal helpers ────────────────────────────────────────────────────

    private function computeAndCache(ObjectId $clientId, string $metric, int $windowDays): array
    {
        $summary = $this->computeTrendSummary($clientId, $metric, $windowDays);

        Database::collection('wearable_trend_cache')->updateOne(
            ['client_id' => $clientId, 'metric' => $metric, 'window_days' => $windowDays],
            ['$set' => [
                'summary'        => $summary,
                'computed_at'    => new UTCDateTime(),
                'stale_at'       => new UTCDateTime((time() + $this->ttlSeconds) * 1000),
                'refresh_status' => 'idle',
            ]],
            ['upsert' => true]
        );

        return $summary;
    }

    /**
     * Pure Mongo aggregation over `wearable_data` — no external HTTP calls.
     */
    private function computeTrendSummary(ObjectId $clientId, string $metric, int $windowDays): array
    {
        $from = date('Y-m-d', strtotime("-{$windowDays} days"));
        $docs = Database::collection('wearable_data')->find(
            ['client_id' => $clientId, 'date' => ['$gte' => $from]],
            ['sort' => ['date' => 1]]
        )->toArray();

        $points = [];
        $values = [];
        foreach ($docs as $doc) {
            $data  = (array) $doc;
            $value = (float) ($data[$metric] ?? 0);
            $points[] = ['date' => $data['date'] ?? null, 'value' => $value];
            $values[] = $value;
        }

        $total   = array_sum($values);
        $average = count($values) > 0 ? round($total / count($values), 2) : 0.0;

        return [
            'metric'      => $metric,
            'window_days' => $windowDays,
            'points'      => $points,
            'average'     => $average,
            'total'       => round($total, 2),
            'min'         => count($values) > 0 ? round(min($values), 2) : 0.0,
            'max'         => count($values) > 0 ? round(max($values), 2) : 0.0,
        ];
    }

    private function enqueueRecompute(ObjectId $clientId, string $metric, int $windowDays): void
    {
        if (!$this->asyncRefreshEnabled) {
            return;
        }

        // Single-flight guard: only enqueue if not already queued/refreshing.
        $claimed = Database::collection('wearable_trend_cache')->findOneAndUpdate(
            [
                'client_id'      => $clientId,
                'metric'         => $metric,
                'window_days'    => $windowDays,
                'refresh_status' => ['$nin' => ['queued', 'refreshing']],
            ],
            ['$set' => ['refresh_status' => 'queued']]
        );
        if (!$claimed) {
            return;
        }

        Database::collection('wearable_sync_jobs')->insertOne([
            'client_id'    => $clientId,
            'type'         => 'trend_recompute',
            'metric'       => $metric,
            'window_days'  => $windowDays,
            'requested_at' => new UTCDateTime(),
            'status'       => 'pending',
            'attempts'     => 0,
        ]);
    }

    // CacheAsideTrait provides: initRedis, writeHotCache, safeRedisGet, toTimestamp
}
