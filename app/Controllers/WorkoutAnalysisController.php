<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{WorkoutAnalysisService, NotificationTriggerService};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class WorkoutAnalysisController
{
    private WorkoutAnalysisService $analysisService;
    private NotificationTriggerService $notifications;

    public function __construct()
    {
        $this->analysisService = new WorkoutAnalysisService();
        $this->notifications   = new NotificationTriggerService();
    }

    // GET /coach/clients/:id/workout-analyses
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = $params['id'] ?? '';

        if (!preg_match('/^[a-f0-9]{24}$/i', $clientId)) {
            Response::error('Invalid client ID', 400);
            return;
        }

        $client = Database::collection('clients')->findOne([
            '_id'      => new ObjectId($clientId),
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::notFound('Client not found');

        $filter = ['client_id' => new ObjectId($clientId), 'coach_id' => $coachId];
        $status = Request::get('status');
        if (is_string($status) && $status !== '') {
            $filter['status'] = $status;
        }

        $page    = max(1, (int) Request::get('page', 1));
        $perPage = 20;
        $col     = Database::collection('client_workout_analyses');
        $total   = $col->countDocuments($filter);
        $docs    = $col->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['generated_at' => -1],
        ]);

        $items = [];
        foreach ($docs as $doc) {
            $items[] = $this->format($doc);
        }

        Response::paginated($items, $total, $page, $perPage);
    }

    // GET /coach/clients/:id/workout-analyses/:analysisId
    public function show(array $params): void
    {
        $analysis = $this->findAnalysis($params);
        Response::success($this->format($analysis, true));
    }

    // POST /coach/clients/:id/workout-analyses
    public function create(array $params): void
    {
        $coachId  = $params['_auth']['sub'];
        $clientId = $params['id'] ?? '';

        if (!preg_match('/^[a-f0-9]{24}$/i', $clientId)) {
            Response::error('Invalid client ID', 400);
            return;
        }

        $clientObjId = new ObjectId($clientId);
        $coachObjId  = new ObjectId($coachId);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientObjId,
            'coach_id' => $coachObjId,
        ]);
        if (!$client) Response::notFound('Client not found');

        // Prevent duplicate pending analyses
        $existingPending = Database::collection('client_workout_analyses')->findOne([
            'client_id' => $clientObjId,
            'coach_id'  => $coachObjId,
            'status'    => 'pending',
        ]);
        if ($existingPending) {
            Response::error('A pending analysis already exists. Review or reject it before creating a new one.', 409);
            return;
        }

        $analysis = $this->analysisService->analyze($clientId, $coachId);

        $doc = [
            'client_id'                   => $clientObjId,
            'coach_id'                    => $coachObjId,
            'status'                      => 'pending',
            'generated_at'                => new UTCDateTime(strtotime($analysis['generated_at']) * 1000),
            'reviewed_at'                 => null,
            'metrics'                     => $analysis['metrics'],
            'recommendations'             => $analysis['recommendations'],
            'coach_notes'                 => null,
            'selected_recommendation_index'=> null,
            'assigned_plan_id'            => null,
        ];

        $result = Database::collection('client_workout_analyses')->insertOne($doc);
        $analysisId = (string) $result->getInsertedId();

        // Notify coach
        try {
            $this->notifications->notifyCoachAnalysisReady($coachId, $clientId, $client['name'] ?? 'Unknown');
        } catch (\Throwable $e) {
            error_log('Failed to notify coach of new analysis: ' . $e->getMessage());
        }

        Response::success(
            $this->format(array_merge($doc, ['_id' => $result->getInsertedId()]), true),
            'Analysis created',
            201
        );
    }

    // GET /coach/clients/:id/workout-analyses/recommendations
    public function recommendations(array $params): void
    {
        $coachId  = $params['_auth']['sub'];
        $clientId = $params['id'] ?? '';

        if (!preg_match('/^[a-f0-9]{24}$/i', $clientId)) {
            Response::error('Invalid client ID', 400);
            return;
        }

        $clientObjId = new ObjectId($clientId);
        $coachObjId  = new ObjectId($coachId);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientObjId,
            'coach_id' => $coachObjId,
        ]);
        if (!$client) Response::notFound('Client not found');

        $analysis = $this->analysisService->analyze($clientId, $coachId);

        Response::success([
            'client_id'       => $clientId,
            'metrics'         => $analysis['metrics'],
            'recommendations' => $analysis['recommendations'],
        ]);
    }

    // POST /coach/clients/:id/workout-analyses/:analysisId/approve
    public function approve(array $params): void
    {
        $analysis = $this->findAnalysis($params);
        $body     = Request::body();

        if ($analysis['status'] !== 'pending') {
            Response::error('Only pending analyses can be approved', 409);
            return;
        }

        $notes = is_string($body['coach_notes'] ?? null) ? $body['coach_notes'] : null;

        Database::collection('client_workout_analyses')->updateOne(
            ['_id' => $analysis['_id']],
            ['$set' => [
                'status'      => 'approved',
                'reviewed_at' => new UTCDateTime(),
                'coach_notes' => $notes,
            ]]
        );

        Response::success(null, 'Analysis approved');
    }

    // POST /coach/clients/:id/workout-analyses/:analysisId/reject
    public function reject(array $params): void
    {
        $analysis = $this->findAnalysis($params);
        $body     = Request::body();

        if ($analysis['status'] !== 'pending') {
            Response::error('Only pending analyses can be rejected', 409);
            return;
        }

        $notes = is_string($body['coach_notes'] ?? null) ? $body['coach_notes'] : null;

        Database::collection('client_workout_analyses')->updateOne(
            ['_id' => $analysis['_id']],
            ['$set' => [
                'status'      => 'rejected',
                'reviewed_at' => new UTCDateTime(),
                'coach_notes' => $notes,
            ]]
        );

        Response::success(null, 'Analysis rejected');
    }

    // POST /coach/clients/:id/workout-analyses/:analysisId/assign
    public function assign(array $params): void
    {
        $analysis = $this->findAnalysis($params);
        $body     = Request::body();
        $coachId  = $params['_auth']['sub'];

        if ($analysis['status'] !== 'pending' && $analysis['status'] !== 'approved') {
            Response::error('Only pending or approved analyses can be assigned', 409);
            return;
        }

        $clientId = (string) $analysis['client_id'];
        $planId   = null;

        // Option A: assign an existing plan by ID
        if (!empty($body['plan_id'])) {
            try {
                $planIdObj = new ObjectId($body['plan_id']);
            } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                Response::error('Invalid plan ID', 400);
                return;
            }
            $plan = Database::collection('workout_plans')->findOne([
                '_id'      => $planIdObj,
                'coach_id' => new ObjectId($coachId),
            ]);
            if (!$plan) {
                Response::error('Plan not found', 404);
                return;
            }
            $planId = (string) $planIdObj;
        }
        // Option B: create a new inline plan from recommendation data
        elseif (!empty($body['plan'])) {
            $planData = $body['plan'];
            $errors = Request::validate($planData, ['title' => 'required']);
            if ($errors) {
                Response::error('Validation failed', 422, $errors);
                return;
            }

            $weekStart = $planData['week_start'] ?? date('Y-m-d', strtotime('next monday'));
            $weekTs = strtotime($weekStart);
            if ($weekTs === false) {
                Response::error('Invalid week_start', 422);
                return;
            }

            $doc = [
                'coach_id'            => new ObjectId($coachId),
                'plan_type'           => 'individual',
                'client_id'           => $analysis['client_id'],
                'title'               => $planData['title'],
                'week_start'          => new UTCDateTime($weekTs * 1000),
                'status'              => $planData['status'] ?? 'active',
                'days'                => $planData['days'] ?? [],
                'notes'               => $planData['notes'] ?? null,
                'imported_from_excel' => false,
                'created_at'          => new UTCDateTime(),
                'updated_at'          => new UTCDateTime(),
            ];

            $result = Database::collection('workout_plans')->insertOne($doc);
            $planId = (string) $result->getInsertedId();
        } else {
            Response::error('plan_id or plan object is required', 422);
            return;
        }

        $selectedIndex = isset($body['selected_recommendation_index']) ? (int) $body['selected_recommendation_index'] : null;

        Database::collection('client_workout_analyses')->updateOne(
            ['_id' => $analysis['_id']],
            ['$set' => [
                'status'                       => 'assigned',
                'reviewed_at'                  => new UTCDateTime(),
                'assigned_plan_id'             => new ObjectId($planId),
                'selected_recommendation_index'=> $selectedIndex,
            ]]
        );

        // Notify client
        try {
            $this->notifications->notifyClientNewPlan(
                $body['plan_title'] ?? ($plan['title'] ?? 'Your new plan'),
                $planId,
                $clientId
            );
        } catch (\Throwable $e) {
            error_log('Failed to notify client of assigned plan: ' . $e->getMessage());
        }

        Response::success(['assigned_plan_id' => $planId], 'Plan assigned');
    }

    private function findAnalysis(array $params): object
    {
        $coachId    = new ObjectId($params['_auth']['sub']);
        $clientId   = $params['id'] ?? '';
        $analysisId = $params['analysisId'] ?? '';

        if (!preg_match('/^[a-f0-9]{24}$/i', $clientId) || !preg_match('/^[a-f0-9]{24}$/i', $analysisId)) {
            Response::error('Invalid ID', 400);
        }

        $analysis = Database::collection('client_workout_analyses')->findOne([
            '_id'      => new ObjectId($analysisId),
            'client_id'=> new ObjectId($clientId),
            'coach_id' => $coachId,
        ]);
        if (!$analysis) Response::notFound('Analysis not found');
        return $analysis;
    }

    private function format(object $doc, bool $full = false): array
    {
        $data = [
            'id'                         => (string) $doc['_id'],
            'client_id'                  => (string) $doc['client_id'],
            'coach_id'                   => (string) $doc['coach_id'],
            'status'                     => (string) $doc['status'],
            'generated_at'               => isset($doc['generated_at']) ? $doc['generated_at']->toDateTime()->format('c') : null,
            'reviewed_at'                => isset($doc['reviewed_at']) ? $doc['reviewed_at']->toDateTime()->format('c') : null,
            'metrics'                    => (array) ($doc['metrics'] ?? []),
            'recommendations'            => (array) ($doc['recommendations'] ?? []),
            'coach_notes'                => $doc['coach_notes'] ?? null,
            'selected_recommendation_index'=> $doc['selected_recommendation_index'] ?? null,
            'assigned_plan_id'           => isset($doc['assigned_plan_id']) ? (string) $doc['assigned_plan_id'] : null,
        ];

        if (!$full) {
            unset($data['recommendations']);
        }

        return $data;
    }
}
