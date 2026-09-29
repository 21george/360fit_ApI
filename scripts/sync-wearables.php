#!/usr/bin/env php
<?php
/**
 * Daily Wearable Sync Worker
 *
 * Scans active wearable connections and dispatches a daily data pull for
 * each provider.  Fitbit syncs are throttled to avoid rate-limiting.
 *
 * Cron example (once per day, e.g. 02:00):
 *   0 2 * * * cd /path/to/backend && php scripts/sync-wearables.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/Database.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use App\Config\Database;
use App\Services\WearableSyncService;

$batchSize = max(1, (int) ($_ENV['WEARABLE_SYNC_BATCH_SIZE'] ?? 50));
$concurrency = max(1, min(10, (int) ($_ENV['WEARABLE_SYNC_CONCURRENCY'] ?? 3)));
$days = max(1, min(30, (int) ($_ENV['WEARABLE_SYNC_DAYS'] ?? 7)));

$service = new WearableSyncService();
$connections = Database::collection('wearable_connections')
    ->find(['status' => 'active'])
    ->toArray();

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Starting daily wearable sync for " . count($connections) . " connection(s)\n";

$processed = 0;
$failed    = 0;

foreach (array_slice($connections, 0, $batchSize) as $connection) {
    $clientId = $connection['client_id'] ?? null;
    $provider = (string) ($connection['provider'] ?? '');

    if (!$clientId instanceof \MongoDB\BSON\ObjectId || $provider === '') {
        continue;
    }

    try {
        if ($provider === 'fitbit') {
            $service->syncFitbitForClient($clientId, $days);
        }
        // Apple Watch / HealthKit data is pushed by the iPhone app; nothing to pull here.
        $processed++;
    } catch (\Throwable $e) {
        $failed++;
        error_log('Daily wearable sync failed for client ' . (string) $clientId . ': ' . $e->getMessage());
    }

    // Throttle to avoid hammering Fitbit API.
    if ($provider === 'fitbit' && $concurrency > 1) {
        usleep((int) (1000000 / $concurrency));
    }
}

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Daily wearable sync complete: processed={$processed}, failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
