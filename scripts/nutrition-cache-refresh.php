#!/usr/bin/env php
<?php
/**
 * Nutrition Cache Refresh Worker
 *
 * Drains pending `cache_refresh_jobs` and refreshes stale-but-in-grace
 * `foods` documents from the USDA API in the background. Cache hits on the
 * request path never trigger this — this worker only handles the
 * stale-in-grace background-refresh case. A failed refresh never deletes or
 * blanks existing cached nutrition data; only refresh_status/last_refresh_error
 * are updated so the item is retried on the next run.
 *
 * Cron example (every NUTRITION_CACHE_REFRESH_INTERVAL_MIN minutes, default 15):
 *   0,15,30,45 * * * * cd /path/to/backend && php scripts/nutrition-cache-refresh.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/Database.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use App\Services\NutritionCacheService;

try {
    $service = new NutritionCacheService();
    $result  = $service->processRefreshQueue();

    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Nutrition cache refresh: processed={$result['processed']}, succeeded={$result['succeeded']}, failed={$result['failed']}\n";

    exit(0);
} catch (\Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$timestamp}] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace: " . $e->getTraceAsString() . "\n");
    exit(1);
}
