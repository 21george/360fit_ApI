<?php
namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class CoachController {

    public function me(array $params): void {
        $coach = Database::collection('coaches')->findOne(
            ['_id' => new ObjectId($params['_auth']['sub'])],
            ['projection' => ['password_hash' => 0]]
        );
        if (!$coach) Response::notFound('Coach not found');

        $data = $this->format($coach);

        // Generate fresh photo URL from stored key
        if (!empty($coach['profile_photo_key'])) {
            $key = (string) $coach['profile_photo_key'];
            if (str_starts_with($key, 'local:')) {
                $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
                $data['profile_photo'] = $appUrl . '/' . substr($key, 6);
            } elseif (\App\Services\S3Service::isConfigured()) {
                $data['profile_photo'] = \App\Services\S3Service::getPresignedDownloadUrl($key, 1440);
            }
        }

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
            // Sanitize URLs
            foreach ($update['social_media'] as $key => $val) {
                $update['social_media'][$key] = filter_var(trim((string)$val), FILTER_SANITIZE_URL);
            }
        }

        if (!empty($body['password'])) {
            if (strlen($body['password']) < 8) Response::error('Password must be at least 8 characters', 422);
            $update['password_hash'] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $update['updated_at'] = new UTCDateTime();
        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($params['_auth']['sub'])],
            ['$set' => $update]
        );

        // Return the updated coach so the frontend can refresh
        $updated = Database::collection('coaches')->findOne(
            ['_id' => new ObjectId($params['_auth']['sub'])],
            ['projection' => ['password_hash' => 0]]
        );
        $data = $this->format($updated);
        if (!empty($updated['profile_photo_key'])) {
            $key = (string) $updated['profile_photo_key'];
            if (str_starts_with($key, 'local:')) {
                $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
                $data['profile_photo'] = $appUrl . '/' . substr($key, 6);
            } elseif (\App\Services\S3Service::isConfigured()) {
                $data['profile_photo'] = \App\Services\S3Service::getPresignedDownloadUrl($key, 1440);
            }
        }
        Response::success($data, 'Profile updated');
    }

    public function uploadPhoto(array $params): void {
        $coachId = $params['_auth']['sub'];
        $ext     = (string) Request::get('ext', 'jpg');
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array(strtolower($ext), $allowed)) {
            Response::error('Invalid file type. Allowed: jpg, jpeg, png, webp', 422);
        }

        // Delete old photo first
        $coach = Database::collection('coaches')->findOne(
            ['_id' => new ObjectId($coachId)],
            ['projection' => ['profile_photo_key' => 1]]
        );
        if (!empty($coach['profile_photo_key'])) {
            $oldKey = (string) $coach['profile_photo_key'];
            if (str_starts_with($oldKey, 'local:')) {
                $oldPath = BASE_PATH . '/public/' . substr($oldKey, 6);
                if (is_file($oldPath)) @unlink($oldPath);
            } elseif (\App\Services\S3Service::isConfigured()) {
                \App\Services\S3Service::delete($oldKey);
            }
        }

        $uuid = bin2hex(random_bytes(8));

        if (\App\Services\S3Service::isConfigured()) {
            // --- S3 mode: generate presigned URLs ---
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $mime    = $mimeMap[strtolower($ext)] ?? 'image/jpeg';
            $key     = "coaches/{$coachId}/profile/{$uuid}.{$ext}";

            $uploadUrl = \App\Services\S3Service::getPresignedUploadUrl($key, $mime);
            $readUrl   = \App\Services\S3Service::getPresignedDownloadUrl($key, 1440);

            Database::collection('coaches')->updateOne(
                ['_id' => new ObjectId($coachId)],
                ['$set' => [
                    'profile_photo'     => $readUrl,
                    'profile_photo_key' => $key,
                    'updated_at'        => new UTCDateTime(),
                ]]
            );

            Response::success([
                'upload_url'    => $uploadUrl,
                'profile_photo' => $readUrl,
                'key'           => $key,
            ], 'Upload URL generated');
        } else {
            // --- Local mode: save uploaded file directly ---
            if (empty($_FILES['photo']['tmp_name'])) {
                Response::error('No photo file provided', 422);
            }
            $file    = $_FILES['photo'];
            $relPath = "uploads/coaches/{$coachId}";
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

            Database::collection('coaches')->updateOne(
                ['_id' => new ObjectId($coachId)],
                ['$set' => [
                    'profile_photo'     => $photoUrl,
                    'profile_photo_key' => $localKey,
                    'updated_at'        => new UTCDateTime(),
                ]]
            );

            Response::success([
                'profile_photo' => $photoUrl,
            ], 'Photo uploaded');
        }
    }

    public function deletePhoto(array $params): void {
        $coachId = $params['_auth']['sub'];
        $coach   = Database::collection('coaches')->findOne(
            ['_id' => new ObjectId($coachId)],
            ['projection' => ['profile_photo_key' => 1]]
        );

        if (!empty($coach['profile_photo_key'])) {
            $key = (string) $coach['profile_photo_key'];
            if (str_starts_with($key, 'local:')) {
                $path = BASE_PATH . '/public/' . substr($key, 6);
                if (is_file($path)) @unlink($path);
            } elseif (\App\Services\S3Service::isConfigured()) {
                \App\Services\S3Service::delete($key);
            }
        }

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => ['profile_photo' => null, 'profile_photo_key' => null, 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Profile photo deleted');
    }

    public function changePassword(array $params): void {
        $body    = Request::body();
        $coachId = $params['_auth']['sub'];

        if (empty($body['current_password']) || empty($body['new_password'])) {
            Response::error('Current password and new password are required', 422);
        }
        if (strlen($body['new_password']) < 8) {
            Response::error('New password must be at least 8 characters', 422);
        }

        $coach = Database::collection('coaches')->findOne(
            ['_id' => new ObjectId($coachId)],
            ['projection' => ['password_hash' => 1]]
        );

        if (!$coach || !password_verify($body['current_password'], (string) $coach['password_hash'])) {
            Response::error('Current password is incorrect', 403);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => [
                'password_hash' => password_hash($body['new_password'], PASSWORD_BCRYPT, ['cost' => 12]),
                'updated_at'    => new UTCDateTime(),
            ]]
        );

        Response::success(null, 'Password changed successfully');
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
