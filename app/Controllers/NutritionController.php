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
        if ($clientId) $filter['client_id'] = new ObjectId($clientId);

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

    // GET /client/nutrition-plan  (client)
    public function clientPlan(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $plan     = Database::collection('nutrition_plans')->findOne(
            ['client_id' => $clientId],
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
        $data = [
            'id'           => (string) $doc['_id'],
            'client_id'    => (string) $doc['client_id'],
            'title'        => $doc['title'],
            'week_start'   => $doc['week_start'] ? date('Y-m-d', (int)((string)$doc['week_start']) / 1000) : null,
            'daily_totals' => $doc['daily_totals'] ?? [],
            'notes'        => $doc['notes'] ?? null,
            'created_at'   => (string) ($doc['created_at'] ?? ''),
        ];
        if ($full) $data['days'] = $doc['days'] ?? [];
        return $data;
    }
}
