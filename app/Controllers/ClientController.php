<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{CodeService, EmailService, NotificationTriggerService};
use App\Controllers\SubscriptionController;
use MongoDB\BSON\ObjectId;

class ClientController
{
    // GET /client/profile
    public function clientProfile(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'active' => true,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);
        Response::success($this->format($client));
    }

    // PUT /client/profile
    public function updateClientProfile(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body = Request::body();
        $allowed = ['name', 'language', 'profile_photo_url', 'phone', 'address', 'city', 'postal_code', 'nationality', 'occupation'];
        $set = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $set[$field] = $body[$field];
            }
        }

        // Get client before update for comparison
        $clientBefore = Database::collection('clients')->findOne(['_id' => $clientId, 'active' => true]);

        Database::collection('clients')->updateOne(
            ['_id' => $clientId, 'active' => true],
            ['$set' => $set]
        );

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'active' => true,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        // Notify coach of profile update
        $changedFields = array_intersect_key($set, array_flip($allowed));
        unset($changedFields['updated_at']);
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
        $coachId  = new ObjectId($params['_auth']['sub']);
        $search   = Request::get('search', '');
        $page     = max(1, (int) Request::get('page', 1));
        $perPage  = 20;
        $filter   = ['coach_id' => $coachId];

        // Include both active and blocked clients (exclude only soft-deleted)
        $filter['active'] = ['$in' => [true, false]];

        if ($search) {
            $escaped = preg_quote($search, '/');
            $filter['$or'] = [
                ['name'  => ['$regex' => $escaped, '$options' => 'i']],
                ['email' => ['$regex' => $escaped, '$options' => 'i']],
            ];
        }

        $col   = Database::collection('clients');
        $total = $col->countDocuments($filter);
        $docs  = $col->find($filter, [
            'skip'       => ($page - 1) * $perPage,
            'limit'      => $perPage,
            'projection' => ['login_code_hash' => 0],
            'sort'       => ['created_at' => -1],
        ]);

        $clients = [];
        foreach ($docs as $doc) {
            $clients[] = $this->format($doc);
        }

        Response::paginated($clients, $total, $page, $perPage);
    }

    // POST /coach/clients
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['name' => 'required|min:2']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $col = Database::collection('clients');

        // Use subscription tier limit
        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        $tier  = $coach['subscription_tier'] ?? 'free';
        $cap   = SubscriptionController::getClientLimit($tier);
        $count = $col->countDocuments(['coach_id' => $coachId, 'active' => true]);
        if ($count >= $cap) {
            Response::error("Client limit of {$cap} reached. Upgrade your plan to add more clients.", 403);
        }

        $code      = CodeService::generate();
        $codeHash  = CodeService::hash($code);
        $codeLookup = CodeService::lookupHash($code);

        $result = $col->insertOne([
            'coach_id'          => $coachId,
            'name'              => $body['name'],
            'email'             => $body['email'] ?? null,
            'phone'             => $body['phone'] ?? null,
            'language'          => $body['language'] ?? 'en',
            'address'           => $body['address'] ?? null,
            'city'              => $body['city'] ?? null,
            'postal_code'       => $body['postal_code'] ?? null,
            'nationality'       => $body['nationality'] ?? null,
            'occupation'        => $body['occupation'] ?? null,
            'login_code_hash'   => $codeHash,
            'code_lookup'       => $codeLookup,
            'active'            => true,
            'is_blocked'        => false,
            'token_version'     => 0,
            'fcm_token'         => null,
            'profile_photo_url' => null,
            'notes'             => $body['notes'] ?? null,
            'created_at'        => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'        => new \MongoDB\BSON\UTCDateTime(),
        ]);

        // Send login code email if client has email
        $emailSent = false;
        if (!empty($body['email'])) {
            $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
            $coachName = $coach['name'] ?? 'Your Coach';
            $emailSent = EmailService::sendClientLoginCode($body['email'], $body['name'], $coachName, $code);
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
        $allowed = ['name', 'email', 'phone', 'language', 'notes', 'address', 'city', 'postal_code', 'nationality', 'occupation'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (isset($body[$field])) $set[$field] = $body[$field];
        }

        Database::collection('clients')->updateOne(['_id' => $client['_id']], ['$set' => $set]);
        Response::success(null, 'Client updated');
    }

    // DELETE /coach/clients/:id
    public function destroy(array $params): void
    {
        $client = $this->findClient($params);
        Database::collection('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => ['active' => false, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
        );
        Response::success(null, 'Client deactivated');
    }

    // POST /coach/clients/:id/block
    public function block(array $params): void
    {
        $clientId = new ObjectId($params['id']);
        $coachId  = new ObjectId($params['_auth']['sub']);

        // Find client even if already blocked (don't require active=true)
        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        // Increment token_version to invalidate all existing tokens
        $currentVersion = $client['token_version'] ?? 0;
        $newVersion     = $currentVersion + 1;

        // Generate new login code to invalidate old access codes
        $code       = CodeService::generate();
        $codeHash   = CodeService::hash($code);
        $codeLookup = CodeService::lookupHash($code);

        Database::collection('clients')->updateOne(
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
        Response::success(null, 'Client blocked');
    }

    // POST /coach/clients/:id/unblock
    public function unblock(array $params): void
    {
        $clientId = new ObjectId($params['id']);
        $coachId  = new ObjectId($params['_auth']['sub']);

        // Find client even if blocked (don't require active=true)
        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ], ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);

        Database::collection('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => [
                'is_blocked' => false,
                'active'     => true,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
        Response::success(null, 'Client unblocked');
    }

    // POST /coach/clients/:id/regenerate-code
    public function regenerateCode(array $params): void
    {
        $client   = $this->findClient($params);
        $code     = CodeService::generate();
        $codeHash = CodeService::hash($code);
        $codeLookup = CodeService::lookupHash($code);

        Database::collection('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => ['login_code_hash' => $codeHash, 'code_lookup' => $codeLookup, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
        );

        Response::success(['login_code' => $code], 'Login code regenerated');
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

    private function findClient(array $params, bool $includeBlocked = false): object
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);
        $filter   = [
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ];
        if (!$includeBlocked) {
            $filter['active'] = true;
        }
        $client = Database::collection('clients')->findOne($filter, ['projection' => ['login_code_hash' => 0]]);

        if (!$client) Response::error('Client not found', 404);
        return $client;
    }

    private function format(object $doc): array
    {
        return [
            'id'                => (string) $doc['_id'],
            'name'              => $doc['name'],
            'email'             => $doc['email'] ?? null,
            'phone'             => $doc['phone'] ?? null,
            'language'          => $doc['language'] ?? 'en',
            'address'           => $doc['address'] ?? null,
            'city'              => $doc['city'] ?? null,
            'postal_code'       => $doc['postal_code'] ?? null,
            'nationality'       => $doc['nationality'] ?? null,
            'occupation'        => $doc['occupation'] ?? null,
            'profile_photo_url' => $doc['profile_photo_url'] ?? null,
            'notes'             => $doc['notes'] ?? null,
            'active'            => $doc['active'],
            'is_blocked'        => $doc['is_blocked'] ?? false,
            'created_at'        => (string) ($doc['created_at'] ?? ''),
        ];
    }
}
