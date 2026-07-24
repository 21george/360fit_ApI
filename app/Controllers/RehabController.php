<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Non-clinical physiotherapy / rehab track (Feature 8): PAR-Q+ pre-screening,
 * consent-gated rehab track enrollment, PROMs, pain logs and SOAP-style notes.
 *
 * This is explicitly a non-clinical wellness feature — SOAP notes and rehab
 * track enrollment are restricted to owner/admin/instructor_coach roles only
 * (never front_desk or manager), and enrollment requires a signed
 * `rehab_consent` document via the same consent mechanism used by studio
 * operations (see StudioController::signConsentAsClient).
 */
class RehabController
{
    private const CLINICAL_ROLES = ['owner', 'admin', 'instructor_coach'];

    // POST /client/parq
    public function submitParq(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::notFound('Client not found');

        $body     = Request::body();
        $answers  = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        $flagged  = in_array(true, $answers, true) || in_array('yes', array_map(
            fn($v) => is_string($v) ? strtolower($v) : $v,
            $answers
        ), true);

        $now = new UTCDateTime();
        $insert = Database::collection('parq_screenings')->insertOne([
            'org_id'      => $client['coach_id'],
            'client_id'   => $clientId,
            'answers'     => $answers,
            'flagged'     => $flagged,
            'submitted_at' => $now,
            'created_at'  => $now,
        ]);

        if ($flagged) {
            // Surface a flagged screening to the org's dashboard notification feed.
            Database::collection('notifications')->insertOne([
                'user_id'    => $client['coach_id'],
                'user_type'  => 'coach',
                'type'       => 'parq_flagged',
                'title'      => 'PAR-Q+ Flagged',
                'body'       => ($client['name'] ?? 'A client') . ' flagged one or more PAR-Q+ questions and should be reviewed before starting a rehab track',
                'data'       => ['clientId' => (string) $clientId],
                'read'       => false,
                'sent_at'    => $now,
                'created_at' => $now,
            ]);
        }

        Response::success(['id' => (string) $insert->getInsertedId(), 'flagged' => $flagged], 'PAR-Q+ submitted', 201);
    }

    // GET /org/clients/:id/parq
    public function getParq(array $params): void
    {
        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $latest = Database::collection('parq_screenings')->findOne(
            ['org_id' => $orgId, 'client_id' => $clientId],
            ['sort' => ['submitted_at' => -1]]
        );

        Response::success($latest ? [
            'id'           => (string) $latest['_id'],
            'answers'      => $latest['answers'],
            'flagged'      => $latest['flagged'],
            'submitted_at' => (string) $latest['submitted_at'],
        ] : null);
    }

    // POST /org/clients/:id/rehab-track/start
    public function startTrack(array $params): void
    {
        if (!in_array($params['_role'], self::CLINICAL_ROLES, true)) {
            Response::error('Forbidden: insufficient role to start a rehab track', 403);
        }

        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $consent = Database::collection('consent_documents')->findOne([
            'org_id' => $orgId, 'client_id' => $clientId, 'document_type' => 'rehab_consent',
        ]);
        if (!$consent) {
            Response::error('A signed rehab consent document is required before starting a rehab track', 412);
        }

        $existing = Database::collection('rehab_tracks')->findOne([
            'org_id' => $orgId, 'client_id' => $clientId, 'status' => 'active',
        ]);
        if ($existing) Response::error('This client already has an active rehab track', 409);

        $body = Request::body();
        $now  = new UTCDateTime();
        $insert = Database::collection('rehab_tracks')->insertOne([
            'org_id'         => $orgId,
            'client_id'      => $clientId,
            'condition_area' => $body['condition_area'] ?? null,
            'goals'          => $body['goals'] ?? null,
            'status'         => 'active',
            'started_by'     => new ObjectId($params['_auth']['sub']),
            'started_at'     => $now,
            'ended_at'       => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Rehab track started', 201);
    }

    // GET /org/clients/:id/rehab-track
    public function getTrack(array $params): void
    {
        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $track = Database::collection('rehab_tracks')->findOne(
            ['org_id' => $orgId, 'client_id' => $clientId],
            ['sort' => ['started_at' => -1]]
        );
        if (!$track) Response::success(null);

        Response::success($this->formatTrack($track));
    }

    // POST /org/rehab-tracks/:id/proms
    public function recordProm(array $params): void
    {
        if (!in_array($params['_role'], self::CLINICAL_ROLES, true)) {
            Response::error('Forbidden: insufficient role to record PROMs', 403);
        }

        $orgId = new ObjectId($params['_org_id']);
        $track = Database::collection('rehab_tracks')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$track) Response::notFound('Rehab track not found');

        $body   = Request::body();
        $errors = Request::validate($body, ['instrument' => 'required', 'score' => 'required|numeric']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $now = new UTCDateTime();
        $insert = Database::collection('proms')->insertOne([
            'org_id'          => $orgId,
            'rehab_track_id'  => $track['_id'],
            'client_id'       => $track['client_id'],
            'instrument'      => $body['instrument'],
            'score'           => (float) $body['score'],
            'max_score'       => isset($body['max_score']) ? (float) $body['max_score'] : null,
            'recorded_by'     => new ObjectId($params['_auth']['sub']),
            'recorded_at'     => $now,
            'created_at'      => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'PROM recorded', 201);
    }

    // POST /org/rehab-tracks/:id/pain-logs  (staff logging on behalf of a client)
    public function logPainAsStaff(array $params): void
    {
        if (!in_array($params['_role'], self::CLINICAL_ROLES, true)) {
            Response::error('Forbidden: insufficient role to log pain entries', 403);
        }
        $orgId = new ObjectId($params['_org_id']);
        $track = Database::collection('rehab_tracks')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$track) Response::notFound('Rehab track not found');

        $this->storePainLog($orgId, $track, Request::body(), 'staff');
    }

    // POST /client/rehab-tracks/:id/pain-logs  (client self-report)
    public function logPainAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $track    = Database::collection('rehab_tracks')->findOne(['_id' => new ObjectId($params['id']), 'client_id' => $clientId]);
        if (!$track) Response::notFound('Rehab track not found');

        $this->storePainLog($track['org_id'], $track, Request::body(), 'self');
    }

    private function storePainLog(ObjectId $orgId, array $track, array $body, string $source): void
    {
        $errors = Request::validate($body, ['pain_level' => 'required|numeric']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $painLevel = max(0, min(10, (int) $body['pain_level']));
        $now = new UTCDateTime();

        $insert = Database::collection('pain_logs')->insertOne([
            'org_id'         => $orgId,
            'rehab_track_id' => $track['_id'],
            'client_id'      => $track['client_id'],
            'pain_level'     => $painLevel,
            'location'       => $body['location'] ?? null,
            'notes'          => $body['notes'] ?? null,
            'source'         => $source,
            'logged_at'      => $now,
            'created_at'     => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Pain log recorded', 201);
    }

    // POST /org/rehab-tracks/:id/soap-notes  (clinical roles only, never front_desk/manager)
    public function addSoapNote(array $params): void
    {
        if (!in_array($params['_role'], self::CLINICAL_ROLES, true)) {
            Response::error('Forbidden: insufficient role to write SOAP notes', 403);
        }

        $orgId = new ObjectId($params['_org_id']);
        $track = Database::collection('rehab_tracks')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$track) Response::notFound('Rehab track not found');

        $body   = Request::body();
        $errors = Request::validate($body, ['subjective' => 'required', 'objective' => 'required', 'assessment' => 'required', 'plan' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $now = new UTCDateTime();
        $insert = Database::collection('soap_notes')->insertOne([
            'org_id'         => $orgId,
            'rehab_track_id' => $track['_id'],
            'client_id'      => $track['client_id'],
            'subjective'     => $body['subjective'],
            'objective'      => $body['objective'],
            'assessment'     => $body['assessment'],
            'plan'           => $body['plan'],
            'authored_by'    => new ObjectId($params['_auth']['sub']),
            'authored_at'    => $now,
            'created_at'     => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'SOAP note added', 201);
    }

    // GET /org/rehab-tracks/:id/soap-notes
    public function listSoapNotes(array $params): void
    {
        if (!in_array($params['_role'], self::CLINICAL_ROLES, true)) {
            Response::error('Forbidden: insufficient role to view SOAP notes', 403);
        }

        $orgId = new ObjectId($params['_org_id']);
        $track = Database::collection('rehab_tracks')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$track) Response::notFound('Rehab track not found');

        $notes = Database::collection('soap_notes')->find(
            ['rehab_track_id' => $track['_id']],
            ['sort' => ['authored_at' => -1]]
        )->toArray();

        Response::success(array_map(fn($n) => [
            'id'          => (string) $n['_id'],
            'subjective'  => $n['subjective'],
            'objective'   => $n['objective'],
            'assessment'  => $n['assessment'],
            'plan'        => $n['plan'],
            'authored_at' => (string) $n['authored_at'],
        ], $notes));
    }

    private function formatTrack(array $t): array
    {
        return [
            'id'             => (string) $t['_id'],
            'client_id'      => (string) $t['client_id'],
            'condition_area' => $t['condition_area'] ?? null,
            'goals'          => $t['goals'] ?? null,
            'status'         => $t['status'],
            'started_at'     => isset($t['started_at']) ? (string) $t['started_at'] : null,
            'ended_at'       => isset($t['ended_at']) && $t['ended_at'] ? (string) $t['ended_at'] : null,
        ];
    }
}
