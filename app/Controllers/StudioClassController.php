<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Studio class schedules (Feature 9).
 */
class StudioClassController
{
    private const SCHEDULE_ROLES = ['owner', 'admin', 'manager'];

    // POST /org/classes
    public function createClass(array $params): void
    {
        if (!in_array($params['_role'], self::SCHEDULE_ROLES, true)) {
            Response::error('Forbidden: insufficient role to schedule classes', 403);
        }

        $orgId  = new ObjectId($params['_org_id']);
        $body   = Request::body();
        $errors = Request::validate($body, [
            'title'      => 'required',
            'capacity'   => 'required|numeric',
            'starts_at'  => 'required',
            'ends_at'    => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $startsTs = strtotime((string) $body['starts_at']);
        $endsTs   = strtotime((string) $body['ends_at']);
        if ($startsTs === false || $endsTs === false || $endsTs <= $startsTs) {
            Response::error('Invalid starts_at/ends_at range', 422);
        }

        $instructorStaffId = null;
        if (!empty($body['instructor_staff_id'])) {
            $instructorStaffId = new ObjectId((string) $body['instructor_staff_id']);
            $staff = Database::collection('staff')->findOne(['_id' => $instructorStaffId, 'org_id' => $orgId]);
            if (!$staff) Response::error('instructor_staff_id not found in this organization', 422);
        }

        $now = new UTCDateTime();
        $insert = Database::collection('class_schedules')->insertOne([
            'org_id'              => $orgId,
            'title'               => trim($body['title']),
            'resource_name'       => $body['resource_name'] ?? null,
            'instructor_staff_id' => $instructorStaffId,
            'capacity'            => (int) $body['capacity'],
            'confirmed_count'     => 0,
            'starts_at'           => new UTCDateTime($startsTs * 1000),
            'ends_at'             => new UTCDateTime($endsTs * 1000),
            'recurrence_rule'     => $body['recurrence_rule'] ?? null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Class scheduled', 201);
    }

    // GET /org/classes
    public function index(array $params): void
    {
        $orgId  = new ObjectId($params['_org_id']);
        $filter = ['org_id' => $orgId];

        $from = Request::get('from');
        $to   = Request::get('to');
        if ($from || $to) {
            $filter['starts_at'] = [];
            if ($from) $filter['starts_at']['$gte'] = new UTCDateTime(strtotime((string) $from) * 1000);
            if ($to)   $filter['starts_at']['$lte'] = new UTCDateTime(strtotime((string) $to) * 1000);
        }

        $classes = Database::collection('class_schedules')->find(
            $filter,
            ['sort' => ['starts_at' => 1]]
        )->toArray();

        Response::success(array_map(fn($c) => $this->formatClass($c), $classes));
    }

    // GET /client/classes
    public function indexForClient(array $params): void
    {
        $client = Database::collection('clients')->findOne(['_id' => new ObjectId($params['_auth']['sub'])]);
        if (!$client) Response::notFound('Client not found');

        $classes = Database::collection('class_schedules')->find(
            ['org_id' => $client['coach_id'], 'starts_at' => ['$gte' => new UTCDateTime()]],
            ['sort' => ['starts_at' => 1]]
        )->toArray();

        Response::success(array_map(fn($c) => $this->formatClass($c), $classes));
    }

    private function formatClass(array $c): array
    {
        return [
            'id'                  => (string) $c['_id'],
            'title'               => $c['title'],
            'resource_name'       => $c['resource_name'] ?? null,
            'instructor_staff_id' => isset($c['instructor_staff_id']) ? (string) $c['instructor_staff_id'] : null,
            'capacity'            => $c['capacity'],
            'starts_at'           => (string) $c['starts_at'],
            'ends_at'             => (string) $c['ends_at'],
            'recurrence_rule'     => $c['recurrence_rule'] ?? null,
        ];
    }
}
