<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response, ScopedCollection};
use App\Services\ProfilePhotoService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Progress Photo Controller
 *
 * Coaches and clients can upload progress photos (front, side, back)
 * with before/after labels for visual comparison.
 */
class ProgressPhotoController
{
    private const ALLOWED_TYPES = ['front', 'side', 'back'];
    private const ALLOWED_LABELS = ['before', 'after', 'progress'];

    /**
     * POST /clients/:id/progress-photos
     *
     * Upload a progress photo for a client.
     * Body: { type: 'front'|'side'|'back', label: 'before'|'after'|'progress', photo_url?, photo_key? }
     * Or multipart with file upload for local mode.
     */
    public function store(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $body = Request::body();
        $type  = strtolower(trim((string) ($body['type'] ?? '')));
        $label = strtolower(trim((string) ($body['label'] ?? 'progress')));

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            Response::error('Invalid photo type. Use front, side, or back', 422);
        }
        if (!in_array($label, self::ALLOWED_LABELS, true)) {
            Response::error('Invalid label. Use before, after, or progress', 422);
        }

        // Handle presigned URL mode (frontend already uploaded to S3/R2)
        $photoUrl = trim((string) ($body['photo_url'] ?? ''));
        $photoKey = trim((string) ($body['photo_key'] ?? ''));

        // Handle local file upload mode
        if (empty($photoUrl) && !empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!ProfilePhotoService::validateExt($ext)) {
                Response::error('Invalid image type. Use jpg, png, or webp', 422);
            }
            try {
                $result = ProfilePhotoService::upload((string) $clientId, 'progress-photos', $ext);
                $photoUrl = $result['url'];
                $photoKey = $result['key'];
            } catch (\RuntimeException $e) {
                Response::error('Failed to upload photo: ' . $e->getMessage(), 500);
            }
        }

        if (empty($photoUrl)) {
            Response::error('Photo URL or file is required', 422);
        }

        $insert = Database::collection('progress_photos')->insertOne([
            'client_id'  => $clientId,
            'coach_id'   => $coachId,
            'type'       => $type,
            'label'      => $label,
            'photo_url'  => $photoUrl,
            'photo_key'  => $photoKey ?: null,
            'taken_at'   => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ]);

        Response::success([
            'id'        => (string) $insert->getInsertedId(),
            'photo_url' => $photoUrl,
            'type'      => $type,
            'label'     => $label,
        ], 'Photo uploaded', 201);
    }

    /**
     * GET /clients/:id/progress-photos
     *
     * List progress photos for a client, optionally filtered by type or label.
     */
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id' => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $filter = ['client_id' => $clientId];

        $type  = Request::get('type');
        $label = Request::get('label');
        if (is_string($type) && $type !== '') {
            $filter['type'] = strtolower($type);
        }
        if (is_string($label) && $label !== '') {
            $filter['label'] = strtolower($label);
        }

        $photos = Database::collection('progress_photos')
            ->find($filter, ['sort' => ['taken_at' => -1]])
            ->toArray();

        Response::success(array_map(fn($p) => [
            'id'        => (string) $p['_id'],
            'type'      => $p['type'],
            'label'     => $p['label'],
            'photo_url' => $p['photo_url'],
            'taken_at'  => isset($p['taken_at']) ? (string) $p['taken_at'] : null,
        ], $photos));
    }

    /**
     * DELETE /clients/:id/progress-photos/:photoId
     */
    public function destroy(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);
        $photoId  = new ObjectId($params['photoId']);

        $photo = Database::collection('progress_photos')->findOne([
            '_id'       => $photoId,
            'client_id' => $clientId,
            'coach_id'  => $coachId,
        ]);
        if (!$photo) Response::error('Photo not found', 404);

        // Delete from storage if key exists
        if (!empty($photo['photo_key'])) {
            ProfilePhotoService::deleteByKey($photo['photo_key']);
        }

        Database::collection('progress_photos')->deleteOne(['_id' => $photoId]);
        Response::success(null, 'Photo deleted');
    }

    /**
     * POST /client/progress-photos
     *
     * Client uploads their own progress photo.
     */
    public function clientStore(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::error('Client not found', 404);

        $body  = Request::body();
        $type  = strtolower(trim((string) ($body['type'] ?? '')));
        $label = strtolower(trim((string) ($body['label'] ?? 'progress')));

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            Response::error('Invalid photo type. Use front, side, or back', 422);
        }
        if (!in_array($label, self::ALLOWED_LABELS, true)) {
            Response::error('Invalid label. Use before, after, or progress', 422);
        }

        $photoUrl = trim((string) ($body['photo_url'] ?? ''));
        $photoKey = trim((string) ($body['photo_key'] ?? ''));

        if (empty($photoUrl) && !empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!ProfilePhotoService::validateExt($ext)) {
                Response::error('Invalid image type. Use jpg, png, or webp', 422);
            }
            try {
                $result   = ProfilePhotoService::upload((string) $clientId, 'progress-photos', $ext);
                $photoUrl = $result['url'];
                $photoKey = $result['key'];
            } catch (\RuntimeException $e) {
                Response::error('Failed to upload photo: ' . $e->getMessage(), 500);
            }
        }

        if (empty($photoUrl)) {
            Response::error('Photo URL or file is required', 422);
        }

        $insert = Database::collection('progress_photos')->insertOne([
            'client_id'  => $clientId,
            'coach_id'   => $client['coach_id'] ?? null,
            'type'       => $type,
            'label'      => $label,
            'photo_url'  => $photoUrl,
            'photo_key'  => $photoKey ?: null,
            'taken_at'   => new UTCDateTime(),
            'created_at' => new UTCDateTime(),
        ]);

        Response::success([
            'id'        => (string) $insert->getInsertedId(),
            'photo_url' => $photoUrl,
            'type'      => $type,
            'label'     => $label,
        ], 'Photo uploaded', 201);
    }

    /**
     * GET /client/progress-photos
     *
     * Client views their own progress photos.
     */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $filter = ['client_id' => $clientId];

        $type  = Request::get('type');
        $label = Request::get('label');
        if (is_string($type) && $type !== '') {
            $filter['type'] = strtolower($type);
        }
        if (is_string($label) && $label !== '') {
            $filter['label'] = strtolower($label);
        }

        $photos = Database::collection('progress_photos')
            ->find($filter, ['sort' => ['taken_at' => -1]])
            ->toArray();

        Response::success(array_map(fn($p) => [
            'id'        => (string) $p['_id'],
            'type'      => $p['type'],
            'label'     => $p['label'],
            'photo_url' => $p['photo_url'],
            'taken_at'  => isset($p['taken_at']) ? (string) $p['taken_at'] : null,
        ], $photos));
    }

    /**
     * DELETE /client/progress-photos/:photoId
     */
    public function clientDestroy(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $photoId  = new ObjectId($params['photoId']);

        $photo = Database::collection('progress_photos')->findOne([
            '_id'       => $photoId,
            'client_id' => $clientId,
        ]);
        if (!$photo) Response::error('Photo not found', 404);

        if (!empty($photo['photo_key'])) {
            ProfilePhotoService::deleteByKey($photo['photo_key']);
        }

        Database::collection('progress_photos')->deleteOne(['_id' => $photoId]);
        Response::success(null, 'Photo deleted');
    }
}
