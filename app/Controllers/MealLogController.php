<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Meal completion tracking.
 *
 * Clients mark meals as done; coaches see completion status alongside
 * workout completion for a combined adherence view.
 */
class MealLogController
{
    /**
     * POST /client/meal-logs
     *
     * Record a completed meal for the authenticated client.
     * Body: { nutrition_plan_id, day, meal_name }
     * Idempotent: marks the meal done; re-posting the same meal_name/day/plan
     * updates the timestamp but does not create a duplicate.
     */
    public function store(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $errors   = Request::validate($body, [
            'nutrition_plan_id' => 'required',
            'day'               => 'required',
            'meal_name'         => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $planId   = new ObjectId($body['nutrition_plan_id']);
        $day      = strtolower(trim($body['day']));
        $mealName = trim($body['meal_name']);

        // Verify plan belongs to this client
        $plan = Database::collection('nutrition_plans')->findOne([
            '_id' => $planId,
            '$or' => [
                ['client_id'  => $clientId],
                ['client_ids' => $clientId],
            ],
        ]);
        if (!$plan) Response::error('Nutrition plan not found', 404);

        $now = new UTCDateTime();

        Database::collection('meal_logs')->updateOne(
            [
                'client_id'          => $clientId,
                'nutrition_plan_id'  => $planId,
                'day'                => $day,
                'meal_name'          => $mealName,
            ],
            [
                '$set' => [
                    'completed_at' => $now,
                    'updated_at'   => $now,
                ],
                '$setOnInsert' => [
                    'created_at' => $now,
                ],
            ],
            ['upsert' => true]
        );

        Response::success(['completed' => true], 'Meal marked as done', 201);
    }

    /**
     * DELETE /client/meal-logs
     *
     * Unmark a completed meal (undo).
     * Body: { nutrition_plan_id, day, meal_name }
     */
    public function destroy(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $errors   = Request::validate($body, [
            'nutrition_plan_id' => 'required',
            'day'               => 'required',
            'meal_name'         => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        Database::collection('meal_logs')->deleteOne([
            'client_id'         => $clientId,
            'nutrition_plan_id' => new ObjectId($body['nutrition_plan_id']),
            'day'               => strtolower(trim($body['day'])),
            'meal_name'         => trim($body['meal_name']),
        ]);

        Response::success(['completed' => false], 'Meal unmarked');
    }

    /**
     * GET /coach/clients/:id/meal-logs
     *
     * List completed meals for a client (coach view).
     * Query params: nutrition_plan_id (optional), from/to ISO dates (optional).
     */
    public function clientLogs(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $this->assertCoachOwnsClient($coachId, $clientId);

        $filter = ['client_id' => $clientId];

        $planIdRaw = Request::get('nutrition_plan_id');
        if ($planIdRaw) {
            $filter['nutrition_plan_id'] = new ObjectId((string) $planIdRaw);
        }

        $from = Request::get('from');
        $to   = Request::get('to');
        if ($from || $to) {
            $filter['completed_at'] = [];
            if ($from) $filter['completed_at']['$gte'] = new UTCDateTime(strtotime($from) * 1000);
            if ($to)   $filter['completed_at']['$lte'] = new UTCDateTime(strtotime($to)   * 1000);
        }

        $logs = Database::collection('meal_logs')->find(
            $filter,
            ['sort' => ['completed_at' => -1]]
        )->toArray();

        Response::success(array_map(fn($l) => $this->format($l), $logs));
    }

    /**
     * GET /coach/clients/:id/adherence
     *
     * Combined adherence summary: workout_logs + meal_logs for a date range.
     * Query: from (ISO date, default 30 days ago), to (ISO date, default today)
     */
    public function adherence(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId  = new ObjectId($params['id']);
        $this->assertCoachOwnsClient($coachId, $clientId);
        $fromTs    = Request::get('from') ? strtotime(Request::get('from')) : strtotime('-30 days');
        $toTs      = Request::get('to')   ? strtotime(Request::get('to'))   : time();
        $fromBson  = new UTCDateTime($fromTs * 1000);
        $toBson    = new UTCDateTime($toTs   * 1000);

        $workoutLogs = Database::collection('workout_logs')->find([
            'client_id'    => $clientId,
            'completed_at' => ['$gte' => $fromBson, '$lte' => $toBson],
        ], ['sort' => ['completed_at' => 1]])->toArray();

        $mealLogs = Database::collection('meal_logs')->find([
            'client_id'    => $clientId,
            'completed_at' => ['$gte' => $fromBson, '$lte' => $toBson],
        ], ['sort' => ['completed_at' => 1]])->toArray();

        $workoutCount = count($workoutLogs);
        $mealCount    = count($mealLogs);

        Response::success([
            'workout_completions' => array_map(fn($l) => [
                'day'          => $l['day'] ?? '',
                'completed_at' => isset($l['completed_at']) ? (string) $l['completed_at'] : null,
                'plan_id'      => isset($l['workout_plan_id']) ? (string) $l['workout_plan_id'] : null,
            ], $workoutLogs),
            'meal_completions' => array_map(fn($l) => $this->format($l), $mealLogs),
            'summary' => [
                'workouts_completed' => $workoutCount,
                'meals_completed'    => $mealCount,
                'from'               => date('Y-m-d', $fromTs),
                'to'                 => date('Y-m-d', $toTs),
            ],
        ]);
    }

    private function format(object $doc): array
    {
        $d = (array) $doc;
        return [
            'id'                 => isset($d['_id']) ? (string) $d['_id'] : '',
            'nutrition_plan_id'  => isset($d['nutrition_plan_id']) ? (string) $d['nutrition_plan_id'] : null,
            'day'                => $d['day'] ?? '',
            'meal_name'          => $d['meal_name'] ?? '',
            'completed_at'       => isset($d['completed_at']) ? (string) $d['completed_at'] : null,
        ];
    }

    private function assertCoachOwnsClient(ObjectId $coachId, ObjectId $clientId): void
    {
        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);
    }
}
