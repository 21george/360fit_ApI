<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
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

        Response::success(['synced' => $upserted], "Synced {$upserted} day(s)");
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
     */
    public function fitbitCallback(array $params): void
    {
        $code  = trim((string) Request::get('code', ''));
        $state = trim((string) Request::get('state', ''));

        if ($code === '' || $state === '') {
            Response::error('Missing code or state', 400);
        }

        // Verify state
        $stateDoc = Database::collection('wearable_oauth_states')->findOneAndDelete([
            'state'      => $state,
            'provider'   => 'fitbit',
            'expires_at' => ['$gt' => new UTCDateTime()],
        ]);
        if (!$stateDoc) Response::error('Invalid or expired state', 400);

        $clientId = $stateDoc['client_id'];

        // Exchange code for tokens
        $tokens = $this->exchangeFitbitCode($code);
        if (!$tokens) Response::error('Failed to exchange Fitbit authorization code', 502);

        $this->storeFitbitTokens($clientId, $tokens);

        // Trigger an initial sync
        $this->syncFitbitData($clientId, $tokens['access_token']);

        Response::success(['connected' => true], 'Fitbit account connected');
    }

    /**
     * DELETE /client/wearable/disconnect
     *
     * Disconnect the wearable (removes tokens + future syncs).
     */
    public function disconnect(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        Database::collection('wearable_tokens')->deleteMany(['client_id' => $clientId]);

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

        $token = Database::collection('wearable_tokens')->findOne(['client_id' => $clientId]);

        $lastSync = null;
        $recent   = Database::collection('wearable_data')->findOne(
            ['client_id' => $clientId],
            ['sort' => ['date' => -1]]
        );
        if ($recent) {
            $lastSync = $recent['date'] ?? null;
        }

        Response::success([
            'connected'   => $token !== null,
            'provider'    => $token ? ($token['provider'] ?? null) : null,
            'last_sync'   => $lastSync,
        ]);
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
        $clientId = new ObjectId($params['id']);

        $from = Request::get('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to   = Request::get('to')   ?? date('Y-m-d');

        $data = Database::collection('wearable_data')->find([
            'client_id' => $clientId,
            'date'      => ['$gte' => $from, '$lte' => $to],
        ], ['sort' => ['date' => 1]])->toArray();

        Response::success(array_map(fn($d) => $this->formatWearable($d), $data));
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
        $iv     = random_bytes(16);

        $accessToken  = $tokens['access_token'];
        $refreshToken = $tokens['refresh_token'] ?? '';

        if ($encKey) {
            $key          = hash('sha256', $encKey, true);
            $accessToken  = base64_encode($iv . openssl_encrypt($accessToken,  'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
            $refreshToken = base64_encode($iv . openssl_encrypt($refreshToken, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
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
        }
    }

    private function formatWearable(object $doc): array
    {
        $d = (array) $doc;
        return [
            'date'            => $d['date'] ?? '',
            'steps'           => (int)   ($d['steps']           ?? 0),
            'active_calories' => (float)  ($d['active_calories'] ?? 0),
            'distance_km'     => (float)  ($d['distance_km']     ?? 0),
            'hydration_ml'    => (float)  ($d['hydration_ml']    ?? 0),
            'resting_hr'      => isset($d['resting_hr'])    ? (int)   $d['resting_hr']    : null,
            'sleep_minutes'   => isset($d['sleep_minutes']) ? (int)   $d['sleep_minutes'] : null,
            'source'          => $d['source'] ?? null,
        ];
    }
}
