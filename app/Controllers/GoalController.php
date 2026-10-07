<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Client Goals & Milestones Controller
 *
 * Coaches create goals for clients. current_value auto-updates from
 * body_measurements and wearable data when the metric matches.
 */
class GoalController
{
    private const ALLOWED_UNITS = ['kg', 'lbs', '%', 'cm', 'in', 'min', 'hrs', 'sessions', 'steps', 'reps'];

    /**
     * POST /clients/:id/goals
     * Body: { title, target_value, unit, deadline? }
     */
    public function store(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $body   = Request::body();
        $errors = Request::validate($body, ['title' => 'required', 'target_value' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $unit = strtolower(trim((string) ($body['unit'] ?? 'kg')));
        if (!in_array($unit, self::ALLOWED_UNITS, true)) {
            Response::error('Invalid unit', 422);
        }

        $deadline = null;
        if (!empty($body['deadline'])) {
            $ts = strtotime($body['deadline']);
            if ($ts !== false) $deadline = new UTCDateTime($ts * 1000);
        }

        $currentValue = $this->inferCurrentValue($clientId, $unit, $body['title'] ?? '');

        $doc = [
            'client_id'     => $clientId,
            'coach_id'      => $coachId,
            'title'         => trim((string) $body['title']),
            'target_value'  => (float) $body['target_value'],
            'current_value' => $currentValue,
            'unit'          => $unit,
            'deadline'      => $deadline,
            'status'        => 'active',
            'created_at'    => new UTCDateTime(),
            'updated_at'    => new UTCDateTime(),
        ];

        $insert = Database::collection('goals')->insertOne($doc);
        Response::success(['id' => (string) $insert->getInsertedId()] + $doc, 'Goal created', 201);
    }

    /**
     * GET /clients/:id/goals
     */
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $goals = Database::collection('goals')
            ->find(['client_id' => $clientId], ['sort' => ['created_at' => -1]])
            ->toArray();

        Response::success(array_map(fn($g) => [
            'id'            => (string) $g['_id'],
            'title'         => $g['title'] ?? '',
            'target_value'  => $g['target_value'] ?? 0,
            'current_value' => $g['current_value'] ?? 0,
            'unit'          => $g['unit'] ?? '',
            'deadline'      => isset($g['deadline']) ? (string) $g['deadline'] : null,
            'status'        => $g['status'] ?? 'active',
            'progress_pct'  => $this->progressPercent($g),
        ], $goals));
    }

    /**
     * PUT /clients/:id/goals/:goalId
     */
    public function update(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $goalId  = new ObjectId($params['goalId']);

        $goal = Database::collection('goals')->findOne([
            '_id'      => $goalId,
            'coach_id' => $coachId,
        ]);
        if (!$goal) Response::error('Goal not found', 404);

        $body   = Request::body();
        $update = ['updated_at' => new UTCDateTime()];

        if (isset($body['title']))       $update['title']       = trim((string) $body['title']);
        if (isset($body['target_value'])) $update['target_value'] = (float) $body['target_value'];
        if (isset($body['current_value'])) $update['current_value'] = (float) $body['current_value'];
        if (isset($body['unit']))          $update['unit']          = strtolower(trim((string) $body['unit']));
        if (isset($body['status']))        $update['status']        = strtolower(trim((string) $body['status']));
        if (isset($body['deadline'])) {
            $ts = strtotime($body['deadline']);
            $update['deadline'] = $ts !== false ? new UTCDateTime($ts * 1000) : null;
        }

        Database::collection('goals')->updateOne(['_id' => $goalId], ['$set' => $update]);
        Response::success(null, 'Goal updated');
    }

    /**
     * DELETE /clients/:id/goals/:goalId
     */
    public function destroy(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $goalId  = new ObjectId($params['goalId']);

        $goal = Database::collection('goals')->findOne([
            '_id'      => $goalId,
            'coach_id' => $coachId,
        ]);
        if (!$goal) Response::error('Goal not found', 404);

        Database::collection('goals')->deleteOne(['_id' => $goalId]);
        Response::success(null, 'Goal deleted');
    }

    /**
     * GET /client/goals
     * Client view of their own goals.
     */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $goals = Database::collection('goals')
            ->find(['client_id' => $clientId], ['sort' => ['created_at' => -1]])
            ->toArray();

        Response::success(array_map(fn($g) => [
            'id'            => (string) $g['_id'],
            'title'         => $g['title'] ?? '',
            'target_value'  => $g['target_value'] ?? 0,
            'current_value' => $g['current_value'] ?? 0,
            'unit'          => $g['unit'] ?? '',
            'deadline'      => isset($g['deadline']) ? (string) $g['deadline'] : null,
            'status'        => $g['status'] ?? 'active',
            'progress_pct'  => $this->progressPercent($g),
        ], $goals));
    }

    private function inferCurrentValue(ObjectId $clientId, string $unit, string $title): float
    {
        // Try to infer from latest body measurement
        if (in_array($unit, ['kg', 'lbs'])) {
            $latest = Database::collection('body_measurements')->findOne(
                ['client_id' => $clientId],
                ['sort' => ['measured_at' => -1]]
            );
            if ($latest && isset($latest['weight'])) {
                $weight = (float) $latest['weight'];
                return $unit === 'lbs' ? round($weight * 2.20462, 1) : $weight;
            }
        }
        if (in_array($unit, ['%'])) {
            $latest = Database::collection('body_measurements')->findOne(
                ['client_id' => $clientId],
                ['sort' => ['measured_at' => -1]]
            );
            if ($latest && isset($latest['body_fat'])) {
                return (float) $latest['body_fat'];
            }
        }
        if (in_array($unit, ['cm', 'in'])) {
            $latest = Database::collection('body_measurements')->findOne(
                ['client_id' => $clientId],
                ['sort' => ['measured_at' => -1]]
            );
            if ($latest) {
                $key = str_contains(strtolower($title), 'waist') ? 'waist' : null;
                if (!$key) $key = str_contains(strtolower($title), 'hip') ? 'hips' : null;
                if (!$key) $key = str_contains(strtolower($title), 'chest') ? 'chest' : null;
                if (!$key) $key = str_contains(strtolower($title), 'arm') ? 'arms' : null;
                if ($key && isset($latest[$key])) {
                    $val = (float) $latest[$key];
                    return $unit === 'in' ? round($val / 2.54, 1) : $val;
                }
            }
        }
        return 0.0;
    }

    private function progressPercent(array $goal): float
    {
        $target = (float) ($goal['target_value'] ?? 0);
        $current = (float) ($goal['current_value'] ?? 0);
        if ($target == 0) return 0.0;
        return min(100, round(($current / $target) * 100, 1));
    }
}
