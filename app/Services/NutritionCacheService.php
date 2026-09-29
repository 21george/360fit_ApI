<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\UTCDateTime;

/**
 * Cache-aside layer in front of FoodService (USDA FoodData Central).
 *
 * NOTE ON SCOPE: the technical spec describes this service as also wrapping
 * an "OpenFoodFactsService" and dispatching USDA + Open Food Facts calls
 * concurrently. Open Food Facts integration does not exist anywhere in this
 * codebase (no service, no routes, no env vars) — per explicit confirmation,
 * this service only wraps the real, existing USDA integration (FoodService).
 * The concurrent multi-source dispatch is a no-op with a single source; the
 * public API is shaped so a second source can be added later without
 * changing callers.
 *
 * Read path for a given FDC id (getFoodDetails):
 *   1. Optional Redis hot cache. Redis is never a correctness dependency —
 *      if the phpredis extension isn't loaded, REDIS_URL is unset, or the
 *      connection fails, this layer is skipped entirely and Mongo is used.
 *   2. Mongo `foods` collection (warm cache) — served immediately if
 *      `stale_at` is in the future (fresh), or if it's in the past but still
 *      within the configured grace window (stale-but-usable: served
 *      immediately, a background refresh job is enqueued, no live call is
 *      made on the request path).
 *   3. True cache miss (no doc at all, or past the grace window) — a live,
 *      synchronous call to FoodService is made and the result is written
 *      back into both cache layers.
 *
 * A failed refresh (foreground or background) NEVER deletes or blanks an
 * existing `foods` document — only `refresh_status` / `last_refresh_error`
 * are updated, so previously-served data is never lost.
 */
class NutritionCacheService
{
    use CacheAsideTrait;

    private const GRACE_UNIT_SECONDS = 86400; // NUTRITION_STALE_GRACE_DAYS is expressed in days

    private FoodService $foodService;
    private bool $asyncRefreshEnabled;
    private int $hotTtlSeconds;
    private int $staleGraceSeconds;

    public function __construct(?FoodService $foodService = null)
    {
        $this->foodService = $foodService ?? new FoodService();
        $this->asyncRefreshEnabled = filter_var($_ENV['NUTRITION_ASYNC_REFRESH_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->hotTtlSeconds = max(1, (int) ($_ENV['NUTRITION_CACHE_HOT_TTL_SECONDS'] ?? 3600));
        $this->staleGraceSeconds = max(0, (int) ($_ENV['NUTRITION_STALE_GRACE_DAYS'] ?? 14)) * self::GRACE_UNIT_SECONDS;
        $this->initRedis('NUTRITION_CACHE_DRIVER');
    }

    /**
     * Get full nutrient details for a USDA FDC id, honoring the cache-aside
     * + stale-while-revalidate rules described in the class docblock.
     *
     * @return array{name: string, fdc_id: int, nutrients_per_100g: array, source: string}
     */
    public function getFoodDetails(int $fdcId): array
    {
        $hotKey = "nutrition:usda:{$fdcId}";

        if ($this->redis) {
            $hit = $this->safeRedisGet($hotKey);
            if ($hit !== null) {
                return $hit; // Hot cache hit — zero Mongo reads, zero live calls.
            }
        }

        $doc = Database::collection('foods')->findOne(['source' => 'usda', 'fdc_id' => $fdcId]);

        if ($doc) {
            $data    = (array) $doc;
            $staleAt = $this->toTimestamp($data['stale_at'] ?? null);
            $now     = time();

            if ($staleAt !== null && $staleAt > $now) {
                // Fresh — served entirely from Mongo, no live call at all.
                $food = $this->toFoodArray($data);
                $this->writeHotCache($hotKey, $food);
                return $food;
            }

            $graceExpiresAt = ($staleAt ?? 0) + $this->staleGraceSeconds;
            if ($graceExpiresAt > $now) {
                // Stale but within grace — serve immediately, refresh only in the background.
                $this->enqueueRefresh($fdcId, 'usda');
                return $this->toFoodArray($data);
            }
            // Past grace: fall through to a live fetch, keeping $doc as a fallback.
        }

        try {
            $food = $this->foodService->fetchAndCacheFoodDetails($fdcId);
            $this->markFresh($fdcId, 'usda', $food);
            $this->writeHotCache($hotKey, $food);
            return $food;
        } catch (\Throwable $e) {
            if ($doc) {
                // Live refresh failed — never delete/blank existing data, just record the error.
                $this->recordRefreshFailure($fdcId, 'usda', $e->getMessage());
                return $this->toFoodArray((array) $doc);
            }
            throw $e;
        }
    }

    /**
     * Search foods (local cache + live USDA search). An arbitrary text query
     * has no stable cache key, so search results themselves are not
     * cache-gated the way a single getFoodDetails() lookup is — but every
     * item returned has its stale_at/refresh_status stamped so a subsequent
     * getFoodDetails() call for the same fdc_id is served from cache.
     *
     * @return array{foods: list<array>, totalHits: int}
     */
    public function searchFoods(string $query, int $page = 1): array
    {
        $result = $this->foodService->searchFoods($query, $page);

        foreach ($result['foods'] ?? [] as $food) {
            if (!empty($food['fdc_id'])) {
                $this->markFresh((int) $food['fdc_id'], 'usda', $food);
            }
        }

        return $result;
    }

    /**
     * Drain pending `cache_refresh_jobs`, refreshing each stale-in-grace
     * food from the USDA API. Called by scripts/nutrition-cache-refresh.php.
     * A failed refresh never deletes/blanks the existing `foods` document.
     *
     * @return array{processed: int, succeeded: int, failed: int}
     */
    public function processRefreshQueue(int $limit = 50): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        $jobs = Database::collection('cache_refresh_jobs')->find(
            ['status' => 'pending'],
            ['sort' => ['requested_at' => 1], 'limit' => $limit]
        )->toArray();

        foreach ($jobs as $job) {
            $jobData = (array) $job;
            $jobId   = $jobData['_id'];
            $fdcId   = (int) ($jobData['food_id'] ?? 0);
            $source  = (string) ($jobData['source'] ?? 'usda');
            $processed++;

            Database::collection('cache_refresh_jobs')->updateOne(
                ['_id' => $jobId],
                ['$set' => ['status' => 'processing'], '$inc' => ['attempts' => 1]]
            );
            Database::collection('foods')->updateOne(
                ['source' => $source, 'fdc_id' => $fdcId],
                ['$set' => ['refresh_status' => 'refreshing']]
            );

            try {
                $food = $this->foodService->fetchAndCacheFoodDetails($fdcId);
                $this->markFresh($fdcId, $source, $food);
                Database::collection('cache_refresh_jobs')->updateOne(
                    ['_id' => $jobId],
                    ['$set' => ['status' => 'done']]
                );
                $succeeded++;
            } catch (\Throwable $e) {
                // Failure never deletes/blanks the existing foods document.
                $this->recordRefreshFailure($fdcId, $source, $e->getMessage());
                Database::collection('cache_refresh_jobs')->updateOne(
                    ['_id' => $jobId],
                    ['$set' => ['status' => 'failed']]
                );
                $failed++;
                error_log("NutritionCacheService: refresh failed for fdc_id={$fdcId}: " . $e->getMessage());
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    // ─── Internal helpers ────────────────────────────────────────────────────

    private function enqueueRefresh(int $fdcId, string $source): void
    {
        if (!$this->asyncRefreshEnabled) {
            return;
        }

        // Single-flight guard: only enqueue if not already queued/refreshing.
        $claimed = Database::collection('foods')->findOneAndUpdate(
            [
                'source'         => $source,
                'fdc_id'         => $fdcId,
                'refresh_status' => ['$nin' => ['queued', 'refreshing']],
            ],
            ['$set' => ['refresh_status' => 'queued']]
        );
        if (!$claimed) {
            return; // Already queued/refreshing by another request — don't double-enqueue.
        }

        Database::collection('cache_refresh_jobs')->insertOne([
            'food_id'      => $fdcId,
            'source'       => $source,
            'requested_at' => new UTCDateTime(),
            'status'       => 'pending',
            'attempts'     => 0,
        ]);
    }

    private function markFresh(int $fdcId, string $source, array $food): void
    {
        Database::collection('foods')->updateOne(
            ['source' => $source, 'fdc_id' => $fdcId],
            ['$set' => [
                'stale_at'           => new UTCDateTime((time() + $this->hotTtlSeconds) * 1000),
                'refresh_status'     => 'idle',
                'last_refresh_error' => null,
                'updated_at'         => new UTCDateTime(),
            ]]
        );
    }

    private function recordRefreshFailure(int $fdcId, string $source, string $error): void
    {
        Database::collection('foods')->updateOne(
            ['source' => $source, 'fdc_id' => $fdcId],
            ['$set' => [
                'refresh_status'     => 'failed',
                'last_refresh_error' => substr($error, 0, 500),
            ]]
        );
    }

    private function toFoodArray(array $data): array
    {
        return [
            'name'               => $data['name'] ?? 'Unknown',
            'fdc_id'             => isset($data['fdc_id']) ? (int) $data['fdc_id'] : 0,
            'nutrients_per_100g' => (array) ($data['nutrients_per_100g'] ?? []),
            'source'             => $data['source'] ?? 'usda',
        ];
    }

    // CacheAsideTrait provides: initRedis, writeHotCache, safeRedisGet, toTimestamp
}
