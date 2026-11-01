<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{WearableSyncService, WearableTrendCacheService};
use MongoDB\BSON\{ObjectId, UTCDateTime};

/**
 * Wearable integration: Apple HealthKit push + Fitbit OAuth.
 *
 * Flow:
 *  HealthKit — client app pushes daily summaries via POST /client/wearable/data
 *  Fitbit — OAuth2 authorization_code flow:
 *    1. GET /client/wearable/fitbit/connect   → returns redirect URL
 *    2. Fitbit redirects to GET /client/wearable/fitbit/callback
 *    3. Callback exchanges code → tokens, stored encrypted, fetch scheduled
 */
class WearableController
{
    private const FITBIT_AUTH_URL  = 'https://www.fitbit.com/oauth2/authorize';
    private const FITBIT_TOKEN_URL = 'https://api.fitbit.com/oauth2/token';
    private const FITBIT_API_URL   = 'https://api.fitbit.com/1/user/-';

    private WearableTrendCacheService $trendCache;
    private WearableSyncService $syncService;

    public function __construct()
    {
        $this->trendCache = new WearableTrendCacheService();
        $this->syncService = new WearableSyncService();
    }

    // ─── HealthKit push ─────────────────────────────────────────────────────────

    /**
     * POST /client/wearable/data
     *
     * Accepts a batch of daily summaries pushed from the iOS/Android app.
     * Body: { source: "healthkit"|"health_connect", summaries: [{ date, steps, active_calories, distance_km, hydration_ml }] }
     */
    public function pushData(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $errors   = Request::validate($body, [
            'source'    => 'required',
            'summaries' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $source    = $body['source'];
        $summaries = $body['summaries'];

        if (!in_array($source, ['healthkit', 'health_connect', 'fitbit'], true)) {
            Response::error('Invalid source. Use healthkit, health_connect, or fitbit', 422);
        }
        if (!is_array($summaries) || empty($summaries)) {
            Response::error('summaries must be a non-empty array', 422);
        }

        $now = new UTCDateTime();
        $upserted = 0;

        foreach ($summaries as $s) {
            $date = trim((string) ($s['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

            $doc = [
                'steps'           => (int)   ($s['steps']           ?? 0),
                'active_calories' => (float)  ($s['active_calories'] ?? 0),
                'distance_km'     => (float)  ($s['distance_km']     ?? 0),
                'hydration_ml'    => (float)  ($s['hydration_ml']    ?? 0),
                'source'          => $source,
                'updated_at'      => $now,
            ];

            // Include optional fields if provided
            if (isset($s['resting_hr']))   $doc['resting_hr']   = (int)   $s['resting_hr'];
            if (isset($s['sleep_minutes'])) $doc['sleep_minutes'] = (int) $s['sleep_minutes'];

            Database::collection('wearable_data')->updateOne(
                ['client_id' => $clientId, 'date' => $date],
                ['$set' => $doc, '$setOnInsert' => ['created_at' => $now]],
                ['upsert' => true]
            );
            $upserted++;
        }

        if ($upserted > 0) {
            // Event-driven invalidation — next trend read is treated as stale-in-grace
            // and refreshed in the background rather than waiting for the TTL to expire.
            $this->trendCache->invalidateForClient($clientId);
        }

        Response::success(['synced' => $upserted], "Synced {$upserted} day(s)");
    }

    /**
     * GET /client/wearable/data
     *
     * Returns the authenticated client's own wearable summaries.
     * Query: from (YYYY-MM-DD), to (YYYY-MM-DD), default last 30 days.
     */
    public function clientSelfData(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $from = Request::get('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to   = Request::get('to')   ?? date('Y-m-d');

        $data = Database::collection('wearable_data')->find([
            'client_id' => $clientId,
            'date'      => ['$gte' => $from, '$lte' => $to],
        ], ['sort' => ['date' => -1]])->toArray();

        // Client can see all their own metrics
        $allowed = $this->defaultSharingSettings();
        Response::success(array_map(fn($d) => $this->formatWearable($d, $allowed), $data));
    }

    // ─── Fitbit OAuth ────────────────────────────────────────────────────────────

    /**
     * GET /client/wearable/fitbit/connect
     *
     * Returns the Fitbit authorization URL the mobile app should open in a browser.
     */
    public function fitbitConnect(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $fitbitClientId  = $_ENV['FITBIT_CLIENT_ID']     ?? '';
        $redirectUri     = $_ENV['FITBIT_REDIRECT_URI']  ?? '';

        if (!$fitbitClientId || !$redirectUri) {
            Response::error('Fitbit integration is not configured', 503);
        }

        $state = bin2hex(random_bytes(16));
        // Store state for CSRF verification (TTL 10 min)
        Database::collection('wearable_oauth_states')->insertOne([
            'client_id'  => $clientId,
            'state'      => $state,
            'provider'   => 'fitbit',
            'expires_at' => new UTCDateTime((time() + 600) * 1000),
        ]);

        $url = self::FITBIT_AUTH_URL . '?' . http_build_query([
            'client_id'     => $fitbitClientId,
            'response_type' => 'code',
            'scope'         => 'activity heartrate nutrition profile sleep weight',
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'expires_in'    => '604800',
        ]);

        Response::success(['auth_url' => $url], 'Fitbit authorization URL');
    }

    /**
     * GET /client/wearable/fitbit/callback?code=...&state=...
     *
     * Exchange authorization code for access + refresh tokens, store encrypted.
     * This endpoint is used by web/browser flows where Fitbit redirects back to
     * the backend directly. The mobile app should use POST /exchange instead.
     */
    public function fitbitCallback(array $params): void
    {
        $code  = trim((string) Request::get('code', ''));
        $state = trim((string) Request::get('state', ''));

        if ($code === '' || $state === '') {
            Response::error('Missing code or state', 400);
        }

        $this->completeFitbitConnection($code, $state, null);
    }

    /**
     * POST /client/wearable/fitbit/exchange
     *
     * Mobile-app completion of Fitbit OAuth. The app captures the redirect
     * (e.g. fitapp://wearable/fitbit/callback?code=...&state=...) and sends
     * the code + state here, authenticated as the client.
     */
    public function fitbitExchange(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $code     = trim((string) ($body['code'] ?? ''));
        $state    = trim((string) ($body['state'] ?? ''));

        if ($code === '' || $state === '') {
            Response::error('Missing code or state', 400);
        }

        // Verify the state belongs to this client (extra defense: mobile auth
        // is already validated by ClientMiddleware, but the state must match).
        $stateDoc = Database::collection('wearable_oauth_states')->findOneAndDelete([
            'state'      => $state,
            'provider'   => 'fitbit',
            'client_id'  => $clientId,
            'expires_at' => ['$gt' => new UTCDateTime()],
        ]);
        if (!$stateDoc) Response::error('Invalid or expired state', 400);

        $this->completeFitbitConnection($code, $state, $clientId);
    }

    /**
     * Shared Fitbit token exchange + persistence logic.
     */
    private function completeFitbitConnection(string $code, string $state, ?ObjectId $verifiedClientId): void
    {
        if ($verifiedClientId === null) {
            $stateDoc = Database::collection('wearable_oauth_states')->findOneAndDelete([
                'state'      => $state,
                'provider'   => 'fitbit',
                'expires_at' => ['$gt' => new UTCDateTime()],
            ]);
            if (!$stateDoc) Response::error('Invalid or expired state', 400);
            $verifiedClientId = $stateDoc['client_id'];
        }

        $tokens = $this->exchangeFitbitCode($code);
        if (!$tokens) Response::error('Failed to exchange Fitbit authorization code', 502);

        $this->storeFitbitTokens($verifiedClientId, $tokens);

        // Trigger an initial sync
        $this->syncService->syncFitbitForClient($verifiedClientId, 7);
        Database::collection('wearable_connections')->updateOne(
            ['client_id' => $verifiedClientId, 'provider' => 'fitbit'],
            ['$set' => [
                'status' => 'active',
                'paired_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
            ], '$setOnInsert' => [
                'settings' => $this->defaultSharingSettings(),
                'created_at' => new UTCDateTime(),
            ]],
            ['upsert' => true]
        );

        Response::success(['connected' => true], 'Fitbit account connected');
    }

    /**
     * POST /client/wearable/fitbit/sync
     *
     * Force an immediate Fitbit sync for the authenticated client.
     */
    public function fitbitSync(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $connection = Database::collection('wearable_connections')->findOne([
            'client_id' => $clientId,
            'provider' => 'fitbit',
            'status' => 'active',
        ]);
        if (!$connection) {
            Response::error('No active Fitbit connection', 404);
        }

        $this->syncService->syncFitbitForClient($clientId, 7);
        Response::success(null, 'Sync started');
    }

    /**
     * DELETE /client/wearable/disconnect
     *
     * Disconnect the wearable (removes tokens + future syncs).
     */
    public function disconnect(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();
        $provider = strtolower(trim((string) ($body['provider'] ?? Request::get('provider', 'fitbit'))));

        Database::collection('wearable_tokens')->deleteMany(['client_id' => $clientId, 'provider' => $provider]);
        Database::collection('wearable_connections')->updateOne(
            ['client_id' => $clientId, 'provider' => $provider],
            ['$set' => ['status' => 'disconnected', 'updated_at' => new UTCDateTime()]]
        );

        Response::success(['connected' => false], 'Wearable disconnected');
    }

    /**
     * GET /client/wearable/status
     *
     * Returns whether any wearable source is connected for the client.
     */
    public function status(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $connections = Database::collection('wearable_connections')
            ->find(['client_id' => $clientId, 'status' => 'active'])
            ->toArray();

        $providers = [];
        foreach ($connections as $c) {
            $provider = (string) ($c['provider'] ?? 'unknown');
            $token = Database::collection('wearable_tokens')->findOne([
                'client_id' => $clientId,
                'provider'  => $provider,
            ]);
            $providers[] = [
                'provider'       => $provider,
                'status'         => (string) ($c['status'] ?? 'active'),
                'sync_status'    => $token ? (string) ($token['sync_status'] ?? 'idle') : null,
                'last_sync_at'   => $token ? $this->formatNullableTimestamp($token['last_sync_at'] ?? null) : null,
                'last_sync_error' => $token ? ($token['last_sync_error'] ?? null) : null,
            ];
        }

        $lastSync = null;
        $recent   = Database::collection('wearable_data')->findOne(
            ['client_id' => $clientId],
            ['sort' => ['date' => -1]]
        );
        if ($recent) {
            $lastSync = $recent['date'] ?? null;
        }

        Response::success([
            'connected'   => count($providers) > 0,
            'providers'   => $providers,
            'last_sync'   => $lastSync,
            'settings'    => $this->getSharingSettings($clientId),
        ]);
    }

    /**
     * GET /client/wearable/sharing
     */
    public function getSharing(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        Response::success($this->getSharingSettings($clientId));
    }

    /**
     * PUT /client/wearable/sharing
     */
    public function updateSharing(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();

        $settings = [];
        foreach ($this->sharingMetricKeys() as $metric) {
            if (isset($body[$metric]) && is_bool($body[$metric])) {
                $settings[$metric] = $body[$metric];
            }
        }

        if (empty($settings)) {
            Response::error('No valid sharing settings provided', 422);
        }

        Database::collection('wearable_connections')->updateMany(
            ['client_id' => $clientId],
            ['$set' => array_merge($settings, ['updated_at' => new UTCDateTime()])]
        );

        Response::success($this->getSharingSettings($clientId), 'Sharing settings updated');
    }

    /**
     * POST /client/wearable/apple-watch/push
     *
     * iPhone app pushes summarized Apple Watch / HealthKit data.
     */
    public function appleWatchPush(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();

        $errors = Request::validate($body, [
            'summaries' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $summaries = $body['summaries'] ?? [];
        $sessions = $body['sessions'] ?? [];

        if (!is_array($summaries) || empty($summaries)) {
            Response::error('summaries must be a non-empty array', 422);
        }

        $upserted = 0;
        foreach ($summaries as $s) {
            $date = trim((string) ($s['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

            $summary = [
                'steps'          => (int)   ($s['steps']          ?? 0),
                'active_calories'=> (float) ($s['active_calories'] ?? 0),
                'distance_km'    => (float) ($s['distance_km']     ?? 0),
                'hydration_ml'   => (float) ($s['hydration_ml']    ?? 0),
                'resting_hr'     => isset($s['resting_hr'])    ? (int)   $s['resting_hr']    : null,
                'sleep_minutes'  => isset($s['sleep_minutes']) ? (int)   $s['sleep_minutes'] : null,
                'hrv_ms'         => isset($s['hrv_ms'])        ? (float) $s['hrv_ms']        : null,
                'sleep_score'    => isset($s['sleep_score'])   ? (int)   $s['sleep_score']   : null,
            ];

            $this->syncService->storeDailySummary($clientId, $date, 'apple_watch', $summary);
            $upserted++;
        }

        if (!empty($sessions)) {
            $this->syncService->storeSessions($clientId, 'apple_watch', $sessions);
        }

        // Ensure a connection record exists for Apple Watch.
        Database::collection('wearable_connections')->updateOne(
            ['client_id' => $clientId, 'provider' => 'apple_watch'],
            ['$set' => [
                'status' => 'active',
                'paired_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
            ], '$setOnInsert' => [
                'settings' => $this->defaultSharingSettings(),
                'created_at' => new UTCDateTime(),
            ]],
            ['upsert' => true]
        );

        Response::success(['synced' => $upserted], "Synced {$upserted} day(s)");
    }

    // ─── Coach: client wearable data ─────────────────────────────────────────────

    /**
     * GET /coach/clients/:id/wearable
     *
     * Retrieve wearable summaries for a specific client.
     * Query: from (YYYY-MM-DD), to (YYYY-MM-DD), default last 30 days.
     */
    public function clientData(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $from = Request::get('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to   = Request::get('to')   ?? date('Y-m-d');

        $data = Database::collection('wearable_data')->find([
            'client_id' => $clientId,
            'date'      => ['$gte' => $from, '$lte' => $to],
        ], ['sort' => ['date' => 1]])->toArray();

        $allowed = $this->allowedMetricsForCoach($clientId);
        Response::success(array_map(fn($d) => $this->formatWearable($d, $allowed), $data));
    }

    /**
     * GET /coach/clients/:id/wearable/trend?metric=steps&days=30
     *
     * Cached trend summary (points + average/min/max/total) for a single
     * metric over a day window. Routed through WearableTrendCacheService's
     * cache-aside + stale-while-revalidate layer.
     */
    public function trend(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);
        $metric   = trim((string) Request::get('metric', 'steps'));

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);
        $days     = (int) Request::get('days', 30);

        if (!$this->metricIsSharedWithCoach($clientId, $metric)) {
            Response::error('Metric not shared by client', 403);
        }

        if (!in_array($metric, WearableTrendCacheService::ALLOWED_METRICS, true)) {
            Response::error('Invalid metric. Use one of: ' . implode(', ', WearableTrendCacheService::ALLOWED_METRICS), 422);
        }

        try {
            $trend = $this->trendCache->getTrend($clientId, $metric, $days);
            Response::success($trend);
        } catch (\Throwable $e) {
            error_log('WearableController::trend failed: ' . $e->getMessage());
            Response::error('Failed to compute wearable trend', 500);
        }
    }

    /**
     * GET /coach/clients/:id/wearable/sessions?from=...&to=...
     *
     * Device workout sessions imported from Fitbit / Apple Watch.
     */
    public function sessions(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $from = Request::get('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to   = Request::get('to')   ?? date('Y-m-d');

        $sessions = Database::collection('wearable_sessions')->find([
            'client_id'    => $clientId,
            'start_time'   => [
                '$gte' => new UTCDateTime(strtotime($from) * 1000),
                '$lte' => new UTCDateTime(strtotime($to . ' 23:59:59') * 1000),
            ],
        ], ['sort' => ['start_time' => -1]])->toArray();

        Response::success(array_map(fn($s) => [
            'id'           => (string) $s['_id'],
            'provider'     => $s['provider'] ?? null,
            'session_id'   => $s['session_id'] ?? null,
            'type'         => $s['type'] ?? 'unknown',
            'start_time'   => $s['start_time'] ? date('c', (int)(((string)$s['start_time']) / 1000)) : null,
            'end_time'     => $s['end_time'] ? date('c', (int)(((string)$s['end_time']) / 1000)) : null,
            'duration_min' => (int) ($s['duration_min'] ?? 0),
            'calories'     => (float) ($s['calories'] ?? 0),
            'avg_hr'       => isset($s['avg_hr']) ? (int) $s['avg_hr'] : null,
            'max_hr'       => isset($s['max_hr']) ? (int) $s['max_hr'] : null,
            'steps'        => (int) ($s['steps'] ?? 0),
            'distance_km'  => (float) ($s['distance_km'] ?? 0),
        ], $sessions));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function exchangeFitbitCode(string $code): ?array
    {
        $clientId     = $_ENV['FITBIT_CLIENT_ID']     ?? '';
        $clientSecret = $_ENV['FITBIT_CLIENT_SECRET'] ?? '';
        $redirectUri  = $_ENV['FITBIT_REDIRECT_URI']  ?? '';

        $credentials = base64_encode("{$clientId}:{$clientSecret}");

        $ch = curl_init(self::FITBIT_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $redirectUri,
            ]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic {$credentials}",
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$result) return null;

        $data = json_decode($result, true);
        if (empty($data['access_token'])) return null;

        return $data;
    }

    private function storeFitbitTokens(ObjectId $clientId, array $tokens): void
    {
        $encKey = $_ENV['WEARABLE_TOKEN_ENCRYPTION_KEY'] ?? '';

        $accessToken  = $tokens['access_token'];
        $refreshToken = $tokens['refresh_token'] ?? '';

        if ($encKey) {
            $key          = hash('sha256', $encKey, true);
            $ivAccess     = random_bytes(16);
            $ivRefresh    = random_bytes(16);
            $accessToken  = base64_encode($ivAccess . openssl_encrypt($accessToken,  'AES-256-CBC', $key, OPENSSL_RAW_DATA, $ivAccess));
            $refreshToken = base64_encode($ivRefresh . openssl_encrypt($refreshToken, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $ivRefresh));
        }

        $now = new UTCDateTime();
        Database::collection('wearable_tokens')->updateOne(
            ['client_id' => $clientId, 'provider' => 'fitbit'],
            ['$set' => [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'fitbit_user_id'=> $tokens['user_id'] ?? null,
                'scope'         => $tokens['scope'] ?? '',
                'expires_at'    => new UTCDateTime((time() + (int)($tokens['expires_in'] ?? 28800)) * 1000),
                'updated_at'    => $now,
            ],
            '$setOnInsert' => ['created_at' => $now]],
            ['upsert' => true]
        );
    }

    private function syncFitbitData(ObjectId $clientId, string $accessToken): void
    {
        Database::collection('wearable_tokens')->updateOne(
            ['client_id' => $clientId, 'provider' => 'fitbit'],
            ['$set' => ['sync_status' => 'syncing']]
        );

        try {
            $this->fetchAndStoreFitbitSummary($clientId, $accessToken);
            Database::collection('wearable_tokens')->updateOne(
                ['client_id' => $clientId, 'provider' => 'fitbit'],
                ['$set' => ['sync_status' => 'idle', 'last_sync_error' => null]]
            );
        } catch (\Throwable $e) {
            // A failed sync never deletes/blanks previously-synced wearable_data.
            Database::collection('wearable_tokens')->updateOne(
                ['client_id' => $clientId, 'provider' => 'fitbit'],
                ['$set' => ['sync_status' => 'failed', 'last_sync_error' => substr($e->getMessage(), 0, 500)]]
            );
            error_log('Fitbit sync failed for client ' . (string) $clientId . ': ' . $e->getMessage());
        }
    }

    private function fetchAndStoreFitbitSummary(ObjectId $clientId, string $accessToken): void
    {
        $date = date('Y-m-d');
        $endpoints = [
            'activities/steps'    => 'steps',
            'activities/calories' => 'active_calories',
            'activities/distance' => 'distance_km',
        ];

        $summary = [];
        foreach ($endpoints as $path => $field) {
            $url = self::FITBIT_API_URL . "/{$path}/date/{$date}/1d.json";
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) continue;

            $data = json_decode($res, true) ?? [];
            // Fitbit wraps daily totals in an object keyed by the dataset name
            foreach ($data as $key => $val) {
                if (is_array($val) && !empty($val)) {
                    $last = end($val);
                    $summary[$field] = (float)($last['value'] ?? 0);
                }
            }
        }

        if (!empty($summary)) {
            $now = new UTCDateTime();
            $summary['source']     = 'fitbit';
            $summary['updated_at'] = $now;
            Database::collection('wearable_data')->updateOne(
                ['client_id' => $clientId, 'date' => $date],
                ['$set' => $summary, '$setOnInsert' => ['created_at' => $now]],
                ['upsert' => true]
            );
            // Event-driven invalidation — next trend read is treated as stale-in-grace.
            $this->trendCache->invalidateForClient($clientId);
        }
    }

    private function formatWearable(object $doc, array $allowed): array
    {
        $d = (array) $doc;
        $out = [
            'date'            => $d['date'] ?? '',
            'source'          => $d['source'] ?? null,
        ];

        $metrics = [
            'steps', 'active_calories', 'distance_km', 'hydration_ml',
            'resting_hr', 'sleep_minutes', 'sleep_score', 'hrv_ms',
            'active_minutes', 'recovery_score',
        ];

        foreach ($metrics as $metric) {
            if (!isset($allowed[$metric]) || !$allowed[$metric]) {
                continue;
            }
            if ($metric === 'steps' || $metric === 'resting_hr' || $metric === 'sleep_minutes' || $metric === 'sleep_score') {
                $out[$metric] = isset($d[$metric]) ? (int) $d[$metric] : null;
            } else {
                $out[$metric] = isset($d[$metric]) ? (float) $d[$metric] : null;
            }
        }

        return $out;
    }

    private function getSharingSettings(ObjectId $clientId): array
    {
        $defaults = $this->defaultSharingSettings();
        $connection = Database::collection('wearable_connections')->findOne([
            'client_id' => $clientId,
            'status' => ['$in' => ['active', 'disconnected']],
        ]);

        $settings = [];
        foreach ($this->sharingMetricKeys() as $metric) {
            $settings[$metric] = (bool) (($connection['settings'] ?? [])[$metric] ?? $defaults[$metric]);
        }
        return $settings;
    }

    private function defaultSharingSettings(): array
    {
        return [
            'steps'          => true,
            'active_calories'=> true,
            'distance_km'    => true,
            'hydration_ml'   => true,
            'resting_hr'     => true,
            'sleep_minutes'  => true,
            'sleep_score'    => true,
            'hrv_ms'         => true,
            'active_minutes' => true,
            'recovery_score' => true,
        ];
    }

    private function sharingMetricKeys(): array
    {
        return array_keys($this->defaultSharingSettings());
    }

    private function metricIsSharedWithCoach(ObjectId $clientId, string $metric): bool
    {
        $settings = $this->getSharingSettings($clientId);
        return (bool) ($settings[$metric] ?? false);
    }

    private function allowedMetricsForCoach(ObjectId $clientId): array
    {
        return $this->getSharingSettings($clientId);
    }

    private function formatNullableTimestamp(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return date('c', (int)(((string)$value) / 1000));
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        return is_numeric($value) ? date('c', (int)$value) : null;
    }
}
