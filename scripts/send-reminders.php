#!/usr/bin/env php
<?php
/**
 * Scheduled Notification Reminder Script
 *
 * Run this via cron every minute to send reminder notifications
 * for check-ins and live sessions happening in 2 hours.
 *
 * Cron example (run every minute):
 *   * * * * * cd /path/to/backend && php scripts/send-reminders.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/Database.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use App\Services\ScheduledNotificationService;

try {
    $service = new ScheduledNotificationService();
    $result = $service->run();

    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Reminders sent: {$result['reminders_sent']}, Old records deleted: {$result['old_records_deleted']}\n";

    exit(0);
} catch (\Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$timestamp}] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace: " . $e->getTraceAsString() . "\n");
    exit(1);
}
