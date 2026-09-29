#!/usr/bin/env php
<?php
/**
 * Wearable Trend Cache Refresh Worker
 *
 * Drains pending `wearable_sync_jobs` (type=trend_recompute) and recomputes
 * stale-but-in-grace wearable trend summaries in the background. A failed
 * recompute never deletes/blanks an existing cached summary; only the job
 * status is marked failed so it can be retried on the next run.
 *
 * Cron example (every WEARABLE_SYNC_WORKER_INTERVAL_MIN minutes, default 5):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * cd /path/to/backend && php scripts/wearable-trend-cache-refresh.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/Database.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use App\Services\WearableTrendCacheService;

try {
    $service = new WearableTrendCacheService();
    $result  = $service->processSyncQueue();

    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Wearable trend cache refresh: processed={$result['processed']}, succeeded={$result['succeeded']}, failed={$result['failed']}\n";

    exit(0);
} catch (\Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$timestamp}] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace: " . $e->getTraceAsString() . "\n");
    exit(1);
}
