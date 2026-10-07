<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Food Diary Controller
 *
 * Free-form food logging for clients. Coaches can view daily entries.
 * Integrates with USDA FoodData Central via FoodService for search.
 */
class FoodDiaryController
{
    /**
     * POST /client/food-diary
     *
     * Log a free-form food entry for the authenticated client.
     * Body: { food_name, quantity, unit, calories, protein, carbs, fat, logged_at? }
     */
    public function store(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $body     = Request::body();
        $errors   = Request::validate($body, [
            'food_name' => 'required',
            'quantity'  => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $loggedAt = !empty($body['logged_at']) ? strtotime($body['logged_at']) : time();
        if ($loggedAt === false) {
            Response::error('Invalid logged_at date', 422);
        }

        $doc = [
            'client_id'  => $clientId,
            'food_name'  => trim((string) $body['food_name']),
            'quantity'   => (float) ($body['quantity'] ?? 0),
            'unit'       => trim((string) ($body['unit'] ?? 'g')),
            'calories'   => (float) ($body['calories'] ?? 0),
            'protein'    => (float) ($body['protein'] ?? 0),
            'carbs'      => (float) ($body['carbs'] ?? 0),
            'fat'        => (float) ($body['fat'] ?? 0),
            'logged_at'  => new UTCDateTime($loggedAt * 1000),
            'created_at' => new UTCDateTime(),
        ];

        $insert = Database::collection('food_diary_entries')->insertOne($doc);

        Response::success([
            'id' => (string) $insert->getInsertedId(),
        ] + $doc, 'Food logged', 201);
    }

    /**
     * GET /client/food-diary?date=YYYY-MM-DD
     *
     * List food diary entries for the authenticated client on a specific date.
     */
    public function clientIndex(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $dateStr  = Request::get('date') ?? date('Y-m-d');

        $startTs = strtotime($dateStr . ' 00:00:00');
        $endTs   = strtotime($dateStr . ' 23:59:59');
        if ($startTs === false || $endTs === false) {
            Response::error('Invalid date', 422);
        }

        $entries = Database::collection('food_diary_entries')
            ->find([
                'client_id' => $clientId,
                'logged_at' => ['$gte' => new UTCDateTime($startTs * 1000), '$lte' => new UTCDateTime($endTs * 1000)],
            ], ['sort' => ['logged_at' => -1]])
            ->toArray();

        $totals = [
            'calories' => 0.0,
            'protein'  => 0.0,
            'carbs'    => 0.0,
            'fat'      => 0.0,
        ];
        foreach ($entries as $e) {
            $totals['calories'] += (float) ($e['calories'] ?? 0);
            $totals['protein']  += (float) ($e['protein'] ?? 0);
            $totals['carbs']    += (float) ($e['carbs'] ?? 0);
            $totals['fat']      += (float) ($e['fat'] ?? 0);
        }

        Response::success([
            'entries' => array_map(fn($e) => [
                'id'        => (string) $e['_id'],
                'food_name' => $e['food_name'] ?? '',
                'quantity'  => $e['quantity'] ?? 0,
                'unit'      => $e['unit'] ?? 'g',
                'calories'  => $e['calories'] ?? 0,
                'protein'   => $e['protein'] ?? 0,
                'carbs'     => $e['carbs'] ?? 0,
                'fat'       => $e['fat'] ?? 0,
                'logged_at' => isset($e['logged_at']) ? (string) $e['logged_at'] : null,
            ], $entries),
            'totals'  => $totals,
            'date'    => $dateStr,
        ]);
    }

    /**
     * GET /coach/clients/:id/food-diary?date=YYYY-MM-DD
     *
     * Coach view of a client's food diary for a specific date.
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

        $dateStr = Request::get('date') ?? date('Y-m-d');
        $startTs = strtotime($dateStr . ' 00:00:00');
        $endTs   = strtotime($dateStr . ' 23:59:59');
        if ($startTs === false || $endTs === false) {
            Response::error('Invalid date', 422);
        }

        $entries = Database::collection('food_diary_entries')
            ->find([
                'client_id' => $clientId,
                'logged_at' => ['$gte' => new UTCDateTime($startTs * 1000), '$lte' => new UTCDateTime($endTs * 1000)],
            ], ['sort' => ['logged_at' => -1]])
            ->toArray();

        $totals = [
            'calories' => 0.0,
            'protein'  => 0.0,
            'carbs'    => 0.0,
            'fat'      => 0.0,
        ];
        foreach ($entries as $e) {
            $totals['calories'] += (float) ($e['calories'] ?? 0);
            $totals['protein']  += (float) ($e['protein'] ?? 0);
            $totals['carbs']    += (float) ($e['carbs'] ?? 0);
            $totals['fat']      += (float) ($e['fat'] ?? 0);
        }

        Response::success([
            'entries' => array_map(fn($e) => [
                'id'        => (string) $e['_id'],
                'food_name' => $e['food_name'] ?? '',
                'quantity'  => $e['quantity'] ?? 0,
                'unit'      => $e['unit'] ?? 'g',
                'calories'  => $e['calories'] ?? 0,
                'protein'   => $e['protein'] ?? 0,
                'carbs'     => $e['carbs'] ?? 0,
                'fat'       => $e['fat'] ?? 0,
                'logged_at' => isset($e['logged_at']) ? (string) $e['logged_at'] : null,
            ], $entries),
            'totals'  => $totals,
            'date'    => $dateStr,
        ]);
    }

    /**
     * DELETE /client/food-diary/:entryId
     */
    public function destroy(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $entryId  = new ObjectId($params['entryId']);

        $entry = Database::collection('food_diary_entries')->findOne([
            '_id'       => $entryId,
            'client_id' => $clientId,
        ]);
        if (!$entry) Response::error('Entry not found', 404);

        Database::collection('food_diary_entries')->deleteOne(['_id' => $entryId]);
        Response::success(null, 'Entry deleted');
    }
}
