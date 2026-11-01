<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response, ScopedCollection, Diff};
use App\Services\{CodeService, EmailService, NotificationTriggerService, ProfilePhotoService};
use App\Controllers\SubscriptionController;
use MongoDB\BSON\ObjectId;

class ClientController
{
    // GET /client/profile
    public function clientProfile(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        // RLS automatically scopes by _id for client role
        $client = ScopedCollection::for('clients')->findOne([
            '_id' => $clientId,
            'active' => true,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        $data = $this->format($client);

        // Populate assigned coach name for client-facing display
        if (!empty($client['primary_staff_id'])) {
            $staff = Database::collection('staff')->findOne(['_id' => $client['primary_staff_id']]);
            if ($staff) {
                $data['assigned_coach_name'] = $staff['name'] ?? $staff['email'] ?? null;
                $data['assigned_coach_id']   = (string) $staff['_id'];
            }
        }

        // Populate coach object for client-facing display
        if (!empty($client['coach_id'])) {
            $coach = Database::collection('coaches')->findOne(['_id' => $client['coach_id']]);
            if ($coach) {
                $data['coach'] = [
                    'id'                => (string) $coach['_id'],
                    'name'              => $coach['name'] ?? 'Coach',
                    'email'             => $coach['email'] ?? null,
                    'phone'             => $coach['phone'] ?? null,
                    'profile_photo_url' => $coach['profile_photo_url'] ?? null,
                    'specialty'         => $coach['specialty'] ?? null,
                    'bio'               => $coach['bio'] ?? null,
                ];
            }
        }

        // Include active goals
        $goals = Database::collection('goals')
            ->find(['client_id' => $clientId, 'status' => 'active'], ['sort' => ['created_at' => -1]])
            ->toArray();
        $data['goals'] = array_map(fn($g) => [
            'id'            => (string) $g['_id'],
            'title'         => $g['title'] ?? '',
            'target_value'  => $g['target_value'] ?? 0,
            'current_value' => $g['current_value'] ?? 0,
            'unit'          => $g['unit'] ?? '',
            'deadline'      => isset($g['deadline']) ? (string) $g['deadline'] : null,
            'status'        => $g['status'] ?? 'active',
            'progress_pct'  => isset($g['target_value']) && $g['target_value'] > 0
                ? min(100, round((($g['current_value'] ?? 0) / $g['target_value']) * 100))
                : 0,
        ], $goals);

        Response::success($data);
    }

    // PUT /client/profile
    public function updateClientProfile(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();
        $allowed = ['name', 'language', 'profile_photo_url', 'phone', 'address', 'city', 'postal_code', 'nationality', 'occupation', 'gender', 'date_of_birth', 'current_weight_kg', 'height_cm', 'chest_cm', 'waist_cm', 'body_fat_pct', 'bmi', 'lean_mass_kg', 'sickness', 'age', 'weight', 'location', 'notes', 'email'];
        $set = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $set[$field] = $body[$field];
            }
        }

        // RLS: client role scopes by _id; coach role by coach_id
        $clientsCol = ScopedCollection::for('clients');

        // Get client before update for comparison
        $clientBefore = $clientsCol->findOne(['_id' => $clientId, 'active' => true]);

        $clientsCol->updateOne(
            ['_id' => $clientId, 'active' => true],
            ['$set' => $set]
        );

        $client = $clientsCol->findOne([
            '_id' => $clientId,
            'active' => true,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        // Notify coach of profile update — but only for fields that ACTUALLY
        // changed compared to the pre-update record. Without this, a client
        // re-submitting unchanged values (e.g. on a form reload) would
        // generate a spurious "profile updated" notification.
        $changedFields = Diff::changedFields($body, $clientBefore, $allowed);
        if (!empty($changedFields) && $clientBefore) {
            $triggerService = new NotificationTriggerService();
            $triggerService->notifyProfileUpdated(
                (string) $clientId,
                $client['name'] ?? 'A client',
                array_keys($changedFields)
            );
        }

        Response::success($this->format($client), 'Profile updated');
    }

    // PUT /client/profile/photo
    public function updateClientProfilePhoto(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();
        $errors = Request::validate($body, ['profile_photo_url' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $url = trim((string) $body['profile_photo_url']);
        // Validate URL format and scheme
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Response::error('Invalid photo URL format', 422);
            return;
        }
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            Response::error('Photo URL must use HTTP or HTTPS', 422);
            return;
        }

        Database::collection('clients')->updateOne(
            ['_id' => $clientId, 'active' => true],
            ['$set' => [
                'profile_photo_url' => $url,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'active' => true,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        // Notify coach of profile photo update
        try {
            $triggerService = new NotificationTriggerService();
            $triggerService->notifyProfileUpdated(
                (string) $clientId,
                $client['name'] ?? 'A client',
                ['profile_photo_url']
            );
        } catch (\Throwable $e) {
            error_log('Failed to send notification for profile photo update: ' . $e->getMessage());
        }

        Response::success($this->format($client), 'Profile photo updated');
    }

    // POST /client/fcm-token
    public function updateFcmToken(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();
        $errors = Request::validate($body, ['fcm_token' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        Database::collection('clients')->updateOne(
            ['_id' => $clientId, 'active' => true],
            ['$set' => [
                'fcm_token' => trim((string) $body['fcm_token']),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        Response::success(null, 'Push token updated');
    }

    // GET /coach/clients
    public function index(array $params): void
    {
        $search   = Request::get('search', '');
        $page     = max(1, (int) Request::get('page', 1));
        $perPage  = 20;
        $filter   = [];

        // Show active clients AND blocked clients, but hide soft-deleted ones.
        // Blocked clients have is_blocked=true; deleted clients have active=false AND is_blocked=false.
        $visibility = [
            '$or' => [
                ['active' => true],
                ['is_blocked' => true],
            ],
        ];

        if ($search) {
            $escaped = preg_quote($search, '/');
            $filter['$and'] = [
                $visibility,
                ['$or' => [
                    ['name'  => ['$regex' => $escaped, '$options' => 'i']],
                    ['email' => ['$regex' => $escaped, '$options' => 'i']],
                ]],
            ];
        } else {
            $filter = $visibility;
        }

        // Support sort parameter: e.g. sort=name_asc or sort=created_at_desc
        $sortParam = Request::get('sort', 'created_at_desc');
        $sort = ['created_at' => -1];
        $parts = explode('_', $sortParam);
        $direction = array_pop($parts);
        $field = implode('_', $parts);
        if (in_array($field, ['name', 'email', 'created_at', 'updated_at']) && in_array($direction, ['asc', 'desc'])) {
            $sort = [$field => $direction === 'asc' ? 1 : -1];
        }

        // RLS automatically scopes by coach_id for coach role
        $col   = ScopedCollection::for('clients');
        $total = $col->countDocuments($filter);
        $docs  = $col->find($filter, [
            'skip'       => ($page - 1) * $perPage,
            'limit'      => $perPage,
            'projection' => ['login_code_hash' => 0],
            'sort'       => $sort,
        ])->toArray();

        $clientIds = array_map(fn($d) => $d['_id'], $docs);

        // ── Batch compute workout progress for all clients on this page ──
        $progressMap = $this->computeClientProgress($clientIds);

        $clients = [];
        foreach ($docs as $doc) {
            $clients[] = $this->format($doc, $progressMap[(string) $doc['_id']] ?? null);
        }

        Response::paginated($clients, $total, $page, $perPage);
    }

    // POST /coach/clients
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['name' => 'required|min:2']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $col = ScopedCollection::for('clients');

        // Use subscription tier limit
        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        $tier  = $coach['subscription_tier'] ?? 'free';
        $cap   = SubscriptionController::getClientLimit($tier);
        $count = $col->countDocuments(['active' => true]);
        if ($count >= $cap) {
            Response::error("Client limit of {$cap} reached. Upgrade your plan to add more clients.", 403);
        }

        $code      = CodeService::generate();
        $codeHash  = CodeService::hash($code);
        $codeLookup = CodeService::lookupHash($code);

        $doc = [
            'coach_id'        => $coachId,
            'name'            => $body['name'],
            'login_code_hash' => $codeHash,
            'code_lookup'     => $codeLookup,
            'active'          => true,
            'is_blocked'      => false,
            'token_version'   => 0,
            'fcm_token'       => null,
            'profile_photo_url' => null,
            'created_at'      => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'      => new \MongoDB\BSON\UTCDateTime(),
        ];

        $nullables = ['email','phone','address','city','postal_code','nationality','occupation','gender','date_of_birth','sickness','location','notes'];
        foreach ($nullables as $f) {
            $doc[$f] = $body[$f] ?? null;
        }
        $doc['language'] = $body['language'] ?? 'en';

        $floats = ['current_weight_kg','height_cm','chest_cm','waist_cm','body_fat_pct','bmi','lean_mass_kg','weight'];
        foreach ($floats as $f) {
            $doc[$f] = isset($body[$f]) ? (float) $body[$f] : null;
        }
        $doc['age'] = isset($body['age']) ? (int) $body['age'] : null;

        $result = $col->insertOne($doc);
        $insertedId = (string) $result->getInsertedId();

        \App\Services\ActivityLogService::log(
            'client_created',
            $insertedId,
            $body['name'] ?? null,
            ['login_code' => $code]
        );

        self::recalculateClientCount($coachId);

        // Send login code email if client has email
        $emailSent = false;
        if (!empty($body['email'])) {
            try {
                $emailSent = EmailService::sendClientLoginCode($body['email'], $body['name'], $coach['name'] ?? 'Your Coach', $code);
            } catch (\Throwable $e) {
                error_log('Client store: failed to send login code email to ' . $body['email'] . ': ' . $e->getMessage());
            }
        }

        Response::success([
            'id'         => (string) $result->getInsertedId(),
            'name'       => $body['name'],
            'login_code' => $code,  // shown ONCE to coach
            'email_sent' => $emailSent,
        ], 'Client created', 201);
    }

    // GET /coach/clients/:id
    public function show(array $params): void
    {
        $client = $this->findClient($params, includeBlocked: true);
        Response::success($this->format($client));
    }

    // PUT /coach/clients/:id
    public function update(array $params): void
    {
        $client  = $this->findClient($params, includeBlocked: true);
        $body    = Request::body();
        $allowed = ['name', 'email', 'phone', 'language', 'notes', 'address', 'city', 'postal_code', 'nationality', 'occupation', 'gender', 'date_of_birth', 'current_weight_kg', 'height_cm', 'chest_cm', 'waist_cm', 'body_fat_pct', 'bmi', 'lean_mass_kg', 'sickness', 'age', 'weight', 'location', 'profile_photo_url'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $set[$field] = $body[$field];
            }
        }

        ScopedCollection::for('clients')->updateOne(['_id' => $client['_id']], ['$set' => $set]);

        $updated = ScopedCollection::for('clients')->findOne(
            ['_id' => $client['_id']],
            ['projection' => ['login_code_hash' => 0]]
        );

        // Compute changed fields for the response so the frontend can
        // show "X fields updated" honestly. This is the coach-side
        // counterpart to updateClientProfile's $changedFields used for
        // the "profile updated" notification.
        $changedFields = Diff::changedFields($body, $client, $allowed);

        \App\Services\ActivityLogService::log(
            'client_updated',
            (string) $client['_id'],
            $client['name'] ?? null,
            ['changed_fields' => array_keys($changedFields)]
        );
        $payload = $this->format($updated);
        $payload['changed_fields'] = $changedFields;
        Response::success($payload, 'Client updated');
    }

    // DELETE /coach/clients/:id
    public function destroy(array $params): void
    {
        $client = $this->findClient($params, includeBlocked: true);

        $currentVersion = ($client['token_version'] ?? 0) + 1;

        ScopedCollection::for('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => [
                'active'        => false,
                'is_blocked'    => false,
                'token_version' => $currentVersion,
                'deleted_at'    => new \MongoDB\BSON\UTCDateTime(),
                'updated_at'    => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        self::recalculateClientCount($client['coach_id']);

        Response::success(null, 'Client deleted');
    }

    public function uploadClientPhoto(array $params): void
    {
        $clientId = new ObjectId($params['id']);

        $client = ScopedCollection::for('clients')->findOne([
            '_id' => $clientId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $ext = (string) Request::get('ext', 'jpg');
        if (!ProfilePhotoService::validateExt($ext)) {
            Response::error('Invalid file type. Allowed: jpg, jpeg, png, webp', 422);
        }

        try {
            $result = ProfilePhotoService::upload(
                (string) $clientId,
                'clients',
                $ext,
                $client['profile_photo_key'] ?? null
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getMessage() === 'No photo file provided' ? 422 : 500);
        }

        Database::collection('clients')->updateOne(
            ['_id' => $clientId],
            ['$set' => [
                'profile_photo_url' => $result['url'],
                'profile_photo_key' => $result['key'],
                'updated_at'        => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        $response = ['profile_photo' => $result['url']];
        if (isset($result['upload_url'])) {
            $response['upload_url'] = $result['upload_url'];
            $response['key']        = $result['key'];
        }

        Response::success($response, isset($result['upload_url']) ? 'Upload URL generated' : 'Photo uploaded');
    }

    // DELETE /coach/clients/:id/permanent
    public function permanentDelete(array $params): void
    {
        $client = $this->findClient($params, includeBlocked: true);
        $clientId = $client['_id'];
        // Defensive: capture the client owner at the top so the
        // cascade is pinned to this coach even if findClient()'s
        // internal filter is later loosened (e.g. an admin mode).
        $coachId = $client['coach_id'] ?? null;

        // Cascade delete related collections.
        // Ownership is enforced by findClient() above (RLS-scoped by
        // coach_id): the $client['_id'] we get back is guaranteed to
        // belong to the requesting coach, so filtering the cascade
        // by client_id is safe. The deleteMany calls bypass the RLS
        // wrapper on purpose — these are destructive admin operations
        // and the wrapper would also inject a coach_id filter for
        // some of these collections, which is a no-op here because
        // the client already belongs to this coach. We still add
        // coach_id as a defensive secondary filter on every cascade
        // row so a future refactor of findClient() cannot silently
        // widen the blast radius of a delete.
        foreach ([
            'workout_plans','workout_logs','body_measurements','media_uploads',
            'messages','checkin_meetings','coaching_sessions','notifications',
            'nutrition_plans','meal_logs','checkins','class_bookings',
            'memberships','pain_logs','proms','soap_notes','rehab_tracks',
            'wearable_data','wearable_sessions','wearable_tokens',
            'wearable_connections','predictions','client_workout_analyses',
            'parq_screenings','consent_documents','staff_activities',
            'support_tickets','live_training_requests','message_typing_status',
            'pr_records','progress_photos',
        ] as $coll) {
            $filter = ['client_id' => $clientId];
            if ($coachId !== null) {
                $filter['coach_id'] = $coachId;
            }
            Database::collection($coll)->deleteMany($filter);
        }

        // Refresh tokens use user_id instead of client_id
        Database::collection('refresh_tokens')->deleteMany([
            'user_id' => $clientId,
            'role'    => 'client',
        ]);

        ScopedCollection::for('clients')->deleteOne(['_id' => $clientId]);

        self::recalculateClientCount($coachId);

        Response::success(null, 'Client permanently deleted');
    }

    // POST /coach/clients/:id/block
    public function block(array $params): void
    {
        $clientId = new ObjectId($params['id']);

        // RLS: coach role scopes by coach_id
        $client = ScopedCollection::for('clients')->findOne([
            '_id' => $clientId,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        // Increment token_version to invalidate all existing tokens
        $newVersion = ($client['token_version'] ?? 0) + 1;

        // Generate new login code to invalidate old access codes
        $code       = CodeService::generate();
        $codeHash   = CodeService::hash($code);
        $codeLookup = CodeService::lookupHash($code);

        ScopedCollection::for('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => [
                'is_blocked'    => true,
                'active'        => false,
                'token_version' => $newVersion,
                'login_code_hash' => $codeHash,
                'code_lookup'   => $codeLookup,
                'updated_at'    => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        self::recalculateClientCount($client['coach_id']);

        Response::success(null, 'Client blocked');
    }

    // POST /coach/clients/:id/unblock
    public function unblock(array $params): void
    {
        $clientId = new ObjectId($params['id']);

        // RLS: coach role scopes by coach_id
        $client = ScopedCollection::for('clients')->findOne([
            '_id' => $clientId,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        ScopedCollection::for('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => [
                'is_blocked' => false,
                'active'     => true,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        self::recalculateClientCount($client['coach_id']);

        Response::success(null, 'Client unblocked');
    }

    // POST /coach/clients/:id/regenerate-code
    public function regenerateCode(array $params): void
    {
        $client      = $this->findClient($params);
        $clientId    = $client['_id'];
        $newVersion  = (int) (($client['token_version'] ?? 0) + 1);
        $code        = CodeService::generate();
        $codeHash    = CodeService::hash($code);
        $codeLookup  = CodeService::lookupHash($code);

        ScopedCollection::for('clients')->updateOne(
            ['_id' => $clientId],
            ['$set' => [
                'login_code_hash' => $codeHash,
                'code_lookup'     => $codeLookup,
                'token_version'   => $newVersion,
                'updated_at'      => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        // Invalidate all outstanding client tokens so the previous login code
        // can no longer be used. This forces any logged-in FitApp clients to
        // log out and re-authenticate with the freshly generated code.
        Database::collection('refresh_tokens')->deleteMany([
            'user_id' => $clientId,
            'role'    => 'client',
        ]);

        // Email the new login code to the client if they have an address.
        $emailSent = false;
        $clientEmail = $client['email'] ?? null;
        if ($clientEmail) {
            try {
                $coachName = $params['_auth']['name'] ?? 'Your Coach';
                $emailSent = EmailService::sendClientLoginCode(
                    (string) $clientEmail,
                    (string) ($client['name'] ?? ''),
                    $coachName,
                    $code
                );
            } catch (\Throwable $e) {
                error_log('Regenerate code: failed to send login code email to ' . $clientEmail . ': ' . $e->getMessage());
            }
        }

        Response::success([
            'login_code' => $code,
            'email_sent' => $emailSent,
        ], 'Login code regenerated. All existing client sessions have been signed out.');
    }

    // GET /coach/clients/:id/analytics
    public function analytics(array $params): void
    {
        $client   = $this->findClient($params);
        $clientId = $client['_id'];

        // Workout completion rate (last 8 weeks)
        $since = new \MongoDB\BSON\UTCDateTime((time() - 56 * 86400) * 1000);

        $plansCount = Database::collection('workout_plans')->countDocuments([
            'client_id'  => $clientId,
            'created_at' => ['$gte' => $since],
        ]);

        $logsCount = Database::collection('workout_logs')->countDocuments([
            'client_id'    => $clientId,
            'completed_at' => ['$gte' => $since],
        ]);

        // Weight progress per exercise
        $weightProgress = Database::collection('workout_logs')->aggregate([
            ['$match'  => ['client_id' => $clientId]],
            ['$unwind' => '$exercises'],
            ['$unwind' => '$exercises.sets_completed'],
            ['$group'  => [
                '_id'    => [
                    'exercise' => '$exercises.name',
                    'week'     => ['$week' => '$completed_at'],
                    'year'     => ['$year' => '$completed_at'],
                ],
                'max_kg' => ['$max' => '$exercises.sets_completed.kg'],
                'avg_kg' => ['$avg' => '$exercises.sets_completed.kg'],
                'date'   => ['$max' => '$completed_at'],
            ]],
            ['$sort'   => ['date' => 1]],
            ['$limit'  => 200],
        ])->toArray();

        // Body measurements
        $measurements = Database::collection('body_measurements')->find(
            ['client_id' => $clientId],
            ['sort' => ['recorded_at' => 1]]
        )->toArray();

        // Recent media
        $media = Database::collection('media_uploads')->find(
            ['client_id' => $clientId],
            ['sort' => ['uploaded_at' => -1], 'limit' => 20]
        )->toArray();

        Response::success([
            'completion_rate'  => $plansCount > 0 ? round(($logsCount / $plansCount) * 100, 1) : 0,
            'plans_count'      => $plansCount,
            'logs_count'       => $logsCount,
            'weight_progress'  => array_map(fn($w) => [
                'exercise' => $w['_id']['exercise'],
                'week'     => $w['_id']['week'],
                'year'     => $w['_id']['year'],
                'max_kg'   => round($w['max_kg'], 1),
                'avg_kg'   => round($w['avg_kg'], 1),
            ], $weightProgress),
            'measurements'     => array_map(fn($m) => array_merge(
                (array) $m,
                ['_id' => (string) $m['_id'], 'client_id' => (string) $m['client_id']]
            ), $measurements),
            'media'            => array_map(fn($m) => [
                'id'          => (string) $m['_id'],
                'type'        => $m['type'],
                'url'         => $m['url'],
                'uploaded_at' => (string) $m['uploaded_at'],
            ], $media),
        ]);
    }

    // POST /coach/clients/import  — bulk import clients from JSON array
    public function import(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();

        if (empty($body['clients']) || !is_array($body['clients'])) {
            Response::error('clients array is required', 422);
            return;
        }

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        $tier  = $coach['subscription_tier'] ?? 'free';
        $cap   = SubscriptionController::getClientLimit($tier);
        $existingCount = Database::collection('clients')->countDocuments(['coach_id' => $coachId, 'active' => true]);

        $created   = 0;
        $failed    = 0;
        $errors    = [];
        $loginCodes = [];

        foreach ($body['clients'] as $index => $row) {
            $name = trim((string) ($row['full_name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                $errors[] = ['index' => $index, 'field' => 'name', 'message' => 'Name is required'];
                $failed++;
                continue;
            }

            if (($existingCount + $created) >= $cap) {
                $errors[] = ['index' => $index, 'field' => null, 'message' => "Client limit of {$cap} reached"];
                $failed++;
                continue;
            }

            $code       = CodeService::generate();
            $codeHash   = CodeService::hash($code);
            $codeLookup = CodeService::lookupHash($code);

            $email = !empty($row['email']) ? trim((string) $row['email']) : null;

            $doc = [
                'coach_id'        => $coachId,
                'name'            => $name,
                'email'           => $email,
                'login_code_hash' => $codeHash,
                'code_lookup'     => $codeLookup,
                'active'          => true,
                'is_blocked'      => false,
                'created_at'      => new \MongoDB\BSON\UTCDateTime(),
                'updated_at'      => new \MongoDB\BSON\UTCDateTime(),
            ];

            $strings = ['phone','location','city','gender','date_of_birth','sickness','nationality','occupation','postal_code','address','language','notes'];
            foreach ($strings as $f) {
                $doc[$f] = !empty($row[$f]) ? trim((string) $row[$f]) : null;
            }
            $doc['language'] = !empty($row['language']) ? trim((string) $row['language']) : 'en';

            $floats = ['weight','height','current_weight_kg','height_cm','chest_cm','waist_cm','body_fat_pct','bmi','lean_mass_kg'];
            foreach ($floats as $f) {
                $doc[$f] = !empty($row[$f]) ? (float) $row[$f] : null;
            }
            $doc['age'] = !empty($row['age']) ? (int) $row['age'] : null;

            $result  = Database::collection('clients')->insertOne($doc);
            $clientId = (string) $result->getInsertedId();

            $emailSent  = false;
            if ($email) {
                try {
                    $emailSent = EmailService::sendClientLoginCode($email, $name, $coach['name'] ?? 'Your Coach', $code);
                } catch (\Throwable $e) {
                    error_log('Import: failed to send welcome email to ' . $email . ': ' . $e->getMessage());
                }
            }

            $loginCodes[] = [
                'id'         => $clientId,
                'name'       => $name,
                'email'      => $email,
                'login_code' => $code,
                'email_sent' => $emailSent,
            ];
            $created++;
        }

        $emailsSent    = count(array_filter($loginCodes, fn($c) => $c['email_sent']));
        $emailsSkipped = count(array_filter($loginCodes, fn($c) => $c['email'] === null));

        self::recalculateClientCount($coachId);

        Response::success([
            'created'        => $created,
            'failed'         => $failed,
            'total'          => count($body['clients']),
            'errors'         => $errors,
            'emails_sent'    => $emailsSent,
            'emails_skipped' => $emailsSkipped,
            'login_codes'    => $loginCodes,
        ], "{$created} client(s) imported", 201);
    }

    private static function recalculateClientCount(ObjectId $coachId): void
    {
        $count = Database::collection('clients')->countDocuments([
            'coach_id' => $coachId,
            'active'   => true,
        ]);

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'client_count' => $count,
                'updated_at'   => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function findClient(array $params, bool $includeBlocked = false): object
    {
        $clientId = new ObjectId($params['id']);
        $filter   = ['_id' => $clientId];
        if (!$includeBlocked) {
            $filter['active'] = true;
        }
        // RLS automatically adds coach_id filter for coach role
        $client = ScopedCollection::for('clients')->findOne($filter, ['projection' => ['login_code_hash' => 0]]);

        if (!$client) {
            Response::error('Client not found', 404);
        }
        return $client;
    }

    /**
     * Batch-compute workout progress for a list of clients.
     * Returns a map: client_id (string) => [total_days, completed_days, progress_pct]
     */
    private function computeClientProgress(array $clientIds): array
    {
        if (empty($clientIds)) return [];

        // Find all active/saved workout plans for these clients
        $plans = Database::collection('workout_plans')->find([
            '$or' => [
                ['client_id'  => ['$in' => $clientIds]],
                ['client_ids' => ['$in' => $clientIds]],
            ],
            'status' => ['$in' => ['active', 'saved']],
        ])->toArray();

        $planMap     = []; // plan_id => plan
        $clientPlans = []; // client_id => [plan_ids]
        foreach ($plans as $plan) {
            $pid = (string) $plan['_id'];
            $planMap[$pid] = $plan;

            if (isset($plan['client_id'])) {
                $cid = (string) $plan['client_id'];
                $clientPlans[$cid][] = $pid;
            }
            if (!empty($plan['client_ids'])) {
                foreach ($plan['client_ids'] as $cid) {
                    $clientPlans[(string) $cid][] = $pid;
                }
            }
        }

        // Find all workout logs for these clients
        $logs = Database::collection('workout_logs')->find([
            'client_id' => ['$in' => $clientIds],
        ])->toArray();

        $logMap = []; // client_id => plan_id => [logged_day_names]
        foreach ($logs as $log) {
            $cid = (string) $log['client_id'];
            $pid = (string) ($log['workout_plan_id'] ?? '');
            if ($pid) {
                $logMap[$cid][$pid][] = strtolower($log['day'] ?? '');
            }
        }

        $progressMap = [];
        foreach ($clientIds as $cid) {
            $cidStr   = (string) $cid;
            $planIds  = array_unique($clientPlans[$cidStr] ?? []);
            $totalDays = 0;
            $completedDays = 0;

            foreach ($planIds as $pid) {
                $plan = $planMap[$pid] ?? null;
                if (!$plan) continue;

                $days = count($plan['days'] ?? []);
                $totalDays += $days;

                $loggedDays = $logMap[$cidStr][$pid] ?? [];
                $uniqueLogged = array_unique(array_filter($loggedDays));
                $completedDays += count($uniqueLogged);
            }

            $progressMap[$cidStr] = [
                'total_days'     => $totalDays,
                'completed_days' => $completedDays,
                'progress_pct'   => $totalDays > 0 ? round(($completedDays / $totalDays) * 100, 1) : 0.0,
            ];
        }

        return $progressMap;
    }

    private function format(object $doc, ?array $progress = null): array
    {
        $fields = ['email','phone','address','city','postal_code','nationality','occupation','gender','date_of_birth','current_weight_kg','height_cm','chest_cm','waist_cm','body_fat_pct','bmi','lean_mass_kg','sickness','age','weight','location','profile_photo_url','notes'];
        $out = ['id' => (string) $doc['_id'], 'name' => $doc['name'] ?? ''];
        foreach ($fields as $f) {
            $out[$f] = $doc[$f] ?? null;
        }
        $out['language'] = $doc['language'] ?? 'en';
        $out['active']   = $doc['active'] ?? false;
        $out['is_blocked'] = $doc['is_blocked'] ?? false;
        $out['created_at'] = isset($doc['created_at']) ? (string) $doc['created_at'] : null;
        $out['updated_at'] = isset($doc['updated_at']) ? (string) $doc['updated_at'] : null;
        $out['coach_id']   = isset($doc['coach_id']) ? (string) $doc['coach_id'] : null;
        $out['primary_staff_id']       = isset($doc['primary_staff_id']) ? (string) $doc['primary_staff_id'] : null;
        $out['last_staff_id']           = isset($doc['last_staff_id']) ? (string) $doc['last_staff_id'] : null;
        $out['last_staff_name']         = $doc['last_staff_name'] ?? null;
        $out['last_staff_role']         = $doc['last_staff_role'] ?? null;
        $out['last_staff_activity']     = $doc['last_staff_activity'] ?? null;
        $out['last_staff_activity_at']   = isset($doc['last_staff_activity_at']) ? (string) $doc['last_staff_activity_at'] : null;
        $out['workout_progress'] = $progress ?? [
            'total_days'     => 0,
            'completed_days' => 0,
            'progress_pct'   => 0.0,
        ];
        return $out;
    }
}
