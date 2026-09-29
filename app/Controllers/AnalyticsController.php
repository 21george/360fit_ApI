<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Services\WearableTrendCacheService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class AnalyticsController
{
    public function clientAnalytics(array $params): void
    {
        $user     = $params['_auth'];
        $clientId = $params['clientId'] ?? '';

        if ($user['role'] !== 'coach' && $user['role'] !== 'staff') Response::error('Forbidden', 403);

        $orgId = $params['_org_id'] ?? $user['sub'];
        $client = Database::collection('clients')->findOne([
            '_id' => new ObjectId($clientId),
            'coach_id' => new ObjectId($orgId)
        ]);
        if (!$client) Response::notFound('Client not found');

        $clientObjId = new ObjectId($clientId);

        // ── 1. Completion rate per week ────────────────────────────────────
        $plans = Database::collection('workout_plans')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['week_start' => -1], 'limit' => 12]
        );

        $completionData = [];
        foreach ($plans as $plan) {
            $totalDays = count($plan['days'] ?? []);
            $completedDays = Database::collection('workout_logs')->countDocuments([
                'client_id'       => $clientObjId,
                'workout_plan_id' => $plan['_id'],
            ]);
            $completionData[] = [
                'week'         => isset($plan['week_start']) ? $plan['week_start']->toDateTime()->format('Y-m-d') : 'Unknown',
                'total_days'   => $totalDays,
                'completed'    => $completedDays,
                'rate'         => $totalDays > 0 ? round(($completedDays / $totalDays) * 100) : 0,
            ];
        }

        // ── 2. Weight lifted per exercise over time ────────────────────────
        $logs = Database::collection('workout_logs')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['completed_at' => 1], 'limit' => 100]
        );

        $exerciseProgress = [];
        $totalVolume      = 0;
        $totalSets        = 0;
        $totalReps        = 0;
        $personalRecords  = [];
        foreach ($logs as $log) {
            $date = isset($log['completed_at']) ? $log['completed_at']->toDateTime()->format('Y-m-d') : null;
            foreach ($log['exercises'] ?? [] as $exercise) {
                $name = $exercise['name'] ?? '';
                if (empty($name)) continue;
                $maxKg = 0;
                foreach ($exercise['sets_completed'] ?? [] as $set) {
                    $kg   = (float)($set['kg'] ?? 0);
                    $reps = (int)($set['reps_done'] ?? $set['reps'] ?? 0);
                    if ($kg > $maxKg) $maxKg = $kg;
                    $totalVolume += $kg * $reps;
                    $totalSets++;
                    $totalReps += $reps;
                }
                if (!isset($exerciseProgress[$name])) $exerciseProgress[$name] = [];
                $exerciseProgress[$name][] = ['date' => $date, 'max_kg' => $maxKg];

                // Track personal records
                if (!isset($personalRecords[$name]) || $maxKg > $personalRecords[$name]['max_kg']) {
                    $personalRecords[$name] = ['max_kg' => $maxKg, 'date' => $date];
                }
            }
        }

        // ── 3. Body measurements ───────────────────────────────────────────
        $measurements = Database::collection('body_measurements')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['recorded_at' => 1]]
        );
        $measurementData = [];
        foreach ($measurements as $m) {
            $measurementData[] = [
                'date'         => isset($m['recorded_at']) ? $m['recorded_at']->toDateTime()->format('Y-m-d') : null,
                'weight_kg'    => $m['weight_kg'] ?? null,
                'chest_cm'     => $m['chest_cm'] ?? null,
                'waist_cm'     => $m['waist_cm'] ?? null,
                'hips_cm'      => $m['hips_cm'] ?? null,
                'body_fat_pct' => $m['body_fat_pct'] ?? null,
            ];
        }

        // ── 4. Photo progress ─────────────────────────────────────────────
        $photos = Database::collection('media_uploads')->find(
            ['client_id' => $clientObjId, 'type' => 'photo'],
            ['sort' => ['uploaded_at' => 1], 'limit' => 20]
        );
        $photoData = [];
        foreach ($photos as $p) {
            $photoData[] = [
                'id'          => (string)$p['_id'],
                'uploaded_at' => isset($p['uploaded_at']) ? $p['uploaded_at']->toDateTime()->format('c') : null,
                's3_key'      => $p['s3_key'] ?? null,
                'url'         => $p['url'] ?? null,
            ];
        }

        // ── 5. Streak ─────────────────────────────────────────────────────
        $allLogs = Database::collection('workout_logs')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['completed_at' => -1], 'projection' => ['completed_at' => 1]]
        );
        $logDates  = [];
        foreach ($allLogs as $log) {
            if (isset($log['completed_at'])) {
                $logDates[] = $log['completed_at']->toDateTime()->format('Y-m-d');
            }
        }
        $uniqueDates = array_unique($logDates);
        rsort($uniqueDates);
        $streak = 0;
        $today  = new \DateTime('today');
        foreach ($uniqueDates as $dateStr) {
            $d    = new \DateTime($dateStr);
            $diff = $today->diff($d)->days;
            if ($diff === $streak) $streak++;
            else break;
        }

        // ── 6. Weekly volume trend (last 8 weeks) ─────────────────────────
        $weeklyVolume = [];
        $eightWeeksAgo = new \DateTime('-8 weeks');
        $recentLogs = Database::collection('workout_logs')->find([
            'client_id'    => $clientObjId,
            'completed_at' => ['$gte' => new UTCDateTime($eightWeeksAgo->getTimestamp() * 1000)],
        ], ['sort' => ['completed_at' => 1]]);

        foreach ($recentLogs as $log) {
            if (!isset($log['completed_at'])) continue;
            $weekKey = $log['completed_at']->toDateTime()->format('Y-W');
            $weekDate = $log['completed_at']->toDateTime()->format('Y-m-d');
            if (!isset($weeklyVolume[$weekKey])) {
                $weeklyVolume[$weekKey] = ['week' => $weekDate, 'volume' => 0, 'sessions' => 0];
            }
            $weeklyVolume[$weekKey]['sessions']++;
            foreach ($log['exercises'] ?? [] as $ex) {
                foreach ($ex['sets_completed'] ?? [] as $set) {
                    $weeklyVolume[$weekKey]['volume'] += (float)($set['kg'] ?? 0) * (int)($set['reps_done'] ?? $set['reps'] ?? 0);
                }
            }
        }

        // ── 7. Top 5 personal records ─────────────────────────────────────
        arsort($personalRecords);
        $topPRs = [];
        foreach (array_slice($personalRecords, 0, 10, true) as $name => $pr) {
            $topPRs[] = ['exercise' => $name, 'max_kg' => $pr['max_kg'], 'date' => $pr['date']];
        }

        // ── 8. Wearable trends + readiness ─────────────────────────────────
        $wearableAnalytics = $this->buildWearableAnalytics($clientObjId);

        Response::success([
            'completion_rate'   => $completionData,
            'exercise_progress' => $exerciseProgress,
            'measurements'      => $measurementData,
            'photos'            => $photoData,
            'current_streak'    => $streak,
            'total_workouts'    => count($uniqueDates),
            'total_volume'      => round($totalVolume),
            'total_sets'        => $totalSets,
            'total_reps'        => $totalReps,
            'personal_records'  => $topPRs,
            'weekly_volume'     => array_values($weeklyVolume),
            'wearable'          => $wearableAnalytics,
        ]);
    }

    /**
     * Build wearable trend arrays and readiness/recovery insights for a client.
     *
     * @return array<string, mixed>
     */
    private function buildWearableAnalytics(ObjectId $clientId): array
    {
        $metrics = ['steps', 'active_calories', 'distance_km', 'resting_hr', 'sleep_minutes'];
        $windows = [7, 30, 90];
        $trends = [];

        $cache = new WearableTrendCacheService();
        foreach ($windows as $days) {
            $trends["{$days}d"] = [];
            foreach ($metrics as $metric) {
                try {
                    $trends["{$days}d"][$metric] = $cache->getTrend($clientId, $metric, $days);
                } catch (\Throwable $e) {
                    $trends["{$days}d"][$metric] = [
                        'metric' => $metric, 'window_days' => $days, 'points' => [],
                        'average' => 0, 'total' => 0, 'min' => 0, 'max' => 0,
                    ];
                }
            }
        }

        // Latest readiness snapshot
        $latest = Database::collection('wearable_data')->findOne(
            ['client_id' => $clientId],
            ['sort' => ['date' => -1]]
        );

        $readiness = null;
        if ($latest) {
            $d = (array) $latest;
            $readiness = [
                'date'           => $d['date'] ?? null,
                'steps'          => (int)   ($d['steps']          ?? 0),
                'active_calories'=> (float) ($d['active_calories'] ?? 0),
                'sleep_minutes'  => (int)   ($d['sleep_minutes']  ?? 0),
                'sleep_score'    => (int)   ($d['sleep_score']    ?? 0),
                'resting_hr'     => (int)   ($d['resting_hr']     ?? 0),
                'recovery_score' => (int)   ($d['recovery_score'] ?? 0),
            ];
        }

        // Workout/sleep correlation over the last 30 days
        $correlation = $this->computeWorkoutSleepCorrelation($clientId);

        return [
            'trends'      => $trends,
            'readiness' => $readiness,
            'correlation' => $correlation,
        ];
    }

    /**
     * Compute simple correlation: average workout completion rate on days
     * grouped by sleep quality buckets (poor / moderate / good).
     *
     * @return array<string, mixed>
     */
    private function computeWorkoutSleepCorrelation(ObjectId $clientId): array
    {
        $since = date('Y-m-d', strtotime('-30 days'));

        $wearable = Database::collection('wearable_data')->find(
            ['client_id' => $clientId, 'date' => ['$gte' => $since]],
            ['sort' => ['date' => 1]]
        )->toArray();

        $logs = Database::collection('workout_logs')->find(
            ['client_id' => $clientId, 'completed_at' => ['$gte' => new UTCDateTime(strtotime($since) * 1000)]],
            ['projection' => ['completed_at' => 1]]
        )->toArray();

        $workoutDates = [];
        foreach ($logs as $log) {
            if (isset($log['completed_at'])) {
                $workoutDates[$log['completed_at']->toDateTime()->format('Y-m-d')] = true;
            }
        }

        $buckets = ['poor' => ['count' => 0, 'workouts' => 0], 'moderate' => ['count' => 0, 'workouts' => 0], 'good' => ['count' => 0, 'workouts' => 0]];
        foreach ($wearable as $day) {
            $minutes = (int) ($day['sleep_minutes'] ?? 0);
            $bucket = $minutes < 360 ? 'poor' : ($minutes < 480 ? 'moderate' : 'good');
            $buckets[$bucket]['count']++;
            if (!empty($workoutDates[$day['date'] ?? ''])) {
                $buckets[$bucket]['workouts']++;
            }
        }

        $result = [];
        foreach ($buckets as $bucket => $data) {
            $result[$bucket] = [
                'days'              => $data['count'],
                'workout_days'      => $data['workouts'],
                'completion_rate'   => $data['count'] > 0 ? round(($data['workouts'] / $data['count']) * 100, 1) : 0,
            ];
        }

        return $result;
    }

    // GET /client/analytics (client-side)
    public function clientSelfAnalytics(array $params): void
    {
        $clientId    = $params['_auth']['sub'];
        $clientObjId = new ObjectId($clientId);

        // Completion rate
        $plans = Database::collection('workout_plans')->find(
            ['$or' => [
                ['client_id' => $clientObjId],
                ['client_ids' => ['$in' => [$clientObjId]]],
            ]],
            ['sort' => ['week_start' => -1], 'limit' => 12]
        );

        $completionData = [];
        $totalPlanned   = 0;
        $totalDone      = 0;
        foreach ($plans as $plan) {
            $td = count($plan['days'] ?? []);
            $cd = Database::collection('workout_logs')->countDocuments([
                'client_id'       => $clientObjId,
                'workout_plan_id' => $plan['_id'],
            ]);
            $totalPlanned += $td;
            $totalDone    += $cd;
            $completionData[] = [
                'week'       => isset($plan['week_start']) ? $plan['week_start']->toDateTime()->format('Y-m-d') : 'Unknown',
                'total_days' => $td,
                'completed'  => $cd,
                'rate'       => $td > 0 ? round(($cd / $td) * 100) : 0,
            ];
        }

        // Exercise progress + totals
        $logs = Database::collection('workout_logs')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['completed_at' => 1], 'limit' => 200]
        );

        $exerciseProgress = [];
        $totalVolume      = 0;
        $totalSets        = 0;
        $personalRecords  = [];
        foreach ($logs as $log) {
            $date = isset($log['completed_at']) ? $log['completed_at']->toDateTime()->format('Y-m-d') : null;
            foreach ($log['exercises'] ?? [] as $exercise) {
                $name = $exercise['name'] ?? '';
                if (empty($name)) continue;
                $maxKg = 0;
                foreach ($exercise['sets_completed'] ?? [] as $set) {
                    $kg   = (float)($set['kg'] ?? 0);
                    $reps = (int)($set['reps_done'] ?? $set['reps'] ?? 0);
                    if ($kg > $maxKg) $maxKg = $kg;
                    $totalVolume += $kg * $reps;
                    $totalSets++;
                }
                if (!isset($exerciseProgress[$name])) $exerciseProgress[$name] = [];
                $exerciseProgress[$name][] = ['date' => $date, 'max_kg' => $maxKg];
                if (!isset($personalRecords[$name]) || $maxKg > $personalRecords[$name]['max_kg']) {
                    $personalRecords[$name] = ['max_kg' => $maxKg, 'date' => $date];
                }
            }
        }

        // Streak
        $allLogs = Database::collection('workout_logs')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['completed_at' => -1], 'projection' => ['completed_at' => 1]]
        );
        $logDates = [];
        foreach ($allLogs as $log) {
            if (isset($log['completed_at'])) $logDates[] = $log['completed_at']->toDateTime()->format('Y-m-d');
        }
        $uniqueDates = array_unique($logDates);
        rsort($uniqueDates);
        $streak = 0;
        $today  = new \DateTime('today');
        foreach ($uniqueDates as $dateStr) {
            $d    = new \DateTime($dateStr);
            $diff = $today->diff($d)->days;
            if ($diff === $streak) $streak++;
            else break;
        }

        arsort($personalRecords);
        $topPRs = [];
        foreach (array_slice($personalRecords, 0, 10, true) as $name => $pr) {
            $topPRs[] = ['exercise' => $name, 'max_kg' => $pr['max_kg'], 'date' => $pr['date']];
        }

        // Weekly volume (last 8 weeks)
        $weeklyVolume   = [];
        $eightWeeksAgo  = new \DateTime('-8 weeks');
        $recentLogs = Database::collection('workout_logs')->find([
            'client_id'    => $clientObjId,
            'completed_at' => ['$gte' => new UTCDateTime($eightWeeksAgo->getTimestamp() * 1000)],
        ], ['sort' => ['completed_at' => 1]]);

        foreach ($recentLogs as $log) {
            if (!isset($log['completed_at'])) continue;
            $weekKey = $log['completed_at']->toDateTime()->format('Y-W');
            $weekDate = $log['completed_at']->toDateTime()->format('Y-m-d');
            if (!isset($weeklyVolume[$weekKey])) {
                $weeklyVolume[$weekKey] = ['week' => $weekDate, 'volume' => 0, 'sessions' => 0];
            }
            $weeklyVolume[$weekKey]['sessions']++;
            foreach ($log['exercises'] ?? [] as $ex) {
                foreach ($ex['sets_completed'] ?? [] as $set) {
                    $weeklyVolume[$weekKey]['volume'] += (float)($set['kg'] ?? 0) * (int)($set['reps_done'] ?? $set['reps'] ?? 0);
                }
            }
        }

        Response::success([
            'completion_rate'   => $completionData,
            'overall_rate'      => $totalPlanned > 0 ? round(($totalDone / $totalPlanned) * 100) : 0,
            'exercise_progress' => $exerciseProgress,
            'current_streak'    => $streak,
            'total_workouts'    => count($uniqueDates),
            'total_volume'      => round($totalVolume),
            'total_sets'        => $totalSets,
            'personal_records'  => $topPRs,
            'weekly_volume'     => array_values($weeklyVolume),
            'wearable'          => $this->buildWearableAnalytics($clientObjId),
        ]);
    }

    // GET /analytics/coach  (coach-level aggregate stats)
    public function coachAnalytics(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);

        // ── 1. Client counts ───────────────────────────────────────────────
        $totalClients = Database::collection('clients')->countDocuments([
            'coach_id' => $coachId,
            'active'   => true,
        ]);

        $monthStart = new UTCDateTime((new \DateTime('first day of this month midnight'))->getTimestamp() * 1000);
        $newClientsThisMonth = Database::collection('clients')->countDocuments([
            'coach_id'   => $coachId,
            'active'     => true,
            'created_at' => ['$gte' => $monthStart],
        ]);

        // ── 2. Active clients (logged a workout in last 7 days) ────────────
        // workout_logs stores client_id (not coach_id), so resolve via clients collection.
        $sevenDaysAgo = new UTCDateTime((new \DateTime('-7 days'))->getTimestamp() * 1000);
        $allClientCursor = Database::collection('clients')->find(
            ['coach_id' => $coachId, 'active' => true],
            ['projection' => ['_id' => 1]]
        );
        $allClientIds = array_map(fn($c) => $c['_id'], iterator_to_array($allClientCursor));

        $activeClients = 0;
        if (!empty($allClientIds)) {
            $recentLogCursor = Database::collection('workout_logs')->find(
                ['client_id' => ['$in' => $allClientIds], 'completed_at' => ['$gte' => $sevenDaysAgo]],
                ['projection' => ['client_id' => 1]]
            );
            $activeClientIds = [];
            foreach ($recentLogCursor as $log) {
                $activeClientIds[(string) $log['client_id']] = true;
            }
            $activeClients = count($activeClientIds);
        }

        // ── 3. Total workouts ──────────────────────────────────────────────
        $totalWorkouts = empty($allClientIds)
            ? 0
            : Database::collection('workout_logs')->countDocuments(['client_id' => ['$in' => $allClientIds]]);

        // ── 4. Completion rate across all plans ────────────────────────────
        $allPlans = Database::collection('workout_plans')
            ->find(['coach_id' => $coachId], ['projection' => ['_id' => 1, 'days' => 1, 'client_id' => 1]])
            ->toArray();

        $totalPlanDays     = 0;
        $completedPlanDays = 0;
        foreach ($allPlans as $plan) {
            $days = count($plan['days'] ?? []);
            $totalPlanDays += $days;
            if (!empty($plan['client_id'])) {
                $completedPlanDays += Database::collection('workout_logs')->countDocuments([
                    'client_id'       => $plan['client_id'],
                    'workout_plan_id' => $plan['_id'],
                ]);
            }
        }
        $completionRate = $totalPlanDays > 0 ? (int) round(($completedPlanDays / $totalPlanDays) * 100) : 0;

        // ── 5. Top clients by workout count (last 30 days) ─────────────────
        $thirtyDaysAgo = new UTCDateTime((new \DateTime('-30 days'))->getTimestamp() * 1000);
        $recentLogs30 = empty($allClientIds) ? [] : Database::collection('workout_logs')->find(
            ['client_id' => ['$in' => $allClientIds], 'completed_at' => ['$gte' => $thirtyDaysAgo]],
            ['projection' => ['client_id' => 1]]
        )->toArray();
        $clientWorkoutCounts = [];
        foreach ($recentLogs30 as $log) {
            $cid = (string) $log['client_id'];
            $clientWorkoutCounts[$cid] = ($clientWorkoutCounts[$cid] ?? 0) + 1;
        }
        arsort($clientWorkoutCounts);
        $topClientIds = array_slice(array_keys($clientWorkoutCounts), 0, 5);

        $topClients = [];
        if (!empty($topClientIds)) {
            $clientDocs = Database::collection('clients')->find([
                '_id' => ['$in' => array_map(fn($id) => new ObjectId($id), $topClientIds)],
            ], ['projection' => ['_id' => 1, 'name' => 1]])->toArray();
            $clientNameMap = [];
            foreach ($clientDocs as $c) {
                $clientNameMap[(string) $c['_id']] = $c['name'] ?? 'Client';
            }
            foreach ($topClientIds as $cid) {
                $topClients[] = [
                    'id'       => $cid,
                    'name'     => $clientNameMap[$cid] ?? 'Client',
                    'sessions' => $clientWorkoutCounts[$cid],
                ];
            }
        }

        Response::success([
            'total_clients'          => $totalClients,
            'new_clients_this_month' => $newClientsThisMonth,
            'active_clients'         => $activeClients,
            'total_workouts'         => $totalWorkouts,
            'completion_rate'        => $completionRate,
            'top_clients'            => $topClients,
        ]);
    }

    // GET /analytics/sessions  (coaching session aggregate stats)
    public function sessionAnalytics(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);

        $allSessions = Database::collection('coaching_sessions')
            ->find(['coach_id' => $coachId], ['sort' => ['scheduled_at' => -1]])
            ->toArray();

        // ── 1. Totals by status ────────────────────────────────────────────
        $total    = count($allSessions);
        $byStatus = ['upcoming' => 0, 'live' => 0, 'ended' => 0, 'cancelled' => 0];
        foreach ($allSessions as $s) {
            $st = $s['status'] ?? 'upcoming';
            if (isset($byStatus[$st])) $byStatus[$st]++;
        }

        // ── 2. This month ──────────────────────────────────────────────────
        $monthStart = new UTCDateTime((new \DateTime('first day of this month midnight'))->getTimestamp() * 1000);
        $thisMonth  = Database::collection('coaching_sessions')->countDocuments([
            'coach_id'     => $coachId,
            'scheduled_at' => ['$gte' => $monthStart],
        ]);

        // ── 3. Average duration (ended sessions with start + end times) ────
        $totalDurationMin = 0;
        $endedWithTimes   = 0;
        foreach ($allSessions as $s) {
            if (($s['status'] ?? '') === 'ended' && isset($s['started_at'], $s['ended_at'])) {
                $start = $s['started_at'] instanceof UTCDateTime
                    ? $s['started_at']->toDateTime()
                    : new \DateTime((string) $s['started_at']);
                $end   = $s['ended_at'] instanceof UTCDateTime
                    ? $s['ended_at']->toDateTime()
                    : new \DateTime((string) $s['ended_at']);
                $diffMin = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
                if ($diffMin > 0) {
                    $totalDurationMin += $diffMin;
                    $endedWithTimes++;
                }
            }
        }
        $avgDurationMin = $endedWithTimes > 0 ? (int) round($totalDurationMin / $endedWithTimes) : null;

        // ── 4. Top clients by session count (last 30 days) ─────────────────
        $thirtyDaysAgo = new UTCDateTime((new \DateTime('-30 days'))->getTimestamp() * 1000);
        $thirtyDaysAgoTs = $thirtyDaysAgo->toDateTime()->getTimestamp();
        $recent30 = array_filter($allSessions, function ($s) use ($thirtyDaysAgoTs) {
            if (!isset($s['scheduled_at'])) return false;
            $ts = $s['scheduled_at'] instanceof UTCDateTime
                ? $s['scheduled_at']->toDateTime()->getTimestamp()
                : strtotime((string) $s['scheduled_at']);
            return $ts !== false && $ts >= $thirtyDaysAgoTs;
        });

        $clientSessionCounts = [];
        foreach ($recent30 as $s) {
            $cid = (string) $s['client_id'];
            $clientSessionCounts[$cid] = ($clientSessionCounts[$cid] ?? 0) + 1;
        }
        arsort($clientSessionCounts);
        $topSessionClientIds = array_slice(array_keys($clientSessionCounts), 0, 5);

        $topClients = [];
        if (!empty($topSessionClientIds)) {
            $clientDocs = Database::collection('clients')->find([
                '_id' => ['$in' => array_map(fn($id) => new ObjectId($id), $topSessionClientIds)],
            ], ['projection' => ['_id' => 1, 'name' => 1]])->toArray();
            $clientNameMap = [];
            foreach ($clientDocs as $c) {
                $clientNameMap[(string) $c['_id']] = $c['name'] ?? 'Client';
            }
            foreach ($topSessionClientIds as $cid) {
                $topClients[] = [
                    'id'       => $cid,
                    'name'     => $clientNameMap[$cid] ?? 'Client',
                    'sessions' => $clientSessionCounts[$cid],
                ];
            }
        }

        // ── 5. Recent 10 sessions ──────────────────────────────────────────
        $recentSessions = array_slice($allSessions, 0, 10);
        $recentClientIds = array_unique(array_map(fn($s) => (string) $s['client_id'], $recentSessions));
        $recentClientDocs = Database::collection('clients')->find([
            '_id' => ['$in' => array_map(fn($id) => new ObjectId($id), $recentClientIds)],
        ], ['projection' => ['_id' => 1, 'name' => 1]])->toArray();
        $recentClientMap = [];
        foreach ($recentClientDocs as $c) {
            $recentClientMap[(string) $c['_id']] = $c['name'] ?? 'Client';
        }

        $recent = [];
        foreach ($recentSessions as $s) {
            $scheduledAt = null;
            if (isset($s['scheduled_at'])) {
                $dt = $s['scheduled_at'] instanceof UTCDateTime
                    ? $s['scheduled_at']->toDateTime()
                    : new \DateTime((string) $s['scheduled_at']);
                $scheduledAt = $dt->format(DATE_ATOM);
            }
            $durationMin = null;
            if (isset($s['started_at'], $s['ended_at'])) {
                $start = $s['started_at'] instanceof UTCDateTime
                    ? $s['started_at']->toDateTime()
                    : new \DateTime((string) $s['started_at']);
                $end   = $s['ended_at'] instanceof UTCDateTime
                    ? $s['ended_at']->toDateTime()
                    : new \DateTime((string) $s['ended_at']);
                $diff  = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
                if ($diff > 0) $durationMin = $diff;
            }
            $recent[] = [
                'id'           => (string) $s['_id'],
                'title'        => $s['title'] ?? '',
                'client_name'  => $recentClientMap[(string) $s['client_id']] ?? 'Client',
                'status'       => $s['status'] ?? 'upcoming',
                'scheduled_at' => $scheduledAt,
                'duration_min' => $durationMin,
            ];
        }

        Response::success([
            'total'            => $total,
            'this_month'       => $thisMonth,
            'by_status'        => $byStatus,
            'avg_duration_min' => $avgDurationMin,
            'top_clients'      => $topClients,
            'recent'           => $recent,
        ]);
    }
}
