<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class CoachMiddleware extends AuthMiddleware
{
    public function handle(array &$params): void
    {
        parent::handle($params);
        if (($params['_auth']['role'] ?? '') !== 'coach') {
            Response::error('Forbidden: Coach access required', 403);
        }

        // Verify coach still exists and is active (token invalidation fix)
        $coach = \App\Config\Database::collection('coaches')->findOne([
            '_id' => new \MongoDB\BSON\ObjectId((string) $params['_auth']['sub']),
        ]);
        if (!$coach) {
            Response::error('Coach not found', 403);
        }

        $params['_coach'] = $coach;
    }
}
