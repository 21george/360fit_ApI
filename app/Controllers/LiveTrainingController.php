<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\NotificationTriggerService;
use MongoDB\BSON\{ObjectId, UTCDateTime};

class LiveTrainingController
{
    private const CATEGORIES  = ['strength', 'cardio', 'hiit', 'yoga', 'pilates', 'stretching', 'functional', 'other'];
    private const LEVELS      = ['beginner', 'intermediate', 'advanced'];
    private const STATUSES    = ['upcoming', 'live', 'ended'];
    private const REQ_STATES  = ['pending', 'approved', 'rejected'];

    /* ── Coach: list all sessions ──────────────────────────────────────────── */
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $filter   = ['coach_id' => $coachId];
        $status   = Request::get('status');
        $category = Request::get('category');
        if ($status && in_array($status, self::STATUSES, true))     $filter['status']   = $status;
        if ($category && in_array($category, self::CATEGORIES, true)) $filter['category'] = $category;

        $sessions = Database::collection('live_training_sessions')
            ->find($filter, ['sort' => ['scheduled_at' => -1]])
            ->toArray();

        Response::success(array_map(fn($s) => $this->format($s), $sessions));
    }

    /* ── Coach: single session ─────────────────────────────────────────────── */
    public function show(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        Response::success($this->format($session));
    }

    /* ── Coach: create session ─────────────────────────────────────────────── */
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, [
            'title'        => 'required',
            'category'     => 'required',
            'level'        => 'required',
            'duration_min' => 'required|numeric',
            'scheduled_at' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        if (!in_array($body['category'], self::CATEGORIES, true)) {
            Response::error('Invalid category', 422);
        }
        if (!in_array($body['level'], self::LEVELS, true)) {
            Response::error('Invalid level', 422);
        }

        $timestamp = strtotime($body['scheduled_at']);
        if ($timestamp === false) Response::error('Invalid scheduled_at date', 422);

        $result = Database::collection('live_training_sessions')->insertOne([
            'coach_id'         => $coachId,
            'title'            => trim($body['title']),
            'description'      => trim($body['description'] ?? ''),
            'category'         => $body['category'],
            'level'            => $body['level'],
            'duration_min'     => (int) $body['duration_min'],
            'scheduled_at'     => new UTCDateTime($timestamp * 1000),
            'max_participants' => (int) ($body['max_participants'] ?? 20),
            'requires_approval'=> (bool) ($body['requires_approval'] ?? false),
            'meeting_link'     => trim($body['meeting_link'] ?? ''),
            'status'           => 'upcoming',
            'participant_ids'  => [],
            'created_at'       => new UTCDateTime(),
            'updated_at'       => new UTCDateTime(),
        ]);

        // Schedule 2-hour reminder for coach and participants
        $triggerService = new NotificationTriggerService();
        $scheduledAt = date('Y-m-d H:i:s', $timestamp);
        $triggerService->scheduleLiveSessionReminder(
            (string) $result->getInsertedId(),
            (string) $coachId,
            trim($body['title']),
            $scheduledAt,
            [] // Will be populated when participants join
        );

        Response::success(['id' => (string) $result->getInsertedId()], 'Session created', 201);
    }

    /* ── Coach: update session ─────────────────────────────────────────────── */
    public function update(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $body    = Request::body();

        $allowed = ['title','description','category','level','duration_min','scheduled_at',
                     'max_participants','requires_approval','meeting_link','status'];
        $set = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) continue;
            if ($field === 'scheduled_at') {
                $ts = strtotime($body[$field]);
                if ($ts === false) Response::error('Invalid scheduled_at', 422);
                $set[$field] = new UTCDateTime($ts * 1000);
            } elseif ($field === 'duration_min' || $field === 'max_participants') {
                $set[$field] = (int) $body[$field];
            } elseif ($field === 'requires_approval') {
                $set[$field] = (bool) $body[$field];
            } elseif ($field === 'status') {
                if (!in_array($body[$field], self::STATUSES, true)) Response::error('Invalid status', 422);
                $set[$field] = $body[$field];
            } elseif ($field === 'category') {
                if (!in_array($body[$field], self::CATEGORIES, true)) Response::error('Invalid category', 422);
                $set[$field] = $body[$field];
            } elseif ($field === 'level') {
                if (!in_array($body[$field], self::LEVELS, true)) Response::error('Invalid level', 422);
                $set[$field] = $body[$field];
            } else {
                $set[$field] = is_string($body[$field]) ? trim($body[$field]) : $body[$field];
            }
        }

        if (!empty($set)) {
            $set['updated_at'] = new UTCDateTime();
            Database::collection('live_training_sessions')->updateOne(
                ['_id' => $session['_id']],
                ['$set' => $set]
            );
        }

        Response::success(null, 'Session updated');
    }

    /* ── Coach: delete session ─────────────────────────────────────────────── */
    public function destroy(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        Database::collection('live_training_sessions')->deleteOne(['_id' => $session['_id']]);
        Database::collection('live_training_requests')->deleteMany(['session_id' => $session['_id']]);
        Response::success(null, 'Session deleted');
    }

    /* ── Coach: go live / end session ──────────────────────────────────────── */
    public function goLive(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        Database::collection('live_training_sessions')->updateOne(
            ['_id' => $session['_id']],
            ['$set' => ['status' => 'live', 'started_at' => new UTCDateTime(), 'updated_at' => new UTCDateTime()]]
        );
        Response::success(null, 'Session is now live');
    }

    public function endSession(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        Database::collection('live_training_sessions')->updateOne(
            ['_id' => $session['_id']],
            ['$set' => ['status' => 'ended', 'ended_at' => new UTCDateTime(), 'updated_at' => new UTCDateTime()]]
        );
        Response::success(null, 'Session ended');
    }

    /* ── Coach: list join requests ─────────────────────────────────────────── */
    public function listRequests(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $status  = Request::get('status');

        $filter = ['session_id' => $session['_id']];
        if ($status && in_array($status, self::REQ_STATES, true)) $filter['status'] = $status;

        $requests = (array) Database::collection('live_training_requests')
            ->find($filter, ['sort' => ['created_at' => -1]])
            ->toArray();

        // Attach client names
        $clientIds = array_values(array_map(fn($r) => $r['client_id'], $requests));
        $clients   = [];
        if ($clientIds) {
            $cursor = Database::collection('clients')->find(['_id' => ['$in' => $clientIds]]);
            foreach ($cursor as $c) $clients[(string) $c['_id']] = $c;
        }

        $formatted = array_values(array_map(function ($r) use ($clients) {
            $cid = (string) $r['client_id'];
            $client = $clients[$cid] ?? null;
            return [
                'id'           => (string) $r['_id'],
                'session_id'   => (string) $r['session_id'],
                'client_id'    => $cid,
                'client_name'  => $client ? ($client['name'] ?? 'Client') : 'Client',
                'client_photo' => $client['profile_photo_url'] ?? null,
                'status'       => $r['status'] ?? 'pending',
                'created_at'   => isset($r['created_at']) ? date('c', intdiv((int) ((string) $r['created_at']), 1000)) : null,
            ];
        }, $requests));

        Response::success($formatted);
    }

    /* ── Coach: approve/reject request ─────────────────────────────────────── */
    public function handleRequest(array $params): void
    {
        $coachId   = new ObjectId($params['_auth']['sub']);
        $session   = $this->findSession($params['id'], $coachId);
        $body      = Request::body();
        $requestId = $body['request_id'] ?? null;
        $action    = $body['action'] ?? null;

        if (!$requestId || !in_array($action, ['approved', 'rejected'], true)) {
            Response::error('request_id and action (approved/rejected) are required', 422);
        }

        if (!preg_match('/^[a-f0-9]{24}$/', $requestId)) {
            Response::error('Invalid request_id', 400);
        }

        $reqOid = new ObjectId($requestId);
        $req = Database::collection('live_training_requests')->findOne([
            '_id'        => $reqOid,
            'session_id' => $session['_id'],
        ]);
        if (!$req) Response::error('Request not found', 404);

        Database::collection('live_training_requests')->updateOne(
            ['_id' => $reqOid],
            ['$set' => ['status' => $action, 'updated_at' => new UTCDateTime()]]
        );

        // If approved, add to participant list
        if ($action === 'approved') {
            Database::collection('live_training_sessions')->updateOne(
                ['_id' => $session['_id']],
                ['$addToSet' => ['participant_ids' => $req['client_id']]]
            );
        }

        Response::success(null, "Request $action");
    }

    /* ── Coach: list participants ──────────────────────────────────────────── */
    public function participants(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);

        $pids = (array) ($session['participant_ids'] ?? []);
        if (empty($pids)) { Response::success([]); }

        $clients = (array) Database::collection('clients')
            ->find(['_id' => ['$in' => array_values($pids)]])
            ->toArray();

        $formatted = array_values(array_map(fn($c) => [
            'id'    => (string) $c['_id'],
            'name'  => $c['name'] ?? 'Client',
            'photo' => $c['profile_photo_url'] ?? null,
        ], $clients));

        Response::success($formatted);
    }

    /* ── Coach: send live chat message ─────────────────────────────────────── */
    public function sendChat(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $body    = Request::body();
        $content = trim($body['content'] ?? '');
        if ($content === '') Response::error('Content is required', 422);

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);

        Database::collection('live_training_chat')->insertOne([
            'session_id'  => $session['_id'],
            'sender_id'   => (string) $coachId,
            'sender_name' => $coach['name'] ?? 'Coach',
            'sender_role' => 'coach',
            'content'     => $content,
            'sent_at'     => new UTCDateTime(),
        ]);

        Response::success(null, 'Message sent', 201);
    }

    /* ── Coach: get chat messages ──────────────────────────────────────────── */
    public function getChat(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);

        $messages = (array) Database::collection('live_training_chat')
            ->find(['session_id' => $session['_id']], ['sort' => ['sent_at' => 1]])
            ->toArray();

        $formatted = array_map(fn($m) => [
            'id'          => (string) $m['_id'],
            'sender_id'   => $m['sender_id'] ?? '',
            'sender_name' => $m['sender_name'] ?? '',
            'sender_role' => $m['sender_role'] ?? 'client',
            'content'     => $m['content'] ?? '',
            'sent_at'     => isset($m['sent_at']) ? date('c', intdiv((int) ((string) $m['sent_at']), 1000)) : null,
        ], $messages);

        Response::success($formatted);
    }

    /* ══ Client endpoints ═══════════════════════════════════════════════════ */

    /* ── Client: get single session detail ─────────────────────────────────── */
    public function clientShow(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client || !isset($client['coach_id'])) Response::error('Client or coach not found', 404);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }
        $session = Database::collection('live_training_sessions')->findOne([
            '_id'      => new ObjectId($params['id']),
            'coach_id' => $client['coach_id'],
        ]);
        if (!$session) Response::error('Session not found', 404);

        $f = $this->format($session);
        $pids = array_map('strval', (array) ($session['participant_ids'] ?? []));
        $f['is_participant'] = in_array((string) $clientId, $pids, true);

        $req = Database::collection('live_training_requests')->findOne([
            'session_id' => $session['_id'],
            'client_id'  => $clientId,
        ]);
        $f['join_status'] = $req ? $req['status'] : null;

        Response::success($f);
    }

    /* ── Client: list participants of a session ────────────────────────────── */
    public function clientParticipants(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client || !isset($client['coach_id'])) Response::error('Client or coach not found', 404);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }
        $session = Database::collection('live_training_sessions')->findOne([
            '_id'      => new ObjectId($params['id']),
            'coach_id' => $client['coach_id'],
        ]);
        if (!$session) Response::error('Session not found', 404);

        $pids = (array) ($session['participant_ids'] ?? []);
        if (empty($pids)) { Response::success([]); return; }

        $clients = Database::collection('clients')
            ->find(['_id' => ['$in' => array_values($pids)]])
            ->toArray();

        $formatted = array_map(fn($c) => [
            'id'    => (string) $c['_id'],
            'name'  => $c['name'] ?? 'Client',
            'photo' => $c['profile_photo_url'] ?? null,
        ], (array) $clients);

        Response::success($formatted);
    }

    /* ── Client: browse sessions by their coach ────────────────────────────── */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client || !isset($client['coach_id'])) Response::error('Client or coach not found', 404);

        $filter = ['coach_id' => $client['coach_id']];
        $status = Request::get('status');
        if ($status && in_array($status, self::STATUSES, true)) $filter['status'] = $status;

        $sessions = Database::collection('live_training_sessions')
            ->find($filter, ['sort' => ['scheduled_at' => -1]])
            ->toArray();

        // Attach client's join status
        $sessionIds = array_map(fn($s) => $s['_id'], (array) $sessions);
        $myRequests = [];
        if ($sessionIds) {
            $cursor = Database::collection('live_training_requests')->find([
                'session_id' => ['$in' => $sessionIds],
                'client_id'  => $clientId,
            ]);
            foreach ($cursor as $r) $myRequests[(string) $r['session_id']] = $r['status'];
        }

        $formatted = array_map(function ($s) use ($clientId, $myRequests) {
            $f = $this->format($s);
            $sid = (string) $s['_id'];
            $pids = array_map('strval', (array) ($s['participant_ids'] ?? []));
            $f['is_participant'] = in_array((string) $clientId, $pids, true);
            $f['join_status']    = $myRequests[$sid] ?? null;
            return $f;
        }, $sessions);

        Response::success($formatted);
    }

    /* ── Client: join / request to join ────────────────────────────────────── */
    public function clientJoin(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client || !isset($client['coach_id'])) Response::error('Client or coach not found', 404);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }
        $sessionId = new ObjectId($params['id']);
        $session   = Database::collection('live_training_sessions')->findOne([
            '_id'      => $sessionId,
            'coach_id' => $client['coach_id'],
        ]);
        if (!$session) Response::error('Session not found', 404);

        // Check max participants
        $participantIds = (array) ($session['participant_ids'] ?? []);
        $currentCount = count($participantIds);
        $max = (int) ($session['max_participants'] ?? 20);
        if ($currentCount >= $max) Response::error('Session is full', 409);

        // Already a participant?
        $pids = array_map('strval', $participantIds);
        if (in_array((string) $clientId, $pids, true)) {
            Response::error('Already joined', 409);
        }

        // Check for existing request
        $existing = Database::collection('live_training_requests')->findOne([
            'session_id' => $sessionId,
            'client_id'  => $clientId,
        ]);
        if ($existing) {
            Response::error('Request already submitted', 409);
        }

        if (!empty($session['requires_approval'])) {
            // Create a pending request
            Database::collection('live_training_requests')->insertOne([
                'session_id' => $sessionId,
                'client_id'  => $clientId,
                'status'     => 'pending',
                'created_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
            ]);
            Response::success(null, 'Join request submitted', 201);
        } else {
            // Direct join
            Database::collection('live_training_sessions')->updateOne(
                ['_id' => $sessionId],
                ['$addToSet' => ['participant_ids' => $clientId]]
            );
            Database::collection('live_training_requests')->insertOne([
                'session_id' => $sessionId,
                'client_id'  => $clientId,
                'status'     => 'approved',
                'created_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
            ]);
            Response::success(null, 'Joined session');
        }
    }

    /* ── Client: send chat message ─────────────────────────────────────────── */
    public function clientSendChat(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client || !isset($client['coach_id'])) Response::error('Client or coach not found', 404);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }
        $sessionId = new ObjectId($params['id']);
        $session   = Database::collection('live_training_sessions')->findOne([
            '_id'      => $sessionId,
            'coach_id' => $client['coach_id'],
        ]);
        if (!$session || ($session['status'] ?? '') !== 'live') {
            Response::error('Session not found or not live', 404);
        }

        // Verify participant
        $pids = array_map('strval', (array) ($session['participant_ids'] ?? []));
        if (!in_array((string) $clientId, $pids, true)) {
            Response::error('Not a participant', 403);
        }

        $body    = Request::body();
        $content = trim($body['content'] ?? '');
        if ($content === '') Response::error('Content is required', 422);

        Database::collection('live_training_chat')->insertOne([
            'session_id'  => $sessionId,
            'sender_id'   => (string) $clientId,
            'sender_name' => $client['name'] ?? 'Client',
            'sender_role' => 'client',
            'content'     => $content,
            'sent_at'     => new UTCDateTime(),
        ]);

        Response::success(null, 'Message sent', 201);
    }

    /* ── Client: get chat messages ─────────────────────────────────────────── */
    public function clientGetChat(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client || !isset($client['coach_id'])) Response::error('Client or coach not found', 404);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }
        $sessionId = new ObjectId($params['id']);
        $session   = Database::collection('live_training_sessions')->findOne([
            '_id'      => $sessionId,
            'coach_id' => $client['coach_id'],
        ]);
        if (!$session) Response::error('Session not found', 404);

        // Verify client is a participant
        $pids = array_map('strval', (array) ($session['participant_ids'] ?? []));
        if (!in_array((string) $clientId, $pids, true)) {
            Response::error('Not a participant', 403);
        }

        $messages = (array) Database::collection('live_training_chat')
            ->find(['session_id' => $sessionId], ['sort' => ['sent_at' => 1]])
            ->toArray();

        $formatted = array_map(fn($m) => [
            'id'          => (string) $m['_id'],
            'sender_id'   => $m['sender_id'] ?? '',
            'sender_name' => $m['sender_name'] ?? '',
            'sender_role' => $m['sender_role'] ?? 'client',
            'content'     => $m['content'] ?? '',
            'sent_at'     => isset($m['sent_at']) ? date('c', intdiv((int) ((string) $m['sent_at']), 1000)) : null,
        ], $messages);

        Response::success($formatted);
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  Helpers                                                               */
    /* ═══════════════════════════════════════════════════════════════════════ */

    private function findSession(string $rawId, ObjectId $coachId): object
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $rawId)) {
            Response::error('Invalid session ID', 400);
        }
        $session = Database::collection('live_training_sessions')->findOne([
            '_id'      => new ObjectId($rawId),
            'coach_id' => $coachId,
        ]);
        if (!$session) Response::error('Session not found', 404);
        return $session;
    }

    private function format(object $s): array
    {
        $d = (array) $s;
        return [
            'id'                => isset($d['_id']) ? (string) $d['_id'] : '',
            'coach_id'          => isset($d['coach_id']) ? (string) $d['coach_id'] : '',
            'title'             => $d['title'] ?? '',
            'description'       => $d['description'] ?? '',
            'category'          => $d['category'] ?? 'other',
            'level'             => $d['level'] ?? 'beginner',
            'duration_min'      => (int) ($d['duration_min'] ?? 0),
            'scheduled_at'      => isset($d['scheduled_at']) ? date('c', intdiv((int) ((string) $d['scheduled_at']), 1000)) : null,
            'max_participants'  => (int) ($d['max_participants'] ?? 20),
            'requires_approval' => (bool) ($d['requires_approval'] ?? false),
            'meeting_link'      => $d['meeting_link'] ?? '',
            'status'            => $d['status'] ?? 'upcoming',
            'participant_count' => count($d['participant_ids'] ?? []),
            'started_at'        => isset($d['started_at']) ? date('c', intdiv((int) ((string) $d['started_at']), 1000)) : null,
            'ended_at'          => isset($d['ended_at']) ? date('c', intdiv((int) ((string) $d['ended_at']), 1000)) : null,
            'created_at'        => isset($d['created_at']) ? date('c', intdiv((int) ((string) $d['created_at']), 1000)) : null,
        ];
    }
}
