<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use MongoDB\BSON\ObjectId;

class ClientMiddleware extends AuthMiddleware
{
    public function handle(array &$params): void
    {
        parent::handle($params);
        if (($params['_auth']['role'] ?? '') !== 'client') {
            Response::error('Forbidden: Client access required', 403);
        }

        $client = Database::collection('clients')->findOne([
            '_id' => new ObjectId((string) $params['_auth']['sub']),
        ]);

        if (!$client) {
            Response::error('Client not found', 403);
        }

        // Check if client is blocked (is_blocked flag or inactive)
        if (($client['is_blocked'] ?? false) || !$client['active']) {
            Response::error('Client access blocked', 403);
        }

        // Verify token version matches — if coach blocked client, token_version was incremented
        $tokenVersion = $params['_auth']['token_version'] ?? 0;
        $dbVersion    = $client['token_version'] ?? 0;
        if ($tokenVersion !== $dbVersion) {
            Response::error('Session invalidated', 401);
        }

        $params['_client'] = $client;
    }
}
