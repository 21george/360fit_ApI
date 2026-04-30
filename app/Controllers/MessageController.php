<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{FcmService, NotificationTriggerService};
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class MessageController
{
    private const TYPING_TTL_MS = 8000;

    // GET /messages/:clientId (coach)
    public function coachThread(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = new ObjectId($params['clientId']);
        $this->verifyClientOwnership($coachId, $clientId);
        $this->getThread($clientId, $coachId);
    }

    // POST /messages (coach or client)
    public function send(array $params): void
    {
        $auth   = $params['_auth'];
        $body   = Request::body();

        $hasContent = !empty(trim($body['content'] ?? ''));
        $hasMedia   = !empty($body['media_url']) && !empty($body['media_type']);

        if (!$hasContent && !$hasMedia) {
            Response::error('Message must contain text or media', 422);
        }

        // Validate media_type if provided
        if ($hasMedia) {
            $allowedTypes = ['image', 'file'];
            if (!in_array($body['media_type'], $allowedTypes)) {
                Response::error('Invalid media type. Allowed: image, file', 422);
            }
            $url = filter_var(trim($body['media_url']), FILTER_VALIDATE_URL);
            if ($url === false) {
                Response::error('Invalid media_url', 422);
            }
            $parsed = parse_url($url);
            if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
                Response::error('media_url must use http:// or https://', 422);
            }
            $body['media_url'] = $url;
        }

        if ($auth['role'] === 'coach') {
            $coachId  = new ObjectId($auth['sub']);
            $clientId = new ObjectId($body['client_id'] ?? '');
            $this->verifyClientOwnership($coachId, $clientId);
            $senderId   = $coachId;
            $senderRole = 'coach';

            // Fetch client now; notification will be sent after insert so we have the messageId
            $client = Database::collection('clients')->findOne(['_id' => $clientId]);
        } else {
            $clientId = new ObjectId($auth['sub']);
            $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
            if (!$client) Response::error('Client not found', 404);
            $coachId    = $client['coach_id'];
            $senderId   = $clientId;
            $senderRole = 'client';
        }

        $doc = [
            'coach_id'    => $coachId,
            'client_id'   => $clientId,
            'sender_id'   => $senderId,
            'sender_role' => $senderRole,
            'content'     => $hasContent ? trim($body['content']) : '',
            'read'        => false,
            'sent_at'     => new \MongoDB\BSON\UTCDateTime(),
        ];

        if ($hasMedia) {
            $doc['media_url']      = $body['media_url'];
            $doc['media_type']     = $body['media_type'];
            $doc['media_filename'] = $body['media_filename'] ?? null;
        }

        $result = Database::collection('messages')->insertOne($doc);
        $messageId = (string) $result->getInsertedId();

        // Notify coach when client sends a message (after insert so we have the real messageId)
        if ($senderRole === 'client') {
            try {
                $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
                if ($coach) {
                    $triggerService = new NotificationTriggerService();
                    $messagePreview = substr(trim($body['content'] ?? ''), 0, 50);
                    if (empty($messagePreview)) {
                        $messagePreview = 'sent a ' . ($body['media_type'] ?? 'file');
                    }
                    $triggerService->notifyNewClientMessage(
                        (string) $clientId,
                        $client['name'] ?? 'A client',
                        $messagePreview,
                        $messageId
                    );
                }
            } catch (\Throwable $e) {
                // Log error but don't fail the message send
                error_log('Failed to send notification for client message: ' . $e->getMessage());
            }
        }

        // Notify client when coach sends a message
        if ($senderRole === 'coach') {
            try {
                if ($client) {
                    $triggerService = new NotificationTriggerService();
                    $coachName = trim(($auth['name'] ?? '') ?: 'Your coach');
                    $triggerService->notifyClientNewMessage(
                        $coachName,
                        (string) $coachId,
                        (string) $clientId,
                        $messageId
                    );
                }
            } catch (\Throwable $e) {
                error_log('Failed to send notification for coach message: ' . $e->getMessage());
            }
        }

        $this->setTypingState($clientId, $coachId, $senderRole, false);

        $response = [
            'id'          => (string) $result->getInsertedId(),
            'content'     => $doc['content'],
            'sender_role' => $senderRole,
            'sent_at'     => date('c'),
        ];
        if ($hasMedia) {
            $response['media_url']      = $doc['media_url'];
            $response['media_type']     = $doc['media_type'];
            $response['media_filename'] = $doc['media_filename'] ?? null;
        }

        Response::success($response, 'Message sent', 201);
    }

    // GET /messages/:clientId/typing (coach)
    public function coachTypingStatus(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = new ObjectId($params['clientId']);
        $this->verifyClientOwnership($coachId, $clientId);

        Response::success([
            'is_typing' => $this->isRoleTyping($clientId, $coachId, 'client'),
        ]);
    }

    // POST /messages/:clientId/typing (coach)
    public function updateCoachTyping(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = new ObjectId($params['clientId']);
        $this->verifyClientOwnership($coachId, $clientId);

        $body = Request::body();
        $this->setTypingState($clientId, $coachId, 'coach', (bool) ($body['is_typing'] ?? false));

        Response::success(['is_typing' => (bool) ($body['is_typing'] ?? false)]);
    }

    // GET /client/messages (client)
    public function clientThread(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::error('Client not found', 404);
        if (!isset($client['coach_id'])) Response::error('Coach not found', 404);

        // Mark coach messages as read BEFORE sending the response (getThread calls exit())
        Database::collection('messages')->updateMany(
            ['client_id' => $clientId, 'sender_role' => 'coach', 'read' => false],
            ['$set' => ['read' => true]]
        );

        $this->getThread($clientId, $client['coach_id']);
    }

    // GET /client/messages/typing (client)
    public function clientTypingStatus(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::error('Client not found', 404);
        if (!isset($client['coach_id'])) Response::error('Coach not found', 404);

        Response::success([
            'is_typing' => $this->isRoleTyping($clientId, $client['coach_id'], 'coach'),
        ]);
    }

    // POST /client/messages/typing (client)
    public function updateClientTyping(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::error('Client not found', 404);
        if (!isset($client['coach_id'])) Response::error('Coach not found', 404);

        $body = Request::body();
        $this->setTypingState($clientId, $client['coach_id'], 'client', (bool) ($body['is_typing'] ?? false));

        Response::success(['is_typing' => (bool) ($body['is_typing'] ?? false)]);
    }

    private function getThread(ObjectId $clientId, mixed $coachId): void
    {
        $page    = max(1, (int) Request::get('page', 1));
        $perPage = 50;
        $col     = Database::collection('messages');
        $filter  = ['client_id' => $clientId, 'coach_id' => $this->buildCoachIdFilter($coachId)];
        $total   = $col->countDocuments($filter);
        $msgs    = $col->find($filter, [
            'sort'  => ['sent_at' => -1],
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
        ])->toArray();

        $result = array_map(function($m) {
            $msg = [
                'id'          => (string) $m['_id'],
                'content'     => $m['content'] ?? '',
                'sender_role' => $m['sender_role'],
                'read'        => $m['read'],
                'sent_at'     => $this->formatDateValue($m['sent_at'] ?? null),
            ];
            if (!empty($m['media_url'])) {
                $msg['media_url']      = $m['media_url'];
                $msg['media_type']     = $m['media_type'] ?? 'file';
                $msg['media_filename'] = $m['media_filename'] ?? null;
            }
            return $msg;
        }, $msgs);

        Response::paginated(array_reverse($result), $total, $page, $perPage);
    }

    private function buildCoachIdFilter(mixed $coachId): mixed
    {
        $variants = [];

        if ($coachId instanceof ObjectId) {
            $variants[] = $coachId;
            $variants[] = (string) $coachId;
        } elseif (is_string($coachId) && $coachId !== '') {
            $variants[] = $coachId;
            if (preg_match('/^[a-f0-9]{24}$/', $coachId)) {
                $variants[] = new ObjectId($coachId);
            }
        }

        $variants = array_values(array_unique($variants, SORT_REGULAR));

        if (count($variants) === 0) {
            Response::error('Coach not found', 404);
        }

        if (count($variants) === 1) {
            return $variants[0];
        }

        return ['$in' => $variants];
    }

    private function setTypingState(ObjectId $clientId, mixed $coachId, string $role, bool $isTyping): void
    {
        Database::collection('message_typing_status')->updateOne(
            [
                'client_id' => $clientId,
                'coach_id'  => $this->buildCoachIdFilter($coachId),
                'role'      => $role,
            ],
            ['$set' => [
                'client_id'  => $clientId,
                'coach_id'   => $coachId,
                'role'       => $role,
                'is_typing'  => $isTyping,
                'updated_at' => new UTCDateTime(),
            ]],
            ['upsert' => true]
        );
    }

    private function isRoleTyping(ObjectId $clientId, mixed $coachId, string $role): bool
    {
        $status = Database::collection('message_typing_status')->findOne([
            'client_id'  => $clientId,
            'coach_id'   => $this->buildCoachIdFilter($coachId),
            'role'       => $role,
            'is_typing'  => true,
            'updated_at' => ['$gte' => new UTCDateTime((int) (microtime(true) * 1000) - self::TYPING_TTL_MS)],
        ]);

        return $status !== null;
    }

    private function formatDateValue(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_numeric($value)) {
            return date(DATE_ATOM, (int) $value);
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            return $timestamp === false ? $value : date(DATE_ATOM, $timestamp);
        }

        return null;
    }

    private function verifyClientOwnership(ObjectId $coachId, ObjectId $clientId): void
    {
        $client = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);
    }

    // POST /messages/upload-media or /client/messages/upload-media
    public function uploadMedia(array $params): void
    {
        $auth = $params['_auth'];

        if (empty($_FILES['file']['tmp_name'])) {
            Response::error('No file provided', 422);
        }

        $file = $_FILES['file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $fileExts  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
        $allowed   = array_merge($imageExts, $fileExts);

        if (!in_array($ext, $allowed)) {
            Response::error('File type not allowed', 422);
        }

        // 10 MB limit
        if ($file['size'] > 10 * 1024 * 1024) {
            Response::error('File too large. Max 10MB', 422);
        }

        $mediaType = in_array($ext, $imageExts) ? 'image' : 'file';
        $uuid      = bin2hex(random_bytes(8));
        $senderId  = $auth['sub'];

        if (\App\Services\S3Service::isConfigured()) {
            $mimeMap = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
                'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'csv' => 'text/csv', 'txt' => 'text/plain', 'zip' => 'application/zip',
            ];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $key  = "messages/{$senderId}/{$uuid}.{$ext}";

            $uploadUrl = \App\Services\S3Service::getPresignedUploadUrl($key, $mime);
            $url       = \App\Services\S3Service::getPresignedDownloadUrl($key, 1440);

            Response::success([
                'upload_url'     => $uploadUrl,
                'media_url'      => $url,
                'media_type'     => $mediaType,
                'media_filename' => $file['name'],
            ], 'Upload URL generated');
        } else {
            $relPath = "uploads/messages/{$senderId}";
            $dir     = BASE_PATH . '/public/' . $relPath;
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $filename = "{$uuid}.{$ext}";
            $dest     = "{$dir}/{$filename}";

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                Response::error('Failed to save file', 500);
            }

            $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
            $url    = "{$appUrl}/{$relPath}/{$filename}";
        }

        Response::success([
            'media_url'      => $url,
            'media_type'     => $mediaType,
            'media_filename' => $file['name'],
        ], 'File uploaded');
    }
}
