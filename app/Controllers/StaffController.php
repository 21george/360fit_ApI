<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{EmailService, JwtService};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Multi-trainer organization staff management (Feature 6: Organizations & RBAC).
 *
 * Roles: owner, admin, manager, front_desk, instructor_coach.
 * The organization root is still the `coaches` collection document (org_id ==
 * coaches._id) so every existing solo coach keeps working unchanged as the
 * implicit "owner" of their own organization — no data migration required.
 */
class StaffController
{
    private const MANAGE_ROLES = ['owner', 'admin'];
    private const VALID_ROLES  = ['admin', 'manager', 'front_desk', 'instructor_coach'];

    // POST /org/staff/invite
    public function invite(array $params): void
    {
        if (!in_array($params['_role'], self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can invite staff', 403);
        }

        $body   = Request::body();
        $errors = Request::validate($body, ['email' => 'required|email', 'role' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $role = $body['role'];
        if (!in_array($role, self::VALID_ROLES, true)) {
            Response::error('Invalid role. Must be one of: ' . implode(', ', self::VALID_ROLES), 422);
        }

        $email  = strtolower(trim($body['email']));
        $orgId  = new ObjectId($params['_org_id']);

        $existing = Database::collection('staff')->findOne(['org_id' => $orgId, 'email' => $email]);
        if ($existing) Response::error('A staff invite for this email already exists', 409);

        $now = new UTCDateTime();
        $insert = Database::collection('staff')->insertOne([
            'org_id'      => $orgId,
            'email'       => $email,
            'name'        => trim($body['name'] ?? ''),
            'role'        => $role,
            'status'      => 'invited',
            'invited_by'  => new ObjectId($params['_auth']['sub']),
            'invited_at'  => $now,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $staffId = (string) $insert->getInsertedId();

        $inviteToken = JwtService::generateStaffInviteToken($staffId);
        $orgName     = $params['_coach']['name'] ?? 'the team';
        $appUrl      = rtrim($_ENV['FRONTEND_URL'] ?? 'https://app.example.com', '/');
        $inviteUrl   = "{$appUrl}/staff/accept-invite?token=" . urlencode($inviteToken);

        EmailService::send(
            $email,
            "You've been invited to join {$orgName}",
            "<p>You've been invited to join <strong>{$orgName}</strong> as a <strong>{$role}</strong>.</p>"
            . "<p><a href=\"{$inviteUrl}\">Accept your invite</a> to set up your account. This link expires in 72 hours.</p>"
        );

        Response::success(['id' => $staffId], 'Invite sent', 201);
    }

    // GET /org/staff
    public function index(array $params): void
    {
        $orgId = new ObjectId($params['_org_id']);
        $staff = Database::collection('staff')->find(
            ['org_id' => $orgId],
            ['sort' => ['created_at' => 1]]
        )->toArray();

        Response::success(array_map(fn($s) => $this->format($s), $staff));
    }

    // POST /org/staff/accept-invite  (public — authenticated via invite token, not StaffMiddleware)
    public function acceptInvite(array $params): void
    {
        $body  = Request::body();
        $token = $body['token'] ?? '';
        $errors = Request::validate($body, ['token' => 'required', 'password' => 'required|min:8', 'name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $staffId = JwtService::verifyStaffInviteToken((string) $token);
        if (!$staffId) Response::error('Invalid or expired invite token', 401);

        $staff = Database::collection('staff')->findOne(['_id' => new ObjectId($staffId)]);
        if (!$staff) Response::error('Staff invite not found', 404);
        if (($staff['status'] ?? '') === 'active') Response::error('Invite already accepted', 409);

        Database::collection('staff')->updateOne(
            ['_id' => $staff['_id']],
            ['$set' => [
                'name'          => trim($body['name']),
                'password_hash' => password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                'status'        => 'active',
                'activated_at'  => new UTCDateTime(),
                'updated_at'    => new UTCDateTime(),
            ]]
        );

        Response::success(['id' => $staffId], 'Account activated. You can now log in.');
    }

    // PATCH /org/staff/:id/role
    public function updateRole(array $params): void
    {
        if (!in_array($params['_role'], self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can change roles', 403);
        }

        $body   = Request::body();
        $errors = Request::validate($body, ['role' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        if (!in_array($body['role'], self::VALID_ROLES, true)) {
            Response::error('Invalid role. Must be one of: ' . implode(', ', self::VALID_ROLES), 422);
        }

        $orgId = new ObjectId($params['_org_id']);
        $staff = Database::collection('staff')->findOne([
            '_id'    => new ObjectId($params['id']),
            'org_id' => $orgId,
        ]);
        if (!$staff) Response::notFound('Staff member not found');

        Database::collection('staff')->updateOne(
            ['_id' => $staff['_id']],
            ['$set' => ['role' => $body['role'], 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Role updated');
    }

    // POST /org/staff/:id/deactivate
    public function deactivate(array $params): void
    {
        if (!in_array($params['_role'], self::MANAGE_ROLES, true)) {
            Response::error('Forbidden: only owners and admins can deactivate staff', 403);
        }

        $orgId = new ObjectId($params['_org_id']);
        $staffObjId = new ObjectId($params['id']);
        $staff = Database::collection('staff')->findOne(['_id' => $staffObjId, 'org_id' => $orgId]);
        if (!$staff) Response::notFound('Staff member not found');

        $body = Request::body();

        // Any clients whose primary staff assignment is this staff member must
        // be reassigned before deactivation, so no client is left unassigned.
        $assignedCount = Database::collection('clients')->countDocuments([
            'coach_id'         => $orgId,
            'primary_staff_id' => $staffObjId,
        ]);
        if ($assignedCount > 0) {
            $reassignTo = $body['reassign_to'] ?? null;
            if (!$reassignTo) {
                Response::error(
                    "This staff member is the primary trainer for {$assignedCount} client(s). Provide 'reassign_to' (staff id, or null for the org owner) to proceed.",
                    409
                );
            }
            $newPrimary = $reassignTo === 'owner' ? null : new ObjectId((string) $reassignTo);
            if ($newPrimary !== null) {
                $target = Database::collection('staff')->findOne(['_id' => $newPrimary, 'org_id' => $orgId, 'status' => 'active']);
                if (!$target) Response::error('reassign_to staff member not found or not active', 422);
            }
            Database::collection('clients')->updateMany(
                ['coach_id' => $orgId, 'primary_staff_id' => $staffObjId],
                ['$set' => ['primary_staff_id' => $newPrimary]]
            );
        }

        Database::collection('staff')->updateOne(
            ['_id' => $staffObjId],
            ['$set' => ['status' => 'deactivated', 'deactivated_at' => new UTCDateTime(), 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Staff member deactivated');
    }

    // PATCH /org/clients/:id/assignment
    public function assignClient(array $params): void
    {
        if (!in_array($params['_role'], ['owner', 'admin', 'manager'], true)) {
            Response::error('Forbidden: insufficient role to reassign clients', 403);
        }

        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $body = Request::body();
        $primaryStaffId = null;
        if (!empty($body['primary_staff_id']) && $body['primary_staff_id'] !== 'owner') {
            $staffObjId = new ObjectId((string) $body['primary_staff_id']);
            $staff = Database::collection('staff')->findOne(['_id' => $staffObjId, 'org_id' => $orgId, 'status' => 'active']);
            if (!$staff) Response::error('primary_staff_id must reference an active staff member', 422);
            $primaryStaffId = $staffObjId;
        }

        Database::collection('clients')->updateOne(
            ['_id' => $clientId],
            ['$set' => ['primary_staff_id' => $primaryStaffId, 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Client assignment updated');
    }

    private function format(array $s): array
    {
        return [
            'id'           => (string) $s['_id'],
            'org_id'       => (string) $s['org_id'],
            'email'        => $s['email'],
            'name'         => $s['name'] ?? '',
            'role'         => $s['role'],
            'status'       => $s['status'],
            'invited_at'   => isset($s['invited_at']) ? (string) $s['invited_at'] : null,
            'activated_at' => isset($s['activated_at']) ? (string) $s['activated_at'] : null,
        ];
    }
}
