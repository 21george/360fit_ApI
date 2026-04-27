<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Controllers\SubscriptionController;
use App\Helpers\Response;
use MongoDB\BSON\ObjectId;

class SubscriptionMiddleware
{
    /**
     * Enforce client limit based on subscription tier.
     * Use this middleware on routes that create new clients.
     */
    public function handle(array &$params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);

        if (!$coach) {
            Response::error('Coach not found', 404);
            return;
        }

        $tier  = $coach['subscription_tier'] ?? 'free';
        $limit = SubscriptionController::getClientLimit($tier);

        $count = Database::collection('clients')->countDocuments([
            'coach_id' => $coachId,
            'active'   => true,
        ]);

        if ($count >= $limit) {
            Response::error(
                "Client limit reached ({$count}/{$limit}). Upgrade your plan to add more clients.",
                403,
                ['tier' => $tier, 'limit' => $limit, 'current' => $count, 'upgrade_required' => true]
            );
        }
    }
}
