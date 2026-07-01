<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class WorkoutAnalysisService
{
    private NotificationTriggerService $notifications;

    public function __construct()
    {
        $this->notifications = new NotificationTriggerService();
    }

    /**
     * Run a full analysis for a client and return the computed metrics + recommendations.
     * Does NOT persist anything.
     */
    public function analyze(string $clientId, string $coachId): array
    {
        $clientObjId = new ObjectId($clientId);
        $coachObjId  = new ObjectId($coachId);

        // ── 1. Fetch raw data ──────────────────────────────────────────────
        $client = Database::collection('clients')->findOne([
            '_id' => $clientObjId,
            'coach_id' => $coachObjId,
        ]);
        if (!$client) {
            throw new \RuntimeException('Client not found');
        }

        $ninetyDaysAgo = new UTCDateTime((new \DateTime('-90 days'))->getTimestamp() * 1000);

        $logs = Database::collection('workout_logs')->find([
            'client_id' => $clientObjId,
            'completed_at' => ['$gte' => $ninetyDaysAgo],
        ], ['sort' => ['completed_at' => 1]]);

        $allLogs = Database::collection('workout_logs')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['completed_at' => 1]]
        );

        $measurements = Database::collection('body_measurements')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['recorded_at' => 1]]
        );

        $plans = Database::collection('workout_plans')->find(
            ['client_id' => $clientObjId],
            ['sort' => ['week_start' => -1], 'limit' => 12]
        );

        // ── 2. Compute metrics ─────────────────────────────────────────────
        $metrics = $this->computeMetrics($logs, $allLogs, $measurements, $plans);

        // ── 3. Generate recommendations ────────────────────────────────────
        $recommendations = $this->generateRecommendations($metrics, $client);

        return [
            'client_id' => $clientId,
            'coach_id' => $coachId,
            'status' => 'pending',
            'generated_at' => (new \DateTime())->format('c'),
            'reviewed_at' => null,
            'metrics' => $metrics,
            'recommendations' => $recommendations,
            'coach_notes' => null,
            'selected_recommendation_index' => null,
            'assigned_plan_id' => null,
        ];
    }

    private function computeMetrics(
        \MongoDB\Driver\Cursor $logs90d,
        \MongoDB\Driver\Cursor $allLogs,
        \MongoDB\Driver\Cursor $measurements,
        \MongoDB\Driver\Cursor $plans
    ): array {
        $totalVolume = 0.0;
        $totalSets = 0;
        $workoutCount = 0;
        $weeklyVolume = [];
        $exerciseMaxKg = [];
        $prCount30d = 0;
        $logDates = [];
        $plannedDaysTotal = 0;
        $completedDaysTotal = 0;
        $durations = [];

        // Process 90-day logs
        foreach ($logs90d as $log) {
            $workoutCount++;
            $date = isset($log['completed_at']) ? $log['completed_at']->toDateTime()->format('Y-m-d') : null;
            if ($date) {
                $logDates[] = $date;
            }

            // Duration estimate: 5 min per set + rest
            $daySets = 0;
            foreach ($log['exercises'] ?? [] as $exercise) {
                foreach ($exercise['sets_completed'] ?? [] as $set) {
                    $kg = (float) ($set['kg'] ?? 0);
                    $reps = (int) ($set['reps_done'] ?? $set['reps'] ?? 0);
                    $totalVolume += $kg * $reps;
                    $totalSets++;
                    $daySets++;

                    $name = $exercise['name'] ?? '';
                    if ($name) {
                        if (!isset($exerciseMaxKg[$name])) {
                            $exerciseMaxKg[$name] = ['all_time' => 0.0, 'last_30d' => 0.0];
                        }
                        if ($kg > $exerciseMaxKg[$name]['all_time']) {
                            $exerciseMaxKg[$name]['all_time'] = $kg;
                        }
                    }
                }
            }
            // Rough estimate: 3 min per set (including rest) + 10 min warmup
            $durations[] = max(10, $daySets * 3);

            // Weekly volume aggregation
            if (isset($log['completed_at'])) {
                $weekKey = $log['completed_at']->toDateTime()->format('Y-W');
                if (!isset($weeklyVolume[$weekKey])) {
                    $weeklyVolume[$weekKey] = 0;
                }
                foreach ($log['exercises'] ?? [] as $ex) {
                    foreach ($ex['sets_completed'] ?? [] as $set) {
                        $weeklyVolume[$weekKey] += (float) ($set['kg'] ?? 0) * (int) ($set['reps_done'] ?? $set['reps'] ?? 0);
                    }
                }
            }
        }

        // PR count in last 30d: count exercises where max in last 30d > all-time max prior
        $allTimeMax = [];
        $last30dMax = [];
        $thirtyDaysAgoDt = new \DateTime('-30 days');
        foreach ($allLogs as $log) {
            $logDate = isset($log['completed_at']) ? $log['completed_at']->toDateTime() : null;
            foreach ($log['exercises'] ?? [] as $exercise) {
                $name = $exercise['name'] ?? '';
                if (!$name) continue;
                $maxKg = 0;
                foreach ($exercise['sets_completed'] ?? [] as $set) {
                    $kg = (float) ($set['kg'] ?? 0);
                    if ($kg > $maxKg) $maxKg = $kg;
                }
                if (!isset($allTimeMax[$name])) $allTimeMax[$name] = 0.0;
                if ($maxKg > $allTimeMax[$name]) $allTimeMax[$name] = $maxKg;

                if ($logDate && $logDate >= $thirtyDaysAgoDt) {
                    if (!isset($last30dMax[$name])) $last30dMax[$name] = 0.0;
                    if ($maxKg > $last30dMax[$name]) $last30dMax[$name] = $maxKg;
                }
            }
        }
        foreach ($last30dMax as $name => $max30d) {
            if ($max30d > 0 && $max30d >= ($allTimeMax[$name] ?? 0.0)) {
                $prCount30d++;
            }
        }

        // Completion rate across recent plans
        foreach ($plans as $plan) {
            $td = count($plan['days'] ?? []);
            $cd = Database::collection('workout_logs')->countDocuments([
                'client_id' => $plan['client_id'],
                'workout_plan_id' => $plan['_id'],
            ]);
            $plannedDaysTotal += $td;
            $completedDaysTotal += min($cd, $td);
        }
        $completionRate = $plannedDaysTotal > 0 ? round($completedDaysTotal / $plannedDaysTotal, 3) : 0.0;

        // Consistency score: workouts per week over 90 days scaled 0-100
        $weeks = max(1, count(array_unique($logDates)) / 7);
        $workoutsPerWeek = $workoutCount / $weeks;
        $consistencyScore = min(100, round(($workoutsPerWeek / 5) * 100)); // 5/week = perfect

        // Progression score: compare recent 4 weeks avg vs prior 4 weeks avg
        $progressionScore = $this->computeProgressionScore($weeklyVolume);

        // Streak
        $uniqueDates = array_unique($logDates);
        rsort($uniqueDates);
        $streak = 0;
        $today = new \DateTime('today');
        foreach ($uniqueDates as $dateStr) {
            $d = new \DateTime($dateStr);
            $diff = $today->diff($d)->days;
            if ($diff === $streak) $streak++;
            else break;
        }

        // Body measurements delta
        $measArray = iterator_to_array($measurements);
        $bodyWeightChange = null;
        $bodyFatChange = null;
        if (count($measArray) >= 2) {
            $first = $measArray[0];
            $last = $measArray[count($measArray) - 1];
            if (isset($first['weight_kg'], $last['weight_kg'])) {
                $bodyWeightChange = round($last['weight_kg'] - $first['weight_kg'], 2);
            }
            if (isset($first['body_fat_pct'], $last['body_fat_pct'])) {
                $bodyFatChange = round($last['body_fat_pct'] - $first['body_fat_pct'], 2);
            }
        }

        $avgDuration = $workoutCount > 0 ? round(array_sum($durations) / count($durations)) : 0;

        return [
            'completion_rate' => $completionRate,
            'avg_workout_duration_min' => (int) $avgDuration,
            'total_volume_lifted_kg' => round($totalVolume, 1),
            'consistency_score' => (int) $consistencyScore,
            'progression_score' => (int) $progressionScore,
            'pr_count_30d' => $prCount30d,
            'streak_days' => $streak,
            'body_weight_change_kg' => $bodyWeightChange,
            'body_fat_change_pct' => $bodyFatChange,
        ];
    }

    private function computeProgressionScore(array $weeklyVolume): int
    {
        if (count($weeklyVolume) < 4) {
            return 50; // neutral
        }
        ksort($weeklyVolume);
        $values = array_values($weeklyVolume);
        $mid = (int) floor(count($values) / 2);
        $firstHalf = array_slice($values, 0, $mid);
        $secondHalf = array_slice($values, $mid);
        $firstAvg = array_sum($firstHalf) / max(1, count($firstHalf));
        $secondAvg = array_sum($secondHalf) / max(1, count($secondHalf));

        if ($firstAvg <= 0) {
            return 50;
        }
        $pctChange = ($secondAvg - $firstAvg) / $firstAvg;
        // Scale: -0.5 → 0, 0 → 50, +0.5 → 100
        $score = 50 + ($pctChange * 100);
        return (int) max(0, min(100, round($score)));
    }

    private function generateRecommendations(array $metrics, array|object $client): array
    {
        $recommendations = [];
        $completion = $metrics['completion_rate'];
        $progression = $metrics['progression_score'];
        $consistency = $metrics['consistency_score'];
        $streak = $metrics['streak_days'];
        $weightChange = $metrics['body_weight_change_kg'];
        $fatChange = $metrics['body_fat_change_pct'];

        // Determine primary signals
        $highCompliance = $completion >= 0.75 && $consistency >= 60;
        $positiveProgression = $progression >= 60;
        $stalled = $progression >= 40 && $progression <= 60;
        $regressing = $progression < 40;
        $lowCompliance = $completion < 0.5 || $consistency < 40;

        // Base confidence on data quality
        $dataQuality = min(1.0, max(0.3, $metrics['total_volume_lifted_kg'] / 1000));

        // Recommendation 1: primary focus based on compliance + progression
        if ($highCompliance && $positiveProgression) {
            $recommendations[] = [
                'plan_title' => 'Progressive Overload Phase',
                'confidence' => round(min(0.95, 0.7 + $dataQuality * 0.25), 2),
                'reasoning' => "Client is highly compliant (" . (int) ($completion * 100) . "% completion) and showing positive progression (score {$progression}). Ideal time to increase volume or intensity with a hypertrophy or strength-focused block.",
                'focus_area' => 'hypertrophy',
                'suggested_frequency_days_per_week' => min(6, max(4, (int) round($consistency / 15))),
                'suggested_duration_weeks' => 4,
                'notes' => 'Consider adding 1-2 sets per exercise or increasing weight by 2.5-5% on compound lifts.',
            ];
        } elseif ($highCompliance && $stalled) {
            $recommendations[] = [
                'plan_title' => 'Periodization Deload / New Split',
                'confidence' => round(min(0.9, 0.65 + $dataQuality * 0.25), 2),
                'reasoning' => "Great compliance but progression has stalled (score {$progression}). A change in stimulus — new exercise selection, rep ranges, or training split — is recommended to break the plateau.",
                'focus_area' => 'strength',
                'suggested_frequency_days_per_week' => min(5, max(3, (int) round($consistency / 18))),
                'suggested_duration_weeks' => 3,
                'notes' => 'Swap 30-40% of exercises for variations (e.g., front squats instead of back squats). Include a deload week.',
            ];
        } elseif ($lowCompliance) {
            $recommendations[] = [
                'plan_title' => 'Sustainable Re-Entry Program',
                'confidence' => round(min(0.85, 0.6 + $dataQuality * 0.25), 2),
                'reasoning' => "Low compliance (" . (int) ($completion * 100) . "% completion) suggests the current plan is too demanding or not engaging. Reduce frequency and complexity to rebuild the habit.",
                'focus_area' => 'maintenance',
                'suggested_frequency_days_per_week' => max(2, min(3, (int) round($consistency / 20))),
                'suggested_duration_weeks' => 4,
                'notes' => 'Focus on full-body sessions with familiar exercises. Add variety only after 3 weeks of consistent attendance.',
            ];
        } elseif ($regressing) {
            $recommendations[] = [
                'plan_title' => 'Recovery & Rebuild',
                'confidence' => round(min(0.88, 0.62 + $dataQuality * 0.25), 2),
                'reasoning' => "Regression detected (progression score {$progression}). Possible causes: overtraining, life stress, or illness. Reduce volume significantly and prioritize recovery.",
                'focus_area' => 'rehab',
                'suggested_frequency_days_per_week' => max(2, min(3, (int) round($consistency / 20))),
                'suggested_duration_weeks' => 3,
                'notes' => 'Lower working weights to 70% of recent maxes. Increase rest days. Check in on sleep, nutrition, and stress levels.',
            ];
        } else {
            // Neutral / mixed signals
            $recommendations[] = [
                'plan_title' => 'Balanced Hybrid Block',
                'confidence' => round(min(0.8, 0.55 + $dataQuality * 0.25), 2),
                'reasoning' => "Mixed signals — compliance is moderate and progression is neutral. A balanced plan mixing strength and hypertrophy with built-in flexibility is recommended.",
                'focus_area' => 'strength',
                'suggested_frequency_days_per_week' => min(5, max(3, (int) round($consistency / 16))),
                'suggested_duration_weeks' => 4,
                'notes' => 'Keep compound lifts as anchors. Rotate accessories every 2 weeks.',
            ];
        }

        // Recommendation 2: body-composition aligned option (if measurements exist)
        if ($weightChange !== null || $fatChange !== null) {
            $goalSignal = '';
            if ($weightChange !== null && $weightChange < -1) {
                $goalSignal = 'significant weight loss detected';
            } elseif ($weightChange !== null && $weightChange > 1) {
                $goalSignal = 'weight gain detected';
            }
            if ($fatChange !== null && $fatChange < -1) {
                $goalSignal = ($goalSignal ? $goalSignal . ', ' : '') . 'body fat reduction';
            } elseif ($fatChange !== null && $fatChange > 1) {
                $goalSignal = ($goalSignal ? $goalSignal . ', ' : '') . 'body fat increase';
            }

            if ($goalSignal) {
                $focus = ($weightChange !== null && $weightChange < -1) || ($fatChange !== null && $fatChange < -1)
                    ? 'endurance'
                    : 'hypertrophy';
                $recommendations[] = [
                    'plan_title' => 'Body Composition Focus Block',
                    'confidence' => round(min(0.82, 0.58 + $dataQuality * 0.24), 2),
                    'reasoning' => "Biometric trends show {$goalSignal}. Align training focus to support these body-composition changes.",
                    'focus_area' => $focus,
                    'suggested_frequency_days_per_week' => min(5, max(3, (int) round($consistency / 16))),
                    'suggested_duration_weeks' => 6,
                    'notes' => 'Coordinate with nutrition plan. Ensure protein intake matches training volume.',
                ];
            }
        }

        // Recommendation 3: streak/motivation option
        if ($streak >= 7) {
            $recommendations[] = [
                'plan_title' => 'Momentum Builder',
                'confidence' => round(min(0.78, 0.55 + $dataQuality * 0.22), 2),
                'reasoning' => "Client is on a {$streak}-day streak. Capitalize on momentum with a challenging but rewarding specialty block.",
                'focus_area' => 'strength',
                'suggested_frequency_days_per_week' => min(6, max(4, (int) round($consistency / 14))),
                'suggested_duration_weeks' => 4,
                'notes' => 'Introduce a benchmark workout at start and end to quantify improvement.',
            ];
        } elseif ($streak === 0 && $completion < 0.3) {
            $recommendations[] = [
                'plan_title' => 'Habit Reset Mini-Block',
                'confidence' => round(min(0.75, 0.5 + $dataQuality * 0.25), 2),
                'reasoning' => "No current streak and very low completion. The priority is rebuilding the exercise habit, not performance.",
                'focus_area' => 'maintenance',
                'suggested_frequency_days_per_week' => 2,
                'suggested_duration_weeks' => 3,
                'notes' => 'Set micro-goals (e.g., "complete 2 sessions this week"). Celebrate each completion.',
            ];
        }

        // Sort by confidence descending
        usort($recommendations, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($recommendations, 0, 3);
    }
}
