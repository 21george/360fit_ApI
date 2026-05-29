<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;

class NutritionController
{
    // GET /nutrition-plans  (coach - list all)
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = Request::get('client_id');
        $filter   = ['coach_id' => $coachId];
        if ($clientId) {
            $oid = new ObjectId($clientId);
            $filter['$or'] = [
                ['client_id' => $oid],
                ['client_ids' => $oid],
            ];
        }

        $plans = Database::collection('nutrition_plans')->find($filter, [
            'sort'       => ['created_at' => -1],
            'projection' => ['days' => 0],
        ])->toArray();

        Response::success(array_map(fn($p) => $this->format($p), $plans));
    }

    // POST /nutrition-plans
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, [
            'client_id'  => 'required',
            'title'      => 'required',
            'week_start' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $clientId = new ObjectId($body['client_id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        $result = Database::collection('nutrition_plans')->insertOne([
            'coach_id'     => $coachId,
            'client_id'    => $clientId,
            'title'        => $body['title'],
            'week_start'   => new \MongoDB\BSON\UTCDateTime(strtotime($body['week_start']) * 1000),
            'daily_totals' => $body['daily_totals'] ?? ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0],
            'days'         => $body['days'] ?? [],
            'notes'        => $body['notes'] ?? null,
            'created_at'   => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'   => new \MongoDB\BSON\UTCDateTime(),
        ]);

        Response::success(['id' => (string) $result->getInsertedId()], 'Nutrition plan created', 201);
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
        $allowed = ['title', 'daily_totals', 'days', 'notes', 'week_start'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (isset($body[$field])) {
                $set[$field] = $field === 'week_start'
                    ? new \MongoDB\BSON\UTCDateTime(strtotime($body[$field]) * 1000)
                    : $body[$field];
            }
        }

        Database::collection('nutrition_plans')->updateOne(['_id' => $plan['_id']], ['$set' => $set]);
        Response::success(null, 'Nutrition plan updated');
    }

    // DELETE /nutrition-plans/:id
    public function destroy(array $params): void
    {
        $this->findPlan($params); // verifies ownership, returns 404 if not found
        Database::collection('nutrition_plans')->deleteOne([
            '_id'      => new ObjectId($params['id']),
            'coach_id' => new ObjectId($params['_auth']['sub']),
        ]);
        Response::success(null, 'Nutrition plan deleted');
    }

    // POST /nutrition-plans/:id/assign
    public function assign(array $params): void
    {
        try {
            $coachId = new ObjectId($params['_auth']['sub']);
            $planId  = new ObjectId($params['id']);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            Response::error('Invalid plan ID', 400);
            return;
        }

        $body = Request::body();

        $plan = Database::collection('nutrition_plans')->findOne([
            '_id'      => $planId,
            'coach_id' => $coachId,
        ]);
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
                $clientId = $id instanceof ObjectId ? $id : new ObjectId((string) $id);
            } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                Response::error('Invalid client ID: ' . (string) $id, 400);
                return;
            }

            $client = Database::collection('clients')->findOne([
                '_id'      => $clientId,
                'coach_id' => $coachId,
            ]);
            if (!$client) {
                Response::error('Client not found: ' . (string) $id, 404);
                return;
            }
            $clientObjects[] = $client;
        }

        $isGroup = count($clientIds) > 1;
        $update = [
            'plan_type'  => $isGroup ? 'group' : 'individual',
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        if ($isGroup) {
            $update['client_ids'] = array_map(fn($c) => $c['_id'], $clientObjects);
            $update['client_id'] = null;
        } else {
            $update['client_id'] = $clientObjects[0]['_id'];
            $update['client_ids'] = [];
        }

        Database::collection('nutrition_plans')->updateOne(
            ['_id' => $planId],
            ['$set' => $update]
        );

        Response::success([
            'client_ids' => array_map(fn($c) => (string) $c['_id'], $clientObjects),
            'plan_type'  => $isGroup ? 'group' : 'individual',
        ], 'Nutrition plan assigned to ' . count($clientObjects) . ' client(s)');
    }

    // GET /client/nutrition-plan  (client)
    public function clientPlan(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $plan     = Database::collection('nutrition_plans')->findOne(
            [
                '$or' => [
                    ['client_id' => $clientId],
                    ['client_ids' => $clientId],
                ],
            ],
            ['sort' => ['week_start' => -1]]
        );
        if (!$plan) Response::error('No nutrition plan assigned', 404);
        Response::success($this->format($plan, true));
    }

    private function findPlan(array $params): object
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $planId  = new ObjectId($params['id']);
        $plan    = Database::collection('nutrition_plans')->findOne([
            '_id'      => $planId,
            'coach_id' => $coachId,
        ]);
        if (!$plan) Response::error('Plan not found', 404);
        return $plan;
    }

    private function format(object $doc, bool $full = false): array
    {
        $clientIds = [];
        if (!empty($doc['client_ids'])) {
            foreach ($doc['client_ids'] as $cid) {
                $clientIds[] = (string) $cid;
            }
        }

        $assignedClient = null;
        if (!empty($doc['client_id'])) {
            $client = Database::collection('clients')->findOne(
                ['_id' => $doc['client_id']],
                ['projection' => ['name' => 1, 'profile_photo_url' => 1]]
            );
            if ($client) {
                $assignedClient = [
                    'id'                => (string) $client['_id'],
                    'name'              => $client['name'],
                    'profile_photo_url' => $client['profile_photo_url'] ?? null,
                ];
            }
        }

        $data = [
            'id'              => (string) $doc['_id'],
            'plan_type'       => (string) ($doc['plan_type'] ?? 'individual'),
            'client_id'       => isset($doc['client_id']) ? (string) $doc['client_id'] : null,
            'client_ids'      => $clientIds,
            'assigned_client' => $assignedClient,
            'title'           => $doc['title'],
            'week_start'      => $doc['week_start'] ? date('Y-m-d', (int)((string)$doc['week_start']) / 1000) : null,
            'daily_totals'    => $doc['daily_totals'] ?? [],
            'notes'           => $doc['notes'] ?? null,
            'created_at'      => (string) ($doc['created_at'] ?? ''),
        ];
        if ($full) $data['days'] = $doc['days'] ?? [];
        return $data;
    }
}
