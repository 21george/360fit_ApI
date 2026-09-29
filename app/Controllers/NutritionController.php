<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response, ScopedCollection};
use MongoDB\BSON\ObjectId;

class NutritionController
{
    private const ALLOWED_STATUSES = ['draft', 'active', 'completed'];

    // GET /nutrition-plans  (coach - list all)
    public function index(array $params): void
    {
        $clientId = Request::get('client_id');
        $filter   = [];
        if ($clientId) {
            $oid = new ObjectId($clientId);
            $filter['$or'] = [
                ['client_id' => $oid],
                ['client_ids' => $oid],
            ];
        }

        // Status filter (active, draft, completed)
        $status = Request::get('status');
        if (is_string($status) && $status !== '') {
            $filter['status'] = $status;
        }

        // Plan type filter (individual, group, team)
        $planType = Request::get('plan_type');
        if (is_string($planType) && $planType !== '') {
            $filter['plan_type'] = $planType;
        }

        // RLS: coach role scopes by coach_id; client role by client_id/client_ids
        $plans = ScopedCollection::for('nutrition_plans')->find($filter, [
            'sort'       => ['created_at' => -1],
            'projection' => ['days' => 0],
        ])->toArray();

        Response::success(array_map(fn($p) => $this->format($p), $plans));
    }

    // POST /nutrition-plans
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, [
            'client_id'  => 'required',
            'title'      => 'required',
            'week_start' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        // Validate every client_id belongs to this coach (prevents cross-coach data pollution)
        $clientIdsRaw = $body['client_ids'] ?? [$body['client_id']];
        $clientObjects = [];
        foreach ($clientIdsRaw as $id) {
            try {
                $clientObjId = $id instanceof ObjectId ? $id : new ObjectId((string) $id);
            } catch (\Throwable) {
                Response::error('Invalid client ID: ' . (string) $id, 400);
            }
            // RLS: coach role scopes clients by coach_id
            $client = ScopedCollection::for('clients')->findOne([
                '_id'    => $clientObjId,
                'active' => true,
            ]);
            if (!$client) {
                Response::error('Client not found: ' . (string) $id, 404);
            }
            $clientObjects[] = $client;
        }
        $clientId    = $clientObjects[0]['_id'];
        $clientIdArr = array_map(fn($c) => $c['_id'], $clientObjects);

        $days = $body['days'] ?? [];
        if (is_array($days)) {
            $this->validateDaysStructure($days);
        }

        $dailyTotals = $body['daily_totals'] ?? null;
        if ($dailyTotals === null && is_array($days) && count($days) > 0) {
            $dailyTotals = $this->calculateDailyTotals($days);
        }

        // RLS: auto-sets coach_id for coach role
        $result = ScopedCollection::for('nutrition_plans')->insertOne([
            'coach_id'     => $coachId,
            'client_id'    => $clientId,
            'client_ids'   => $clientIdArr,
            'plan_type'    => $body['plan_type'] ?? 'individual',
            'group_name'   => $body['group_name'] ?? null,
            'title'        => $body['title'],
            'week_start'   => new \MongoDB\BSON\UTCDateTime(strtotime($body['week_start']) * 1000),
            'daily_totals' => $dailyTotals ?? ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0],
            'days'         => $days,
            'notes'        => $body['notes'] ?? null,
            'status'       => $body['status'] ?? 'draft',
            'created_at'   => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'   => new \MongoDB\BSON\UTCDateTime(),
        ]);

        $planId  = (string) $result->getInsertedId();
        $planTitle = $body['title'];

        // Notify assigned clients (in-app + push)
        $triggerService = new NotificationTriggerService();
        foreach ($clientObjects as $c) {
            try {
                $triggerService->notifyClientNewPlan($planTitle, $planId, (string) $c['_id'], 'nutrition');
            } catch (\Throwable $e) {
                error_log('Failed to send new nutrition plan notification to client ' . (string) $c['_id'] . ': ' . $e->getMessage());
            }
        }

        foreach ($clientObjects as $c) {
            \App\Services\ActivityLogService::log(
                'nutrition_plan_created',
                (string) $c['_id'],
                $c['name'] ?? null,
                ['plan_id' => $planId, 'title' => $planTitle]
            );
        }

        Response::success(['id' => $planId], 'Nutrition plan created', 201);
    }

    // GET /nutrition-plans/:id
    public function show(array $params): void
    {
        $plan = $this->findPlan($params);
        Response::success($this->format($plan, true));
    }

    // PUT /nutrition-plans/:id
    public function update(array $params): void
    {
        $plan    = $this->findPlan($params);
        $body    = Request::body();
        $allowed = ['title', 'daily_totals', 'days', 'notes', 'week_start', 'status', 'plan_type', 'group_name'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) continue;
            if ($field === 'week_start') {
                $set[$field] = new \MongoDB\BSON\UTCDateTime(strtotime($body[$field]) * 1000);
            } elseif ($field === 'days') {
                $days = $body[$field];
                if (is_array($days)) {
                    $this->validateDaysStructure($days);
                }
                $set[$field] = $days;

                // Re-calculate daily_totals if not explicitly provided alongside days
                if (!array_key_exists('daily_totals', $body) && is_array($days) && count($days) > 0) {
                    $set['daily_totals'] = $this->calculateDailyTotals($days);
                }
            } elseif ($field === 'status') {
                if (!in_array($body[$field], self::ALLOWED_STATUSES, true)) {
                    Response::error('Invalid status', 422);
                }
                $set[$field] = $body[$field];
            } else {
                $set[$field] = $body[$field];
            }
        }

        ScopedCollection::for('nutrition_plans')->updateOne(['_id' => $plan['_id']], ['$set' => $set]);

        // Fetch the updated plan to ensure notifications use the latest data
        $updatedPlan = ScopedCollection::for('nutrition_plans')->findOne(['_id' => $plan['_id']]);

        // Notify assigned client(s) that the plan was updated
        $triggerService = new NotificationTriggerService();
        $planTitle      = $updatedPlan['title'] ?? 'Your plan';
        $planIdStr      = (string) $plan['_id'];

        if (!empty($updatedPlan['client_id'])) {
            try {
                $triggerService->notifyClientPlanUpdated($planTitle, $planIdStr, (string) $updatedPlan['client_id'], 'nutrition');
            } catch (\Throwable $e) {
                error_log('Failed to send nutrition-plan-updated notification: ' . $e->getMessage());
            }
        }

        if (!empty($updatedPlan['client_ids'])) {
            foreach ($updatedPlan['client_ids'] as $cid) {
                try {
                    $triggerService->notifyClientPlanUpdated($planTitle, $planIdStr, (string) $cid, 'nutrition');
                } catch (\Throwable $e) {
                    error_log('Failed to send nutrition-plan-updated notification to client ' . (string) $cid . ': ' . $e->getMessage());
                }
            }
        }

        Response::success(null, 'Nutrition plan updated');
    }

    // DELETE /nutrition-plans/:id
    public function destroy(array $params): void
    {
        $plan = $this->findPlan($params);
        ScopedCollection::for('nutrition_plans')->deleteOne(['_id' => $plan['_id']]);
        Response::success(null, 'Nutrition plan deleted');
    }

    // POST /nutrition-plans/:id/assign
    public function assign(array $params): void
    {
        try {
            $planId = new ObjectId($params['id']);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            Response::error('Invalid plan ID', 400);
            return;
        }

        $body = Request::body();

        // RLS: coach role scopes by coach_id
        $plan = ScopedCollection::for('nutrition_plans')->findOne(['_id' => $planId]);
        if (!$plan) {
            Response::error('Plan not found or access denied', 404);
            return;
        }

        $clientIds = $body['client_ids'] ?? ($body['client_id'] ?? null);
        if (empty($clientIds)) {
            Response::error('client_ids is required', 422);
            return;
        }
        if (!is_array($clientIds)) {
            $clientIds = [$clientIds];
        }

        $clientObjects = [];
        foreach ($clientIds as $id) {
            try {
                $clientObjId = $id instanceof ObjectId ? $id : new ObjectId((string) $id);
            } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                Response::error('Invalid client ID: ' . (string) $id, 400);
                return;
            }
            // RLS: coach role scopes clients by coach_id
            $client = ScopedCollection::for('clients')->findOne([
                '_id'      => $clientObjId,
                'active'   => true,
            ]);
            if (!$client) {
                Response::error('Client not found: ' . (string) $id, 404);
                return;
            }
            $clientObjects[] = $client;
        }

        $isGroup = count($clientObjects) > 1;
        $update  = [
            'plan_type'  => $isGroup ? 'group' : 'individual',
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        if ($isGroup) {
            $update['client_ids'] = array_map(fn($c) => $c['_id'], $clientObjects);
            $update['client_id']  = null;
        } else {
            $update['client_id']  = $clientObjects[0]['_id'];
            $update['client_ids'] = [];
        }

        ScopedCollection::for('nutrition_plans')->updateOne(
            ['_id' => $planId],
            ['$set' => $update]
        );

        // Notify all assigned clients (in-app + push)
        $triggerService = new NotificationTriggerService();
        $planTitle = $plan['title'] ?? 'Your plan';
        foreach ($clientObjects as $client) {
            try {
                $triggerService->notifyClientNewPlan($planTitle, (string) $planId, (string) $client['_id'], 'nutrition');
            } catch (\Throwable $e) {
                error_log('Failed to send nutrition assignment notification to client ' . (string) $client['_id'] . ': ' . $e->getMessage());
            }
        }

        foreach ($clientObjects as $client) {
            \App\Services\ActivityLogService::log(
                'nutrition_plan_assigned',
                (string) $client['_id'],
                $client['name'] ?? null,
                ['plan_id' => (string) $planId, 'title' => $plan['title'] ?? null]
            );
        }

        Response::success([
            'client_ids' => array_map(fn($c) => (string) $c['_id'], $clientObjects),
            'plan_type'  => $isGroup ? 'group' : 'individual',
        ], 'Nutrition plan assigned to ' . count($clientObjects) . ' client(s)');
    }

    // GET /client/nutrition-plan  (client)
    public function clientPlan(array $params): void
    {
        // RLS: client role automatically scopes by client_id/client_ids
        $plan = ScopedCollection::for('nutrition_plans')->findOne(
            [],
            ['sort' => ['week_start' => -1]]
        );
        if (!$plan) Response::error('No nutrition plan assigned', 404);
        Response::success($this->format($plan, true));
    }

    private function findPlan(array $params): object
    {
        $planId = new ObjectId($params['id']);
        // RLS: coach role scopes by coach_id; client role by client_id/client_ids
        $plan = ScopedCollection::for('nutrition_plans')->findOne(['_id' => $planId]);
        if (!$plan) Response::error('Plan not found', 404);
        return $plan;
    }

    /**
     * Validate the structure of the days array.
     * Each day must contain meals, each meal must contain foods with basic fields.
     */
    private function validateDaysStructure(array $days): void
    {
        foreach ($days as $dayIndex => $day) {
            if (!is_array($day)) {
                Response::error("Invalid days structure at index {$dayIndex}", 422);
            }
            if (isset($day['meals']) && is_array($day['meals'])) {
                foreach ($day['meals'] as $mealIndex => $meal) {
                    if (!is_array($meal)) {
                        Response::error("Invalid meal structure at day {$dayIndex}, meal {$mealIndex}", 422);
                    }
                    if (isset($meal['foods']) && is_array($meal['foods'])) {
                        foreach ($meal['foods'] as $foodIndex => $food) {
                            if (!is_array($food)) {
                                Response::error("Invalid food structure at day {$dayIndex}, meal {$mealIndex}, food {$foodIndex}", 422);
                            }
                            if (!isset($food['name']) || !is_string($food['name'])) {
                                Response::error("Food name is required at day {$dayIndex}, meal {$mealIndex}, food {$foodIndex}", 422);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Calculate average daily totals from the structured days array.
     * Sums all day_totals (or calculates from foods) and averages by number of days.
     */
    private function calculateDailyTotals(array $days): array
    {
        $totals = ['calories' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
        $dayCount = count($days);
        if ($dayCount === 0) {
            return $totals;
        }

        foreach ($days as $day) {
            $dayTotals = $day['day_totals'] ?? null;
            if ($dayTotals === null && isset($day['meals']) && is_array($day['meals'])) {
                // Calculate from foods if day_totals not provided
                $dayTotals = ['calories' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
                foreach ($day['meals'] as $meal) {
                    if (!isset($meal['foods']) || !is_array($meal['foods'])) continue;
                    foreach ($meal['foods'] as $food) {
                        $calculated = $food['calculated_nutrients'] ?? [];
                        $dayTotals['calories']  += (float) ($calculated['calories'] ?? 0);
                        $dayTotals['protein_g'] += (float) ($calculated['protein_g'] ?? 0);
                        $dayTotals['carbs_g']   += (float) ($calculated['carbs_g'] ?? 0);
                        $dayTotals['fat_g']     += (float) ($calculated['fat_g'] ?? 0);
                    }
                }
            }
            if (is_array($dayTotals)) {
                $totals['calories']  += (float) ($dayTotals['calories'] ?? 0);
                $totals['protein_g'] += (float) ($dayTotals['protein_g'] ?? 0);
                $totals['carbs_g']   += (float) ($dayTotals['carbs_g'] ?? 0);
                $totals['fat_g']     += (float) ($dayTotals['fat_g'] ?? 0);
            }
        }

        // Average across days
        return [
            'calories'  => round($totals['calories'] / $dayCount, 1),
            'protein_g' => round($totals['protein_g'] / $dayCount, 1),
            'carbs_g'   => round($totals['carbs_g'] / $dayCount, 1),
            'fat_g'     => round($totals['fat_g'] / $dayCount, 1),
        ];
    }

    private function format(object $doc, bool $full = false): array
    {
        $data = (array) $doc;
        $result = [
            'id'           => (string) $data['_id'],
            'client_id'    => !empty($data['client_id']) ? (string) $data['client_id'] : null,
            'client_ids'   => array_map(fn($id) => (string) $id, (array) ($data['client_ids'] ?? [])),
            'plan_type'    => $data['plan_type'] ?? 'individual',
            'group_name'   => $data['group_name'] ?? null,
            'title'        => $data['title'],
            'week_start'   => $data['week_start'] ? date('Y-m-d', (int)(((string)$data['week_start']) / 1000)) : null,
            'daily_totals' => $data['daily_totals'] ?? [],
            'status'       => $data['status'] ?? 'draft',
            'notes'        => $data['notes'] ?? null,
            'created_at'   => (string) ($data['created_at'] ?? ''),
            'updated_at'   => (string) ($data['updated_at'] ?? ''),
        ];
        if ($full) $result['days'] = $data['days'] ?? [];
        return $result;
    }
}
