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
            'user_id' => $coach['_id'],
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
            'user_id' => $coach['_id'],
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
            'user_id' => $coach['_id'],
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
                'user_id' => $coach['_id'],
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
            'user_id' => $coach['_id'],
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
                    $scheduledAt = date('Y-m-d H:i:s', (int) (((string) $checkin['scheduled_at']) / 1000));
                    $this->notifyCheckinReminder($targetId, $clientId, $clientName, $scheduledAt);
                }
            } elseif ($type === 'live_session_reminder') {
                $session = Database::getInstance()->getCollection('live_training_sessions')->findOne(['_id' => new ObjectId($targetId)]);
                if ($session) {
                    $scheduledAt = date('Y-m-d H:i:s', (int) (((string) $session['scheduled_at']) / 1000));
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
     * Notify client when coach sends them a message
     */
    public function notifyClientNewMessage(string $coachName, string $coachId, string $clientId, ?string $messageId = null): void
    {
        $notification = [
            'user_id'    => new ObjectId($clientId),
            'user_type'  => 'client',
            'type'       => 'new_message',
            'title'      => 'New Message',
            'body'       => "New message from {$coachName}",
            'data'       => [
                'coachId'   => $coachId,
                'coachName' => $coachName,
                'messageId' => $messageId,
            ],
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $notification['data']);
    }

    /**
     * Notify client when a coach is assigned to them
     */
    public function notifyClientCoachAssigned(string $clientId, string $coachName, string $coachId): void
    {
        $notification = [
            'user_id'    => new ObjectId($clientId),
            'user_type'  => 'client',
            'type'       => 'coach_assigned',
            'title'      => 'New Coach Assigned',
            'body'       => "{$coachName} is now your primary coach",
            'data'       => ['coach_id' => $coachId, 'coach_name' => $coachName],
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $notification['data']);
    }

    /**
     * Notify client when a new workout plan is assigned to them
     */
    public function notifyClientNewPlan(string $planTitle, string $planId, string $clientId, string $planType = 'workout'): void
    {
        $typeLabels = [
            'workout' => 'Workout Plan',
            'nutrition' => 'Nutrition Plan',
        ];
        $label = $typeLabels[$planType] ?? 'Plan';
        $data  = [
            'planId'    => $planId,
            'planTitle' => $planTitle,
            'planType'  => $planType,
        ];

        $notification = [
            'user_id'    => new ObjectId($clientId),
            'user_type'  => 'client',
            'type'       => "new_{$planType}_plan",
            'title'      => "New {$label}",
            'body'       => "Your new {$label} \"{$planTitle}\" is ready!",
            'data'       => $data,
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify client when their assigned workout plan is updated
     */
    public function notifyClientPlanUpdated(string $planTitle, string $planId, string $clientId, string $planType = 'workout'): void
    {
        $typeLabels = [
            'workout' => 'Workout Plan',
            'nutrition' => 'Nutrition Plan',
        ];
        $label = $typeLabels[$planType] ?? 'Plan';
        $data  = [
            'planId'    => $planId,
            'planTitle' => $planTitle,
            'planType'  => $planType,
        ];

        $notification = [
            'user_id'    => new ObjectId($clientId),
            'user_type'  => 'client',
            'type'       => "{$planType}_plan_updated",
            'title'      => "{$label} Updated",
            'body'       => "Your {$label} \"{$planTitle}\" has been updated.",
            'data'       => $data,
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify client when their coach schedules a check-in
     */
    public function notifyClientCheckinScheduled(string $clientId, string $checkinId, string $scheduledAt, string $type = 'chat'): void
    {
        $formattedTime = date('D M j, g:i A', strtotime($scheduledAt));
        $typeLabels = [
            'call' => 'Call',
            'video' => 'Video',
            'chat' => 'Chat',
        ];
        $typeLabel = $typeLabels[$type] ?? 'Check-in';
        $data = [
            'checkinId' => $checkinId,
            'scheduledAt' => $scheduledAt,
            'type' => $type,
        ];

        $notification = [
            'user_id'    => new ObjectId($clientId),
            'user_type'  => 'client',
            'type'       => 'checkin_scheduled',
            'title'      => 'New Check-in Scheduled',
            'body'       => "Your coach scheduled a {$typeLabel} for {$formattedTime}.",
            'data'       => $data,
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify coach that a client requested to join a live training session
     */
    public function notifyCoachLiveRequest(string $coachId, string $sessionId, string $sessionTitle, string $clientId, string $clientName): void
    {
        $data = [
            'sessionId'   => $sessionId,
            'sessionTitle'=> $sessionTitle,
            'clientId'    => $clientId,
            'clientName'  => $clientName,
        ];

        $notification = [
            'user_id'    => new ObjectId($coachId),
            'user_type'  => 'coach',
            'type'       => 'live_training_request',
            'title'      => 'New Join Request',
            'body'       => "{$clientName} requested to join \"{$sessionTitle}\".",
            'data'       => $data,
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToCoach(new ObjectId($coachId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify client that their live training request was approved or rejected
     */
    public function notifyClientLiveRequestStatus(string $clientId, string $sessionId, string $sessionTitle, string $status): void
    {
        $isApproved = $status === 'approved';
        $data = [
            'sessionId'   => $sessionId,
            'sessionTitle'=> $sessionTitle,
            'status'      => $status,
        ];

        $notification = [
            'user_id'    => new ObjectId($clientId),
            'user_type'  => 'client',
            'type'       => 'live_training_request_' . $status,
            'title'      => $isApproved ? 'Join Request Approved' : 'Join Request Declined',
            'body'       => $isApproved
                ? "You're in! See you at \"{$sessionTitle}\"."
                : "Your request to join \"{$sessionTitle}\" was declined.",
            'data'       => $data,
            'read'       => false,
            'sent_at'    => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $data);
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
     * Helper: Check if DND (Do Not Disturb) is active for a given coach.
     * DND settings are stored in coach.notification_settings.
     * Respects coach.timezone if set; falls back to UTC.
     */
    private function isDndActive(array $coach): bool
    {
        $settings = $coach['notification_settings'] ?? [];
        if (empty($settings['dnd_enabled'])) {
            return false;
        }

        $timezone = $coach['timezone'] ?? 'UTC';
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'UTC';
        }

        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $from = $settings['dnd_from'] ?? '22:00';
        $to   = $settings['dnd_to']   ?? '08:00';

        $currentMinutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        [$fromHour, $fromMin] = array_map('intval', explode(':', $from));
        [$toHour, $toMin]     = array_map('intval', explode(':', $to));
        $fromMinutes = $fromHour * 60 + $fromMin;
        $toMinutes   = $toHour * 60 + $toMin;

        if ($fromMinutes === $toMinutes) {
            return false; // No DND window set
        }

        if ($fromMinutes < $toMinutes) {
            return $currentMinutes >= $fromMinutes && $currentMinutes < $toMinutes;
        }
        // DND window wraps across midnight (e.g., 22:00 → 08:00)
        return $currentMinutes >= $fromMinutes || $currentMinutes < $toMinutes;
    }

    /**
     * Helper: Send push notification to coach
     */
    private function sendPushToCoach(ObjectId $coachId, string $title, string $body, array $data = []): void
    {
        $coach = Database::getInstance()->getCollection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach || empty($coach['fcm_token'])) return;

        if ($this->isDndActive((array) $coach)) {
            // Suppress push during DND — the in-app notification is still stored
            return;
        }

        FcmService::send($coach['fcm_token'], $title, $body, $data);
    }

    /**
     * Notify staff participants when a team meeting is scheduled
     */
    public function notifyTeamMeetingScheduled(string $staffId, string $staffName, string $meetingId, string $title, string $scheduledAt, bool $isGroup): void
    {
        $formattedTime = date('D M j, g:i A', strtotime($scheduledAt));
        $meetingType = $isGroup ? 'Group meeting' : '1-on-1 meeting';
        $data = [
            'meetingId' => $meetingId,
            'title' => $title,
            'scheduledAt' => $scheduledAt,
            'isGroup' => $isGroup,
        ];

        $notification = [
            'user_id' => new ObjectId($staffId),
            'user_type' => 'staff',
            'type' => 'team_meeting_scheduled',
            'title' => 'New Team Meeting',
            'body' => "{$meetingType} \"{$title}\" scheduled for {$formattedTime}.",
            'data' => $data,
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToStaff(new ObjectId($staffId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify coach that a client requested a meeting
     */
    public function notifyClientMeetingRequest(string $coachId, string $clientId, string $clientName, string $checkinId, string $scheduledAt, string $type): void
    {
        $formattedTime = date('D M j, g:i A', strtotime($scheduledAt));
        $typeLabels = ['call' => 'Call', 'video' => 'Video', 'chat' => 'Chat'];
        $typeLabel = $typeLabels[$type] ?? 'Meeting';
        $data = [
            'checkinId' => $checkinId,
            'clientId' => $clientId,
            'clientName' => $clientName,
            'scheduledAt' => $scheduledAt,
            'type' => $type,
        ];

        $notification = [
            'user_id' => new ObjectId($coachId),
            'user_type' => 'coach',
            'type' => 'client_meeting_request',
            'title' => 'Client Meeting Request',
            'body' => "{$clientName} requested a {$typeLabel} for {$formattedTime}.",
            'data' => $data,
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToCoach(new ObjectId($coachId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify client that their meeting request was approved
     */
    public function notifyClientMeetingApproved(string $clientId, string $checkinId, string $scheduledAt): void
    {
        $formattedTime = date('D M j, g:i A', strtotime($scheduledAt));
        $data = [
            'checkinId' => $checkinId,
            'scheduledAt' => $scheduledAt,
        ];

        $notification = [
            'user_id' => new ObjectId($clientId),
            'user_type' => 'client',
            'type' => 'meeting_request_approved',
            'title' => 'Meeting Approved',
            'body' => "Your meeting request for {$formattedTime} was approved.",
            'data' => $data,
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $data);
    }

    /**
     * Notify client that their meeting request was declined
     */
    public function notifyClientMeetingDeclined(string $clientId, string $checkinId, string $scheduledAt): void
    {
        $formattedTime = date('D M j, g:i A', strtotime($scheduledAt));
        $data = [
            'checkinId' => $checkinId,
            'scheduledAt' => $scheduledAt,
        ];

        $notification = [
            'user_id' => new ObjectId($clientId),
            'user_type' => 'client',
            'type' => 'meeting_request_declined',
            'title' => 'Meeting Declined',
            'body' => "Your meeting request for {$formattedTime} was declined.",
            'data' => $data,
            'read' => false,
            'sent_at' => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ];

        $this->collection->insertOne($notification);
        $this->sendPushToClient(new ObjectId($clientId), $notification['title'], $notification['body'], $data);
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

    /**
     * Helper: Send push notification to staff member
     */
    private function sendPushToStaff(ObjectId $staffId, string $title, string $body, array $data = []): void
    {
        $staff = Database::getInstance()->getCollection('staff')->findOne(['_id' => $staffId]);
        if (!$staff || empty($staff['fcm_token'])) return;

        FcmService::send($staff['fcm_token'], $title, $body, $data);
    }
}
