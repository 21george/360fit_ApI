<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Scoring logic for the sports-science assessment types (Feature 7).
 * Returns a normalised result array: ['computed_value' => ?float, 'composite_score' => ?float, 'flagged' => ?bool]
 */
class AssessmentScoringService
{
    private const FMS_TESTS = [
        'deep_squat', 'hurdle_step', 'in_line_lunge', 'shoulder_mobility',
        'active_straight_leg_raise', 'trunk_stability_pushup', 'rotary_stability',
    ];

    public static function score(string $type, array $inputs, ?string $methodDetail = null): array
    {
        return match ($type) {
            'fms'             => self::scoreFms($inputs),
            'one_rep_max'     => self::scoreOneRepMax($inputs, $methodDetail),
            'vo2max_cooper'   => self::scoreVo2MaxCooper($inputs),
            'body_composition' => self::scoreBodyComposition($inputs),
            default           => ['computed_value' => null, 'composite_score' => null, 'flagged' => null],
        };
    }

    private static function scoreFms(array $inputs): array
    {
        $total = 0;
        foreach (self::FMS_TESTS as $test) {
            $raw = $inputs[$test] ?? 0;
            $score = is_numeric($raw) ? (int) $raw : 0;
            $total += max(0, min(3, $score));
        }

        return [
            'computed_value'  => null,
            'composite_score' => $total,
            // FMS composite score of 14 or below is associated with elevated injury risk
            'flagged'         => $total <= 14,
        ];
    }

    private static function scoreOneRepMax(array $inputs, ?string $methodDetail): array
    {
        if ($methodDetail === 'estimated_formula') {
            $weight = (float) ($inputs['weight'] ?? 0);
            $reps   = (float) ($inputs['reps'] ?? 0);
            // Epley formula
            $value = $reps > 0 ? $weight * (1 + $reps / 30) : $weight;
        } else {
            $value = (float) ($inputs['value'] ?? 0);
        }

        return [
            'computed_value'  => round($value, 2),
            'composite_score' => null,
            'flagged'         => null,
        ];
    }

    private static function scoreVo2MaxCooper(array $inputs): array
    {
        $distanceM = (float) ($inputs['distance_m'] ?? 0);
        // Cooper 12-minute run test formula
        $vo2max = ($distanceM - 504.9) / 44.73;

        return [
            'computed_value'  => round($vo2max, 1),
            'composite_score' => null,
            'flagged'         => null,
        ];
    }

    private static function scoreBodyComposition(array $inputs): array
    {
        // Body composition is recorded as raw measurements (method varies: calipers,
        // BIA, DEXA); no single composite score is computed here.
        return [
            'computed_value'  => isset($inputs['body_fat_pct']) ? (float) $inputs['body_fat_pct'] : null,
            'composite_score' => null,
            'flagged'         => null,
        ];
    }
    
}
