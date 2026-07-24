<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\AssessmentScoringService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Sports-science assessments (Feature 7): FMS screening, 1RM, VO2max (Cooper),
 * body composition. Recorded per-client, scoped to the caller's organization.
 */
class AssessmentController
{
    private const VALID_TYPES = ['fms', 'one_rep_max', 'vo2max_cooper', 'body_composition'];
    private const RECORD_ROLES = ['owner', 'admin', 'instructor_coach'];

    // POST /org/clients/:id/assessments
    public function store(array $params): void
    {
        if (!in_array($params['_role'], self::RECORD_ROLES, true)) {
            Response::error('Forbidden: insufficient role to record assessments', 403);
        }

        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $body   = Request::body();
        $errors = Request::validate($body, ['type' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $type = $body['type'];
        if (!in_array($type, self::VALID_TYPES, true)) {
            Response::error('Invalid assessment type. Must be one of: ' . implode(', ', self::VALID_TYPES), 422);
        }

        $inputs       = is_array($body['inputs'] ?? null) ? $body['inputs'] : [];
        $methodDetail = $body['method_detail'] ?? null;
        $scored       = AssessmentScoringService::score($type, $inputs, $methodDetail);
        $now          = new UTCDateTime();

        $insert = Database::collection('assessments')->insertOne([
            'org_id'          => $orgId,
            'client_id'       => $clientId,
            'recorded_by'     => new ObjectId($params['_auth']['sub']),
            'type'            => $type,
            'method_detail'   => $methodDetail,
            'inputs'          => $inputs,
            'computed_value'  => $scored['computed_value'],
            'composite_score' => $scored['composite_score'],
            'flagged'         => $scored['flagged'],
            'notes'           => $body['notes'] ?? null,
            'recorded_at'     => $now,
            'created_at'      => $now,
        ]);

        Response::success(['id' => (string) $insert->getInsertedId()], 'Assessment recorded', 201);
    }

    // GET /org/clients/:id/assessments?type=
    public function index(array $params): void
    {
        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $filter = ['org_id' => $orgId, 'client_id' => $clientId];
        $type   = Request::get('type');
        if (is_string($type) && in_array($type, self::VALID_TYPES, true)) {
            $filter['type'] = $type;
        }

        $records = Database::collection('assessments')->find(
            $filter,
            ['sort' => ['recorded_at' => -1]]
        )->toArray();

        Response::success(array_map(fn($r) => $this->format($r), $records));
    }

    // GET /org/clients/:id/assessments/latest
    public function latest(array $params): void
    {
        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId($params['id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $result = [];
        foreach (self::VALID_TYPES as $type) {
            $latest = Database::collection('assessments')->findOne(
                ['org_id' => $orgId, 'client_id' => $clientId, 'type' => $type],
                ['sort' => ['recorded_at' => -1]]
            );
            $result[$type] = $latest ? $this->format($latest) : null;
        }

        Response::success($result);
    }

    private function format(array $a): array
    {
        return [
            'id'              => (string) $a['_id'],
            'client_id'       => (string) $a['client_id'],
            'type'            => $a['type'],
            'method_detail'   => $a['method_detail'] ?? null,
            'inputs'          => $a['inputs'] ?? [],
            'computed_value'  => $a['computed_value'] ?? null,
            'composite_score' => $a['composite_score'] ?? null,
            'flagged'         => $a['flagged'] ?? null,
            'notes'           => $a['notes'] ?? null,
            'recorded_at'     => isset($a['recorded_at']) ? (string) $a['recorded_at'] : null,
        ];
    }
}
