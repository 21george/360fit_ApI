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
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            // Inject auth data into params so controllers can access it
            $params['_auth'] = (array) $decoded;
        } catch (\Exception $e) {
            Response::error('Unauthorized', 401);
        }
    }
}
