<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{CodeService, EmailService, JwtService};
use MongoDB\BSON\ObjectId;

class AuthController
{
    private const VERIFICATION_CODE_TTL_SECONDS = 900;
    private const VERIFICATION_RESEND_COOLDOWN_SECONDS = 60;

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

        $coachId = (string) $coach['_id'];
        $payload = [
            'sub'   => $coachId,
            'role'  => 'coach',
            'name'  => $coach['name'],
            'email' => $coach['email'],
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'coach'         => [
                'id'       => $coachId,
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

        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = new \MongoDB\BSON\UTCDateTime();

        $result = $col->insertOne([
            'name'              => $body['name'],
            'email'             => $email,
            'password_hash'     => password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'language'          => $body['language'] ?? 'en',
            'client_count'      => 0,
            'subscription_tier'   => 'free',
            'subscription_status' => 'pending',
            'email_verified_at' => null,
            'verification_code_hash' => password_hash($verificationCode, PASSWORD_BCRYPT, ['cost' => 12]),
            'verification_code_expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VERIFICATION_CODE_TTL_SECONDS) * 1000),
            'verification_code_sent_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $coachId = (string) $result->getInsertedId();

        // Generate a short-lived setup token for subscription selection
        $setupToken = JwtService::generateSetupToken($coachId);

        if (!EmailService::sendCoachVerificationCode($email, $body['name'], $verificationCode)) {
            error_log("Unable to send verification email for coach {$coachId}");
        }

        Response::success([
            'id' => $coachId,
            'setup_token' => $setupToken,
        ], 'Registration successful. Please select your subscription plan.', 201);
    }

    // POST /auth/verify-email
    public function verifyEmail(array $params): void
    {
        $body = Request::body();
        $errors = Request::validate($body, ['coach_id' => 'required', 'code' => 'required|numeric|min:6|max:6']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        try {
            $coachId = new ObjectId((string) $body['coach_id']);
        } catch (\Throwable) {
            Response::error('Invalid coach ID', 422);
        }

        $coaches = Database::collection('coaches');
        $coach = $coaches->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        if (($coach['email_verified_at'] ?? null) !== null) {
            Response::error('Email is already verified', 409);
        }

        $expiresAt = $coach['verification_code_expires_at'] ?? null;
        $isExpired = !$expiresAt || $expiresAt->toDateTime()->getTimestamp() < time();
        if ($isExpired || !CodeService::verify((string) $body['code'], (string) ($coach['verification_code_hash'] ?? ''))) {
            Response::error('Invalid or expired verification code', 422);
        }

        $now = new \MongoDB\BSON\UTCDateTime();
        $coaches->updateOne(['_id' => $coachId], ['$set' => [
            'email_verified_at' => $now,
            'updated_at' => $now,
        ], '$unset' => [
            'verification_code_hash' => '',
            'verification_code_expires_at' => '',
            'verification_code_sent_at' => '',
        ]]);

        Response::success($this->authenticatedCoachResponse($coach), 'Email verified');
    }

    // POST /auth/resend-verification
    public function resendVerification(array $params): void
    {
        $body = Request::body();
        $errors = Request::validate($body, ['coach_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        try {
            $coachId = new ObjectId((string) $body['coach_id']);
        } catch (\Throwable) {
            Response::error('Invalid coach ID', 422);
        }

        $coaches = Database::collection('coaches');
        $coach = $coaches->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);
        if (($coach['email_verified_at'] ?? null) !== null) Response::error('Email is already verified', 409);

        $sentAt = $coach['verification_code_sent_at'] ?? null;
        if ($sentAt && (time() - $sentAt->toDateTime()->getTimestamp()) < self::VERIFICATION_RESEND_COOLDOWN_SECONDS) {
            Response::error('Please wait before requesting another verification code', 429);
        }

        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = new \MongoDB\BSON\UTCDateTime();
        $coaches->updateOne(['_id' => $coachId], ['$set' => [
            'verification_code_hash' => password_hash($verificationCode, PASSWORD_BCRYPT, ['cost' => 12]),
            'verification_code_expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VERIFICATION_CODE_TTL_SECONDS) * 1000),
            'verification_code_sent_at' => $now,
            'updated_at' => $now,
        ]]);

        if (!EmailService::sendCoachVerificationCode((string) $coach['email'], (string) $coach['name'], $verificationCode)) {
            Response::error('Unable to send verification email', 503);
        }

        Response::success(null, 'Verification code sent');
    }

    private function authenticatedCoachResponse(object $coach): array
    {
        $coachId = (string) $coach['_id'];
        $payload = [
            'sub' => $coachId,
            'role' => 'coach',
            'name' => $coach['name'],
            'email' => $coach['email'],
        ];
        $accessToken = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->setTokenCookies($accessToken, $refreshToken);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'coach' => [
                'id' => $coachId,
                'name' => $coach['name'],
                'email' => $coach['email'],
                'language' => $coach['language'] ?? 'en',
            ],
        ];
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

        $clientId = (string) $matched['_id'];

        // Update FCM token if provided
        if (!empty($body['fcm_token'])) {
            Database::collection('clients')->updateOne(
                ['_id' => $matched['_id']],
                ['$set' => ['fcm_token' => $body['fcm_token'], 'last_login' => new \MongoDB\BSON\UTCDateTime()]]
            );
        }

        $payload = [
            'sub'           => $clientId,
            'role'          => 'client',
            'name'          => $matched['name'],
            'coach_id'      => (string) $matched['coach_id'],
            'token_version' => $matched['token_version'] ?? 0,
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->setTokenCookies($accessToken, $refreshToken);

        $fields = ['email','phone','profile_photo_url','notes','address','city','postal_code','nationality','occupation','gender','date_of_birth','current_weight_kg','height_cm','chest_cm','waist_cm','body_fat_pct','bmi','lean_mass_kg','sickness','age','weight','location'];
        $clientData = ['id' => $clientId, 'name' => $matched['name']];
        foreach ($fields as $f) {
            $clientData[$f] = $matched[$f] ?? null;
        }
        $clientData['language']   = $matched['language'] ?? 'en';
        $clientData['active']     = $matched['active'] ?? true;
        $clientData['created_at'] = isset($matched['created_at']) ? (string) $matched['created_at'] : null;
        $clientData['updated_at'] = isset($matched['updated_at']) ? (string) $matched['updated_at'] : null;

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'client'        => $clientData,
        ], 'Login successful');
    }

    // POST /auth/staff/login
    public function staffLogin(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, ['email' => 'required|email', 'password' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $staff = Database::collection('staff')->findOne(['email' => strtolower($body['email'])]);
        if (!$staff || empty($staff['password_hash']) || !password_verify($body['password'], $staff['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }
        if (($staff['status'] ?? '') !== 'active') {
            Response::error('Staff account is not active', 403);
        }

        $org = Database::collection('coaches')->findOne(['_id' => $staff['org_id']]);
        if (!$org || ($org['active'] ?? true) !== true) {
            Response::error('Organization access blocked', 403);
        }

        $staffId = (string) $staff['_id'];
        $payload = [
            'sub'        => $staffId,
            'role'       => 'staff',
            'name'       => $staff['name'] ?? '',
            'email'      => $staff['email'],
            'org_id'     => (string) $staff['org_id'],
            'staff_role' => $staff['role'],
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'staff'         => [
                'id'    => $staffId,
                'name'  => $staff['name'] ?? '',
                'email' => $staff['email'],
                'role'  => $staff['role'],
                'org_id' => (string) $staff['org_id'],
            ],
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

            if (($decoded['role'] ?? '') === 'coach') {
                $coach = Database::collection('coaches')->findOne([
                    '_id' => new ObjectId((string) $decoded['sub']),
                ]);
                if (!$coach || ($coach['active'] ?? true) !== true) {
                    $this->clearTokenCookies();
                    Response::error('Coach access blocked', 403);
                }
            }

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

            if (($decoded['role'] ?? '') === 'staff') {
                $staff = Database::collection('staff')->findOne([
                    '_id' => new ObjectId((string) $decoded['sub']),
                ]);
                if (!$staff || ($staff['status'] ?? '') !== 'active') {
                    $this->clearTokenCookies();
                    Response::error('Staff access blocked', 403);
                }
            }

            $payload = [
                'sub'           => $decoded['sub'],
                'role'          => $decoded['role'],
                'name'          => $decoded['name'],
                'token_version' => $decoded['token_version'] ?? 0,
            ];
            foreach (['coach_id', 'email', 'org_id', 'staff_role'] as $k) {
                if (isset($decoded[$k])) $payload[$k] = $decoded[$k];
            }

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
