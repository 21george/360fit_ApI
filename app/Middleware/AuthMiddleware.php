<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware
{
    public function handle(array &$params): void
    {
        // Accept token from Authorization header (mobile) or httpOnly cookie (web)
        $token = \App\Helpers\Request::bearerToken() ?? ($_COOKIE['access_token'] ?? null);
        if (!$token) {
            Response::error('Unauthorized: No token provided', 401);
        }

        try {
            $secret = $_ENV['JWT_SECRET'] ?? '';
            if ($secret === '') {
                Response::error('Server configuration error', 500);
            }
            $decoded = (array) JWT::decode($token, new Key($secret, 'HS256'));

            // For clients, the access token embeds a token_version. If a coach
            // regenerates the login code (or blocks/deletes the client), the
            // stored version is bumped and any in-flight access token must be
            // rejected immediately, not just at refresh time.
            if (($decoded['role'] ?? '') === 'client' && isset($decoded['token_version'])) {
                $client = \App\Config\Database::collection('clients')->findOne([
                    '_id' => new \MongoDB\BSON\ObjectId((string) $decoded['sub']),
                ], ['projection' => ['token_version' => 1, 'active' => 1, 'is_blocked' => 1]]);

                if (!$client || !($client['active'] ?? true) || ($client['is_blocked'] ?? false)) {
                    Response::error('Client access blocked', 403);
                }

                if (((int) $decoded['token_version']) !== ((int) ($client['token_version'] ?? 0))) {
                    Response::error('Session invalidated. Please log in again.', 401);
                }
            }

            // Inject auth data into params so controllers can access it
            $params['_auth'] = $decoded;
        } catch (\Exception) {
            Response::error('Unauthorized', 401);
        }
    }
}
