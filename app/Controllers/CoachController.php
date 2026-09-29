<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response, Diff, ScopedCollection};
use App\Services\ProfilePhotoService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class CoachController {

    public function me(array $params): void {
        $coach = ScopedCollection::for('coaches')->findOne(
            ['_id' => new ObjectId($params['_auth']['sub'])],
            ['projection' => ['password_hash' => 0]]
        );
        if (!$coach) Response::notFound('Coach not found');

        $data = $this->format($coach);
        $data['client_count'] = $this->getClientCount((string) $coach['_id']);
        $data = $this->resolveProfilePhoto($data, $coach);

        Response::success($data);
    }

    public function update(array $params): void {
        $body    = Request::body();
        $allowed = [
            'name', 'surname', 'language', 'profile_photo',
            'phone', 'currency', 'social_media',
            'city', 'timezone', 'date_time_format',
            'daily_time_utilization', 'core_work_min', 'core_work_max',
            'function', 'job_title', 'responsibilities',
        ];
        $update  = array_filter($body, fn($k) => in_array($k, $allowed), ARRAY_FILTER_USE_KEY);

        // Validate social_media structure if provided
        if (isset($update['social_media']) && is_array($update['social_media'])) {
            $socialAllowed = ['linkedin', 'instagram', 'website'];
            $update['social_media'] = array_filter(
                $update['social_media'],
                fn($k) => in_array($k, $socialAllowed),
                ARRAY_FILTER_USE_KEY
            );
            // Sanitize URLs — reject invalid URLs instead of silently corrupting them
            foreach ($update['social_media'] as $key => $val) {
                $trimmed = trim((string) $val);
                if ($trimmed === '') {
                    $update['social_media'][$key] = '';
                    continue;
                }

                $url = filter_var($trimmed, FILTER_VALIDATE_URL);
                if ($url === false) {
                    Response::error("Invalid URL for social_media.{$key}", 422);
                }
                $parsed = parse_url($url);
                if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
                    Response::error("Invalid URL scheme for social_media.{$key}", 422);
                }
                $update['social_media'][$key] = $url;
            }
        }

        // Password changes are NOT allowed through this endpoint.
        // Use PUT /coach/profile/password (changePassword) instead.
        if (!empty($body['password'])) {
            Response::error('Password update is not allowed here. Use /coach/profile/password', 422);
        }

        $coachIdObj = new ObjectId($params['_auth']['sub']);

        // Capture the pre-update record so we can compute which fields
        // actually changed. Used both for the response (so the frontend
        // can show honest "X fields updated" feedback) and for any
        // future "coach profile updated" notification trigger.
        $coachBefore = ScopedCollection::for('coaches')->findOne(['_id' => $coachIdObj]);

        $update['updated_at'] = new UTCDateTime();
        ScopedCollection::for('coaches')->updateOne(
            ['_id' => $coachIdObj],
            ['$set' => $update]
        );

        $updated = ScopedCollection::for('coaches')->findOne(
            ['_id' => $coachIdObj],
            ['projection' => ['password_hash' => 0]]
        );
        $data = $this->format($updated);
        $data['client_count'] = $this->getClientCount((string) $updated['_id']);
        $data = $this->resolveProfilePhoto($data, $updated);

        // Field-level diff: only fields present in BOTH the body and
        // the allowed list are considered, and only when the value
        // actually differs from the pre-update record. social_media
        // is included as a single field — its sub-document is
        // compared via canonical JSON inside Diff::valuesEqual.
        // updated_at is excluded: it is server-set and would always
        // register as a "change" against the pre-update record.
        $diffKeys = array_keys($update);
        $diffKeys = array_values(array_filter(
            $diffKeys,
            fn(string $k): bool => $k !== 'updated_at',
        ));
        $data['changed_fields'] = Diff::changedFields($update, $coachBefore, $diffKeys);
        Response::success($data, 'Profile updated');
    }

    public function uploadPhoto(array $params): void {
        $coachId    = $params['_auth']['sub'];
        $coachIdObj = new ObjectId($coachId);
        $ext        = (string) Request::get('ext', 'jpg');

        if (!ProfilePhotoService::validateExt($ext)) {
            Response::error('Invalid file type. Allowed: jpg, jpeg, png, webp', 422);
        }

        $coach = ScopedCollection::for('coaches')->findOne(
            ['_id' => $coachIdObj],
            ['projection' => ['profile_photo_key' => 1]]
        );

        try {
            $result = ProfilePhotoService::upload($coachId, 'coaches', $ext, $coach['profile_photo_key'] ?? null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getMessage() === 'No photo file provided' ? 422 : 500);
        }

        ScopedCollection::for('coaches')->updateOne(
            ['_id' => $coachIdObj],
            ['$set' => [
                'profile_photo'     => $result['url'],
                'profile_photo_key' => $result['key'],
                'updated_at'        => new UTCDateTime(),
            ]]
        );

        $response = ['profile_photo' => $result['url']];
        if (isset($result['upload_url'])) {
            $response['upload_url'] = $result['upload_url'];
            $response['key']        = $result['key'];
        }

        Response::success($response, isset($result['upload_url']) ? 'Upload URL generated' : 'Photo uploaded');
    }

    public function deletePhoto(array $params): void {
        $coachId    = $params['_auth']['sub'];
        $coachIdObj = new ObjectId($coachId);
        $coach      = ScopedCollection::for('coaches')->findOne(
            ['_id' => $coachIdObj],
            ['projection' => ['profile_photo_key' => 1]]
        );

        ProfilePhotoService::deleteByKey($coach['profile_photo_key'] ?? null);

        ScopedCollection::for('coaches')->updateOne(
            ['_id' => $coachIdObj],
            ['$set' => ['profile_photo' => null, 'profile_photo_key' => null, 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Profile photo deleted');
    }

    // GET /coaches/registered — list all active coaches (public directory).
    // Bypasses RLS via ScopedCollection::raw() because this is a directory
    // listing, not a self-resource access; RLS would only return the
    // requesting coach.
    public function index(array $params): void {
        $coaches = ScopedCollection::for('coaches')->raw()->find(
            ['active' => ['$ne' => false]],
            [
                'projection' => ['password_hash' => 0, 'stripe_customer_id' => 0],
                'sort'       => ['created_at' => -1],
                'limit'      => 100,
            ]
        )->toArray();

        Response::success(array_map(fn($c) => $this->format($c), $coaches));
    }

    // DELETE /coach/profile — soft-delete the authenticated coach account
    public function deleteAccount(array $params): void {
        $coachId = new ObjectId($params['_auth']['sub']);

        ScopedCollection::for('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'active'     => false,
                'deleted_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
            ]]
        );

        // Expire all refresh tokens by removing them
        Database::collection('refresh_tokens')->deleteMany(['coach_id' => $coachId]);

        Response::success(null, 'Account deleted');
    }

    public function changePassword(array $params): void {
        $body       = Request::body();
        $coachId    = $params['_auth']['sub'];
        $coachIdObj = new ObjectId($coachId);

        if (empty($body['current_password']) || empty($body['new_password'])) {
            Response::error('Current password and new password are required', 422);
        }
        if (strlen($body['new_password']) < 8) {
            Response::error('New password must be at least 8 characters', 422);
        }

        $coach = ScopedCollection::for('coaches')->findOne(
            ['_id' => $coachIdObj],
            ['projection' => ['password_hash' => 1]]
        );

        if (!$coach || !password_verify($body['current_password'], (string) $coach['password_hash'])) {
            Response::error('Current password is incorrect', 403);
        }

        ScopedCollection::for('coaches')->updateOne(
            ['_id' => $coachIdObj],
            ['$set' => [
                'password_hash' => password_hash($body['new_password'], PASSWORD_BCRYPT, ['cost' => 12]),
                'updated_at'    => new UTCDateTime(),
            ]]
        );

        Response::success(null, 'Password changed successfully');
    }

    private function resolveProfilePhoto(array $data, $record): array
    {
        $data['profile_photo'] = ProfilePhotoService::resolveUrl($record['profile_photo_key'] ?? null) ?? $data['profile_photo'] ?? null;
        return $data;
    }

    private function getClientCount(string $coachId): int {
        return ScopedCollection::for('clients')->countDocuments([
            'coach_id' => new ObjectId($coachId),
            'active'   => true,
        ]);
    }

    private function format($coach): array {
        $tier = $coach['subscription_tier'] ?? 'free';
        return [
            'id'                     => (string) $coach['_id'],
            'name'                   => $coach['name'],
            'surname'                => $coach['surname'] ?? '',
            'email'                  => $coach['email'],
            'phone'                  => $coach['phone'] ?? '',
            'language'               => $coach['language'] ?? 'en',
            'currency'               => $coach['currency'] ?? 'USD',
            'profile_photo'          => $coach['profile_photo'] ?? null,
            'social_media'           => $coach['social_media'] ?? ['linkedin' => '', 'instagram' => '', 'website' => ''],
            'city'                   => $coach['city'] ?? '',
            'timezone'               => $coach['timezone'] ?? 'UTC/GMT +0 hours',
            'date_time_format'       => $coach['date_time_format'] ?? 'dd/mm/yyyy  00:00',
            'daily_time_utilization' => (int)($coach['daily_time_utilization'] ?? 7),
            'core_work_min'          => (int)($coach['core_work_min'] ?? 3),
            'core_work_max'          => (int)($coach['core_work_max'] ?? 6),
            'function'               => $coach['function'] ?? '',
            'job_title'              => $coach['job_title'] ?? '',
            'responsibilities'       => $coach['responsibilities'] ?? '',
            'client_count'           => $coach['client_count'] ?? 0,
            'max_clients'            => \App\Controllers\SubscriptionController::getClientLimit($tier),
            'subscription_tier'      => $tier,
            'subscription_status'    => $coach['subscription_status'] ?? 'none',
            'trial_ends_at'          => isset($coach['trial_ends_at']) ? (string) $coach['trial_ends_at'] : null,
            'created_at'             => isset($coach['created_at']) ? $coach['created_at']->toDateTime()->format('c') : null,
        ];
    }
}
