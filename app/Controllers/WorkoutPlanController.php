<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{ExcelImportService, FcmService, NotificationTriggerService};
use MongoDB\BSON\ObjectId;

class WorkoutPlanController
{
    // GET /workout-plans
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $filter   = ['coach_id' => $coachId];
        $clientId = Request::get('client_id');

        // NoSQL injection fix: validate GET params are scalar strings
        $status   = Request::get('status');
        if (is_string($status) && $status !== '') {
            $filter['status'] = $status;
        }
        $week = Request::get('week_start');
        if (is_string($week) && $week !== '') {
            $filter['week_start'] = new \MongoDB\BSON\UTCDateTime(strtotime($week) * 1000);
        }

        $planType = Request::get('plan_type');
        if (is_string($planType) && $planType !== '') {
            $filter['plan_type'] = $planType;
        }

        $isClient = false;
        if (is_string($clientId) && $clientId !== '' && preg_match('/^[a-f0-9]{24}$/', $clientId)) {
            $isClient = true;
            // Verify the client belongs to this coach before showing their plans
            $clientObjId = new ObjectId($clientId);
            $client = Database::collection('clients')->findOne([
                '_id'      => $clientObjId,
                'coach_id' => $coachId,
            ]);
            if (!$client) Response::error('Client not found', 404);
        }

        $page    = max(1, (int) Request::get('page', 1));
        $perPage = 20;
        $col     = Database::collection('workout_plans');

        if ($isClient) {
            // Show plans where client is assigned (individual or group)
            $clientObjId = new ObjectId($clientId);
            $filter = ['coach_id' => $coachId];
            $filter['$or'] = [
                ['client_id' => $clientObjId],
                ['client_ids' => ['$in' => [$clientObjId]]]
            ];
            if (is_string($status) && $status !== '') {
                $filter['status'] = $status;
            }
            if (is_string($week) && $week !== '') {
                $filter['week_start'] = new \MongoDB\BSON\UTCDateTime(strtotime($week) * 1000);
            }
            if (is_string($planType) && $planType !== '') {
                $filter['plan_type'] = $planType;
            }
        }

        $total   = $col->countDocuments($filter);
        $docs    = $col->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['week_start' => -1],
        ]);

        $plans = [];
        foreach ($docs as $doc) {
            $plans[] = $this->format($doc);
        }

        Response::paginated($plans, $total, $page, $perPage);
    }

    // POST /workout-plans
    public function store(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $planType = $body['plan_type'] ?? 'individual';

        $errors = Request::validate($body, [
            'title'      => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        // If no week_start provided, default to next Monday
        $weekStart = $body['week_start'] ?? null;
        if (!$weekStart) {
            $weekStart = date('Y-m-d', strtotime('next monday'));
        }

        $doc = [
            'coach_id'            => $coachId,
            'plan_type'           => $planType,
            'title'               => $body['title'],
            'week_start'          => new \MongoDB\BSON\UTCDateTime(strtotime($weekStart) * 1000),
            'status'              => $body['status'] ?? 'draft',
            'days'                => $body['days'] ?? [],
            'notes'               => $body['notes'] ?? null,
            'imported_from_excel' => false,
            'created_at'          => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
        ];
        $clientIds = is_array($body['client_ids'] ?? null) ? array_values($body['client_ids']) : [];

        // Client assignment is optional — plan can be saved without assignment
        if ($planType === 'individual' && !empty($body['client_id'])) {
            $clientId = new ObjectId($body['client_id']);
            $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
            if (!$client) Response::error('Client not found', 404);
            $doc['client_id'] = $clientId;
        } elseif (in_array($planType, ['group', 'team'])) {
            if (!empty($body['group_name'])) {
                $doc['group_name'] = $body['group_name'];
            }
            if (!empty($clientIds)) {
                $clientIds = array_map(fn($id) => new ObjectId($id), $clientIds);
                $doc['client_ids'] = $clientIds;
            }
        }

        $result    = Database::collection('workout_plans')->insertOne($doc);
        $planId    = (string) $result->getInsertedId();
        $planTitle = $doc['title'];

        // Notify assigned clients (in-app + push)
        $triggerService = new NotificationTriggerService();
        
        if ($planType === 'individual' && isset($client)) {
            try {
                $triggerService->notifyClientNewPlan($planTitle, $planId, (string) $doc['client_id']);
            } catch (\Throwable $e) {
                error_log('Failed to send new plan notification to individual client: ' . $e->getMessage());
            }
        } elseif (in_array($planType, ['group', 'team']) && !empty($clientIds)) {
            $assignedClients = Database::collection('clients')->find([
                '_id'      => ['$in' => $clientIds],
                'coach_id' => $coachId,
            ]);
            foreach ($assignedClients as $c) {
                try {
                    $triggerService->notifyClientNewPlan($planTitle, $planId, (string) $c['_id']);
                } catch (\Throwable $e) {
                    error_log('Failed to send new plan notification to client ' . (string) $c['_id'] . ': ' . $e->getMessage());
                }
            }
        }

        Response::success(['id' => $planId], 'Workout plan created', 201);
    }

    // GET /workout-plans/:id
    public function show(array $params): void
    {
        $plan = $this->findPlan($params);
        Response::success($this->format($plan, true));
    }

    // PUT /workout-plans/:id
    public function update(array $params): void
    {
        $plan    = $this->findPlan($params);
        $body    = Request::body();
        $allowed = ['title', 'status', 'days', 'notes', 'week_start', 'plan_type', 'group_name', 'client_ids'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (isset($body[$field])) {
                if ($field === 'week_start') {
                    $set[$field] = new \MongoDB\BSON\UTCDateTime(strtotime($body[$field]) * 1000);
                } elseif ($field === 'client_ids' && is_array($body[$field])) {
                    $set[$field] = array_map(fn($id) => new ObjectId($id), $body[$field]);
                } else {
                    $set[$field] = $body[$field];
                }
            }
        }

        Database::collection('workout_plans')->updateOne(['_id' => $plan['_id']], ['$set' => $set]);

        // Fetch the updated plan to ensure notifications use the latest data
        $updatedPlan = Database::collection('workout_plans')->findOne(['_id' => $plan['_id']]);

        // Notify assigned client(s) that the plan was updated
        try {
            $triggerService = new NotificationTriggerService();
            $planTitle      = $updatedPlan['title'] ?? 'Your plan';
            $planIdStr      = (string) $plan['_id'];

            if (!empty($updatedPlan['client_id'])) {
                $triggerService->notifyClientPlanUpdated($planTitle, $planIdStr, (string) $updatedPlan['client_id']);
            }
            if (!empty($updatedPlan['client_ids'])) {
                foreach ($updatedPlan['client_ids'] as $cid) {
                    $triggerService->notifyClientPlanUpdated($planTitle, $planIdStr, (string) $cid);
                }
            }
        } catch (\Throwable $e) {
            error_log('Failed to send plan-updated notification: ' . $e->getMessage());
        }

        Response::success(null, 'Plan updated');
    }

    // DELETE /workout-plans/:id
    public function destroy(array $params): void
    {
        $plan = $this->findPlan($params);
        Database::collection('workout_plans')->deleteOne(['_id' => $plan['_id']]);
        Response::success(null, 'Plan deleted');
    }

    // POST /workout-plans/:id/assign - Assign plan to client(s)
    public function assign(array $params): void
    {
        try {
            $coachId = new ObjectId($params['_auth']['sub']);
            $planId  = new ObjectId($params['id']);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            Response::error('Invalid plan ID', 400);
            return;
        }

        $body = Request::body();

        // Find plan
        $plan = Database::collection('workout_plans')->findOne([
            '_id'      => $planId,
            'coach_id' => $coachId,
        ]);
        if (!$plan) {
            Response::error('Plan not found or access denied', 404);
            return;
        }

        // Accept client_ids (array) or client_id (single)
        $clientIds = $body['client_ids'] ?? ($body['client_id'] ?? null);
        if (empty($clientIds)) {
            Response::error('client_ids is required', 422);
            return;
        }

        // Normalize to array
        if (!is_array($clientIds)) {
            $clientIds = [$clientIds];
        }

        // Validate all clients exist and belong to coach
        $clientObjects = [];
        foreach ($clientIds as $id) {
            try {
                $clientId = $id instanceof ObjectId ? $id : new ObjectId((string) $id);
            } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                Response::error('Invalid client ID: ' . (string) $id, 400);
                return;
            }

            $client = Database::collection('clients')->findOne([
                '_id'      => $clientId,
                'coach_id' => $coachId,
            ]);
            if (!$client) {
                Response::error('Client not found: ' . (string) $id, 404);
                return;
            }
            if (!($client['active'] ?? true)) {
                Response::error('Client is inactive: ' . (string) $id, 422);
                return;
            }
            $clientObjects[] = $client;
        }

        // Determine plan type based on number of clients
        $isGroup = count($clientIds) > 1;
        $update = [
            'plan_type' => $isGroup ? 'group' : 'individual',
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        if ($isGroup) {
            // Group plan: set client_ids array
            $update['client_ids'] = array_map(fn($c) => $c['_id'], $clientObjects);
            $update['client_id'] = null;
        } else {
            // Individual plan: set single client_id
            $update['client_id'] = $clientObjects[0]['_id'];
            $update['client_ids'] = [];
        }

        // Clear group_name if present
        if (isset($plan['group_name'])) {
            $update['group_name'] = null;
        }

        Database::collection('workout_plans')->updateOne(
            ['_id' => $planId],
            ['$set' => $update]
        );

        // Notify all assigned clients (in-app + push)
        try {
            $triggerService = new NotificationTriggerService();
            $planTitle = $plan['title'] ?? 'Your plan';
            foreach ($clientObjects as $client) {
                $triggerService->notifyClientNewPlan($planTitle, (string) $planId, (string) $client['_id']);
            }
        } catch (\Throwable $e) {
            error_log('Failed to send assignment notification: ' . $e->getMessage());
        }

        Response::success([
            'client_ids' => array_map(fn($c) => (string) $c['_id'], $clientObjects),
            'plan_type' => $isGroup ? 'group' : 'individual',
        ], 'Plan assigned to ' . count($clientObjects) . ' client(s)');
    }

    // GET /workout-plans/saved — List saved (unassigned/draft) plans
    public function savedPlans(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $page    = max(1, (int) Request::get('page', 1));
        $perPage = 20;

        $col    = Database::collection('workout_plans');
        $filter = [
            'coach_id' => $coachId,
            '$and' => [
                ['$or' => [
                    ['client_id' => null],
                    ['client_id' => ['$exists' => false]],
                ]],
                ['$or' => [
                    ['client_ids' => ['$size' => 0]],
                    ['client_ids' => ['$exists' => false]],
                    ['client_ids' => []],
                ]],
            ],
            'status' => ['$in' => ['draft', 'saved']],
        ];

        $total = $col->countDocuments($filter);
        $docs  = $col->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['created_at' => -1],
        ]);

        $plans = [];
        foreach ($docs as $doc) {
            $plans[] = $this->format($doc);
        }

        Response::paginated($plans, $total, $page, $perPage);
    }

    // POST /workout-plans/import
    public function import(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);

        if (!isset($_FILES['file'])) {
            Response::error('No file uploaded', 400);
            return;
        }

        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
            ];
            $errorCode = $_FILES['file']['error'];
            Response::error($errorMessages[$errorCode] ?? 'File upload failed', 400);
            return;
        }

        $file    = $_FILES['file'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['xlsx', 'xls', 'csv'];

        if (!in_array($ext, $allowed)) {
            Response::error('Invalid file type. Use .xlsx, .xls, or .csv', 422);
            return;
        }

        try {
            $service = new ExcelImportService();
            $result  = $service->import($file['tmp_name'], (string) $coachId);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }

        Response::success([
            'imported'     => $result['plans_created'],
            'plan_ids'     => $result['plan_ids'],
            'warnings'     => $result['warnings'],
            'total_rows'   => $result['total_rows'] ?? 0,
            'processed_rows' => $result['processed_rows'] ?? 0,
            'skipped_rows'  => $result['skipped_rows'] ?? 0,
        ], "{$result['plans_created']} plan(s) imported successfully");
    }

    // POST /workout-plans/import-drive — Import from Google Drive URL
    public function importDrive(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();

        $url = $body['url'] ?? '';
        if (empty($url)) {
            Response::error('Google Drive URL is required', 422);
            return;
        }

        // Extract file ID from various Google Drive URL formats
        $fileId = null;
        // Format: https://drive.google.com/file/d/{FILE_ID}/view
        if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            $fileId = $m[1];
        }
        // Format: https://drive.google.com/open?id={FILE_ID}
        elseif (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m)) {
            $fileId = $m[1];
        }
        // Format: https://drive.google.com/uc?id={FILE_ID}&export=download
        elseif (preg_match('#/uc\?.*id=([a-zA-Z0-9_-]+)#', $url, $m)) {
            $fileId = $m[1];
        }

        if (!$fileId) {
            Response::error('Could not extract file ID from Google Drive URL. Please use a share link like: https://drive.google.com/file/d/FILE_ID/view', 422);
            return;
        }

        // Download the file via Google Drive export URL
        $exportUrl = "https://drive.google.com/uc?export=download&id=" . urlencode($fileId);

        $tempDir = sys_get_temp_dir() . '/coachpro_import';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . '/drive_' . $fileId . '_' . time();

        // Use cURL to download
        $ch = curl_init($exportUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'CoachPro-Import/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            Response::error('Failed to download file from Google Drive. Make sure the file is shared publicly (Anyone with the link).', 422);
            return;
        }

        // Detect content type and determine extension
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
        $ext = match (true) {
            str_contains($contentType, 'spreadsheetml') => 'xlsx',
            str_contains($contentType, 'ms-excel')      => 'xls',
            str_contains($contentType, 'csv')            => 'csv',
            str_contains($contentType, 'sheet')          => 'xlsx',
            default => 'xlsx', // Default to xlsx for Google Sheets exports
        };

        // Check for virus scan warning page (Google shows HTML for large files)
        if (str_contains($response, '<html') || str_contains($response, '<!DOCTYPE')) {
            // Try to extract the confirm link for large files
            if (preg_match('/href="([^"]*confirm[^"]*)"/', $response, $m)) {
                Response::error('This file is too large for direct import. Please download it manually and upload the file instead.', 422);
            } else {
                Response::error('Google Drive returned an unexpected response. Please make sure the file is publicly shared and try again, or download it manually and upload the file.', 422);
            }
            return;
        }

        $tempFile .= '.' . $ext;
        file_put_contents($tempFile, $response);

        // Validate file extension
        $allowed = ['xlsx', 'xls', 'csv'];
        if (!in_array($ext, $allowed)) {
            @unlink($tempFile);
            Response::error('Invalid file type from Google Drive. The file must be an Excel or CSV file.', 422);
            return;
        }

        try {
            $service = new ExcelImportService();
            $result  = $service->import($tempFile, (string) $coachId);
        } catch (\RuntimeException $e) {
            @unlink($tempFile);
            Response::error($e->getMessage(), 422);
            return;
        } finally {
            // Clean up temp file
            @unlink($tempFile);
        }

        Response::success([
            'imported'       => $result['plans_created'],
            'plan_ids'       => $result['plan_ids'],
            'warnings'       => $result['warnings'],
            'total_rows'     => $result['total_rows'] ?? 0,
            'processed_rows' => $result['processed_rows'] ?? 0,
            'skipped_rows'   => $result['skipped_rows'] ?? 0,
        ], "{$result['plans_created']} plan(s) imported successfully");
    }

    private function findPlan(array $params): object
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $planId  = new ObjectId($params['id']);
        $plan    = Database::collection('workout_plans')->findOne([
            '_id'      => $planId,
            'coach_id' => $coachId,
        ]);
        if (!$plan) Response::error('Plan not found', 404);
        return $plan;
    }

    private function format(object $doc, bool $full = false): array
    {
        $clientIds = [];
        if (!empty($doc['client_ids'])) {
            foreach ($doc['client_ids'] as $cid) {
                $clientIds[] = (string) $cid;
            }
        }

        // Resolve assigned client info
        $assignedClient = null;
        if (!empty($doc['client_id'])) {
            $client = Database::collection('clients')->findOne(
                ['_id' => $doc['client_id']],
                ['projection' => ['name' => 1, 'profile_photo_url' => 1]]
            );
            if ($client) {
                $assignedClient = [
                    'id'                => (string) $client['_id'],
                    'name'              => $client['name'],
                    'profile_photo_url' => $client['profile_photo_url'] ?? null,
                ];
            }
        }

        $data = [
            'id'              => (string) $doc['_id'],
            'plan_type'       => (string) ($doc['plan_type'] ?? 'individual'),
            'client_id'       => isset($doc['client_id']) ? (string) $doc['client_id'] : null,
            'client_ids'      => $clientIds,
            'assigned_client' => $assignedClient,
            'group_name'      => $doc['group_name'] ?? null,
            'title'           => $doc['title'],
            'week_start'      => $doc['week_start'] ? date('Y-m-d', (int)((string)$doc['week_start']) / 1000) : null,
            'status'          => $doc['status'],
            'notes'           => $doc['notes'] ?? null,
            'created_at'      => (string) ($doc['created_at'] ?? ''),
        ];
        if ($full) $data['days'] = $doc['days'] ?? [];
        return $data;
    }
}
