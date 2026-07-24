<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\ConsentSigningService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Studio operations (Feature 9): class scheduling, bookings with waitlist
 * promotion, memberships, and front-desk check-ins gated on a signed
 * liability waiver.
 */
class StudioController
{
    private const SCHEDULE_ROLES  = ['owner', 'admin', 'manager'];
    private const FRONT_DESK_ROLES = ['owner', 'admin', 'manager', 'front_desk'];

    // ── Classes ──────────────────────────────────────────────────────────────

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

    // GET /client/classes (client-facing upcoming class list)
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

    // ── Bookings ─────────────────────────────────────────────────────────────

    // POST /client/classes/:id/book
    public function bookAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::notFound('Client not found');

        $this->book($client['coach_id'], new ObjectId($params['id']), $clientId);
    }

    // POST /org/classes/:id/book  (staff booking on behalf of a client)
    public function bookAsStaff(array $params): void
    {
        if (!in_array($params['_role'], self::FRONT_DESK_ROLES, true)) {
            Response::error('Forbidden: insufficient role to book classes', 403);
        }
        $body = Request::body();
        $errors = Request::validate($body, ['client_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId((string) $body['client_id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $this->book($orgId, new ObjectId($params['id']), $clientId);
    }

    private function book(ObjectId $orgId, ObjectId $classId, ObjectId $clientId): void
    {
        $class = Database::collection('class_schedules')->findOne(['_id' => $classId, 'org_id' => $orgId]);
        if (!$class) Response::notFound('Class not found');

        $existing = Database::collection('class_bookings')->findOne([
            'class_schedule_id' => $classId,
            'client_id'         => $clientId,
            'status'            => ['$in' => ['confirmed', 'waitlisted']],
        ]);
        if ($existing) Response::error('Already booked for this class', 409);

        $confirmedCount = Database::collection('class_bookings')->countDocuments([
            'class_schedule_id' => $classId,
            'status'            => 'confirmed',
        ]);

        $now = new UTCDateTime();
        if ($confirmedCount < (int) $class['capacity']) {
            $status = 'confirmed';
            $waitlistPosition = null;
        } else {
            $status = 'waitlisted';
            $waitlistPosition = 1 + Database::collection('class_bookings')->countDocuments([
                'class_schedule_id' => $classId,
                'status'            => 'waitlisted',
            ]);
        }

        $insert = Database::collection('class_bookings')->insertOne([
            'org_id'             => $orgId,
            'class_schedule_id'  => $classId,
            'client_id'          => $clientId,
            'status'             => $status,
            'waitlist_position'  => $waitlistPosition,
            'booked_at'          => $now,
            'cancelled_at'       => null,
        ]);

        Response::success([
            'id'                => (string) $insert->getInsertedId(),
            'status'            => $status,
            'waitlist_position' => $waitlistPosition,
        ], $status === 'confirmed' ? 'Booking confirmed' : 'Added to waitlist', 201);
    }

    // POST /client/bookings/:id/cancel
    public function cancelAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $booking  = Database::collection('class_bookings')->findOne(['_id' => new ObjectId($params['id']), 'client_id' => $clientId]);
        if (!$booking) Response::notFound('Booking not found');

        $this->cancel($booking);
    }

    // POST /org/bookings/:id/cancel
    public function cancelAsStaff(array $params): void
    {
        if (!in_array($params['_role'], self::FRONT_DESK_ROLES, true)) {
            Response::error('Forbidden: insufficient role to cancel bookings', 403);
        }
        $orgId   = new ObjectId($params['_org_id']);
        $booking = Database::collection('class_bookings')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$booking) Response::notFound('Booking not found');

        $this->cancel($booking);
    }

    private function cancel(array $booking): void
    {
        if ($booking['status'] === 'cancelled') Response::error('Booking already cancelled', 409);

        $wasConfirmed = $booking['status'] === 'confirmed';

        Database::collection('class_bookings')->updateOne(
            ['_id' => $booking['_id']],
            ['$set' => ['status' => 'cancelled', 'cancelled_at' => new UTCDateTime(), 'waitlist_position' => null]]
        );

        // Promote the earliest waitlisted booking into the now-open confirmed slot.
        if ($wasConfirmed) {
            $next = Database::collection('class_bookings')->findOne(
                ['class_schedule_id' => $booking['class_schedule_id'], 'status' => 'waitlisted'],
                ['sort' => ['waitlist_position' => 1]]
            );
            if ($next) {
                Database::collection('class_bookings')->updateOne(
                    ['_id' => $next['_id']],
                    ['$set' => ['status' => 'confirmed', 'waitlist_position' => null]]
                );
            }
        }

        Response::success(null, 'Booking cancelled');
    }

    // ── Memberships ──────────────────────────────────────────────────────────

    // POST /org/memberships
    public function createMembership(array $params): void
    {
        if (!in_array($params['_role'], self::SCHEDULE_ROLES, true)) {
            Response::error('Forbidden: insufficient role to create memberships', 403);
        }

        $orgId  = new ObjectId($params['_org_id']);
        $body   = Request::body();
        $errors = Request::validate($body, ['client_id' => 'required', 'plan_name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $clientId = new ObjectId((string) $body['client_id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $now = new UTCDateTime();
        // NOTE: billing/payment collection (Stripe) is not wired up here — this
        // records the membership plan itself. Attach a Stripe subscription id
        // once the studio billing product/price catalog is finalised.
        $insert = Database::collection('memberships')->insertOne([
            'org_id'           => $orgId,
            'client_id'        => $clientId,
            'plan_name'        => trim($body['plan_name']),
            'billing_amount'   => isset($body['billing_amount']) ? (float) $body['billing_amount'] : null,
            'billing_interval' => $body['billing_interval'] ?? 'monthly',
            'status'           => 'active',
            'started_at'       => $now,
            'paused_at'        => null,
            'cancelled_at'     => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Membership created', 201);
    }

    // GET /org/memberships
    public function listMemberships(array $params): void
    {
        $orgId  = new ObjectId($params['_org_id']);
        $filter = ['org_id' => $orgId];

        $clientId = Request::get('client_id');
        if (is_string($clientId) && $clientId !== '') {
            $filter['client_id'] = new ObjectId($clientId);
        }

        $memberships = Database::collection('memberships')->find(
            $filter,
            ['sort' => ['created_at' => -1]]
        )->toArray();

        Response::success(array_map(fn($m) => $this->formatMembership($m), $memberships));
    }

    // POST /org/memberships/:id/status
    public function updateMembershipStatus(array $params): void
    {
        if (!in_array($params['_role'], self::FRONT_DESK_ROLES, true)) {
            Response::error('Forbidden: insufficient role to update memberships', 403);
        }

        $orgId  = new ObjectId($params['_org_id']);
        $body   = Request::body();
        $errors = Request::validate($body, ['status' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $status = $body['status'];
        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) {
            Response::error('Invalid status. Must be one of: active, paused, cancelled', 422);
        }

        $membership = Database::collection('memberships')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$membership) Response::notFound('Membership not found');

        $now = new UTCDateTime();
        $set = ['status' => $status, 'updated_at' => $now];
        if ($status === 'paused') $set['paused_at'] = $now;
        if ($status === 'cancelled') $set['cancelled_at'] = $now;
        if ($status === 'active') { $set['paused_at'] = null; $set['cancelled_at'] = null; }

        Database::collection('memberships')->updateOne(['_id' => $membership['_id']], ['$set' => $set]);

        Response::success(null, 'Membership status updated');
    }

    // ── Consent documents (shared with the rehab track) ─────────────────────

    // POST /client/consent-documents
    public function signConsentAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::notFound('Client not found');

        $this->signConsent($client['coach_id'], $clientId, Request::body(), (string) $clientId);
    }

    private function signConsent(ObjectId $orgId, ObjectId $clientId, array $body, string $signerId): void
    {
        $errors = Request::validate($body, ['document_type' => 'required', 'document_version' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $signed = ConsentSigningService::sign(
            (string) $body['document_type'],
            (string) $body['document_version'],
            ['client_id' => $signerId]
        );

        $insert = Database::collection('consent_documents')->insertOne([
            'org_id'           => $orgId,
            'client_id'        => $clientId,
            'document_type'    => $body['document_type'],
            'document_version' => $body['document_version'],
            'signature_hash'   => $signed['signature_hash'],
            'signed_at'        => $signed['signed_at'],
            'created_at'       => new UTCDateTime(),
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Consent recorded', 201);
    }

    // ── Check-ins ────────────────────────────────────────────────────────────

    // POST /org/clients/:id/checkin  (front-desk / staff)
    public function checkinAsStaff(array $params): void
    {
        if (!in_array($params['_role'], self::FRONT_DESK_ROLES, true)) {
            Response::error('Forbidden: insufficient role to check clients in', 403);
        }
        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $this->checkin($orgId, $clientId, 'staff');
    }

    // POST /client/checkin  (self check-in)
    public function checkinAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::notFound('Client not found');

        $this->checkin($client['coach_id'], $clientId, 'self');
    }

    private function checkin(ObjectId $orgId, ObjectId $clientId, string $method): void
    {
        $waiver = Database::collection('consent_documents')->findOne([
            'org_id'        => $orgId,
            'client_id'     => $clientId,
            'document_type' => 'studio_liability_waiver',
        ]);
        if (!$waiver) {
            Response::error('A signed liability waiver is required before check-in', 412);
        }

        $insert = Database::collection('checkins')->insertOne([
            'org_id'        => $orgId,
            'client_id'     => $clientId,
            'method'        => $method,
            'checked_in_at' => new UTCDateTime(),
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Checked in', 201);
    }

    // GET /org/reports/utilization
    public function utilizationReport(array $params): void
    {
        if (!in_array($params['_role'], self::SCHEDULE_ROLES, true)) {
            Response::error('Forbidden: insufficient role to view reports', 403);
        }

        $orgId    = new ObjectId($params['_org_id']);
        $fromTs   = Request::get('from') ? strtotime((string) Request::get('from')) : strtotime('-30 days');
        $toTs     = Request::get('to') ? strtotime((string) Request::get('to')) : time();
        $fromBson = new UTCDateTime($fromTs * 1000);
        $toBson   = new UTCDateTime($toTs * 1000);

        $classCount = Database::collection('class_schedules')->countDocuments([
            'org_id' => $orgId, 'starts_at' => ['$gte' => $fromBson, '$lte' => $toBson],
        ]);
        $bookingCount = Database::collection('class_bookings')->countDocuments([
            'org_id' => $orgId, 'status' => 'confirmed', 'booked_at' => ['$gte' => $fromBson, '$lte' => $toBson],
        ]);
        $checkinCount = Database::collection('checkins')->countDocuments([
            'org_id' => $orgId, 'checked_in_at' => ['$gte' => $fromBson, '$lte' => $toBson],
        ]);
        $activeMemberships = Database::collection('memberships')->countDocuments([
            'org_id' => $orgId, 'status' => 'active',
        ]);

        Response::success([
            'classes_scheduled'   => $classCount,
            'confirmed_bookings'  => $bookingCount,
            'checkins'            => $checkinCount,
            'active_memberships'  => $activeMemberships,
        ]);
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

    private function formatMembership(array $m): array
    {
        return [
            'id'               => (string) $m['_id'],
            'client_id'        => (string) $m['client_id'],
            'plan_name'        => $m['plan_name'],
            'billing_amount'   => $m['billing_amount'] ?? null,
            'billing_interval' => $m['billing_interval'] ?? null,
            'status'           => $m['status'],
            'started_at'       => isset($m['started_at']) ? (string) $m['started_at'] : null,
        ];
    }
}
