<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use MongoDB\BSON\{ObjectId, UTCDateTime};

class DashboardController
{
    private const MANAGE_ROLES = ['owner', 'admin'];

    // GET /dashboard/weekly-sessions
    public function weeklySessions(array $params): void
    {
        $role = $params['_role'] ?? '';
        if (!in_array($role, self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can view the weekly dashboard', 403);
        }

        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);

        // Determine week boundaries (Monday 00:00 → Sunday 23:59)
        $weekStartParam = $_GET['week_start'] ?? null;
        if ($weekStartParam) {
            $weekStart = new \DateTime($weekStartParam, new \DateTimeZone('UTC'));
            $weekStart->modify('monday this week');
        } else {
            $weekStart = new \DateTime('monday this week', new \DateTimeZone('UTC'));
        }
        $weekStart->setTime(0, 0, 0);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');
        $weekEnd->setTime(23, 59, 59);

        $weekStartTs = $weekStart->getTimestamp() * 1000;
        $weekEndTs = $weekEnd->getTimestamp() * 1000;

        $dateFilter = [
            '$gte' => new UTCDateTime($weekStartTs),
            '$lte' => new UTCDateTime($weekEndTs),
        ];

        // 1. Team meetings
        $teamMeetings = Database::collection('team_meetings')->find([
            'org_id' => $orgId,
            'scheduled_at' => $dateFilter,
            'status' => ['$ne' => 'cancelled'],
        ], ['sort' => ['scheduled_at' => 1]])->toArray();

        // 2. 1-on-1 coaching sessions
        $coachingSessions = Database::collection('coaching_sessions')->find([
            'coach_id' => $orgId,
            'scheduled_at' => $dateFilter,
            'status' => ['$ne' => 'cancelled'],
        ], ['sort' => ['scheduled_at' => 1]])->toArray();

        // 3. Client check-in meetings
        $clientMeetings = Database::collection('checkin_meetings')->find([
            'coach_id' => $orgId,
            'scheduled_at' => $dateFilter,
            'status' => ['$ne' => 'cancelled'],
        ], ['sort' => ['scheduled_at' => 1]])->toArray();

        // Enrich with names
        $formattedTeam = array_map(fn($m) => $this->formatTeamMeeting($m), $teamMeetings);
        $formattedCoaching = array_map(fn($s) => $this->formatCoachingSession($s), $coachingSessions);
        $formattedCheckins = array_map(fn($c) => $this->formatCheckin($c), $clientMeetings);

        Response::success([
            'week_start' => $weekStart->format('c'),
            'week_end' => $weekEnd->format('c'),
            'team_meetings' => $formattedTeam,
            'coaching_sessions' => $formattedCoaching,
            'client_meetings' => $formattedCheckins,
        ]);
    }

    private function formatTeamMeeting(object $doc): array
    {
        $data = (array) $doc;
        $participantIds = $data['participant_ids'] ?? [];
        $participantNames = [];

        if (!empty($participantIds)) {
            $staffCursor = Database::collection('staff')->find(
                ['_id' => ['$in' => $participantIds]],
                ['projection' => ['_id' => 1, 'name' => 1]]
            );
            $staffMap = [];
            foreach ($staffCursor as $s) {
                $staffMap[(string) $s['_id']] = $s['name'] ?? 'Unknown';
            }
            foreach ($participantIds as $pid) {
                $participantNames[] = [
                    'id' => (string) $pid,
                    'name' => $staffMap[(string) $pid] ?? 'Unknown',
                ];
            }
        }

        $scheduledAt = $data['scheduled_at'] ?? null;
        return [
            'id' => isset($data['_id']) ? (string) $data['_id'] : '',
            'type' => 'team_meeting',
            'title' => $data['title'] ?? '',
            'meeting_type' => $data['type'] ?? 'chat',
            'scheduled_at' => $scheduledAt ? date('c', (int) (((string) $scheduledAt) / 1000)) : null,
            'duration_min' => $data['duration_min'] ?? null,
            'status' => $data['status'] ?? 'scheduled',
            'is_group' => $data['is_group'] ?? false,
            'participants' => $participantNames,
            'created_by_name' => $data['created_by_name'] ?? '',
        ];
    }

    private function formatCoachingSession(object $doc): array
    {
        $data = (array) $doc;
        $clientId = isset($data['client_id']) ? (string) $data['client_id'] : '';
        $clientName = 'Client';
        if ($clientId) {
            $client = Database::collection('clients')->findOne(['_id' => new ObjectId($clientId)], ['projection' => ['name' => 1]]);
            if ($client) {
                $clientName = $client['name'] ?? 'Client';
            }
        }

        $scheduledAt = $data['scheduled_at'] ?? null;
        return [
            'id' => isset($data['_id']) ? (string) $data['_id'] : '',
            'type' => 'coaching_session',
            'title' => $data['title'] ?? '',
            'scheduled_at' => $scheduledAt ? date('c', (int) (((string) $scheduledAt) / 1000)) : null,
            'duration_min' => $data['duration_min'] ?? null,
            'status' => $data['status'] ?? 'upcoming',
            'client_id' => $clientId,
            'client_name' => $clientName,
        ];
    }

    private function formatCheckin(object $doc): array
    {
        $data = (array) $doc;
        $clientId = isset($data['client_id']) ? (string) $data['client_id'] : '';
        $clientName = 'Client';
        if ($clientId) {
            $client = Database::collection('clients')->findOne(['_id' => new ObjectId($clientId)], ['projection' => ['name' => 1]]);
            if ($client) {
                $clientName = $client['name'] ?? 'Client';
            }
        }

        $scheduledAt = $data['scheduled_at'] ?? null;
        return [
            'id' => isset($data['_id']) ? (string) $data['_id'] : '',
            'type' => 'client_meeting',
            'meeting_type' => $data['type'] ?? 'chat',
            'scheduled_at' => $scheduledAt ? date('c', (int) (((string) $scheduledAt) / 1000)) : null,
            'status' => $data['status'] ?? 'scheduled',
            'source' => $data['source'] ?? 'coach',
            'client_id' => $clientId,
            'client_name' => $clientName,
            'client_response' => $data['client_response'] ?? 'pending',
        ];
    }
}
