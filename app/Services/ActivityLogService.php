<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\RequestContext;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Activity logging for multi-trainer organizations.
 *
 * Records which staff member performed what action on which client,
 * so admins can see "who last worked with this client".
 */
class ActivityLogService
{
    /**
     * Log a staff activity. Automatically reads the current actor from RequestContext.
     *
     * @param string $action         e.g. 'client_created', 'checkin_scheduled', 'plan_assigned'
     * @param string|null $clientId  The affected client, if any
     * @param string|null $clientName The affected client's name, if any
     * @param array $metadata        Extra context (e.g. plan id, checkin type)
     */
    public static function log(string $action, ?string $clientId = null, ?string $clientName = null, array $metadata = []): void
    {
        $auth = RequestContext::getAuth();
        if (!$auth) {
            return;
        }

        $role = $auth['role'] ?? null;
        // Only log for staff roles (owner/admin/manager/front_desk/instructor_coach)
        if ($role === 'client' || $role === null) {
            return;
        }

        $orgId    = $auth['coach_id'] ?? ($auth['org_id'] ?? null);
        $staffId  = $auth['sub'] ?? null;
        $staffName = $auth['name'] ?? 'Staff';

        if (!$orgId || !$staffId) {
            return;
        }

        $now = new UTCDateTime();

        Database::collection('staff_activities')->insertOne([
            'org_id'      => new ObjectId((string) $orgId),
            'staff_id'    => new ObjectId((string) $staffId),
            'staff_name'  => $staffName,
            'staff_role'  => $role,
            'action'      => $action,
            'client_id'   => $clientId ? new ObjectId($clientId) : null,
            'client_name' => $clientName,
            'metadata'    => $metadata,
            'created_at'  => $now,
        ]);

        // If this activity touches a client, update the client's "last worked with" snapshot
        if ($clientId) {
            Database::collection('clients')->updateOne(
                ['_id' => new ObjectId($clientId)],
                ['$set' => [
                    'last_staff_id'         => new ObjectId((string) $staffId),
                    'last_staff_name'       => $staffName,
                    'last_staff_role'       => $role,
                    'last_staff_activity'   => $action,
                    'last_staff_activity_at' => $now,
                ]]
            );
        }
    }
}
