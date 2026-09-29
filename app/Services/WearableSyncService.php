<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\{ObjectId, UTCDateTime};

/**
 * Normalizes and stores wearable daily summaries and device workout sessions
 * from Fitbit, Apple HealthKit, and Apple Watch.
 *
 * This service is called both synchronously (after OAuth connect) and by the
 * daily sync cron script.  It intentionally does not call external APIs
 * itself — callers pass raw provider responses and this layer writes the
 * canonical `wearable_data` and `wearable_sessions` documents.
 */
class WearableSyncService
{
    private WearableTrendCacheService $trendCache;

    public function __construct()
    {
        $this->trendCache = new WearableTrendCacheService();
    }

    /**
     * Upsert one daily summary document for a client.
     *
     * @param array<string, mixed> $summary
     */
    public function storeDailySummary(ObjectId $clientId, string $date, string $source, array $summary): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Invalid date format: ' . $date);
        }

        $allowed = [
            'steps', 'active_calories', 'distance_km', 'hydration_ml',
            'resting_hr', 'sleep_minutes', 'sleep_score', 'hrv_ms',
            'active_minutes', 'workout_sessions',
        ];

        $doc = [
            'source'     => $source,
            'updated_at' => new UTCDateTime(),
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $summary)) {
                $doc[$key] = $summary[$key];
            }
        }

        Database::collection('wearable_data')->updateOne(
            ['client_id' => $clientId, 'date' => $date],
            ['$set' => $doc, '$setOnInsert' => ['created_at' => new UTCDateTime()]],
            ['upsert' => true]
        );

        $this->recomputeDailyScores($clientId, $date);
        $this->trendCache->invalidateForClient($clientId);
    }

    /**
     * Store a batch of device workout sessions.  Duplicate external session
     * IDs are ignored (idempotent).
     *
     * @param array<int, array<string, mixed>> $sessions
     */
    public function storeSessions(ObjectId $clientId, string $source, array $sessions): void
    {
        if (empty($sessions)) {
            return;
        }

        $now = new UTCDateTime();
        $bulk = [];
        foreach ($sessions as $s) {
            $sessionId = (string) ($s['session_id'] ?? ($s['logId'] ?? ''));
            if ($sessionId === '') {
                continue;
            }

            $bulk[] = [
                'client_id'    => $clientId,
                'provider'     => $source,
                'session_id'   => $sessionId,
                'type'         => $s['type'] ?? 'unknown',
                'start_time'   => $this->normalizeDateTime($s['start_time'] ?? null),
                'end_time'     => $this->normalizeDateTime($s['end_time'] ?? null),
                'duration_min' => (int)   ($s['duration_min'] ?? 0),
                'calories'     => (float) ($s['calories']     ?? 0),
                'avg_hr'       => isset($s['avg_hr']) ? (int) $s['avg_hr'] : null,
                'max_hr'       => isset($s['max_hr']) ? (int) $s['max_hr'] : null,
                'steps'        => (int)   ($s['steps'] ?? 0),
                'distance_km'  => (float) ($s['distance_km'] ?? 0),
                'zones'        => $s['zones'] ?? null,
                'raw'          => $s['raw'] ?? null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($bulk)) {
            return;
        }

        $collection = Database::collection('wearable_sessions');
        foreach ($bulk as $doc) {
            $collection->updateOne(
                ['client_id' => $doc['client_id'], 'provider' => $doc['provider'], 'session_id' => $doc['session_id']],
                ['$set' => $doc],
                ['upsert' => true]
            );
        }
    }

    /**
     * Refresh OAuth access token for a Fitbit connection.
     * Returns the decrypted access token or null if refresh failed.
     */
    public function refreshFitbitToken(ObjectId $clientId): ?string
    {
        $tokenDoc = Database::collection('wearable_tokens')->findOne(
            ['client_id' => $clientId, 'provider' => 'fitbit']
        );
        if (!$tokenDoc) {
            return null;
        }

        $refreshToken = $this->decryptToken((string) ($tokenDoc['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            return null;
        }

        $clientIdStr     = $_ENV['FITBIT_CLIENT_ID']     ?? '';
        $clientSecret    = $_ENV['FITBIT_CLIENT_SECRET'] ?? '';
        $credentials     = base64_encode("{$clientIdStr}:{$clientSecret}");

        $ch = curl_init('https://api.fitbit.com/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
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

        if ($code !== 200 || !$result) {
            Database::collection('wearable_tokens')->updateOne(
                ['client_id' => $clientId, 'provider' => 'fitbit'],
                ['$set' => ['sync_status' => 'failed', 'last_sync_error' => 'Token refresh failed (' . $code . ')', 'last_sync_at' => new UTCDateTime()]]
            );
            return null;
        }

        $tokens = json_decode($result, true);
        if (empty($tokens['access_token'])) {
            return null;
        }

        $this->persistFitbitTokens($clientId, $tokens);
        return $tokens['access_token'];
    }

    /**
     * Pull and store Fitbit data for a single client for a date range.
     * Defaults to last 7 days.
     */
    public function syncFitbitForClient(ObjectId $clientId, int $days = 7): void
    {
        $tokenDoc = Database::collection('wearable_tokens')->findOne(
            ['client_id' => $clientId, 'provider' => 'fitbit']
        );
        if (!$tokenDoc) {
            return;
        }

        $expiresAt = $this->toTimestamp($tokenDoc['expires_at'] ?? null);
        $accessToken = (string) ($tokenDoc['access_token'] ?? '');

        if (time() >= ($expiresAt - 300)) {
            $accessToken = $this->refreshFitbitToken($clientId);
        } else {
            $accessToken = $this->decryptToken($accessToken);
        }

        if (!$accessToken) {
            return;
        }

        Database::collection('wearable_tokens')->updateOne(
            ['client_id' => $clientId, 'provider' => 'fitbit'],
            ['$set' => ['sync_status' => 'syncing']]
        );

        try {
            $end   = new \DateTime();
            $start = (clone $end)->modify("-" . ($days - 1) . " days");
            $period = new \DatePeriod($start, new \DateInterval('P1D'), (clone $end)->modify('+1 day'));

            foreach ($period as $d) {
                $date = $d->format('Y-m-d');
                $summary = $this->fetchFitbitDaySummary($clientId, $accessToken, $date);
                if (!empty($summary)) {
                    $this->storeDailySummary($clientId, $date, 'fitbit', $summary);
                }
            }

            // Workouts are fetched as a list; map them to their start dates.
            $sessions = $this->fetchFitbitSessions($clientId, $accessToken, $start, $end);
            $this->storeSessions($clientId, 'fitbit', $sessions);

            Database::collection('wearable_tokens')->updateOne(
                ['client_id' => $clientId, 'provider' => 'fitbit'],
                ['$set' => [
                    'sync_status'     => 'idle',
                    'last_sync_error' => null,
                    'last_sync_at'    => new UTCDateTime(),
                ]]
            );
        } catch (\Throwable $e) {
            Database::collection('wearable_tokens')->updateOne(
                ['client_id' => $clientId, 'provider' => 'fitbit'],
                ['$set' => [
                    'sync_status'     => 'failed',
                    'last_sync_error' => substr($e->getMessage(), 0, 500),
                    'last_sync_at'    => new UTCDateTime(),
                ]]
            );
            error_log('Fitbit sync failed for client ' . (string) $clientId . ': ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFitbitDaySummary(ObjectId $clientId, string $accessToken, string $date): array
    {
        $baseUrl = 'https://api.fitbit.com/1/user/-';
        $paths = [
            'activities/steps'           => 'steps',
            'activities/calories'        => 'active_calories',
            'activities/distance'          => 'distance_km',
            'activities/minutesFairlyActive' => 'active_minutes',
            'activities/heart'           => 'resting_hr',
            'sleep'                      => 'sleep_minutes',
        ];

        $summary = [];
        foreach ($paths as $path => $field) {
            $url = $path === 'sleep'
                ? "{$baseUrl}/sleep/date/{$date}.json"
                : "{$baseUrl}/{$path}/date/{$date}/1d.json";

            $data = $this->fitbitApiGet($accessToken, $url);
            if ($data === null) {
                continue;
            }

            if ($field === 'sleep_minutes') {
                $minutes = 0;
                $summarySleep = [];
                foreach ($data['sleep'] ?? [] as $sleep) {
                    $minutes += (int) ($sleep['minutesAsleep'] ?? 0);
                    $summarySleep[] = [
                        'isMainSleep' => $sleep['isMainSleep'] ?? false,
                        'efficiency'  => (int) ($sleep['efficiency'] ?? 0),
                        'deep_min'    => (int) ($sleep['levels']['summary']['deep']['minutes'] ?? 0),
                        'rem_min'     => (int) ($sleep['levels']['summary']['rem']['minutes']  ?? 0),
                        'light_min'   => (int) ($sleep['levels']['summary']['light']['minutes'] ?? 0),
                        'wake_min'    => (int) ($sleep['levels']['summary']['wake']['minutes'] ?? 0),
                    ];
                }
                if ($minutes > 0) {
                    $summary['sleep_minutes'] = $minutes;
                    $summary['sleep_summary'] = $summarySleep;
                    // Simple sleep score 0-100: efficiency weighted + deep bonus
                    $efficiency = 0;
                    foreach ($summarySleep as $s) {
                        $efficiency += $s['efficiency'];
                    }
                    $avgEfficiency = count($summarySleep) > 0 ? round($efficiency / count($summarySleep)) : 0;
                    $summary['sleep_score'] = min(100, (int) ($avgEfficiency * 0.7 + ($minutes / 480) * 30));
                }
                continue;
            }

            if ($field === 'resting_hr') {
                $resting = $data['activities-heart'] ?? [];
                foreach ($resting as $entry) {
                    if (isset($entry['value']['restingHeartRate'])) {
                        $summary['resting_hr'] = (int) $entry['value']['restingHeartRate'];
                        break;
                    }
                }
                continue;
            }

            foreach ($data as $key => $val) {
                if (is_array($val) && !empty($val)) {
                    $last = end($val);
                    $summary[$field] = $this->normalizeFitbitValue($field, $last['value'] ?? 0);
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFitbitSessions(ObjectId $clientId, string $accessToken, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $baseUrl = 'https://api.fitbit.com/1/user/-';
        $url = "{$baseUrl}/activities/list.json?afterDate=" . $start->format('Y-m-d') . "&sort=asc&offset=0&limit=100";

        $data = $this->fitbitApiGet($accessToken, $url);
        if ($data === null) {
            return [];
        }

        $sessions = [];
        foreach ($data['activities'] ?? [] as $act) {
            $startTime = $act['startTime'] ?? null;
            if (!$startTime) {
                continue;
            }
            $dt = new \DateTime($startTime);
            if ($dt < $start || $dt > $end) {
                continue;
            }

            $sessions[] = [
                'session_id'   => (string) ($act['logId'] ?? uniqid('fb_', true)),
                'type'         => strtolower((string) ($act['activityName'] ?? 'unknown')),
                'start_time'   => $startTime,
                'end_time'     => null,
                'duration_min' => (int) round(((int) ($act['duration'] ?? 0)) / 60000),
                'calories'     => (float) ($act['calories'] ?? 0),
                'avg_hr'       => isset($act['averageHeartRate']) ? (int) $act['averageHeartRate'] : null,
                'max_hr'       => null,
                'steps'        => (int) ($act['steps'] ?? 0),
                'distance_km'  => (float) ($act['distance'] ?? 0),
                'zones'        => $act['heartRateZones'] ?? null,
                'raw'          => $act,
            ];
        }

        return $sessions;
    }

    private function fitbitApiGet(string $accessToken, string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) {
            return null;
        }
        $data = json_decode($res, true);
        return is_array($data) ? $data : null;
    }

    private function normalizeFitbitValue(string $field, mixed $value): float|int
    {
        $num = (float) $value;
        return match ($field) {
            'steps' => (int) $num,
            'active_calories' => $num,
            'distance_km' => round($num, 2),
            'active_minutes' => (int) $num,
            default => $num,
        };
    }

    /**
     * Recompute recovery_score for a single day based on sleep and activity.
     */
    private function recomputeDailyScores(ObjectId $clientId, string $date): void
    {
        $doc = Database::collection('wearable_data')->findOne(['client_id' => $clientId, 'date' => $date]);
        if (!$doc) {
            return;
        }

        $sleepMin = (int) ($doc['sleep_minutes'] ?? 0);
        $sleepScore = (int) ($doc['sleep_score'] ?? 0);
        $steps = (int) ($doc['steps'] ?? 0);
        $restingHr = (int) ($doc['resting_hr'] ?? 0);

        // Simple recovery score 0-100:
        // 40% sleep score, 30% sleep duration (target 7-9h), 20% steps (target 8-10k), 10% resting HR trend placeholder.
        $sleepDurationScore = $sleepMin >= 420 && $sleepMin <= 540 ? 100 : min(100, round($sleepMin / 480 * 100));
        $stepScore = min(100, round($steps / 9000 * 100));
        $hrScore = $restingHr > 0 ? min(100, max(0, 100 - max(0, $restingHr - 60) * 2)) : 50;

        $recoveryScore = (int) round(
            $sleepScore * 0.40 +
            $sleepDurationScore * 0.30 +
            $stepScore * 0.20 +
            $hrScore * 0.10
        );

        Database::collection('wearable_data')->updateOne(
            ['client_id' => $clientId, 'date' => $date],
            ['$set' => ['recovery_score' => $recoveryScore, 'updated_at' => new UTCDateTime()]]
        );
    }

    private function persistFitbitTokens(ObjectId $clientId, array $tokens): void
    {
        $encKey = $_ENV['WEARABLE_TOKEN_ENCRYPTION_KEY'] ?? '';
        if ($encKey === '') {
            throw new \RuntimeException('WEARABLE_TOKEN_ENCRYPTION_KEY is required');
        }

        $key = hash('sha256', $encKey, true);

        $accessToken  = $tokens['access_token'];
        $refreshToken = $tokens['refresh_token'] ?? '';

        $ivAccess  = random_bytes(16);
        $ivRefresh = random_bytes(16);
        $accessToken  = base64_encode($ivAccess . openssl_encrypt($accessToken, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $ivAccess));
        $refreshToken = base64_encode($ivRefresh . openssl_encrypt($refreshToken, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $ivRefresh));

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
            ], '$setOnInsert' => ['created_at' => $now]],
            ['upsert' => true]
        );
    }

    public function decryptToken(string $token): string
    {
        $encKey = $_ENV['WEARABLE_TOKEN_ENCRYPTION_KEY'] ?? '';
        if ($encKey === '' || $token === '') {
            return '';
        }

        $raw = base64_decode($token, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }

        $iv  = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key = hash('sha256', $encKey, true);

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    private function normalizeDateTime(mixed $value): ?UTCDateTime
    {
        if ($value instanceof UTCDateTime) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return new UTCDateTime($value->getTimestamp() * 1000);
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            return $ts === false ? null : new UTCDateTime($ts * 1000);
        }
        return null;
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof UTCDateTime) {
            return (int) (((string) $value) / 1000);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        return is_numeric($value) ? (int) $value : null;
    }
}
