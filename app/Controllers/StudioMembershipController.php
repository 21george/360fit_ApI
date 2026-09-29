<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Studio memberships (Feature 9).
 */
class StudioMembershipController
{
    private const SCHEDULE_ROLES  = ['owner', 'admin', 'manager'];
    private const FRONT_DESK_ROLES = ['owner', 'admin', 'manager', 'front_desk'];

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
