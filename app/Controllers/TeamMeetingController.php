<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{NotificationTriggerService, ActivityLogService};
use MongoDB\BSON\{ObjectId, UTCDateTime};

class TeamMeetingController
{
    private const ALLOWED_TYPES = ['call', 'video', 'chat', 'in_person'];
    private const ALLOWED_STATUSES = ['scheduled', 'completed', 'cancelled'];
    private const MANAGE_ROLES = ['owner', 'admin'];

    // GET /team-meetings
    public function index(array $params): void
    {
        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $role = $params['_role'] ?? '';
        $staffId = $params['_staff']['_id'] ?? null;

        $status = Request::get('status');
        $from = Request::get('from');
        $to = Request::get('to');

        $filter = [];

        if ($status && in_array($status, self::ALLOWED_STATUSES, true)) {
            $filter['status'] = $status;
        }

        if ($from || $to) {
            $dateFilter = [];
            if ($from) {
                $dateFilter['$gte'] = new UTCDateTime(strtotime($from) * 1000);
            }
            if ($to) {
                $dateFilter['$lte'] = new UTCDateTime(strtotime($to) * 1000);
            }
            if (!empty($dateFilter)) {
                $filter['scheduled_at'] = $dateFilter;
            }
        }

        // Owner/admin sees all meetings in org; other staff see only where they are a participant
        if (in_array($role, self::MANAGE_ROLES, true)) {
            $filter['org_id'] = $orgId;
        } elseif ($staffId) {
            $filter['$and'] = [
                ['org_id' => $orgId],
                ['participant_ids' => ['$in' => [$staffId]]],
            ];
        } else {
            // Legacy coach JWT (owner) — see all org meetings
            $filter['org_id'] = $orgId;
        }

        $meetings = Database::collection('team_meetings')
            ->find($filter, ['sort' => ['scheduled_at' => 1]])
            ->toArray();

        Response::success(array_map(fn($m) => $this->format($m, $orgId), $meetings));
    }

    // POST /team-meetings
    public function store(array $params): void
    {
        $role = $params['_role'] ?? '';
        if (!in_array($role, self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can schedule team meetings', 403);
        }

        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $createdBy = new ObjectId($params['_auth']['sub']);
        $createdByName = $params['_auth']['name'] ?? 'Admin';

        $body = Request::body();
        $errors = Request::validate($body, [
            'title' => 'required|min:1',
            'scheduled_at' => 'required',
            'type' => 'required',
            'participant_ids' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        if (!in_array($body['type'], self::ALLOWED_TYPES, true)) {
            Response::error('Validation failed', 422, ['type' => ['Must be call, video, chat, or in_person']]);
        }

        $participantIds = is_array($body['participant_ids']) ? $body['participant_ids'] : [$body['participant_ids']];
        if (empty($participantIds)) {
            Response::error('Validation failed', 422, ['participant_ids' => ['At least one participant is required']]);
        }

        // Verify all participants exist in staff collection and belong to this org
        $participantObjectIds = [];
        foreach ($participantIds as $pid) {
            try {
                $participantObjectIds[] = new ObjectId($pid);
            } catch (\Throwable) {
                Response::error('Validation failed', 422, ['participant_ids' => ["Invalid participant ID: {$pid}"]]);
            }
        }

        $staffCursor = Database::collection('staff')->find([
            '_id' => ['$in' => $participantObjectIds],
            'org_id' => $orgId,
            'status' => 'active',
        ]);
        $foundStaff = [];
        foreach ($staffCursor as $s) {
            $foundStaff[(string) $s['_id']] = $s;
        }

        foreach ($participantIds as $pid) {
            if (!isset($foundStaff[$pid])) {
                Response::error('Validation failed', 422, ['participant_ids' => ["Staff not found or not active: {$pid}"]]);
            }
        }

        // XSS guard on meeting_link
        $meetingLink = isset($body['meeting_link']) ? trim((string) $body['meeting_link']) : null;
        if ($meetingLink !== null && $meetingLink !== '') {
            $validated = filter_var($meetingLink, FILTER_VALIDATE_URL);
            if ($validated === false || !preg_match('#^https?://#i', $validated)) {
                Response::error('meeting_link must be an http(s) URL', 422);
            }
            $meetingLink = $validated;
        }

        $result = Database::collection('team_meetings')->insertOne([
            'org_id' => $orgId,
            'created_by' => $createdBy,
            'created_by_name' => $createdByName,
            'title' => $body['title'],
            'description' => $body['description'] ?? null,
            'type' => $body['type'],
            'scheduled_at' => new UTCDateTime(strtotime($body['scheduled_at']) * 1000),
            'duration_min' => isset($body['duration_min']) ? (int) $body['duration_min'] : null,
            'meeting_link' => $meetingLink,
            'notes' => $body['notes'] ?? null,
            'status' => 'scheduled',
            'participant_ids' => $participantObjectIds,
            'is_group' => count($participantObjectIds) > 1,
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);

        // Notify participants
        $triggerService = new NotificationTriggerService();
        foreach ($foundStaff as $sid => $staff) {
            try {
                $triggerService->notifyTeamMeetingScheduled(
                    $sid,
                    $staff['name'] ?? 'Staff',
                    (string) $result->getInsertedId(),
                    $body['title'],
                    $body['scheduled_at'],
                    count($participantObjectIds) > 1
                );
            } catch (\Throwable $e) {
                error_log('Failed to send team meeting notification: ' . $e->getMessage());
            }
        }

        ActivityLogService::log(
            'team_meeting_scheduled',
            null,
            null,
            [
                'meeting_id' => (string) $result->getInsertedId(),
                'title' => $body['title'],
                'participant_count' => count($participantObjectIds),
            ]
        );

        Response::success(['id' => (string) $result->getInsertedId()], 'Team meeting scheduled', 201);
    }

    // GET /team-meetings/:id
    public function show(array $params): void
    {
        $mtgId = new ObjectId($params['id']);
        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $role = $params['_role'] ?? '';
        $staffId = $params['_staff']['_id'] ?? null;

        $meeting = Database::collection('team_meetings')->findOne(['_id' => $mtgId]);
        if (!$meeting) Response::error('Meeting not found', 404);

        // Verify access
        if (!in_array($role, self::MANAGE_ROLES, true)) {
            if (!$staffId || !in_array($staffId, iterator_to_array($meeting['participant_ids']), true)) {
                Response::error('Forbidden', 403);
            }
        }

        Response::success($this->format($meeting, $orgId));
    }

    // PUT /team-meetings/:id
    public function update(array $params): void
    {
        $role = $params['_role'] ?? '';
        if (!in_array($role, self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can update team meetings', 403);
        }

        $mtgId = new ObjectId($params['id']);
        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);

        $meeting = Database::collection('team_meetings')->findOne(['_id' => $mtgId, 'org_id' => $orgId]);
        if (!$meeting) Response::error('Meeting not found', 404);

        $body = Request::body();
        $allowed = ['title', 'description', 'scheduled_at', 'type', 'duration_min', 'meeting_link', 'notes', 'participant_ids'];
        $set = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) continue;

            if ($field === 'scheduled_at') {
                $set[$field] = new UTCDateTime(strtotime($body[$field]) * 1000);
            } elseif ($field === 'duration_min') {
                $set[$field] = (int) $body[$field];
            } elseif ($field === 'meeting_link') {
                $val = is_string($body[$field]) ? trim($body[$field]) : '';
                if ($val === '') {
                    $set[$field] = '';
                } else {
                    $validated = filter_var($val, FILTER_VALIDATE_URL);
                    if ($validated === false || !preg_match('#^https?://#i', $validated)) {
                        Response::error('meeting_link must be an http(s) URL', 422);
                    }
                    $set[$field] = $validated;
                }
            } elseif ($field === 'participant_ids') {
                $participantIds = is_array($body[$field]) ? $body[$field] : [$body[$field]];
                $participantObjectIds = [];
                foreach ($participantIds as $pid) {
                    try {
                        $participantObjectIds[] = new ObjectId($pid);
                    } catch (\Throwable) {
                        Response::error('Validation failed', 422, ['participant_ids' => ["Invalid participant ID: {$pid}"]]);
                    }
                }
                // Verify participants belong to org
                $count = Database::collection('staff')->countDocuments([
                    '_id' => ['$in' => $participantObjectIds],
                    'org_id' => $orgId,
                ]);
                if ($count !== count($participantObjectIds)) {
                    Response::error('Validation failed', 422, ['participant_ids' => ['One or more participants not found in organization']]);
                }
                $set[$field] = $participantObjectIds;
                $set['is_group'] = count($participantObjectIds) > 1;
            } elseif ($field === 'type') {
                if (!in_array($body[$field], self::ALLOWED_TYPES, true)) {
                    Response::error('Validation failed', 422, ['type' => ['Must be call, video, chat, or in_person']]);
                }
                $set[$field] = $body[$field];
            } else {
                $set[$field] = $body[$field];
            }
        }

        if (!empty($set)) {
            $set['updated_at'] = new UTCDateTime();
            Database::collection('team_meetings')->updateOne(['_id' => $mtgId], ['$set' => $set]);
        }

        Response::success(null, 'Meeting updated');
    }

    // DELETE /team-meetings/:id
    public function destroy(array $params): void
    {
        $role = $params['_role'] ?? '';
        if (!in_array($role, self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can cancel team meetings', 403);
        }

        $mtgId = new ObjectId($params['id']);
        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);

        $meeting = Database::collection('team_meetings')->findOne(['_id' => $mtgId, 'org_id' => $orgId]);
        if (!$meeting) Response::error('Meeting not found', 404);

        Database::collection('team_meetings')->updateOne(
            ['_id' => $mtgId],
            ['$set' => ['status' => 'cancelled', 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Meeting cancelled');
    }

    // POST /team-meetings/:id/status
    public function updateStatus(array $params): void
    {
        $role = $params['_role'] ?? '';
        if (!in_array($role, self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can update team meeting status', 403);
        }

        $mtgId = new ObjectId($params['id']);
        $orgId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body = Request::body();
        $status = $body['status'] ?? null;

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            Response::error('Validation failed', 422, ['status' => ['Must be scheduled, completed, or cancelled']]);
        }

        $meeting = Database::collection('team_meetings')->findOne(['_id' => $mtgId, 'org_id' => $orgId]);
        if (!$meeting) Response::error('Meeting not found', 404);

        Database::collection('team_meetings')->updateOne(
            ['_id' => $mtgId],
            ['$set' => ['status' => $status, 'updated_at' => new UTCDateTime()]]
        );

        $messages = ['scheduled' => 'Meeting reactivated', 'completed' => 'Meeting marked as completed', 'cancelled' => 'Meeting cancelled'];
        Response::success(null, $messages[$status] ?? 'Status updated');
    }

    private function format(object $doc, ObjectId $orgId): array
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
            'org_id' => isset($data['org_id']) ? (string) $data['org_id'] : '',
            'created_by' => isset($data['created_by']) ? (string) $data['created_by'] : '',
            'created_by_name' => $data['created_by_name'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'chat',
            'scheduled_at' => $scheduledAt ? date('c', (int) (((string) $scheduledAt) / 1000)) : null,
            'duration_min' => $data['duration_min'] ?? null,
            'meeting_link' => $data['meeting_link'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'scheduled',
            'participants' => $participantNames,
            'is_group' => $data['is_group'] ?? false,
            'created_at' => isset($data['created_at']) ? date('c', (int) (((string) $data['created_at']) / 1000)) : null,
            'updated_at' => isset($data['updated_at']) ? date('c', (int) (((string) $data['updated_at']) / 1000)) : null,
        ];
    }
}
