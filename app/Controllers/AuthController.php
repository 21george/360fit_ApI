<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{CodeService, EmailService, JwtService, StripeService};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

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
        if (($coach['email_verified_at'] ?? null) === null) {
            Response::error('Email verification required', 403, [
                'coach_id' => (string) $coach['_id'],
                'email'    => (string) $coach['email'],
            ]);
        }
        if (($coach['active'] ?? true) !== true) {
            Response::error('Coach access blocked', 403);
        }

        $coachId = (string) $coach['_id'];
        if (($coach['subscription_status'] ?? 'pending') === 'pending') {
            Response::success([
                'coach' => [
                    'id' => $coachId,
                    'name' => $coach['name'],
                    'email' => $coach['email'],
                    'language' => $coach['language'] ?? 'en',
                    'subscription_status' => 'pending',
                ],
                'setup_token' => JwtService::generateSetupToken($coachId),
            ], 'Subscription setup required');
        }

        $payload = [
            'sub'   => $coachId,
            'role'  => 'coach',
            'name'  => $coach['name'],
            'email' => $coach['email'],
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->storeRefreshToken($refreshToken, $coachId, 'coach');
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'coach'         => [
                'id'       => $coachId,
                'name'     => $coach['name'],
                'email'    => $coach['email'],
                'language' => $coach['language'] ?? 'en',
                'subscription_status' => $coach['subscription_status'] ?? 'active',
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
            'verification_code_hash' => password_hash($verificationCode, PASSWORD_BCRYPT, ['cost' => 10]),
            'verification_code_expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VERIFICATION_CODE_TTL_SECONDS) * 1000),
            'verification_code_sent_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $coachId = (string) $result->getInsertedId();

        if (!EmailService::sendCoachVerificationCode($email, $body['name'], $verificationCode, $coachId)) {
            error_log("Unable to send verification email for coach {$coachId}");
            Response::error('Unable to send verification email. Please check your mail configuration or try resending.', 503, [
                'email_failed' => true,
                'coach_id'     => $coachId,
            ]);
        }

        Response::success([
            'id' => $coachId,
        ], 'Registration successful. Please verify your email.', 201);
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

        $coachIdStr = (string) $coachId;

        // If subscription is still pending, return a setup token so the frontend
        // can redirect to plan selection instead of issuing dashboard JWTs.
        if (($coach['subscription_status'] ?? 'pending') === 'pending') {
            $setupToken = JwtService::generateSetupToken($coachIdStr);
            Response::success([
                'coach' => [
                    'id'                  => $coachIdStr,
                    'name'                => $coach['name'],
                    'email'               => $coach['email'],
                    'language'            => $coach['language'] ?? 'en',
                    'subscription_status' => 'pending',
                    'subscription_tier'   => $coach['subscription_tier'] ?? 'free',
                ],
                'setup_token' => $setupToken,
                'redirect'    => '/subscription/select-plan',
            ], 'Email verified. Please complete your subscription setup.');
        }

        $payload = [
            'sub'   => $coachIdStr,
            'role'  => 'coach',
            'name'  => $coach['name'],
            'email' => $coach['email'],
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->storeRefreshToken($refreshToken, $coachIdStr, 'coach');
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'coach' => [
                'id' => $coachIdStr,
                'name' => $coach['name'],
                'email' => $coach['email'],
                'language' => $coach['language'] ?? 'en',
                'subscription_status' => $coach['subscription_status'] ?? 'active',
                'subscription_tier'   => $coach['subscription_tier'] ?? 'free',
            ],
            'redirect' => '/dashboard',
        ], 'Email verified. Welcome to CoachPro.');
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
            'verification_code_hash' => password_hash($verificationCode, PASSWORD_BCRYPT, ['cost' => 10]),
            'verification_code_expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VERIFICATION_CODE_TTL_SECONDS) * 1000),
            'verification_code_sent_at' => $now,
            'updated_at' => $now,
        ]]);

        if (!EmailService::sendCoachVerificationCode((string) $coach['email'], (string) $coach['name'], $verificationCode, (string) $coachId)) {
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
        $this->storeRefreshToken($refreshToken, $clientId, 'client');
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
        if (!$staff) {
            error_log("[STAFF LOGIN] Failed login for unknown email: " . ($body['email'] ?? 'empty'));
            Response::error('Invalid credentials', 401);
        }

        $status = $staff['status'] ?? '';
        $setupComplete = true;

        // Invited staff may finish onboarding by logging in. If they have not
        // set a password yet, the password they supply now becomes their password.
        if ($status === 'invited') {
            if (empty($staff['password_hash'])) {
                if (strlen($body['password']) < 8) {
                    Response::error('Password must be at least 8 characters', 422);
                }
                $passwordHash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
                Database::collection('staff')->updateOne(
                    ['_id' => $staff['_id']],
                    ['$set' => [
                        'password_hash' => $passwordHash,
                        'status'        => 'active',
                        'activated_at'  => new UTCDateTime(),
                        'updated_at'    => new UTCDateTime(),
                    ]]
                );
                $staff['password_hash'] = $passwordHash;
                $setupComplete = !empty($staff['name']) && strlen(trim($staff['name'])) >= 2;
            } elseif (password_verify($body['password'], $staff['password_hash'])) {
                // Invite was already accepted via another path but status is still invited.
                Database::collection('staff')->updateOne(
                    ['_id' => $staff['_id']],
                    ['$set' => [
                        'status'        => 'active',
                        'activated_at'  => new UTCDateTime(),
                        'updated_at'    => new UTCDateTime(),
                    ]]
                );
            } else {
                error_log("[STAFF LOGIN] Failed login for invited staff: " . ($body['email'] ?? 'empty'));
                Response::error('Invalid credentials', 401);
            }
            $status = 'active';
        }

        if (empty($staff['password_hash']) || !password_verify($body['password'], $staff['password_hash'])) {
            error_log("[STAFF LOGIN] Failed login for email: " . ($body['email'] ?? 'empty'));
            Response::error('Invalid credentials', 401);
        }

        if ($status !== 'active') {
            error_log("[STAFF LOGIN] Blocked login for inactive staff: " . ($body['email'] ?? 'empty') . " status=" . $status);
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
        $this->storeRefreshToken($refreshToken, $staffId, 'staff');
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'setup_complete' => $setupComplete,
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
        $body  = Request::body();
        $token = $body['refresh_token'] ?? $_COOKIE['refresh_token'] ?? '';
        if ($token) {
            $this->revokeRefreshToken($token);
        }
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

            // Revocation check: token must exist in refresh_tokens collection
            if ($this->isRefreshTokenRevoked($token)) {
                $this->clearTokenCookies();
                Response::error('Refresh token revoked', 401);
            }

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

                $org = Database::collection('coaches')->findOne([
                    '_id' => new ObjectId((string) $staff['org_id']),
                ]);
                if (!$org || ($org['active'] ?? true) !== true) {
                    $this->clearTokenCookies();
                    Response::error('Organization access blocked', 403);
                }

                // Refresh claims from DB so role changes take effect immediately
                $decoded['name']       = $staff['name'] ?? '';
                $decoded['email']      = $staff['email'];
                $decoded['org_id']     = (string) $staff['org_id'];
                $decoded['staff_role'] = $staff['role'];
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
            $this->storeRefreshToken($refreshToken, (string) $decoded['sub'], (string) $decoded['role']);
            $this->setTokenCookies($accessToken, $refreshToken);

            Response::success([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ]);
        } catch (\Exception $e) {
            Response::error('Invalid or expired refresh token', 401);
        }
    }

    // POST /auth/complete-onboarding
    // Authenticates a coach after successful Stripe checkout during onboarding.
    // The frontend calls this from /subscription/success with the Stripe session_id.
    // We look up the session, confirm payment, and issue auth cookies/tokens.
    public function completeOnboarding(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, ['session_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured', 503);
        }

        try {
            $session = $stripe->getCheckoutSession((string) $body['session_id']);
        } catch (\RuntimeException $e) {
            Response::error('Invalid Stripe session', 404);
        }

        if (($session['payment_status'] ?? '') !== 'paid' && ($session['payment_status'] ?? '') !== 'no_payment_required') {
            Response::error('Payment not completed', 402);
        }

        $coachId = $session['metadata']['coach_id'] ?? null;
        if (!$coachId) {
            Response::error('Session missing coach reference', 500);
        }

        $coach = Database::collection('coaches')->findOne(['_id' => new ObjectId((string) $coachId)]);
        if (!$coach) Response::error('Coach not found', 404);
        if (($coach['active'] ?? true) !== true) Response::error('Coach access blocked', 403);

        // Require verified email before completing onboarding
        if (($coach['email_verified_at'] ?? null) === null) {
            Response::error('Email verification required', 403, [
                'coach_id' => (string) $coach['_id'],
                'email'    => (string) $coach['email'],
            ]);
        }

        $coachIdStr = (string) $coach['_id'];

        // If the webhook hasn't fired yet, activate the subscription now
        // so the next login returns dashboard tokens immediately.
        $subscriptionStatus = $coach['subscription_status'] ?? 'pending';
        if ($subscriptionStatus === 'pending' || $subscriptionStatus === 'none') {
            $pendingTier = $session['metadata']['pending_tier'] ?? null;
            if ($pendingTier && in_array($pendingTier, ['pro', 'business'], true)) {
                $trialDays = (int) ($_ENV['STRIPE_TRIAL_DAYS'] ?? 30);
                $trialEnd  = new \MongoDB\BSON\UTCDateTime((time() + $trialDays * 86400) * 1000);
                Database::collection('coaches')->updateOne(
                    ['_id' => new ObjectId($coachIdStr)],
                    ['$set' => [
                        'subscription_tier'       => $pendingTier,
                        'subscription_status'     => 'trialing',
                        'trial_ends_at'         => $trialEnd,
                        'subscription_period_end' => $trialEnd,
                        'updated_at'            => new \MongoDB\BSON\UTCDateTime(),
                    ],
                    '$unset' => [
                        'pending_subscription_tier'   => '',
                        'pending_subscription_period' => '',
                    ]]
                );
                $coach['subscription_status'] = 'trialing';
                $coach['subscription_tier']   = $pendingTier;
            }
        }

        $payload = [
            'sub'   => $coachIdStr,
            'role'  => 'coach',
            'name'  => $coach['name'],
            'email' => $coach['email'],
        ];

        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);
        $this->storeRefreshToken($refreshToken, $coachIdStr, 'coach');
        $this->setTokenCookies($accessToken, $refreshToken);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'coach'         => [
                'id'                  => $coachIdStr,
                'name'                => $coach['name'],
                'email'               => $coach['email'],
                'language'            => $coach['language'] ?? 'en',
                'subscription_status' => $coach['subscription_status'] ?? 'active',
                'subscription_tier'   => $coach['subscription_tier'] ?? 'free',
            ],
            'redirect'      => '/dashboard',
        ], 'Onboarding completed');
    }

    // ── Password Reset ───────────────────────────────────────────────────────────

    // POST /auth/forgot-password
    public function forgotPassword(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, ['email' => 'required|email']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $email = strtolower(trim($body['email']));
        $user  = null;
        $type  = '';

        // Search coaches first, then staff — silently return same message either way.
        $coach = Database::collection('coaches')->findOne(['email' => $email]);
        if ($coach) {
            $user = $coach;
            $type = 'coach';
        } else {
            $staff = Database::collection('staff')->findOne(['email' => $email]);
            if ($staff) {
                $user = $staff;
                $type = 'staff';
            }
        }

        if (!$user) {
            // Do not reveal whether the email exists (prevents enumeration).
            Response::success(null, 'If an account exists, a reset link has been sent.');
        }

        // Generate a cryptographically secure random token.
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT, ['cost' => 10]);
        $expiresAt = new UTCDateTime((time() + 3600) * 1000); // 1 hour

        $collection = $type === 'coach' ? 'coaches' : 'staff';
        Database::collection($collection)->updateOne(
            ['_id' => $user['_id']],
            ['$set' => [
                'password_reset_token_hash' => $tokenHash,
                'password_reset_expires_at' => $expiresAt,
                'updated_at'                => new UTCDateTime(),
            ]]
        );

        $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000', '/');
        $resetUrl    = "{$frontendUrl}/auth/reset-password?token=" . urlencode($rawToken) . "&type={$type}&email=" . urlencode($email);

        if (!EmailService::sendPasswordReset($email, (string) ($user['name'] ?? ''), $resetUrl)) {
            error_log("[FORGOT PASSWORD] Failed to send reset email to {$email}");
            // Still return success so attackers can't distinguish email delivery failures.
        }

        Response::success(null, 'If an account exists, a reset link has been sent.');
    }

    // POST /auth/reset-password
    public function resetPassword(array $params): void
    {
        $body   = Request::body();
        $errors = Request::validate($body, [
            'token'    => 'required',
            'password' => 'required|min:8',
            'type'     => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $token = (string) $body['token'];
        $type  = (string) $body['type'];
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!in_array($type, ['coach', 'staff'], true)) {
            Response::error('Invalid user type', 422);
        }

        $collection = $type === 'coach' ? 'coaches' : 'staff';
        $filter = ['password_reset_token_hash' => ['$exists' => true]];
        if ($email !== '') {
            $filter['email'] = $email;
        }

        $user = Database::collection($collection)->findOne($filter);
        if (!$user) {
            Response::error('Invalid or expired reset token', 422);
        }

        $expiresAt = $user['password_reset_expires_at'] ?? null;
        if (!$expiresAt || $expiresAt->toDateTime()->getTimestamp() < time()) {
            Response::error('Reset token has expired. Please request a new one.', 422);
        }

        if (!password_verify($token, (string) ($user['password_reset_token_hash'] ?? ''))) {
            Response::error('Invalid or expired reset token', 422);
        }

        // Update password and clear reset fields in one atomic operation.
        $passwordHash = password_hash((string) $body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        Database::collection($collection)->updateOne(
            ['_id' => $user['_id']],
            ['$set' => [
                'password_hash' => $passwordHash,
                'updated_at'    => new UTCDateTime(),
            ], '$unset' => [
                'password_reset_token_hash' => '',
                'password_reset_expires_at' => '',
            ]]
        );

        Response::success(null, 'Password updated successfully. You can now log in with your new password.');
    }


    private function storeRefreshToken(string $token, string $userId, string $role): void
    {
        Database::collection('refresh_tokens')->insertOne([
            'token_hash' => hash('sha256', $token),
            'user_id'    => new ObjectId($userId),
            'role'       => $role,
            'expires_at' => new UTCDateTime((time() + 2592000) * 1000), // 30 days
            'created_at' => new UTCDateTime(),
        ]);
    }

    private function revokeRefreshToken(string $token): void
    {
        Database::collection('refresh_tokens')->deleteOne([
            'token_hash' => hash('sha256', $token),
        ]);
    }

    private function isRefreshTokenRevoked(string $token): bool
    {
        return Database::collection('refresh_tokens')->countDocuments([
            'token_hash' => hash('sha256', $token),
        ]) === 0;
    }
}
