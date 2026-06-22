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
        $allowed = ['name', 'language', 'profile_photo_url', 'phone', 'address', 'city', 'postal_code', 'nationality', 'occupation', 'gender', 'date_of_birth', 'current_weight_kg', 'height_cm', 'chest_cm', 'waist_cm', 'body_fat_pct', 'bmi', 'lean_mass_kg', 'sickness', 'age', 'weight', 'location', 'notes', 'email'];
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
            $filter = array_merge($filter, $visibility);
        }

        // Support sort parameter: e.g. sort=name_asc or sort=created_at_desc
        $sortParam = Request::get('sort', 'created_at_desc');
        $sort = ['created_at' => -1];
        if ($sortParam) {
            $parts = explode('_', $sortParam);
            $direction = array_pop($parts);
            $field = implode('_', $parts);
            if (in_array($field, ['name', 'email', 'created_at', 'updated_at']) && in_array($direction, ['asc', 'desc'])) {
                $sort = [$field => $direction === 'asc' ? 1 : -1];
            }
        }

        $col   = Database::collection('clients');
        $total = $col->countDocuments($filter);
        $docs  = $col->find($filter, [
            'skip'       => ($page - 1) * $perPage,
            'limit'      => $perPage,
            'projection' => ['login_code_hash' => 0],
            'sort'       => $sort,
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
            'gender'            => $body['gender'] ?? null,
            'date_of_birth'     => $body['date_of_birth'] ?? null,
            'current_weight_kg' => isset($body['current_weight_kg']) ? (float) $body['current_weight_kg'] : null,
            'height_cm'         => isset($body['height_cm']) ? (float) $body['height_cm'] : null,
            'chest_cm'          => isset($body['chest_cm']) ? (float) $body['chest_cm'] : null,
            'waist_cm'          => isset($body['waist_cm']) ? (float) $body['waist_cm'] : null,
            'body_fat_pct'      => isset($body['body_fat_pct']) ? (float) $body['body_fat_pct'] : null,
            'bmi'               => isset($body['bmi']) ? (float) $body['bmi'] : null,
            'lean_mass_kg'      => isset($body['lean_mass_kg']) ? (float) $body['lean_mass_kg'] : null,
            'sickness'          => $body['sickness'] ?? null,
            'age'               => isset($body['age']) ? (int) $body['age'] : null,
            'weight'            => isset($body['weight']) ? (float) $body['weight'] : null,
            'location'          => $body['location'] ?? null,
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
        $allowed = ['name', 'email', 'phone', 'language', 'notes', 'address', 'city', 'postal_code', 'nationality', 'occupation', 'gender', 'date_of_birth', 'current_weight_kg', 'height_cm', 'chest_cm', 'waist_cm', 'body_fat_pct', 'bmi', 'lean_mass_kg', 'sickness', 'age', 'weight', 'location', 'profile_photo_url'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $set[$field] = $body[$field];
            }
        }

        Database::collection('clients')->updateOne(['_id' => $client['_id']], ['$set' => $set]);

        $updated = Database::collection('clients')->findOne(
            ['_id' => $client['_id']],
            ['projection' => ['login_code_hash' => 0]]
        );
        Response::success($this->format($updated), 'Client updated');
    }

    // DELETE /coach/clients/:id
    public function destroy(array $params): void
    {
        $client = $this->findClient($params, includeBlocked: true);

        $currentVersion = ($client['token_version'] ?? 0) + 1;

        Database::collection('clients')->updateOne(
            ['_id' => $client['_id']],
            ['$set' => [
                'active'        => false,
                'is_blocked'    => false,
                'token_version' => $currentVersion,
                'deleted_at'    => new \MongoDB\BSON\UTCDateTime(),
                'updated_at'    => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
        Response::success(null, 'Client deleted');
    }

    public function uploadClientPhoto(array $params): void
    {
        $clientId = new ObjectId($params['id']);
        $coachId  = new ObjectId($params['_auth']['sub']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $ext = (string) Request::get('ext', 'jpg');
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array(strtolower($ext), $allowed)) {
            Response::error('Invalid file type. Allowed: jpg, jpeg, png, webp', 422);
        }

        // Delete old photo first
        if (!empty($client['profile_photo_url'])) {
            $oldKey = $client['profile_photo_key'] ?? null;
            if ($oldKey && str_starts_with((string) $oldKey, 'local:')) {
                $oldPath = BASE_PATH . '/public/' . substr((string) $oldKey, 6);
                if (is_file($oldPath)) @unlink($oldPath);
            } elseif ($oldKey && \App\Services\S3Service::isConfigured()) {
                \App\Services\S3Service::delete((string) $oldKey);
            }
        }

        $uuid = bin2hex(random_bytes(8));

        if (\App\Services\S3Service::isConfigured()) {
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $mime    = $mimeMap[strtolower($ext)] ?? 'image/jpeg';
            $key     = "clients/{$clientId}/profile/{$uuid}.{$ext}";

            $uploadUrl = \App\Services\S3Service::getPresignedUploadUrl($key, $mime);
            $readUrl   = \App\Services\S3Service::getPresignedDownloadUrl($key, 1440);

            Database::collection('clients')->updateOne(
                ['_id' => $clientId],
                ['$set' => [
                    'profile_photo_url' => $readUrl,
                    'profile_photo_key' => $key,
                    'updated_at'        => new \MongoDB\BSON\UTCDateTime(),
                ]]
            );

            Response::success([
                'upload_url'    => $uploadUrl,
                'profile_photo' => $readUrl,
                'key'           => $key,
            ], 'Upload URL generated');
        } else {
            if (empty($_FILES['photo']['tmp_name'])) {
                Response::error('No photo file provided', 422);
            }
            $file    = $_FILES['photo'];
            $relPath = "uploads/clients/{$clientId}";
            $dir     = BASE_PATH . '/public/' . $relPath;
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $filename = "{$uuid}.{$ext}";
            $dest     = "{$dir}/{$filename}";

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                Response::error('Failed to save photo', 500);
            }

            $appUrl   = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
            $photoUrl = "{$appUrl}/{$relPath}/{$filename}";
            $localKey = "local:{$relPath}/{$filename}";

            Database::collection('clients')->updateOne(
                ['_id' => $clientId],
                ['$set' => [
                    'profile_photo_url' => $photoUrl,
                    'profile_photo_key' => $localKey,
                    'updated_at'        => new \MongoDB\BSON\UTCDateTime(),
                ]]
            );

            Response::success([
                'profile_photo' => $photoUrl,
            ], 'Photo uploaded');
        }
    }

    // DELETE /coach/clients/:id/permanent
    public function permanentDelete(array $params): void
    {
        $client = $this->findClient($params, includeBlocked: true);
        $clientId = $client['_id'];

        // Cascade delete related collections
        Database::collection('workout_plans')->deleteMany(['client_id' => $clientId]);
        Database::collection('workout_logs')->deleteMany(['client_id' => $clientId]);
        Database::collection('body_measurements')->deleteMany(['client_id' => $clientId]);
        Database::collection('media_uploads')->deleteMany(['client_id' => $clientId]);
        Database::collection('messages')->deleteMany(['client_id' => $clientId]);
        Database::collection('checkin_meetings')->deleteMany(['client_id' => $clientId]);
        Database::collection('coaching_sessions')->deleteMany(['client_id' => $clientId]);
        Database::collection('notifications')->deleteMany(['client_id' => $clientId]);

        Database::collection('clients')->deleteOne(['_id' => $clientId]);
        Response::success(null, 'Client permanently deleted');
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

    // POST /coach/clients/import  — bulk import clients from JSON array
    public function import(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
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
                'coach_id'         => $coachId,
                'name'             => $name,
                'email'            => $email,
                'phone'            => !empty($row['phone']) ? trim((string) $row['phone']) : null,
                'age'              => !empty($row['age']) ? (int) $row['age'] : null,
                'location'         => !empty($row['location']) ? trim((string) $row['location']) : null,
                'city'             => !empty($row['city']) ? trim((string) $row['city']) : null,
                'weight'           => !empty($row['weight']) ? (float) $row['weight'] : null,
                'height'           => !empty($row['height']) ? (float) $row['height'] : null,
                'gender'           => !empty($row['gender']) ? trim((string) $row['gender']) : null,
                'date_of_birth'    => !empty($row['date_of_birth']) ? trim((string) $row['date_of_birth']) : null,
                'current_weight_kg'=> !empty($row['current_weight_kg']) ? (float) $row['current_weight_kg'] : null,
                'height_cm'        => !empty($row['height_cm']) ? (float) $row['height_cm'] : null,
                'chest_cm'         => !empty($row['chest_cm']) ? (float) $row['chest_cm'] : null,
                'waist_cm'         => !empty($row['waist_cm']) ? (float) $row['waist_cm'] : null,
                'body_fat_pct'     => !empty($row['body_fat_pct']) ? (float) $row['body_fat_pct'] : null,
                'bmi'              => !empty($row['bmi']) ? (float) $row['bmi'] : null,
                'lean_mass_kg'     => !empty($row['lean_mass_kg']) ? (float) $row['lean_mass_kg'] : null,
                'sickness'         => !empty($row['sickness']) ? trim((string) $row['sickness']) : null,
                'nationality'      => !empty($row['nationality']) ? trim((string) $row['nationality']) : null,
                'occupation'       => !empty($row['occupation']) ? trim((string) $row['occupation']) : null,
                'postal_code'      => !empty($row['postal_code']) ? trim((string) $row['postal_code']) : null,
                'address'          => !empty($row['address']) ? trim((string) $row['address']) : null,
                'language'         => !empty($row['language']) ? trim((string) $row['language']) : 'en',
                'notes'            => !empty($row['notes']) ? trim((string) $row['notes']) : null,
                'login_code_hash'  => $codeHash,
                'code_lookup'      => $codeLookup,
                'active'           => true,
                'is_blocked'       => false,
                'created_at'       => new \MongoDB\BSON\UTCDateTime(),
                'updated_at'       => new \MongoDB\BSON\UTCDateTime(),
            ];

            $result  = Database::collection('clients')->insertOne($doc);
            $clientId = (string) $result->getInsertedId();

            $emailSent  = false;
            if ($email) {
                try {
                    $emailService = new EmailService();
                    $emailService->sendClientWelcome($email, $name, $code, $coach['name'] ?? 'Your Coach');
                    $emailSent = true;
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
        $emailsSkipped = count(array_filter($loginCodes, fn($c) => !$c['email_sent'] && $c['email'] === null));

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

        if (!$client) {
            Response::error('Client not found', 404);
        }
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
            'gender'            => $doc['gender'] ?? null,
            'date_of_birth'     => $doc['date_of_birth'] ?? null,
            'current_weight_kg' => $doc['current_weight_kg'] ?? null,
            'height_cm'         => $doc['height_cm'] ?? null,
            'chest_cm'          => $doc['chest_cm'] ?? null,
            'waist_cm'          => $doc['waist_cm'] ?? null,
            'body_fat_pct'      => $doc['body_fat_pct'] ?? null,
            'bmi'               => $doc['bmi'] ?? null,
            'lean_mass_kg'      => $doc['lean_mass_kg'] ?? null,
            'sickness'          => $doc['sickness'] ?? null,
            'age'               => $doc['age'] ?? null,
            'weight'            => $doc['weight'] ?? null,
            'location'          => $doc['location'] ?? null,
            'profile_photo_url' => $doc['profile_photo_url'] ?? null,
            'notes'             => $doc['notes'] ?? null,
            'active'            => $doc['active'],
            'is_blocked'        => $doc['is_blocked'] ?? false,
            'created_at'        => isset($doc['created_at']) ? (string) $doc['created_at'] : null,
            'updated_at'        => isset($doc['updated_at']) ? (string) $doc['updated_at'] : null,
        ];
    }
}
