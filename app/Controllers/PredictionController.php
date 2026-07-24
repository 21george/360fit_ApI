<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\{ObjectId, UTCDateTime};

/**
 * 2-month progress prediction.
 *
 * Combines:
 *  - Body metrics: weight, height, body_fat_pct, age (from client profile)
 *  - Workout adherence: completions over the last 4 weeks
 *  - Meal adherence: completions over the last 4 weeks
 *  - Wearable activity: average daily steps over the last 4 weeks
 *
 * Outputs:
 *  - Predicted weight change (kg) over 60 days
 *  - Predicted body fat change (%) over 60 days
 *  - Confidence label: high / medium / low (based on data completeness)
 *
 * NOTE: This is a heuristic model — not a certified medical prediction.
 * Accuracy is tracked per-cohort via stored predictions vs. actual outcomes.
 */
class PredictionController
{
    /**
     * GET /coach/clients/:id/prediction
     * GET /client/prediction        (client self-view, :id implicit from JWT)
     */
    public function show(array $params): void
    {
        // Support both coach view (/coach/clients/:id/prediction)
        // and client self-view (/client/prediction)
        $isClient = ($params['_auth']['role'] ?? '') === 'client';
        $clientId = $isClient
            ? new ObjectId($params['_auth']['sub'])
            : new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::error('Client not found', 404);

        $prediction = $this->compute((array) $client, $clientId);

        // Persist for accuracy tracking (upsert by client + computed_at month)
        $monthKey = date('Y-m');
        Database::collection('predictions')->updateOne(
            ['client_id' => $clientId, 'month_key' => $monthKey],
            ['$set' => array_merge($prediction, [
                'client_id'   => $clientId,
                'month_key'   => $monthKey,
                'computed_at' => new UTCDateTime(),
            ])],
            ['upsert' => true]
        );

        Response::success($prediction);
    }

    // ─── Core computation ────────────────────────────────────────────────────────

    private function compute(array $client, ObjectId $clientId): array
    {
        $weightKg   = (float) ($client['current_weight_kg'] ?? 0);
        $heightCm   = (float) ($client['height_cm']         ?? 0);
        $bodyFatPct = (float) ($client['body_fat_pct']      ?? 0);
        $dob        = $client['date_of_birth'] ?? null;
        $age        = $dob ? (int) ((time() - strtotime($dob)) / 31557600) : 30;

        // ── Adherence (last 28 days) ────────────────────────────────────────────
        $since = new UTCDateTime((time() - 28 * 86400) * 1000);

        $workoutCount = (int) Database::collection('workout_logs')->countDocuments([
            'client_id'    => $clientId,
            'completed_at' => ['$gte' => $since],
        ]);

        $mealCount = (int) Database::collection('meal_logs')->countDocuments([
            'client_id'    => $clientId,
            'completed_at' => ['$gte' => $since],
        ]);

        // ── Wearable (last 28 days avg steps) ──────────────────────────────────
        $wearable = Database::collection('wearable_data')->find([
            'client_id' => $clientId,
            'date'      => ['$gte' => date('Y-m-d', strtotime('-28 days'))],
        ])->toArray();
        $avgSteps = 0.0;
        if (!empty($wearable)) {
            $avgSteps = array_sum(array_map(fn($d) => (int)($d['steps'] ?? 0), $wearable)) / count($wearable);
        }

        // ── Data quality → confidence ────────────────────────────────────────────
        $dataPoints = 0;
        if ($weightKg > 0)   $dataPoints++;
        if ($heightCm > 0)   $dataPoints++;
        if ($bodyFatPct > 0) $dataPoints++;
        if ($workoutCount > 0) $dataPoints++;
        if ($mealCount > 0)  $dataPoints++;
        if ($avgSteps > 0)   $dataPoints++;

        $confidence = match(true) {
            $dataPoints >= 5 => 'high',
            $dataPoints >= 3 => 'medium',
            default          => 'low',
        };

        // ── Heuristic model ─────────────────────────────────────────────────────
        // Weekly workout sessions (capped 0–7)
        $weeklyWorkouts = min(7, round($workoutCount / 4, 1));
        // Weekly meal completions (capped 0–35, i.e. 5 meals/day)
        $weeklyMeals    = min(35, round($mealCount / 4, 1));

        // Activity score 0–1 from steps (10 000 steps = full score)
        $activityScore = min(1.0, $avgSteps / 10000);

        // Caloric deficit proxy: each workout session ≈ 300 kcal deficit equivalent
        // Each 1 000 steps beyond sedentary ≈ 40 kcal
        $weeklyCalEquiv = ($weeklyWorkouts * 300) + (max(0, $avgSteps - 2000) / 1000 * 40);
        // 7 700 kcal ≈ 1 kg of fat; over 8 weeks:
        $weightChangePredKg = $weightKg > 0
            ? round(-(($weeklyCalEquiv * 8) / 7700), 2)
            : 0.0;

        // Body fat prediction: losing ~0.5% per 4 effective workout sessions/week
        $bodyFatChangePct = ($weeklyWorkouts >= 3 && $mealCount > 0)
            ? round(-(($weeklyWorkouts / 4) * 0.25 * 2), 2) // 2-month horizon
            : 0.0;

        // Apply adherence dampening: if meal completion is very low, gains are smaller
        $mealAdherenceRatio = $weeklyMeals > 0 ? min(1.0, $weeklyMeals / 21) : 0.5;
        $weightChangePredKg = round($weightChangePredKg * $mealAdherenceRatio, 2);
        $bodyFatChangePct   = round($bodyFatChangePct   * $mealAdherenceRatio, 2);

        $predictedWeightKg   = $weightKg   > 0 ? round($weightKg   + $weightChangePredKg, 1) : null;
        $predictedBodyFatPct = $bodyFatPct > 0 ? round(max(0, $bodyFatPct + $bodyFatChangePct), 1) : null;

        return [
            'horizon_days'            => 60,
            'confidence'              => $confidence,
            'current_weight_kg'       => $weightKg   > 0 ? $weightKg   : null,
            'current_body_fat_pct'    => $bodyFatPct > 0 ? $bodyFatPct : null,
            'predicted_weight_kg'     => $predictedWeightKg,
            'predicted_body_fat_pct'  => $predictedBodyFatPct,
            'weight_change_kg'        => $weightChangePredKg,
            'body_fat_change_pct'     => $bodyFatChangePct,
            'inputs' => [
                'weekly_workouts'      => $weeklyWorkouts,
                'weekly_meal_logs'     => $weeklyMeals,
                'avg_daily_steps'      => round($avgSteps),
                'activity_score'       => round($activityScore, 2),
            ],
            'disclaimer' => 'This is a heuristic estimate based on recent adherence data and body metrics. It is not a medical prediction.',
        ];
    }
}
