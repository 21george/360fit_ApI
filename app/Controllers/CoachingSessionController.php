<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{LiveKitService, NotificationTriggerService};
use MongoDB\BSON\{ObjectId, UTCDateTime};

/**
 * 1-on-1 live coaching sessions.
 *
 * Coach endpoints (CoachMiddleware):
 *   GET    /coaching-sessions                → index
 *   POST   /coaching-sessions                → store
 *   GET    /coaching-sessions/:id            → show
 *   PUT    /coaching-sessions/:id            → update
 *   DELETE /coaching-sessions/:id            → destroy
 *   POST   /coaching-sessions/:id/status     → updateStatus  (go-live / end)
 *   POST   /coaching-sessions/:id/token      → getToken      (LiveKit JWT)
 *   POST   /coaching-sessions/:id/notes      → saveNotes
 *
 * Client endpoints (ClientMiddleware):
 *   GET    /client/coaching-sessions         → clientIndex
 *   GET    /client/coaching-sessions/:id     → clientShow
 *   POST   /client/coaching-sessions/:id/token → clientToken
 */
class CoachingSessionController
{
    private const STATUSES = ['upcoming', 'live', 'ended', 'cancelled'];

    // ─── Coach: list ─────────────────────────────────────────────────────────

    public function index(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $filter  = ['coach_id' => $coachId];

        $status   = Request::get('status');
        $clientId = Request::get('client_id');

        if ($status && in_array($status, self::STATUSES, true)) {
            $filter['status'] = $status;
        }
        if ($clientId && preg_match('/^[a-f0-9]{24}$/', $clientId)) {
            $filter['client_id'] = new ObjectId($clientId);
        }

        $sessions = Database::collection('coaching_sessions')
            ->find($filter, ['sort' => ['scheduled_at' => -1]])
            ->toArray();

        $clientMap = $this->buildClientMap(array_map(fn($s) => $s['client_id'], $sessions));

        Response::success(array_map(fn($s) => $this->format($s, $clientMap), $sessions));
    }

    // ─── Coach: single ───────────────────────────────────────────────────────

    public function show(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $map     = $this->buildClientMap([$session['client_id']]);
        Response::success($this->format($session, $map));
    }

    // ─── Coach: create ───────────────────────────────────────────────────────

    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();

        $errors = Request::validate($body, [
            'title'        => 'required',
            'client_id'    => 'required',
            'duration_min' => 'required|numeric',
            'scheduled_at' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        if (!preg_match('/^[a-f0-9]{24}$/', $body['client_id'] ?? '')) {
            Response::error('Invalid client_id', 400);
        }

        $clientId = new ObjectId($body['client_id']);
        $client   = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $timestamp = strtotime((string) ($body['scheduled_at'] ?? ''));
        if ($timestamp === false) Response::error('Invalid scheduled_at', 422);

        $sessionOid = new ObjectId();
        $roomName   = LiveKitService::roomName((string) $sessionOid);

        Database::collection('coaching_sessions')->insertOne([
            '_id'          => $sessionOid,
            'coach_id'     => $coachId,
            'client_id'    => $clientId,
            'title'        => trim($body['title']),
            'description'  => trim($body['description'] ?? ''),
            'agenda'       => trim($body['agenda'] ?? ''),
            'duration_min' => (int) $body['duration_min'],
            'scheduled_at' => new UTCDateTime($timestamp * 1000),
            'status'       => 'upcoming',
            'livekit_room' => $roomName,
            'notes'        => null,
            'started_at'   => null,
            'ended_at'     => null,
            'created_at'   => new UTCDateTime(),
            'updated_at'   => new UTCDateTime(),
        ]);

        // Notify client
        try {
            $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
            (new NotificationTriggerService())->notifyNewCoachingSession(
                $coach['name'] ?? 'Your coach',
                (string) $clientId,
                (string) $sessionOid,
                trim($body['title']),
                date('Y-m-d H:i:s', $timestamp)
            );
        } catch (\Throwable $e) {
            error_log('Coaching session notification failed: ' . $e->getMessage());
        }

        Response::success(['id' => (string) $sessionOid], 'Session created', 201);
    }

    // ─── Coach: update ───────────────────────────────────────────────────────

    public function update(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $body    = Request::body();

        $set = [];
        foreach (['title', 'description', 'agenda', 'duration_min', 'scheduled_at'] as $field) {
            if (!array_key_exists($field, $body)) continue;
            if ($field === 'scheduled_at') {
                $ts = strtotime((string) $body[$field]);
                if ($ts === false) Response::error('Invalid scheduled_at', 422);
                $set[$field] = new UTCDateTime($ts * 1000);
            } elseif ($field === 'duration_min') {
                $set[$field] = (int) $body[$field];
            } else {
                $set[$field] = is_string($body[$field]) ? trim($body[$field]) : $body[$field];
            }
        }

        if (!empty($set)) {
            $set['updated_at'] = new UTCDateTime();
            Database::collection('coaching_sessions')->updateOne(
                ['_id' => $session['_id']],
                ['$set' => $set]
            );
        }

        Response::success(null, 'Session updated');
    }

    // ─── Coach: delete ───────────────────────────────────────────────────────

    public function destroy(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);

        if ($session['status'] === 'live') {
            Response::error('Cannot delete a live session; end it first', 409);
        }

        Database::collection('coaching_sessions')->deleteOne(['_id' => $session['_id']]);
        Response::success(null, 'Session deleted');
    }

    // ─── Coach: status (go live / end / cancel) ───────────────────────────────

    public function updateStatus(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $body    = Request::body();
        $status  = $body['status'] ?? '';

        if (!in_array($status, self::STATUSES, true)) {
            Response::error('Invalid status. Allowed: ' . implode(', ', self::STATUSES), 422);
        }

        $set = ['status' => $status, 'updated_at' => new UTCDateTime()];
        if ($status === 'live')  $set['started_at'] = new UTCDateTime();
        if ($status === 'ended') $set['ended_at']   = new UTCDateTime();

        Database::collection('coaching_sessions')->updateOne(
            ['_id' => $session['_id']],
            ['$set' => $set]
        );

        if ($status === 'live') {
            try {
                $coach  = Database::collection('coaches')->findOne(['_id' => $coachId]);
                (new NotificationTriggerService())->notifyCoachingSessionStarted(
                    $coach['name'] ?? 'Your coach',
                    (string) $session['client_id'],
                    (string) $session['_id'],
                    $session['title'] ?? 'Coaching Session'
                );
            } catch (\Throwable $e) {
                error_log('Session-start notification failed: ' . $e->getMessage());
            }
        }

        Response::success(null, "Status updated to {$status}");
    }

    // ─── Coach: LiveKit token ─────────────────────────────────────────────────

    public function getToken(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);

        if (!in_array($session['status'], ['upcoming', 'live'], true)) {
            Response::error('Session is not available for video', 409);
        }

        $coach    = Database::collection('coaches')->findOne(['_id' => $coachId]);
        $identity = 'coach_' . (string) $coachId;

        try {
            $token = LiveKitService::createToken(
                $identity,
                $coach['name'] ?? 'Coach',
                $session['livekit_room'],
                true,
                true
            );
        } catch (\RuntimeException $e) {
            Response::error('Video service unavailable: please set LIVEKIT credentials', 503);
        }

        Response::success([
            'token'      => $token,
            'room_name'  => $session['livekit_room'],
            'server_url' => $_ENV['LIVEKIT_URL'] ?? '',
            'identity'   => $identity,
        ]);
    }

    // ─── Coach: save notes ────────────────────────────────────────────────────

    public function saveNotes(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $session = $this->findSession($params['id'], $coachId);
        $body    = Request::body();

        Database::collection('coaching_sessions')->updateOne(
            ['_id' => $session['_id']],
            ['$set' => ['notes' => trim($body['notes'] ?? ''), 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Notes saved');
    }

    // ─── Client: list their sessions ──────────────────────────────────────────

    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $filter   = ['client_id' => $clientId];

        $status = Request::get('status');
        if ($status && in_array($status, self::STATUSES, true)) {
            $filter['status'] = $status;
        }

        $sessions = Database::collection('coaching_sessions')
            ->find($filter, ['sort' => ['scheduled_at' => -1]])
            ->toArray();

        // Build coach map
        $coachIds = array_values(array_unique(array_map(fn($s) => (string) $s['coach_id'], $sessions)));
        $coachMap = [];
        if ($coachIds) {
            $oids = array_map(fn($id) => new ObjectId($id), $coachIds);
            foreach (Database::collection('coaches')->find(['_id' => ['$in' => $oids]]) as $c) {
                $coachMap[(string) $c['_id']] = $c;
            }
        }

        Response::success(array_map(fn($s) => $this->formatForClient($s, $coachMap), $sessions));
    }

    // ─── Client: single session ───────────────────────────────────────────────

    public function clientShow(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }

        $session = Database::collection('coaching_sessions')->findOne([
            '_id'       => new ObjectId($params['id']),
            'client_id' => $clientId,
        ]);
        if (!$session) Response::error('Session not found', 404);

        $coach    = Database::collection('coaches')->findOne(['_id' => $session['coach_id']]);
        $coachMap = $coach ? [(string) $coach['_id'] => $coach] : [];

        Response::success($this->formatForClient($session, $coachMap));
    }

    // ─── Client: LiveKit token ────────────────────────────────────────────────

    public function clientToken(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        if (!preg_match('/^[a-f0-9]{24}$/', $params['id'])) {
            Response::error('Invalid session ID', 400);
        }

        $session = Database::collection('coaching_sessions')->findOne([
            '_id'       => new ObjectId($params['id']),
            'client_id' => $clientId,
        ]);
        if (!$session) Response::error('Session not found', 404);

        if (!in_array($session['status'], ['upcoming', 'live'], true)) {
            Response::error('Session is not available for video', 409);
        }

        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        $identity = 'client_' . (string) $clientId;

        try {
            $token = LiveKitService::createToken(
                $identity,
                $client['name'] ?? 'Client',
                $session['livekit_room'],
                true,
                true
            );
        } catch (\RuntimeException $e) {
            Response::error('Video service unavailable', 503);
        }

        Response::success([
            'token'      => $token,
            'room_name'  => $session['livekit_room'],
            'server_url' => $_ENV['LIVEKIT_URL'] ?? '',
            'identity'   => $identity,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function findSession(string $id, ObjectId $coachId): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            Response::error('Invalid session ID', 400);
        }
        $s = Database::collection('coaching_sessions')->findOne([
            '_id'      => new ObjectId($id),
            'coach_id' => $coachId,
        ]);
        if (!$s) Response::error('Session not found', 404);
        return (array) $s;
    }

    /** @param mixed[] $oidArray */
    private function buildClientMap(array $oidArray): array
    {
        $map = [];
        if (empty($oidArray)) return $map;
        $unique = array_values(array_unique(array_map(fn($id) => (string) $id, $oidArray)));
        $oids   = array_map(fn($id) => new ObjectId($id), $unique);
        foreach (Database::collection('clients')->find(['_id' => ['$in' => $oids]]) as $c) {
            $map[(string) $c['_id']] = $c;
        }
        return $map;
    }

    private function format(array|object $s, array $clientMap): array
    {
        $s = (array) $s;
        $cid    = (string) $s['client_id'];
        $client = $clientMap[$cid] ?? null;
        return [
            'id'           => (string) $s['_id'],
            'client_id'    => $cid,
            'client_name'  => $client['name'] ?? 'Client',
            'client_photo' => $client['profile_photo_url'] ?? null,
            'title'        => $s['title'] ?? '',
            'description'  => $s['description'] ?? '',
            'agenda'       => $s['agenda'] ?? '',
            'duration_min' => (int) ($s['duration_min'] ?? 60),
            'scheduled_at' => $this->fmtDate($s['scheduled_at'] ?? null),
            'started_at'   => $this->fmtDate($s['started_at']   ?? null),
            'ended_at'     => $this->fmtDate($s['ended_at']     ?? null),
            'status'       => $s['status'] ?? 'upcoming',
            'livekit_room' => $s['livekit_room'] ?? '',
            'notes'        => $s['notes'] ?? null,
            'created_at'   => $this->fmtDate($s['created_at']   ?? null),
        ];
    }

    private function formatForClient(array|object $s, array $coachMap): array
    {
        $s = (array) $s;
        $coachId = (string) $s['coach_id'];
        $coach   = $coachMap[$coachId] ?? null;
        return [
            'id'           => (string) $s['_id'],
            'coach_id'     => $coachId,
            'coach_name'   => $coach['name'] ?? 'Coach',
            'coach_photo'  => $coach['profile_photo'] ?? null,
            'title'        => $s['title'] ?? '',
            'description'  => $s['description'] ?? '',
            'agenda'       => $s['agenda'] ?? '',
            'duration_min' => (int) ($s['duration_min'] ?? 60),
            'scheduled_at' => $this->fmtDate($s['scheduled_at'] ?? null),
            'started_at'   => $this->fmtDate($s['started_at']   ?? null),
            'ended_at'     => $this->fmtDate($s['ended_at']     ?? null),
            'status'       => $s['status'] ?? 'upcoming',
            'notes'        => $s['notes'] ?? null,
        ];
    }

    private function fmtDate(mixed $v): ?string
    {
        if ($v instanceof UTCDateTime) return $v->toDateTime()->format(DATE_ATOM);
        if (is_string($v) && $v !== '') {
            $ts = strtotime($v);
            return $ts !== false ? date(DATE_ATOM, $ts) : null;
        }
        return null;
    }
}
