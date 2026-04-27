<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Service for sending scheduled reminder notifications.
 * Designed to be called by a cron job every minute.
 *
 * Usage: php scripts/send-reminders.php
 */
class ScheduledNotificationService
{
    private NotificationTriggerService $triggerService;
    private ?\MongoDB\Collection $scheduledCollection = null;

    public function __construct()
    {
        $this->triggerService = new NotificationTriggerService();
    }

    private function getScheduledCollection(): ?\MongoDB\Collection
    {
        if ($this->scheduledCollection === null) {
            try {
                $this->scheduledCollection = Database::getInstance()->getCollection('scheduled_notifications');
            } catch (\Throwable $e) {
                return null;
            }
        }
        return $this->scheduledCollection;
    }

    /**
     * Send all due reminders.
     * Returns the number of reminders sent.
     */
    public function sendDueReminders(): int
    {
        return $this->triggerService->sendDueReminders();
    }

    /**
     * Clean up old sent reminders (older than 24 hours).
     * Returns the number of records deleted.
     */
    public function cleanupOldReminders(): int
    {
        $collection = $this->getScheduledCollection();
        if (!$collection) return 0;

        $yesterday = new UTCDateTime((time() - 86400) * 1000);
        try {
            $result = $collection->deleteMany([
                'sent' => true,
                'scheduled_for' => ['$lt' => $yesterday],
            ]);
            return $result->getDeletedCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Run the full reminder cycle:
     * 1. Send due reminders
     * 2. Clean up old records
     */
    public function run(): array
    {
        $sentCount = $this->sendDueReminders();
        $deletedCount = $this->cleanupOldReminders();

        return [
            'reminders_sent' => $sentCount,
            'old_records_deleted' => $deletedCount,
        ];
    }
}
