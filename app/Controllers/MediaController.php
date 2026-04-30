<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\S3Service;
use MongoDB\BSON\ObjectId;

class MediaController
{
    // POST /media/presigned-url
    public function presignedUrl(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $errors   = Request::validate($body, ['filename' => 'required', 'content_type' => 'required', 'type' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $allowed = ['video/mp4', 'video/quicktime', 'image/jpeg', 'image/png', 'image/heic'];
        if (!in_array($body['content_type'], $allowed)) {
            Response::error('Unsupported media type', 422);
        }

        if (S3Service::isConfigured()) {
            $key = S3Service::buildKey((string)$clientId, $body['type'], $body['filename']);
            $url = S3Service::getPresignedUploadUrl($key, $body['content_type']);
            Response::success(['upload_url' => $url, 's3_key' => $key, 'mode' => 's3']);
        } else {
            // Local mode – client will POST the file to /client/media/upload
            Response::success(['upload_url' => null, 's3_key' => null, 'mode' => 'local']);
        }
    }

    private const ALLOWED_UPLOAD_TYPES = ['photo', 'video', 'document'];

    // POST /client/media/upload  (local file upload fallback)
    public function uploadFile(array $params): void
    {
        $clientId = (string) $params['_auth']['sub'];

        if (empty($_FILES['file']['tmp_name'])) {
            Response::error('No file provided', 422);
        }

        $file = $_FILES['file'];
        $type = $_GET['type'] ?? $_POST['type'] ?? 'photo';
        // Prevent path traversal: whitelist and sanitize the type parameter
        if (!is_string($type) || !in_array($type, self::ALLOWED_UPLOAD_TYPES, true)) {
            Response::error('Invalid upload type', 422);
        }
        $type = preg_replace('/[^a-z0-9_-]/i', '', $type);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'heic', 'mp4', 'mov'];
        if (!in_array($ext, $allowedExts)) {
            Response::error('File type not allowed', 422);
        }

        // Validate MIME type from file content, not just extension
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $imageMimes = ['image/jpeg', 'image/png', 'image/heic'];
        $videoMimes = ['video/mp4', 'video/quicktime'];
        if (!in_array($mime, array_merge($imageMimes, $videoMimes), true)) {
            Response::error('Invalid file content', 422);
        }

        // 50 MB limit for videos
        $maxSize = in_array($ext, ['mp4', 'mov']) ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            Response::error('File too large', 422);
        }

        $uuid    = bin2hex(random_bytes(8));
        $relPath = "uploads/clients/{$clientId}/{$type}";
        $dir     = BASE_PATH . '/public/' . $relPath;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = "{$uuid}.{$ext}";
        $dest     = "{$dir}/{$filename}";

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('Failed to save file', 500);
        }

        $appUrl   = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
        $mediaUrl = "{$appUrl}/{$relPath}/{$filename}";
        $localKey = "local:{$relPath}/{$filename}";

        Response::success([
            'media_url' => $mediaUrl,
            's3_key'    => $localKey,
            'type'      => $type,
        ], 'File uploaded');
    }

    // GET /coach/media/:clientId (coach)
    public function clientMedia(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = new ObjectId($params['clientId']);

        // Verify ownership
        $client = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        $type   = Request::get('type');
        $filter = ['client_id' => $clientId];
        if (is_string($type) && $type !== '') $filter['type'] = $type;

        $media = Database::collection('media_uploads')->find($filter, [
            'sort' => ['uploaded_at' => -1],
        ])->toArray();

        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
        $result = array_map(function($m) use ($appUrl) {
            $key = (string) ($m['s3_key'] ?? '');
            if (str_starts_with($key, 'local:')) {
                $url = "{$appUrl}/" . substr($key, 6);
            } elseif (S3Service::isConfigured()) {
                $url = S3Service::getPresignedDownloadUrl($key);
            } else {
                $url = $m['url'] ?? null;
            }
            return [
                'id'          => (string) $m['_id'],
                'type'        => $m['type'],
                's3_key'      => $key,
                'url'         => $url,
                'log_id'      => isset($m['log_id']) ? (string) $m['log_id'] : null,
                'uploaded_at' => $m['uploaded_at'] ? date('c', (int)((int)((string)$m['uploaded_at']) / 1000)) : null,
            ];
        }, $media);

        Response::success($result);
    }

    // GET /client/media (client - their own)
    public function myMedia(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $media    = Database::collection('media_uploads')->find(['client_id' => $clientId], [
            'sort' => ['uploaded_at' => -1],
        ])->toArray();

        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
        $result = array_map(function($m) use ($appUrl) {
            $key = (string) ($m['s3_key'] ?? '');
            if (str_starts_with($key, 'local:')) {
                $url = "{$appUrl}/" . substr($key, 6);
            } elseif (S3Service::isConfigured()) {
                $url = S3Service::getPresignedDownloadUrl($key);
            } else {
                $url = $m['url'] ?? null;
            }
            return [
                'id'          => (string) $m['_id'],
                'type'        => $m['type'],
                'url'         => $url,
                'uploaded_at' => $m['uploaded_at'] ? date('c', (int)((int)((string)$m['uploaded_at']) / 1000)) : null,
            ];
        }, $media);

        Response::success($result);
    }

    // POST /coach/clients/:id/measurements
    public function storeMeasurement(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        $body    = Request::body();
        $result  = Database::collection('body_measurements')->insertOne([
            'client_id'   => $clientId,
            'coach_id'    => $coachId,
            'weight_kg'   => (float) ($body['weight_kg'] ?? 0),
            'chest_cm'    => (float) ($body['chest_cm'] ?? 0),
            'waist_cm'    => (float) ($body['waist_cm'] ?? 0),
            'hips_cm'     => (float) ($body['hips_cm'] ?? 0),
            'arms_cm'     => (float) ($body['arms_cm'] ?? 0),
            'legs_cm'     => (float) ($body['legs_cm'] ?? 0),
            'body_fat_pct'=> (float) ($body['body_fat_pct'] ?? 0),
            'notes'       => $body['notes'] ?? null,
            'recorded_at' => new \MongoDB\BSON\UTCDateTime(),
        ]);

        Response::success(['id' => (string) $result->getInsertedId()], 'Measurement recorded', 201);
    }
}
