<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response, ScopedCollection, Diff};
use App\Services\{CodeService, EmailService, NotificationTriggerService};
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

        // Send login code email if client has email
        $emailSent = false;
        if (!empty($body['email'])) {
            $emailSent = EmailService::sendClientLoginCode($body['email'], $body['name'], $coach['name'] ?? 'Your Coach', $code);
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
        Response::success(null, 'Client deleted');
    }

    public function uploadClientPhoto(array $params): void
    {
        $clientId = new ObjectId($params['id']);

        // RLS automatically scopes by coach_id for coach role
        $client = ScopedCollection::for('clients')->findOne([
            '_id' => $clientId,
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
            $relPath = "uploads/clients/{$clientId}";
            $dir     = BASE_PATH . '/public/' . $relPath;
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $filename = "{$uuid}.{$ext}";
            $dest     = "{$dir}/{$filename}";

            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
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
        foreach (['workout_plans','workout_logs','body_measurements','media_uploads','messages','checkin_meetings','coaching_sessions','notifications'] as $coll) {
            $filter = ['client_id' => $clientId];
            if ($coachId !== null) {
                $filter['coach_id'] = $coachId;
            }
            Database::collection($coll)->deleteMany($filter);
        }

        ScopedCollection::for('clients')->deleteOne(['_id' => $clientId]);
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
        Response::success(null, 'Client unblocked');
    }

    // POST /coach/clients/:id/regenerate-code
    public function regenerateCode(array $params): void
    {
        $client   = $this->findClient($params);
        $code     = CodeService::generate();
        $codeHash = CodeService::hash($code);
        $codeLookup = CodeService::lookupHash($code);

        ScopedCollection::for('clients')->updateOne(
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

    private function format(object $doc): array
    {
        $fields = ['email','phone','address','city','postal_code','nationality','occupation','gender','date_of_birth','current_weight_kg','height_cm','chest_cm','waist_cm','body_fat_pct','bmi','lean_mass_kg','sickness','age','weight','location','profile_photo_url','notes'];
        $out = ['id' => (string) $doc['_id'], 'name' => $doc['name']];
        foreach ($fields as $f) {
            $out[$f] = $doc[$f] ?? null;
        }
        $out['language'] = $doc['language'] ?? 'en';
        $out['active']   = $doc['active'];
        $out['is_blocked'] = $doc['is_blocked'] ?? false;
        $out['created_at'] = isset($doc['created_at']) ? (string) $doc['created_at'] : null;
        $out['updated_at'] = isset($doc['updated_at']) ? (string) $doc['updated_at'] : null;
        return $out;
    }
}
