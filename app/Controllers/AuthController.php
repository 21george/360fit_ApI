<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{JwtService, CodeService};
use MongoDB\BSON\ObjectId;

class AuthController
{
    // ── Cookie helpers ──────────────────────────────────────────────────────────
    private function isSecure(): bool
    {
        return ($_ENV['APP_ENV'] ?? '') === 'production'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    }

    private function setTokenCookies(string $accessToken, string $refreshToken): void
    {
        $secure = $this->isSecure();

        setcookie('access_token', $accessToken, [
            'expires'  => time() + 900,       // 15 min
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);

        setcookie('refresh_token', $refreshToken, [
            'expires'  => time() + 2592000,   // 30 days
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);
    }

    private function clearTokenCookies(): void
    {
        $secure = $this->isSecure();
        $opts   = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Strict',
        ];
        setcookie('access_token', '', $opts);
        setcookie('refresh_token', '', $opts);
    }

    // POST /auth/coach/login
    public function coachLogin(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, ['email' => 'required|email', 'password' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $coach = Database::collection('coaches')->findOne(['email' => strtolower($body['email'])]);
        if (!$coach || !password_verify($body['password'], $coach['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        $payload = [
            'sub'      => (string) $coach['_id'],
            'role'     => 'coach',
            'name'     => $coach['name'],
            'email'    => $coach['email'],
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'coach'         => [
                'id'       => (string) $coach['_id'],
                'name'     => $coach['name'],
                'email'    => $coach['email'],
                'language' => $coach['language'] ?? 'en',
            ]
        ], 'Login successful');
    }

    // POST /auth/coach/register
    public function coachRegister(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, [
            'name'     => 'required|min:2',
            'email'    => 'required|email',
            'password' => 'required|min:8',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $col   = Database::collection('coaches');
        $email = strtolower($body['email']);

        if ($col->findOne(['email' => $email])) {
            Response::error('Email already registered', 409);
        }

        $result = $col->insertOne([
            'name'              => $body['name'],
            'email'             => $email,
            'password_hash'     => password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'language'          => $body['language'] ?? 'en',
            'client_count'      => 0,
            'subscription_tier'   => 'free',
            'subscription_status' => 'pending',
            'created_at'        => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'        => new \MongoDB\BSON\UTCDateTime(),
        ]);

        $coachId = (string) $result->getInsertedId();

        // Generate a short-lived setup token for subscription selection
        $setupToken = JwtService::generateSetupToken($coachId);

        Response::success([
            'id' => $coachId,
            'setup_token' => $setupToken,
        ], 'Registration successful. Please select your subscription plan.', 201);
    }

   

    // POST /auth/client/login
    public function clientLogin(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, ['code' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $code = strtoupper(trim($body['code']));

        // O(1) lookup using SHA-256 hash index, then bcrypt verify
        $codeLookup = CodeService::lookupHash($code);
        $matched = Database::collection('clients')->findOne([
            'code_lookup' => $codeLookup,
        ]);

        if (!$matched || !CodeService::verify($code, $matched['login_code_hash'] ?? '')) {
            Response::error('Invalid login code', 401);
        }

        if (($matched['active'] ?? true) !== true) {
            Response::error('Client access blocked', 403);
        }

        // Update FCM token if provided
        if (!empty($body['fcm_token'])) {
            Database::collection('clients')->updateOne(
                ['_id' => $matched['_id']],
                ['$set' => ['fcm_token' => $body['fcm_token'], 'last_login' => new \MongoDB\BSON\UTCDateTime()]]
            );
        }

        $payload = [
            'sub'           => (string) $matched['_id'],
            'role'          => 'client',
            'name'          => $matched['name'],
            'coach_id'      => (string) $matched['coach_id'],
            'token_version' => $matched['token_version'] ?? 0,
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'client'        => [
                'id'                => (string) $matched['_id'],
                'name'              => $matched['name'],
                'email'             => $matched['email'] ?? null,
                'phone'             => $matched['phone'] ?? null,
                'language'          => $matched['language'] ?? 'en',
                'profile_photo_url' => $matched['profile_photo_url'] ?? null,
                'notes'             => $matched['notes'] ?? null,
                'active'            => $matched['active'] ?? true,
                'created_at'        => (string) ($matched['created_at'] ?? ''),
            ]
        ], 'Login successful');
    }

    // POST /auth/logout
    public function logout(array $params): void
    {
        $this->clearTokenCookies();
        Response::success(null, 'Logged out');
    }

    // POST /auth/refresh
    public function refresh(array $params): void
    {
        $body  = Request::body();
        // Accept refresh token from body (mobile) or httpOnly cookie (web)
        $token = $body['refresh_token'] ?? $_COOKIE['refresh_token'] ?? '';
        if (!$token) Response::error('Refresh token required', 400);

        try {
            $decoded = JwtService::decodeRefresh($token);
            if (($decoded['type'] ?? '') !== 'refresh') Response::error('Invalid token type', 401);

            if (($decoded['role'] ?? '') === 'client') {
                $client = Database::collection('clients')->findOne([
                    '_id' => new ObjectId((string) $decoded['sub']),
                ]);

                if (!$client || ($client['is_blocked'] ?? false) || !$client['active']) {
                    $this->clearTokenCookies();
                    Response::error('Client access blocked', 403);
                }

                // Verify token version — reject if client was blocked (version bumped)
                $tokenVersion = $decoded['token_version'] ?? 0;
                $dbVersion    = $client['token_version'] ?? 0;
                if ($tokenVersion !== $dbVersion) {
                    $this->clearTokenCookies();
                    Response::error('Session invalidated', 401);
                }
            }

            $payload = [
                'sub'           => $decoded['sub'],
                'role'          => $decoded['role'],
                'name'          => $decoded['name'],
                'token_version' => $decoded['token_version'] ?? 0,
            ];
            if (isset($decoded['coach_id'])) $payload['coach_id'] = $decoded['coach_id'];
            if (isset($decoded['email']))    $payload['email']    = $decoded['email'];

            $accessToken  = JwtService::generateAccessToken($payload);
            $refreshToken = JwtService::generateRefreshToken($payload);
            $this->setTokenCookies($accessToken, $refreshToken);

            Response::success([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ]);
        } catch (\Exception $e) {
            Response::error('Invalid or expired refresh token', 401);
        }
    }
}
