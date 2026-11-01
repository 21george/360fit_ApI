<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\NotificationTriggerService;
use MongoDB\BSON\ObjectId;

class WorkoutLogController
{
    // GET /client/workout-plan/current  (client)
    public function currentPlan(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $monday   = strtotime('monday this week');
        $sunday   = strtotime('sunday this week 23:59:59');

        $clientFilter = $this->clientPlanFilter($clientId);

        $plan = Database::collection('workout_plans')->findOne(array_merge(
            $clientFilter,
            [
                'status'     => 'active',
                'week_start' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($monday * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($sunday * 1000),
                ],
            ]
        ));

        if (!$plan) {
            // Return most recent active plan
            $plan = Database::collection('workout_plans')->findOne(
                array_merge($clientFilter, ['status' => 'active']),
                ['sort' => ['week_start' => -1]]
            );
        }

        if (!$plan) Response::error('No active workout plan', 404);

        // Get completed days for this plan
        $logs = Database::collection('workout_logs')->find([
            'client_id'       => $clientId,
            'workout_plan_id' => $plan['_id'],
        ])->toArray();

        $completedDays = array_map(fn($l) => $l['day'], $logs);

        Response::success([
            'plan'           => $this->formatPlan($plan),
            'completed_days' => $completedDays,
        ]);
    }

    // GET /client/workout-plan/history  (client)
    public function history(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $page     = max(1, (int) Request::get('page', 1));
        $perPage  = 10;

        $clientFilter = $this->clientPlanFilter($clientId);
        $col   = Database::collection('workout_plans');
        $total = $col->countDocuments($clientFilter);
        $plans = $col->find($clientFilter, [
            'sort'  => ['week_start' => -1],
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
        ])->toArray();

        $result = [];
        foreach ($plans as $plan) {
            $logCount = Database::collection('workout_logs')->countDocuments([
                'client_id'       => $clientId,
                'workout_plan_id' => $plan['_id'],
            ]);
            $formatted          = $this->formatPlan($plan);
            $formatted['logs_count'] = $logCount;
            $result[] = $formatted;
        }

        Response::paginated($result, $total, $page, $perPage);
    }

    // POST /client/workout-logs  (client)
    public function store(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $errors   = Request::validate($body, [
            'workout_plan_id' => 'required',
            'day'             => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $planId = new ObjectId($body['workout_plan_id']);

        // Verify plan belongs to client (individual or group)
        $plan = Database::collection('workout_plans')->findOne(array_merge(
            ['_id' => $planId],
            $this->clientPlanFilter($clientId)
        ));
        if (!$plan) Response::error('Plan not found', 404);

        // Check not already logged
        $existing = Database::collection('workout_logs')->findOne([
            'client_id'       => $clientId,
            'workout_plan_id' => $planId,
            'day'             => $body['day'],
        ]);
        if ($existing) Response::error('Day already logged', 409);

        $logId = Database::collection('workout_logs')->insertOne([
            'client_id'       => $clientId,
            'workout_plan_id' => $planId,
            'day'             => $body['day'],
            'exercises'       => $body['exercises'] ?? [],
            'notes'           => $body['notes'] ?? null,
            'session_rpe'     => isset($body['session_rpe']) && is_numeric($body['session_rpe'])
                ? max(1, min(10, (int) $body['session_rpe']))
                : null,
            'media_uploads'   => [],
            'completed_at'    => new \MongoDB\BSON\UTCDateTime(),
        ]);

        // Get client info for notification
        $client = Database::collection('clients')->findOne(['_id' => $clientId]);
        if ($client && $plan) {
            $triggerService = new NotificationTriggerService();
            $triggerService->notifyWorkoutCompleted(
                (string) $clientId,
                $client['name'] ?? 'A client',
                $plan['title'] ?? 'a workout',
                (string) $logId->getInsertedId()
            );
        }

        Response::success(['id' => (string) $logId->getInsertedId()], 'Workout logged', 201);
    }

    // PUT /client/workout-logs/:id
    public function update(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $logId    = new ObjectId($params['id']);

        $log = Database::collection('workout_logs')->findOne([
            '_id'       => $logId,
            'client_id' => $clientId,
        ]);
        if (!$log) Response::error('Log not found', 404);

        $body    = Request::body();
        $allowed = ['exercises', 'notes'];
        $set     = [];
        foreach ($allowed as $field) {
            if (isset($body[$field])) $set[$field] = $body[$field];
        }

        if (!empty($set)) {
            Database::collection('workout_logs')->updateOne(['_id' => $logId], ['$set' => $set]);
        }

        Response::success(null, 'Log updated');
    }

    // POST /client/workout-logs/:id/media  (client)
    public function addMedia(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $logId    = new ObjectId($params['id']);

        $log = Database::collection('workout_logs')->findOne([
            '_id'       => $logId,
            'client_id' => $clientId,
        ]);
        if (!$log) Response::error('Log not found', 404);

        $body   = Request::body();
        $errors = Request::validate($body, ['s3_key' => 'required', 'type' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $mediaResult = Database::collection('media_uploads')->insertOne([
            'client_id'    => $clientId,
            'log_id'       => $logId,
            'plan_id'      => $log['workout_plan_id'],
            'type'         => $body['type'],  // 'video' | 'photo'
            's3_key'       => $body['s3_key'],
            'url'          => $body['url'] ?? null,
            'uploaded_at'  => new \MongoDB\BSON\UTCDateTime(),
        ]);

        Database::collection('workout_logs')->updateOne(
            ['_id' => $logId],
            ['$push' => ['media_uploads' => $mediaResult->getInsertedId()]]
        );

        Response::success(['id' => (string) $mediaResult->getInsertedId()], 'Media uploaded', 201);
    }

    // GET /coach/clients/:id/logs (coach view)
    public function clientLogs(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        // Verify the client belongs to this coach (IDOR fix)
        $client = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        $page     = max(1, (int) Request::get('page', 1));
        $perPage  = 20;

        $col   = Database::collection('workout_logs');
        $total = $col->countDocuments(['client_id' => $clientId]);
        $logs  = $col->find(['client_id' => $clientId], [
            'sort'  => ['completed_at' => -1],
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
        ])->toArray();

        // Resolve media for each log
        $allMediaIds = [];
        foreach ($logs as $log) {
            foreach ($log['media_uploads'] ?? [] as $mid) {
                $allMediaIds[] = $mid instanceof ObjectId ? $mid : new ObjectId((string) $mid);
            }
        }

        $mediaMap = [];
        if (!empty($allMediaIds)) {
            $mediaItems = Database::collection('media_uploads')->find([
                '_id' => ['$in' => $allMediaIds]
            ])->toArray();
            foreach ($mediaItems as $m) {
                $mediaMap[(string) $m['_id']] = [
                    'id'          => (string) $m['_id'],
                    'type'        => $m['type'] ?? 'photo',
                    's3_key'      => $m['s3_key'] ?? null,
                    'url'         => $m['url'] ?? null,
                    'uploaded_at' => isset($m['uploaded_at']) ? $m['uploaded_at']->toDateTime()->format('c') : null,
                ];
            }
        }

        // Resolve plan info
        $planIds = array_values(array_unique(array_filter(array_map(fn($l) => isset($l['workout_plan_id']) ? (string) $l['workout_plan_id'] : null, $logs))));
        $planMap = [];
        if (!empty($planIds)) {
            $planDocs = Database::collection('workout_plans')->find([
                '_id' => ['$in' => array_map(fn($id) => new ObjectId($id), $planIds)]
            ])->toArray();
            foreach ($planDocs as $p) {
                $planMap[(string) $p['_id']] = $this->formatPlan($p);
            }
        }

        // Resolve reviewer (coach/staff) names for reviewed logs
        $reviewerIds = [];
        foreach ($logs as $l) {
            if (!empty($l['reviewed_by'])) {
                $reviewerIds[] = $l['reviewed_by'] instanceof ObjectId ? $l['reviewed_by'] : new ObjectId((string) $l['reviewed_by']);
            }
        }
        $reviewerMap = [];
        if (!empty($reviewerIds)) {
            $reviewers = Database::collection('coaches')->find([
                '_id' => ['$in' => $reviewerIds]
            ])->toArray();
            foreach ($reviewers as $r) {
                $reviewerMap[(string) $r['_id']] = $r['name'] ?? $r['email'] ?? 'Unknown';
            }
            // Reviewer may also be a staff member
            $staffReviewers = Database::collection('staff')->find([
                '_id' => ['$in' => $reviewerIds]
            ])->toArray();
            foreach ($staffReviewers as $r) {
                $reviewerMap[(string) $r['_id']] = $r['name'] ?? $r['email'] ?? 'Unknown';
            }
        }

        $result = array_map(function ($l) use ($mediaMap, $planMap, $reviewerMap) {
            $logMedia = [];
            foreach ($l['media_uploads'] ?? [] as $mid) {
                $midStr = (string) $mid;
                if (isset($mediaMap[$midStr])) $logMedia[] = $mediaMap[$midStr];
            }

            $planId = (string) $l['workout_plan_id'];

            // Build planned vs actual comparison
            $plan = $planMap[$planId] ?? null;
            $plannedExercises = [];
            if ($plan) {
                $dayName = $l['day'] ?? '';
                foreach ($plan['days'] ?? [] as $d) {
                    if (strcasecmp($d['day'], $dayName) === 0) {
                        $plannedExercises = $d['exercises'] ?? [];
                        break;
                    }
                }
            }

            // Index planned exercises by normalized name for easy lookup
            $planByName = [];
            foreach ($plannedExercises as $pex) {
                $name = strtolower(trim((string) ($pex['name'] ?? '')));
                if ($name !== '') {
                    $planByName[$name] = $pex;
                }
            }

            // Enrich each logged exercise with planned set data
            $enrichedExercises = [];
            foreach ($l['exercises'] ?? [] as $ex) {
                $exNameRaw = $ex['name'] ?? '';
                $exNameKey = strtolower(trim((string) $exNameRaw));
                $planned   = $planByName[$exNameKey] ?? null;

                $setsCompleted = (array) ($ex['sets_completed'] ?? []);
                $planSets      = isset($planned['sets']) ? (int) $planned['sets'] : count($setsCompleted);
                $planKg        = isset($planned['kg']) ? (float) $planned['kg'] : null;
                $planReps      = isset($planned['reps']) ? (string) $planned['reps'] : null;

                $setComparison = [];
                for ($i = 0; $i < max(count($setsCompleted), $planSets); $i++) {
                    $actual = $setsCompleted[$i] ?? null;
                    $setComparison[] = [
                        'set_number'  => $actual['set_number'] ?? ($i + 1),
                        'plan_kg'     => $planKg,
                        'plan_reps'   => $planReps,
                        'actual_kg'   => isset($actual['kg']) ? (float) $actual['kg'] : null,
                        'actual_reps' => isset($actual['reps']) ? (int) $actual['reps'] : null,
                    ];
                }

                $enrichedExercises[] = [
                    'name'           => $exNameRaw,
                    'plan_sets'      => $planSets,
                    'plan_kg'        => $planKg,
                    'plan_reps'      => $planReps,
                    'rest_seconds'   => $planned['rest_seconds'] ?? null,
                    'set_comparison' => $setComparison,
                    'sets_completed' => $setsCompleted,
                    'notes'          => $ex['notes'] ?? null,
                ];
            }

            $reviewedById = isset($l['reviewed_by']) ? (string) $l['reviewed_by'] : null;

            return [
                'id'              => (string) $l['_id'],
                'workout_plan_id' => $planId,
                'plan_title'      => $plan['title'] ?? null,
                'day'             => $l['day'],
                'exercises'       => $enrichedExercises,
                'planned_exercises' => $plannedExercises,
                'notes'           => $l['notes'] ?? null,
                'session_rpe'     => $l['session_rpe'] ?? null,
                'completed_at'    => (string) ($l['completed_at'] ?? ''),
                'media_count'     => count($logMedia),
                'media'           => $logMedia,
                'review_status'   => $l['review_status'] ?? 'pending',
                'review_feedback' => $l['review_feedback'] ?? null,
                'reviewed_at'     => isset($l['reviewed_at']) ? (string) $l['reviewed_at'] : null,
                'reviewed_by'     => $reviewedById ? ($reviewerMap[$reviewedById] ?? 'Unknown') : null,
            ];
        }, $logs);

        Response::paginated($result, $total, $page, $perPage);
    }

    // GET /client/group-workout-plans  (client)
    public function clientGroupPlans(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $col      = Database::collection('workout_plans');

        $filter = [
            'client_ids'  => ['$in' => [$clientId]],
            'plan_type'   => ['$in' => ['group', 'team']],
        ];

        $status = Request::get('status');
        if ($status) $filter['status'] = $status;

        $plans = $col->find($filter, ['sort' => ['week_start' => -1]])->toArray();

        // collect all client_ids across plans and resolve names
        $allClientIds = [];
        foreach ($plans as $plan) {
            if (!empty($plan['client_ids'])) {
                foreach ($plan['client_ids'] as $cid) {
                    $allClientIds[(string) $cid] = new ObjectId((string) $cid);
                }
            }
        }

        $memberMap = [];
        if (!empty($allClientIds)) {
            $clients = Database::collection('clients')->find(
                ['_id' => ['$in' => array_values($allClientIds)]],
                ['projection' => ['name' => 1, 'profile_photo_url' => 1]]
            );
            foreach ($clients as $c) {
                $memberMap[(string) $c['_id']] = [
                    'id'   => (string) $c['_id'],
                    'name' => $c['name'] ?? 'Unknown',
                    'profile_photo_url' => $c['profile_photo_url'] ?? null,
                ];
            }
        }

        $result = array_map(fn($p) => $this->formatPlan($p, $memberMap), $plans);

        Response::success($result);
    }

    private function sanitizeTitle(mixed $title): string
    {
        $str = is_string($title) ? trim($title) : '';
        if ($str === '' || preg_match('/^[a-f0-9]{24}$/i', $str)) {
            return 'Untitled Plan';
        }
        return $str;
    }

    private function formatPlan(object $doc, array $memberMap = []): array
    {
        $clientIds = [];
        $members = [];
        if (!empty($doc['client_ids'])) {
            foreach ($doc['client_ids'] as $cid) {
                $id = (string) $cid;
                $clientIds[] = $id;
                if (isset($memberMap[$id])) {
                    $members[] = $memberMap[$id];
                }
            }
        }

        return [
            'id'         => (string) $doc['_id'],
            'title'      => $this->sanitizeTitle($doc['title']),
            'plan_type'  => (string) ($doc['plan_type'] ?? 'individual'),
            'group_name' => $doc['group_name'] ?? null,
            'client_ids' => $clientIds,
            'members'    => $members,
            'week_start' => $doc['week_start'] ? date('Y-m-d', (int)(((string)$doc['week_start']) / 1000)) : null,
            'status'     => $doc['status'],
            'days'       => $doc['days'] ?? [],
            'notes'      => $doc['notes'] ?? null,
        ];
    }

    /** Build a filter that matches plans where this client is assigned (individual or group). */
    private function clientPlanFilter(ObjectId $clientId): array
    {
        return [
            '$or' => [
                ['client_id'  => $clientId],
                ['client_ids' => ['$in' => [$clientId]]],
            ],
        ];
    }

    // GET /coach/clients/:id/live-progress (coach real-time dashboard card)
    public function liveProgress(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = $params['id'] ?? '';

        if (!preg_match('/^[a-f0-9]{24}$/i', $clientId)) {
            Response::error('Invalid client ID', 400);
            return;
        }

        $clientObjId = new ObjectId($clientId);

        // Verify the client belongs to this coach
        $client = Database::collection('clients')->findOne(['_id' => $clientObjId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        // Active plan: most recent active or saved plan
        $clientFilter = $this->clientPlanFilter($clientObjId);
        $activePlan = Database::collection('workout_plans')->findOne(
            array_merge($clientFilter, ['status' => ['$in' => ['active', 'saved']]]),
            ['sort' => ['week_start' => -1]]
        );

        // Build active plan progress if one exists
        $planResponse = null;
        if ($activePlan) {
            $planId = (string) $activePlan['_id'];
            $logs = Database::collection('workout_logs')->find([
                'client_id'       => $clientObjId,
                'workout_plan_id' => $activePlan['_id'],
            ])->toArray();

            $completedDays = [];
            foreach ($logs as $log) {
                $completedDays[strtolower($log['day'] ?? '')] = $log;
            }

            $totalDays = count($activePlan['days'] ?? []);
            $doneCount = 0;
            $daysOut   = [];
            foreach ($activePlan['days'] ?? [] as $day) {
                $dayKey = strtolower($day['day'] ?? '');
                $isDone = isset($completedDays[$dayKey]);
                if ($isDone) $doneCount++;
                $daysOut[] = [
                    'day'          => $day['day'],
                    'is_completed' => $isDone,
                    'completed_at' => $isDone ? (string) ($completedDays[$dayKey]['completed_at'] ?? '') : null,
                ];
            }

            $planResponse = [
                'id'             => $planId,
                'title'          => $this->sanitizeTitle($activePlan['title'] ?? ''),
                'progress_pct'   => $totalDays > 0 ? round(($doneCount / $totalDays) * 100, 1) : 0,
                'total_days'     => $totalDays,
                'completed_days' => $doneCount,
                'days'           => $daysOut,
            ];
        }

        // Latest log
        $latestLog = Database::collection('workout_logs')->findOne(
            ['client_id' => $clientObjId],
            ['sort' => ['completed_at' => -1]]
        );

        $latestLogOut = null;
        $lastActivity = null;
        if ($latestLog) {
            $lastActivity = (string) ($latestLog['completed_at'] ?? '');

            // Resolve reviewer name if present
            $reviewedByName = null;
            if (!empty($latestLog['reviewed_by'])) {
                $reviewerId = $latestLog['reviewed_by'] instanceof ObjectId ? $latestLog['reviewed_by'] : new ObjectId((string) $latestLog['reviewed_by']);
                $reviewer = Database::collection('coaches')->findOne(['_id' => $reviewerId], ['projection' => ['name' => 1, 'email' => 1]]);
                if (!$reviewer) {
                    $reviewer = Database::collection('staff')->findOne(['_id' => $reviewerId], ['projection' => ['name' => 1, 'email' => 1]]);
                }
                $reviewedByName = $reviewer['name'] ?? $reviewer['email'] ?? 'Unknown';
            }

            $latestLogOut = [
                'id'   => (string) $latestLog['_id'],
                'day'  => $latestLog['day'] ?? '',
                'exercises' => array_map(function ($ex) {
                    return [
                        'name' => $ex['name'] ?? '',
                        'sets_completed' => array_map(function ($set) {
                            return [
                                'set_number' => $set['set_number'] ?? 1,
                                'reps'       => $set['reps'] ?? 0,
                                'kg'         => $set['kg'] ?? 0,
                            ];
                        }, (array) ($ex['sets_completed'] ?? [])),
                    ];
                }, (array) ($latestLog['exercises'] ?? [])),
                'notes'          => $latestLog['notes'] ?? null,
                'session_rpe'    => $latestLog['session_rpe'] ?? null,
                'completed_at'   => $lastActivity,
                'review_status'  => $latestLog['review_status'] ?? 'pending',
                'review_feedback'=> $latestLog['review_feedback'] ?? null,
                'reviewed_at'    => isset($latestLog['reviewed_at']) ? (string) $latestLog['reviewed_at'] : null,
                'reviewed_by'    => $reviewedByName,
            ];
        }

        Response::success([
            'client' => [
                'id'    => $clientId,
                'name'  => $client['name'] ?? 'Unknown',
                'photo' => $client['profile_photo_url'] ?? null,
            ],
            'active_plan'      => $planResponse,
            'latest_log'       => $latestLogOut,
            'last_activity_at' => $lastActivity,
        ]);
    }

    // GET /coach/clients/:id/workout-progress (coach view — grouped completed vs in-progress)
    public function clientWorkoutProgress(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        // Verify the client belongs to this coach (IDOR fix)
        $client = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        // Get all plans assigned to this client
        $clientFilter = $this->clientPlanFilter($clientId);
        $plans = Database::collection('workout_plans')->find(
            $clientFilter,
            ['sort' => ['week_start' => -1]]
        )->toArray();

        // Get all logs for this client
        $logs = Database::collection('workout_logs')->find(
            ['client_id' => $clientId],
            ['sort' => ['completed_at' => -1]]
        )->toArray();

        // Build a map of plan_id => [day => log]
        $logMap = [];
        foreach ($logs as $log) {
            $planId = (string) $log['workout_plan_id'];
            $day    = strtolower($log['day'] ?? '');
            $logMap[$planId][$day] = $log;
        }

        $completed  = [];
        $inProgress = [];

        foreach ($plans as $plan) {
            $planId     = (string) $plan['_id'];
            $planLogs   = $logMap[$planId] ?? [];
            $totalDays  = count($plan['days'] ?? []);
            $completedDays = 0;

            foreach ($plan['days'] ?? [] as $day) {
                $dayKey = strtolower($day['day'] ?? '');
                if (isset($planLogs[$dayKey])) {
                    $completedDays++;
                }
            }

            $planData = [
                'id'              => $planId,
                'title'           => $this->sanitizeTitle($plan['title']),
                'week_start'      => $plan['week_start'] ? date('Y-m-d', (int)(((string)$plan['week_start']) / 1000)) : null,
                'status'          => $plan['status'],
                'total_days'      => $totalDays,
                'completed_days'  => $completedDays,
                'progress_pct'    => $totalDays > 0 ? round(($completedDays / $totalDays) * 100, 1) : 0,
                'days'            => array_map(function($day) use ($planLogs) {
                    $dayKey = strtolower($day['day'] ?? '');
                    $isCompleted = isset($planLogs[$dayKey]);
                    return [
                        'day'        => $day['day'],
                        'exercises'  => $day['exercises'] ?? [],
                        'is_completed' => $isCompleted,
                        'completed_at'  => $isCompleted ? (string) ($planLogs[$dayKey]['completed_at'] ?? '') : null,
                    ];
                }, (array) ($plan['days'] ?? [])),
            ];

            if ($completedDays === $totalDays && $totalDays > 0) {
                $completed[] = $planData;
            } else {
                $inProgress[] = $planData;
            }
        }

        Response::success([
            'completed'   => $completed,
            'in_progress' => $inProgress,
            'stats'       => [
                'total_plans'     => count($plans),
                'completed_count' => count($completed),
                'in_progress_count' => count($inProgress),
            ],
        ]);
    }

    // ─── Trainer Review Workflow ──────────────────────────────────────────────

    /**
     * GET /coach/workout-logs/pending-review
     *
     * Returns workout logs awaiting trainer review.
     */
    public function pendingReview(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);

        // Find all clients belonging to this coach
        $clients = Database::collection('clients')->find(
            ['coach_id' => $coachId],
            ['projection' => ['_id' => 1, 'name' => 1, 'profile_photo_url' => 1]]
        )->toArray();

        $clientIds = array_map(fn($c) => $c['_id'], $clients);
        $clientMap = [];
        foreach ($clients as $c) {
            $clientMap[(string) $c['_id']] = [
                'name'  => $c['name'] ?? 'Unknown',
                'photo' => $c['profile_photo_url'] ?? null,
            ];
        }

        // Find logs that don't have review_status or have review_status = 'pending'
        $logs = Database::collection('workout_logs')
            ->find(
                [
                    'client_id' => ['$in' => $clientIds],
                    '$or' => [
                        ['review_status' => ['$exists' => false]],
                        ['review_status' => 'pending'],
                    ],
                ],
                ['sort' => ['completed_at' => -1], 'limit' => 50]
            )
            ->toArray();

        $result = array_map(function ($log) use ($clientMap) {
            $clientId = (string) $log['client_id'];
            return [
                'id'           => (string) $log['_id'],
                'client'       => $clientMap[$clientId] ?? ['name' => 'Unknown', 'photo' => null],
                'day'          => $log['day'] ?? '',
                'exercises'    => $log['exercises'] ?? [],
                'notes'        => $log['notes'] ?? null,
                'completed_at' => (string) ($log['completed_at'] ?? ''),
                'review_status' => $log['review_status'] ?? 'pending',
            ];
        }, $logs);

        Response::success([
            'logs'  => $result,
            'count' => count($result),
        ]);
    }

    /**
     * POST /coach/workout-logs/:id/review
     *
     * Trainer reviews a workout log.
     * Body: { status: 'approved'|'needs_redo', feedback?: string }
     */
    public function reviewLog(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $logId   = new ObjectId($params['id']);

        $log = Database::collection('workout_logs')->findOne(['_id' => $logId]);
        if (!$log) Response::error('Log not found', 404);

        // Verify the log belongs to a client of this coach
        $client = Database::collection('clients')->findOne([
            '_id'      => $log['client_id'],
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Unauthorized', 403);

        $body = Request::body();
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if (!in_array($status, ['approved', 'needs_redo'], true)) {
            Response::error("Invalid status. Use 'approved' or 'needs_redo'", 422);
        }

        Database::collection('workout_logs')->updateOne(
            ['_id' => $logId],
            ['$set' => [
                'review_status'   => $status,
                'review_feedback' => $body['feedback'] ?? null,
                'reviewed_at'     => new \MongoDB\BSON\UTCDateTime(),
                'reviewed_by'     => $coachId,
            ]]
        );

        Response::success([
            'id'       => (string) $logId,
            'status'   => $status,
            'feedback' => $body['feedback'] ?? null,
        ], 'Review saved');
    }

    /**
     * GET /client/workout-logs/:id/review
     *
     * Client view of their workout review.
     */
    public function clientReview(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $logId    = new ObjectId($params['id']);

        $log = Database::collection('workout_logs')->findOne([
            '_id'       => $logId,
            'client_id' => $clientId,
        ]);
        if (!$log) Response::error('Log not found', 404);

        Response::success([
            'review_status'   => $log['review_status'] ?? 'pending',
            'review_feedback' => $log['review_feedback'] ?? null,
            'reviewed_at'     => isset($log['reviewed_at']) ? (string) $log['reviewed_at'] : null,
        ]);
    }
}
