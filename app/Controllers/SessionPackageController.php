<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Session Package Tracking Controller
 *
 * Coaches can sell session packages to clients. Each session completion
 * decrements the remaining count.
 */
class SessionPackageController
{
    /**
     * POST /clients/:id/session-packages
     * Body: { total_sessions, expires_at? }
     */
    public function store(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $body   = Request::body();
        $errors = Request::validate($body, ['total_sessions' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $total = max(1, (int) $body['total_sessions']);

        $expiresAt = null;
        if (!empty($body['expires_at'])) {
            $ts = strtotime($body['expires_at']);
            if ($ts !== false) $expiresAt = new UTCDateTime($ts * 1000);
        }

        $doc = [
            'client_id'      => $clientId,
            'coach_id'       => $coachId,
            'total_sessions' => $total,
            'used_sessions'  => 0,
            'status'         => 'active',
            'purchased_at'   => new UTCDateTime(),
            'expires_at'     => $expiresAt,
            'created_at'     => new UTCDateTime(),
            'updated_at'     => new UTCDateTime(),
        ];

        $insert = Database::collection('session_packages')->insertOne($doc);
        Response::success(['id' => (string) $insert->getInsertedId()] + $doc, 'Session package created', 201);
    }

    /**
     * GET /clients/:id/session-packages
     */
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $packages = Database::collection('session_packages')
            ->find(['client_id' => $clientId], ['sort' => ['created_at' => -1]])
            ->toArray();

        $now = new UTCDateTime();
        $formatted = [];
        foreach ($packages as $p) {
            $status = (string) ($p['status'] ?? 'active');
            $total  = (int) ($p['total_sessions'] ?? 0);
            $used   = (int) ($p['used_sessions'] ?? 0);
            $remaining = $total - $used;

            if ($remaining <= 0 && $status === 'active') {
                $status = 'depleted';
                Database::collection('session_packages')->updateOne(
                    ['_id' => $p['_id']],
                    ['$set' => ['status' => 'depleted', 'updated_at' => $now]]
                );
            }

            if (!empty($p['expires_at']) && $p['expires_at'] instanceof UTCDateTime && $p['expires_at'] <= $now && $status === 'active') {
                $status = 'expired';
                Database::collection('session_packages')->updateOne(
                    ['_id' => $p['_id']],
                    ['$set' => ['status' => 'expired', 'updated_at' => $now]]
                );
            }

            $formatted[] = [
                'id'              => (string) $p['_id'],
                'total_sessions'  => $total,
                'used_sessions'   => $used,
                'remaining'       => max(0, $remaining),
                'status'          => $status,
                'purchased_at'    => isset($p['purchased_at']) ? (string) $p['purchased_at'] : null,
                'expires_at'      => isset($p['expires_at']) ? (string) $p['expires_at'] : null,
            ];
        }

        Response::success($formatted);
    }

    /**
     * POST /session-packages/:id/use
     * Decrement remaining sessions by 1.
     */
    public function use(array $params): void
    {
        $coachId   = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $packageId = new ObjectId($params['id']);

        $package = Database::collection('session_packages')->findOne([
            '_id'      => $packageId,
            'coach_id' => $coachId,
        ]);
        if (!$package) Response::error('Package not found', 404);

        $total = (int) ($package['total_sessions'] ?? 0);
        $used  = (int) ($package['used_sessions'] ?? 0);

        if ($used >= $total) {
            Response::error('No remaining sessions in this package', 409);
        }

        $newUsed = $used + 1;
        $status  = ($newUsed >= $total) ? 'depleted' : (string) ($package['status'] ?? 'active');

        Database::collection('session_packages')->updateOne(
            ['_id' => $packageId],
            ['$set' => [
                'used_sessions' => $newUsed,
                'status'        => $status,
                'updated_at'    => new UTCDateTime(),
            ]]
        );

        Response::success([
            'remaining' => $total - $newUsed,
            'status'    => $status,
        ], 'Session used');
    }

    /**
     * DELETE /session-packages/:id
     */
    public function destroy(array $params): void
    {
        $coachId   = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $packageId = new ObjectId($params['id']);

        $package = Database::collection('session_packages')->findOne([
            '_id'      => $packageId,
            'coach_id' => $coachId,
        ]);
        if (!$package) Response::error('Package not found', 404);

        Database::collection('session_packages')->deleteOne(['_id' => $packageId]);
        Response::success(null, 'Package deleted');
    }

    /**
     * GET /client/session-packages
     * Client view of their own active packages.
     */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $packages = Database::collection('session_packages')
            ->find(
                ['client_id' => $clientId, 'status' => ['$in' => ['active', 'depleted']]],
                ['sort' => ['created_at' => -1]]
            )
            ->toArray();

        $formatted = [];
        foreach ($packages as $p) {
            $total = (int) ($p['total_sessions'] ?? 0);
            $used  = (int) ($p['used_sessions'] ?? 0);
            $formatted[] = [
                'id'             => (string) $p['_id'],
                'total_sessions' => $total,
                'used_sessions'  => $used,
                'remaining'      => max(0, $total - $used),
                'status'         => $p['status'] ?? 'active',
            ];
        }

        Response::success($formatted);
    }
}
