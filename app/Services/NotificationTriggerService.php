<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class NotificationTriggerService
{
    private \MongoDB\Collection $collection;
    private ?\MongoDB\Collection $scheduledCollection = null;

    public function __construct()
    {
        $this->collection = Database::getInstance()->getCollection('notifications');
    }

    private function getScheduledCollection(): \MongoDB\Collection
    {
        if ($this->scheduledCollection === null) {
            $this->scheduledCollection = Database::getInstance()->getCollection('scheduled_notifications');
        }
        return $this->scheduledCollection;
    }

    /**
     * Notify coach when a client completes a workout
     */
    public function notifyWorkoutCompleted(string $clientId, string $clientName, string $workoutPlanName, ?string $workoutLogId = null): void
    {
        $coach = $this->getCoachByClientId($clientId);
        if (!$coach) return;

        $notification = [
            'user_id' => new ObjectId($coach['_id']),
            'user_type' => 'coach',
            'type' => 'workout_completed',
            'title' => 'Workout Completed',
            'body' => "{$clientName} finished \"{$workoutPlanName}\"",
            'data' => [
                'clientId' => $clientId,
                'clientName' => $clientName,
                'workoutLogId' => $workoutLogId,
                'workoutPlanName' => $workoutPlanName,
            ],
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToCoach($coach['_id'], $notification['title'], $notification['body'], $notification['data']);
    }

    /**
     * Notify coach when a client sends a message
     */
    public function notifyNewClientMessage(string $clientId, string $clientName, string $messagePreview, ?string $messageId = null): void
    {
        $coach = $this->getCoachByClientId($clientId);
        if (!$coach) return;

        $notification = [
            'user_id' => new ObjectId($coach['_id']),
            'user_type' => 'coach',
            'type' => 'new_message',
            'title' => 'New Message',
            'body' => "{$clientName}: {$messagePreview}",
            'data' => [
                'clientId' => $clientId,
                'clientName' => $clientName,
                'messageId' => $messageId,
            ],
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToCoach($coach['_id'], $notification['title'], $notification['body'], $notification['data']);
    }

    /**
     * Notify coach when a client updates their profile
     */
    public function notifyProfileUpdated(string $clientId, string $clientName, array $changedFields = []): void
    {
        $coach = $this->getCoachByClientId($clientId);
        if (!$coach) return;

        $fieldList = implode(', ', $changedFields);
        $notification = [
            'user_id' => new ObjectId($coach['_id']),
            'user_type' => 'coach',
            'type' => 'profile_updated',
            'title' => 'Profile Updated',
            'body' => "{$clientName} updated their profile" . ($fieldList ? " ({$fieldList})" : ''),
            'data' => [
                'clientId' => $clientId,
                'clientName' => $clientName,
                'changedFields' => $changedFields,
            ],
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToCoach($coach['_id'], $notification['title'], $notification['body'], $notification['data']);
    }

    /**
     * Schedule a reminder notification for 2 hours before a check-in
     */
    public function scheduleCheckinReminder(string $checkinId, string $clientId, string $clientName, string $scheduledAt): void
    {
        $coach = $this->getCoachByClientId($clientId);
        if (!$coach) return;

        $scheduledTime = new UTCDateTime(strtotime($scheduledAt) * 1000);
        $reminderTime = new UTCDateTime((strtotime($scheduledAt) - 7200) * 1000); // 2 hours before

        // Don't schedule if reminder time is in the past
        if ($reminderTime < new UTCDateTime()) {
            $this->notifyCheckinReminder($checkinId, $clientId, $clientName, $scheduledAt);
            return;
        }

        try {
            $this->getScheduledCollection()->insertOne([
                'notification_type' => 'checkin_reminder',
                'target_id' => new ObjectId($checkinId),
                'user_id' => new ObjectId($coach['_id']),
                'client_id' => new ObjectId($clientId),
                'client_name' => $clientName,
                'scheduled_for' => $reminderTime,
                'sent' => false,
                'created_at' => new UTCDateTime(),
            ]);
        } catch (\Throwable $e) {
            // Silently ignore if scheduled_notifications collection doesn't exist
            error_log('Failed to schedule checkin reminder: ' . $e->getMessage());
        }
    }

    /**
     * Send immediate check-in reminder (used when check-in is within 2 hours)
     */
    public function notifyCheckinReminder(string $checkinId, string $clientId, string $clientName, string $scheduledAt): void
    {
        $coach = $this->getCoachByClientId($clientId);
        if (!$coach) return;

        $formattedTime = date('M j, g:i A', strtotime($scheduledAt));
        $notification = [
            'user_id' => new ObjectId($coach['_id']),
            'user_type' => 'coach',
            'type' => 'checkin_reminder',
            'title' => 'Check-in Reminder',
            'body' => "Check-in with {$clientName} at {$formattedTime}",
            'data' => [
                'checkinId' => $checkinId,
                'clientId' => $clientId,
                'clientName' => $clientName,
                'scheduledAt' => $scheduledAt,
            ],
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToCoach($coach['_id'], $notification['title'], $notification['body'], $notification['data']);
    }

    /**
     * Schedule a reminder notification for 2 hours before a live session
     */
    public function scheduleLiveSessionReminder(string $sessionId, string $coachId, string $title, string $scheduledAt, array $participantIds = []): void
    {
        $reminderTime = new UTCDateTime((strtotime($scheduledAt) - 7200) * 1000); // 2 hours before

        // Don't schedule if reminder time is in the past
        if ($reminderTime < new UTCDateTime()) {
            $this->notifyLiveSessionReminder($sessionId, $coachId, $title, $scheduledAt);
            return;
        }

        // Schedule for coach
        $this->getScheduledCollection()->insertOne([
            'notification_type' => 'live_session_reminder',
            'target_id' => new ObjectId($sessionId),
            'user_id' => new ObjectId($coachId),
            'scheduled_for' => $reminderTime,
            'sent' => false,
            'created_at' => new UTCDateTime(),
        ]);

        // Schedule for all participants
        foreach ($participantIds as $participantId) {
            $this->getScheduledCollection()->insertOne([
                'notification_type' => 'live_session_reminder',
                'target_id' => new ObjectId($sessionId),
                'user_id' => new ObjectId($participantId),
                'scheduled_for' => $reminderTime,
                'sent' => false,
                'created_at' => new UTCDateTime(),
            ]);
        }
    }

    /**
     * Send immediate live session reminder
     */
    public function notifyLiveSessionReminder(string $sessionId, string $userId, string $title, string $scheduledAt, bool $isCoach = false): void
    {
        $formattedTime = date('M j, g:i A', strtotime($scheduledAt));
        $notification = [
            'user_id' => new ObjectId($userId),
            'user_type' => $isCoach ? 'coach' : 'client',
            'type' => 'live_session_reminder',
            'title' => 'Live Session Starting Soon',
            'body' => "\"{$title}\" starts at {$formattedTime}",
            'data' => [
                'sessionId' => $sessionId,
                'title' => $title,
                'scheduledAt' => $scheduledAt,
            ],
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);

        if ($isCoach) {
            $this->sendPushToCoach($userId, $notification['title'], $notification['body'], $notification['data']);
        } else {
            $this->sendPushToClient($userId, $notification['title'], $notification['body'], $notification['data']);
        }
    }

    /**
     * Get all scheduled reminders that should be sent now
     * @return array Array of scheduled notifications to send
     */
    public function getDueReminders(): array
    {
        $now = new UTCDateTime();
        $twoHoursAgo = new UTCDateTime((time() - 7200) * 1000);

        $scheduled = $this->getScheduledCollection()->find([
            'sent' => false,
            'scheduled_for' => ['$lte' => $now, '$gte' => $twoHoursAgo],
        ]);

        return iterator_to_array($scheduled);
    }

    /**
     * Mark a scheduled reminder as sent
     */
    public function markReminderSent(string $scheduledId): void
    {
        $this->getScheduledCollection()->updateOne(
            ['_id' => new ObjectId($scheduledId)],
            ['$set' => ['sent' => true]]
        );
    }

    /**
     * Send scheduled reminders (called by cron job)
     */
    public function sendDueReminders(): int
    {
        $dueReminders = $this->getDueReminders();
        $sentCount = 0;

        foreach ($dueReminders as $reminder) {
            $type = $reminder['notification_type'];
            $targetId = (string) $reminder['target_id'];
            $userId = (string) $reminder['user_id'];

            if ($type === 'checkin_reminder') {
                $clientId = (string) $reminder['client_id'];
                $clientName = $reminder['client_name'];
                $checkin = Database::getInstance()->getCollection('checkin_meetings')->findOne(['_id' => new ObjectId($targetId)]);
                if ($checkin) {
                    $scheduledAt = date('Y-m-d H:i:s', (int) ((string) $checkin['scheduled_at']) / 1000);
                    $this->notifyCheckinReminder($targetId, $clientId, $clientName, $scheduledAt);
                }
            } elseif ($type === 'live_session_reminder') {
                $session = Database::getInstance()->getCollection('live_training_sessions')->findOne(['_id' => new ObjectId($targetId)]);
                if ($session) {
                    $scheduledAt = date('Y-m-d H:i:s', (int) ((string) $session['scheduled_at']) / 1000);
                    $this->notifyLiveSessionReminder(
                        $targetId,
                        $userId,
                        $session['title'],
                        $scheduledAt,
                        (string) $session['coach_id'] === $userId
                    );
                }
            }

            $this->markReminderSent((string) $reminder['_id']);
            $sentCount++;
        }

        return $sentCount;
    }

    /**
     * Helper: Get coach by client ID
     */
    private function getCoachByClientId(string $clientId): ?array
    {
        $client = Database::getInstance()->getCollection('clients')->findOne(['_id' => new ObjectId($clientId)]);
        if (!$client) return null;

        $coach = Database::getInstance()->getCollection('coaches')->findOne(['_id' => $client['coach_id']]);
        return $coach ? (array) $coach : null;
    }

    /**
     * Helper: Send push notification to coach
     */
    private function sendPushToCoach(ObjectId $coachId, string $title, string $body, array $data = []): void
    {
        $coach = Database::getInstance()->getCollection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach || empty($coach['fcm_token'])) return;

        FcmService::send($coach['fcm_token'], $title, $body, $data);
    }

    /**
     * Helper: Send push notification to client
     */
    private function sendPushToClient(ObjectId $clientId, string $title, string $body, array $data = []): void
    {
        $client = Database::getInstance()->getCollection('clients')->findOne(['_id' => $clientId]);
        if (!$client || empty($client['fcm_token'])) return;

        FcmService::send($client['fcm_token'], $title, $body, $data);
    }
}
