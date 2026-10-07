<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Habit Tracking Controller
 *
 * Coaches create habits for clients. Clients mark them complete daily.
 * Streaks are calculated on read.
 */
class HabitController
{
    /**
     * POST /clients/:id/habits
     *
     * Coach creates a habit for a client.
     * Body: { title, frequency: 'daily'|'weekly', target_count }
     */
    public function store(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $body   = Request::body();
        $errors = Request::validate($body, ['title' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $frequency = strtolower(trim((string) ($body['frequency'] ?? 'daily')));
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            Response::error('Invalid frequency. Use daily or weekly', 422);
        }

        $doc = [
            'client_id'    => $clientId,
            'coach_id'     => $coachId,
            'title'        => trim((string) $body['title']),
            'frequency'    => $frequency,
            'target_count' => max(1, (int) ($body['target_count'] ?? 1)),
            'created_at'   => new UTCDateTime(),
            'updated_at'   => new UTCDateTime(),
        ];

        $insert = Database::collection('habits')->insertOne($doc);
        Response::success(['id' => (string) $insert->getInsertedId()] + $doc, 'Habit created', 201);
    }

    /**
     * GET /clients/:id/habits
     *
     * List habits for a client with current streaks.
     */
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);

        $client = Database::collection('clients')->findOne([
            '_id'      => $clientId,
            'coach_id' => $coachId,
        ]);
        if (!$client) Response::error('Client not found', 404);

        $habits = Database::collection('habits')
            ->find(['client_id' => $clientId], ['sort' => ['created_at' => -1]])
            ->toArray();

        $enriched = [];
        foreach ($habits as $h) {
            $streak = $this->calculateStreak($h['_id'], $clientId, $h['frequency'] ?? 'daily');
            $enriched[] = [
                'id'           => (string) $h['_id'],
                'title'        => $h['title'] ?? '',
                'frequency'    => $h['frequency'] ?? 'daily',
                'target_count' => $h['target_count'] ?? 1,
                'streak'       => $streak,
                'created_at'   => isset($h['created_at']) ? (string) $h['created_at'] : null,
            ];
        }

        Response::success($enriched);
    }

    /**
     * PUT /clients/:id/habits/:habitId
     *
     * Update a habit (title, frequency, target_count).
     */
    public function update(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);
        $habitId  = new ObjectId($params['habitId']);

        $habit = Database::collection('habits')->findOne([
            '_id'       => $habitId,
            'client_id' => $clientId,
            'coach_id'  => $coachId,
        ]);
        if (!$habit) Response::error('Habit not found', 404);

        $body   = Request::body();
        $update = ['updated_at' => new UTCDateTime()];

        if (isset($body['title'])) {
            $update['title'] = trim((string) $body['title']);
        }
        if (isset($body['frequency'])) {
            $freq = strtolower(trim((string) $body['frequency']));
            if (in_array($freq, ['daily', 'weekly'], true)) {
                $update['frequency'] = $freq;
            }
        }
        if (isset($body['target_count'])) {
            $update['target_count'] = max(1, (int) $body['target_count']);
        }

        Database::collection('habits')->updateOne(['_id' => $habitId], ['$set' => $update]);
        Response::success(null, 'Habit updated');
    }

    /**
     * DELETE /clients/:id/habits/:habitId
     */
    public function destroy(array $params): void
    {
        $coachId  = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $clientId = new ObjectId($params['id']);
        $habitId  = new ObjectId($params['habitId']);

        $habit = Database::collection('habits')->findOne([
            '_id'       => $habitId,
            'client_id' => $clientId,
            'coach_id'  => $coachId,
        ]);
        if (!$habit) Response::error('Habit not found', 404);

        Database::collection('habits')->deleteOne(['_id' => $habitId]);
        Database::collection('habit_logs')->deleteMany(['habit_id' => $habitId]);
        Response::success(null, 'Habit deleted');
    }

    /**
     * POST /client/habits/:habitId/log
     *
     * Client marks a habit as complete for today.
     * Body: { date? (YYYY-MM-DD) }
     */
    public function log(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $habitId  = new ObjectId($params['habitId']);

        $habit = Database::collection('habits')->findOne([
            '_id'       => $habitId,
            'client_id' => $clientId,
        ]);
        if (!$habit) Response::error('Habit not found', 404);

        $body = Request::body();
        $dateStr = trim((string) ($body['date'] ?? date('Y-m-d')));
        $dateTs = strtotime($dateStr . ' 00:00:00');
        if ($dateTs === false) {
            Response::error('Invalid date', 422);
        }

        // Upsert: mark completed for this date
        Database::collection('habit_logs')->updateOne(
            [
                'habit_id'  => $habitId,
                'client_id' => $clientId,
                'date'      => new UTCDateTime($dateTs * 1000),
            ],
            ['$set' => [
                'completed'  => true,
                'updated_at' => new UTCDateTime(),
            ], '$setOnInsert' => [
                'created_at' => new UTCDateTime(),
            ]],
            ['upsert' => true]
        );

        Response::success(null, 'Habit logged');
    }

    /**
     * DELETE /client/habits/:habitId/log
     *
     * Client unmarks a habit for today.
     */
    public function unlog(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $habitId  = new ObjectId($params['habitId']);

        $body = Request::body();
        $dateStr = trim((string) ($body['date'] ?? date('Y-m-d')));
        $dateTs = strtotime($dateStr . ' 00:00:00');
        if ($dateTs === false) {
            Response::error('Invalid date', 422);
        }

        Database::collection('habit_logs')->updateOne(
            [
                'habit_id'  => $habitId,
                'client_id' => $clientId,
                'date'      => new UTCDateTime($dateTs * 1000),
            ],
            ['$set' => ['completed' => false, 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Habit unlogged');
    }

    /**
     * GET /client/habits
     *
     * Client view: list their own habits with completion status for today.
     */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);

        $habits = Database::collection('habits')
            ->find(['client_id' => $clientId], ['sort' => ['created_at' => -1]])
            ->toArray();

        $todayStr = date('Y-m-d');
        $todayTs  = strtotime($todayStr . ' 00:00:00');
        $todayBson = new UTCDateTime($todayTs * 1000);

        $enriched = [];
        foreach ($habits as $h) {
            $streak = $this->calculateStreak($h['_id'], $clientId, $h['frequency'] ?? 'daily');
            $log = Database::collection('habit_logs')->findOne([
                'habit_id'  => $h['_id'],
                'client_id' => $clientId,
                'date'      => $todayBson,
            ]);
            $enriched[] = [
                'id'           => (string) $h['_id'],
                'title'        => $h['title'] ?? '',
                'frequency'    => $h['frequency'] ?? 'daily',
                'target_count' => $h['target_count'] ?? 1,
                'streak'       => $streak,
                'completed_today' => (bool) ($log['completed'] ?? false),
            ];
        }

        Response::success($enriched);
    }

    private function calculateStreak(ObjectId $habitId, ObjectId $clientId, string $frequency): int
    {
        $logs = Database::collection('habit_logs')
            ->find([
                'habit_id'  => $habitId,
                'client_id' => $clientId,
                'completed' => true,
            ], ['sort' => ['date' => -1]])
            ->toArray();

        if (empty($logs)) return 0;

        $streak = 0;
        $expected = new \DateTime();
        $expected->setTime(0, 0, 0);

        foreach ($logs as $log) {
            $logDate = $log['date'] instanceof UTCDateTime
                ? (new \DateTime())->setTimestamp((int) (((string) $log['date']) / 1000))
                : new \DateTime();
            $logDate->setTime(0, 0, 0);

            if ($frequency === 'weekly') {
                // For weekly habits, check consecutive weeks
                $expectedYearWeek = (int) $expected->format('oW');
                $logYearWeek = (int) $logDate->format('oW');
                if ($logYearWeek === $expectedYearWeek) {
                    $streak++;
                    $expected->modify('-1 week');
                } elseif ($logYearWeek === $expectedYearWeek - 1) {
                    $streak++;
                    $expected->modify('-1 week');
                } else {
                    break;
                }
            } else {
                // Daily
                $diff = (int) $expected->diff($logDate)->days;
                if ($diff === 0 || ($streak > 0 && $diff === 1)) {
                    $streak++;
                    $expected->modify('-1 day');
                } else {
                    break;
                }
            }
        }

        return $streak;
    }
}
