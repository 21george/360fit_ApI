<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\ConsentSigningService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Studio operations (Feature 9): consent documents, front-desk check-ins,
 * and utilization reports.
 *
 * Class scheduling, bookings and memberships have been extracted to
 * StudioClassController, StudioBookingController and StudioMembershipController.
 */
class StudioController
{
    private const SCHEDULE_ROLES  = ['owner', 'admin', 'manager'];
    private const FRONT_DESK_ROLES = ['owner', 'admin', 'manager', 'front_desk'];

    // POST /client/consent-documents
    public function signConsentAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::notFound('Client not found');

        $this->signConsent(new ObjectId((string) $client['coach_id']), $clientId, Request::body(), (string) $clientId);
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

        $this->checkin(new ObjectId((string) $client['coach_id']), $clientId, 'self');
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
}
