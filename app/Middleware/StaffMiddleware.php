<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use MongoDB\BSON\ObjectId;

/**
 * Resolves organization-scoped access for either:
 *  - a legacy coach JWT (role=coach)  => implicit "owner" of org_id = coach._id
 *  - a staff JWT (role=staff)         => explicit staff doc with org_id + role
 *
 * This lets every existing solo coach account keep working unchanged (they are
 * automatically the Owner of their own organization) while allowing additional
 * staff accounts to be invited and scoped to the same organization.
 *
 * Sets on $params:
 *   _coach  => the organization root document (the `coaches` collection doc)
 *   _org_id => string org id (== coach._id for both legacy coach and staff)
 *   _role   => 'owner' | 'admin' | 'manager' | 'front_desk' | 'instructor_coach'
 *   _staff  => the staff doc array, or null when the caller is the legacy coach/owner
 *
 * Controllers are responsible for checking `$params['_role']` against the set
 * of roles allowed to perform a given action (mirrors the existing manual RLS
 * checks already used throughout the codebase).
 */
class StaffMiddleware extends AuthMiddleware
{
    public function handle(array &$params): void
    {
        parent::handle($params);
        $auth = $params['_auth'];
        $role = $auth['role'] ?? '';

        if ($role === 'coach') {
            try {
                $coach = Database::collection('coaches')->findOne([
                    '_id' => new ObjectId((string) $auth['sub']),
                ]);
            } catch (\InvalidArgumentException | \MongoDB\Driver\Exception\InvalidArgumentException $e) {
                $coach = null;
            }
            if (!$coach) Response::error('Coach not found', 403);
            if (($coach['active'] ?? true) !== true) Response::error('Coach access blocked', 403);

            $params['_coach']  = $coach;
            $params['_org_id'] = (string) $coach['_id'];
            $params['_role']   = 'owner';
            $params['_staff']  = null;
        } elseif ($role === 'staff') {
            try {
                $staff = Database::collection('staff')->findOne([
                    '_id' => new ObjectId((string) $auth['sub']),
                ]);
            } catch (\InvalidArgumentException | \MongoDB\Driver\Exception\InvalidArgumentException $e) {
                $staff = null;
            }
            if (!$staff) Response::error('Staff account not found', 403);
            if (($staff['status'] ?? '') !== 'active') Response::error('Staff account is not active', 403);

            $org = Database::collection('coaches')->findOne(['_id' => $staff['org_id']]);
            if (!$org) Response::error('Organization not found', 403);
            if (($org['active'] ?? true) !== true) Response::error('Organization access blocked', 403);

            $params['_coach']  = $org;
            $params['_org_id'] = (string) $staff['org_id'];
            $params['_role']   = $staff['role'];
            $params['_staff']  = $staff;
        } else {
            Response::error('Forbidden: staff or coach access required', 403);
        }

        \App\Helpers\RequestContext::setAuth([
            'sub'        => (string) $auth['sub'],
            'role'       => $role,
            'org_id'     => $params['_org_id'],
            'coach_id'   => $params['_org_id'],
            'staff_role' => $params['_role'],
        ]);
    }
}
