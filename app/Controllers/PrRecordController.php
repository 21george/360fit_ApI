<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Personal Record (PR) Controller
 *
 * Tracks exercise PRs per client. Computes 1RM using Epley formula:
 * weight * (1 + reps/30).
 *
 * Endpoints:
 *   GET  /client/prs              — list all PRs for authenticated client
 *   GET  /client/prs/:exerciseId — PR history for a specific exercise
 *   POST /client/prs            — record a new PR (or skip if not a PR)
 *   GET  /coach/clients/:id/prs — coach view of client PRs
 */
class PrRecordController
{
    /**
     * GET /client/prs
     *
     * List all current PRs for the authenticated client.
     * Returns the best record per exercise (max 1RM).
     */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $prs = $this->getClientPrs($clientId);

        Response::success([
            'prs'         => $prs,
            'total_count' => count($prs),
        ]);
    }

    /**
     * GET /client/prs/:exerciseId
     *
     * Full history for a single exercise, newest first.
     */
    public function clientExerciseHistory(array $params): void
    {
        $clientId   = new ObjectId($params['_auth']['sub']);
        $exerciseId = $params['exerciseId'] ?? '';

        if (!$this->isValidId($exerciseId)) {
            Response::error('Invalid exercise ID', 400);
        }

        $history = Database::collection('pr_records')
            ->find(
                ['client_id' => $clientId, 'exercise_id' => $exerciseId],
                ['sort' => ['date' => -1]]
            )
            ->toArray();

        $formatted = array_map([$this, 'formatPrRecord'], $history);

        Response::success([
            'exercise_id' => $exerciseId,
            'history'     => $formatted,
        ]);
    }

    /**
     * POST /client/prs
     *
     * Record a set and create/update PR if it's a new best.
     * Body: {
     *   exercise_id: string,
     *   exercise_name: string,
     *   reps: int,
     *   weight: float,     // kg or lbs
     *   unit: 'kg'|'lbs',
     *   date?: string      // ISO 8601, defaults to now
     * }
     *
     * Response: { is_pr: bool, previous_best?: float, current_best: float, pr_record?: object }
     */
    public function store(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();

        $errors = Request::validate($body, [
            'exercise_id'   => 'required',
            'exercise_name' => 'required',
            'reps'          => 'required',
            'weight'        => 'required',
            'unit'          => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $exerciseId   = (string) $body['exercise_id'];
        $exerciseName = trim((string) $body['exercise_name']);
        $reps         = max(1, (int) $body['reps']);
        $weight       = (float) $body['weight'];
        $unit         = strtolower(trim((string) $body['unit']));

        if (!in_array($unit, ['kg', 'lbs'], true)) {
            Response::error('Invalid unit. Use kg or lbs', 422);
        }

        $date = new UTCDateTime();
        if (!empty($body['date'])) {
            $ts = strtotime((string) $body['date']);
            if ($ts !== false) {
                $date = new UTCDateTime($ts * 1000);
            }
        }

        $oneRm = $this->calculateOneRm($weight, $reps);

        // Find existing best for this exercise
        $existingBest = Database::collection('pr_records')->findOne([
            'client_id'   => $clientId,
            'exercise_id' => $exerciseId,
        ], ['sort' => ['one_rm' => -1]]);

        $isPr = false;
        $previousBest = null;

        if (!$existingBest || $oneRm > (float) ($existingBest['one_rm'] ?? 0)) {
            $isPr = true;
            if ($existingBest) {
                $previousBest = (float) $existingBest['one_rm'];
            }
        }

        $doc = [
            'client_id'     => $clientId,
            'exercise_id'   => $exerciseId,
            'exercise_name' => $exerciseName,
            'reps'          => $reps,
            'weight'        => $weight,
            'unit'          => $unit,
            'one_rm'        => $oneRm,
            'date'          => $date,
            'created_at'    => new UTCDateTime(),
        ];

        $insert = Database::collection('pr_records')->insertOne($doc);

        $record = [
            'id'            => (string) $insert->getInsertedId(),
            'exercise_id'   => $exerciseId,
            'exercise_name' => $exerciseName,
            'reps'          => $reps,
            'weight'        => $weight,
            'unit'          => $unit,
            'one_rm'        => round($oneRm, 2),
            'date'          => $this->iso8601($date),
        ];

        Response::success([
            'is_pr'          => $isPr,
            'previous_best'  => $previousBest !== null ? round($previousBest, 2) : null,
            'current_best'   => round($oneRm, 2),
            'pr_record'      => $record,
        ], $isPr ? 'New personal record!' : 'Set logged', $isPr ? 201 : 200);
    }

    /**
     * GET /coach/clients/:id/prs
     *
     * Coach view of client's PRs.
     */
    public function coachIndex(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $prs = $this->getClientPrs($clientId);

        Response::success([
            'prs'         => $prs,
            'total_count' => count($prs),
        ]);
    }

    /**
     * GET /coach/clients/:id/prs/:exerciseId
     *
     * Coach view of client's PR history for a specific exercise.
     */
    public function coachExerciseHistory(array $params): void
    {
        $coachId    = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId   = new ObjectId($params['id']);
        $exerciseId = $params['exerciseId'] ?? '';

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        if (!$this->isValidId($exerciseId)) {
            Response::error('Invalid exercise ID', 400);
        }

        $history = Database::collection('pr_records')
            ->find(
                ['client_id' => $clientId, 'exercise_id' => $exerciseId],
                ['sort' => ['date' => -1]]
            )
            ->toArray();

        $formatted = array_map([$this, 'formatPrRecord'], $history);

        Response::success([
            'exercise_id' => $exerciseId,
            'history'     => $formatted,
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get the best PR per exercise for a client.
     */
    private function getClientPrs(ObjectId $clientId): array
    {
        $pipeline = [
            ['$match' => ['client_id' => $clientId]],
            ['$sort'  => ['one_rm' => -1]],
            ['$group' => [
                '_id'           => '$exercise_id',
                'doc'           => ['$first' => '$$ROOT'],
            ]],
            ['$replaceRoot' => ['newRoot' => '$doc']],
            ['$sort' => ['date' => -1]],
        ];

        $results = Database::collection('pr_records')->aggregate($pipeline)->toArray();
        return array_map([$this, 'formatPrRecord'], $results);
    }

    private function calculateOneRm(float $weight, int $reps): float
    {
        // Epley formula
        if ($reps === 1) return $weight;
        return $weight * (1 + $reps / 30);
    }

    private function formatPrRecord(array $doc): array
    {
        return [
            'id'            => (string) ($doc['_id'] ?? ''),
            'exercise_id'   => (string) ($doc['exercise_id'] ?? ''),
            'exercise_name' => (string) ($doc['exercise_name'] ?? ''),
            'reps'          => (int) ($doc['reps'] ?? 0),
            'weight'        => (float) ($doc['weight'] ?? 0),
            'unit'          => (string) ($doc['unit'] ?? 'kg'),
            'one_rm'        => round((float) ($doc['one_rm'] ?? 0), 2),
            'date'          => $this->iso8601($doc['date'] ?? null),
        ];
    }

    private function iso8601($date): ?string
    {
        if ($date instanceof UTCDateTime) {
            return $date->toDateTime()->format('c');
        }
        return null;
    }

    private function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $id);
    }
}
