<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Services\JwtService;

class SetupAuthMiddleware
{
    public function handle(array &$params): void
    {
        // Accept token from Authorization header
        $token = \App\Helpers\Request::bearerToken();
        if (!$token) {
            Response::error('Unauthorized: No setup token provided', 401);
        }

        $coachId = JwtService::verifySetupToken($token);
        if (!$coachId) {
            Response::error('Unauthorized: Invalid or expired setup token', 401);
        }

        // Load coach and verify they exist
        $coach = Database::collection('coaches')->findOne(['_id' => new \MongoDB\BSON\ObjectId($coachId)]);
        if (!$coach) {
            Response::error('Coach not found', 404);
        }

        // Verify coach is in pending subscription state
        $subscriptionStatus = $coach['subscription_status'] ?? 'none';
        if ($subscriptionStatus !== 'pending' && $subscriptionStatus !== 'none') {
            Response::error('Subscription already configured', 409);
        }

        // Inject auth data into params
        $params['_auth'] = [
            'sub'  => $coachId,
            'type' => 'setup',
        ];
    }
}
