<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

/**
 * Per-route RBAC gate for organization-scoped endpoints.
 *
 * StaffMiddleware resolves the caller's staff role onto $params['_role']
 * ('owner' | 'admin' | 'manager' | 'front_desk' | 'instructor_coach').
 * This middleware rejects the request (403) unless that role is in the
 * allowed list supplied to the constructor, e.g.:
 *
 *   new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])
 *
 * Must be registered AFTER StaffMiddleware in the middleware chain so
 * that $params['_role'] is populated. Note the Router instantiates
 * class-string middleware with `new $mw()` (no args), so to pass
 * constructor args you must register a pre-instantiated instance —
 * Router::dispatch() supports both class strings and instances.
 */
class RoleGateMiddleware
{
    /** @var string[] */
    private array $allowedRoles;

    /**
     * @param string[] $allowedRoles Roles permitted to access the route.
     */
    public function __construct(array $allowedRoles = [])
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle(array &$params): void
    {
        $role = $params['_role'] ?? null;

        if ($role === null || !in_array($role, $this->allowedRoles, true)) {
            Response::error(
                'Forbidden: your role does not have access to this action',
                403
            );
        }
    }
}