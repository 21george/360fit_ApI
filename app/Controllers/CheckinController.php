<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{FcmService, NotificationTriggerService};
use MongoDB\BSON\{ObjectId, UTCDateTime};

class CheckinController
{
    private const CLIENT_RESPONSES = ['pending', 'accepted', 'declined', 'reschedule_requested'];

    // GET /checkins (coach)
    public function index(array $params): void
    {
        $coachId  = new ObjectId($params['_auth']['sub']);
        $clientId = Request::get('client_id');
        $filter   = ['coach_id' => $coachId];
        if ($clientId) $filter['client_id'] = new ObjectId($clientId);

        $meetings = Database::collection('checkin_meetings')->find($filter, [
            'sort' => ['scheduled_at' => 1],
        ])->toArray();

        Response::success(array_map(fn($m) => $this->format($m), $meetings));
    }

    // POST /checkins
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, [
            'client_id'    => 'required',
            'scheduled_at' => 'required',
            'type'         => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $clientId = new ObjectId($body['client_id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $coachId]);
        if (!$client) Response::error('Client not found', 404);

        $result = Database::collection('checkin_meetings')->insertOne([
            'coach_id'     => $coachId,
            'client_id'    => $clientId,
            'scheduled_at' => new \MongoDB\BSON\UTCDateTime(strtotime($body['scheduled_at']) * 1000),
            'type'         => $body['type'],    // 'call' | 'video' | 'chat'
            'meeting_link' => $body['meeting_link'] ?? null,
            'notes'        => $body['notes'] ?? null,
            'status'       => 'scheduled',
            'client_response' => 'pending',
            'client_response_note' => null,
            'client_responded_at' => null,
            'proposed_scheduled_at' => null,
            'created_at'   => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'   => new \MongoDB\BSON\UTCDateTime(),
        ]);

        // Notify client
        if (!empty($client['fcm_token'])) {
            $datetime = date('D M j, g:i A', strtotime($body['scheduled_at']));
            FcmService::notifyCheckin($client['fcm_token'], $datetime);
        }

        // Schedule 2-hour reminder for coach
        $triggerService = new NotificationTriggerService();
        $triggerService->scheduleCheckinReminder(
            (string) $result->getInsertedId(),
            (string) $clientId,
            $client['name'] ?? 'A client',
            $body['scheduled_at']
        );

        Response::success(['id' => (string) $result->getInsertedId()], 'Check-in scheduled', 201);
    }

    // PUT /checkins/:id
    public function update(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $mtgId   = new ObjectId($params['id']);
        $meeting = Database::collection('checkin_meetings')->findOne(['_id' => $mtgId, 'coach_id' => $coachId]);
        if (!$meeting) Response::error('Meeting not found', 404);

        $body    = Request::body();
        $allowed = ['scheduled_at', 'type', 'meeting_link', 'notes', 'status'];
        $set     = [];
        foreach ($allowed as $field) {
            if (isset($body[$field])) {
                $set[$field] = $field === 'scheduled_at'
                    ? new \MongoDB\BSON\UTCDateTime(strtotime($body[$field]) * 1000)
                    : $body[$field];
            }
        }

        if (isset($set['scheduled_at'])) {
            $set['client_response'] = 'pending';
            $set['client_response_note'] = null;
            $set['client_responded_at'] = null;
            $set['proposed_scheduled_at'] = null;
        }

        if (!empty($set)) {
            $set['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        }

        if (!empty($set)) {
            Database::collection('checkin_meetings')->updateOne(['_id' => $mtgId], ['$set' => $set]);
        }

        Response::success(null, 'Meeting updated');
    }

    // DELETE /checkins/:id
    public function destroy(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $mtgId   = new ObjectId($params['id']);
        $meeting = Database::collection('checkin_meetings')->findOne(['_id' => $mtgId, 'coach_id' => $coachId]);
        if (!$meeting) Response::error('Meeting not found', 404);
        Database::collection('checkin_meetings')->deleteOne(['_id' => $mtgId]);
        Response::success(null, 'Meeting cancelled');
    }

    // GET /client/checkins (client)
    public function clientCheckins(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $meetings = Database::collection('checkin_meetings')->find(
            ['client_id' => $clientId, 'status' => 'scheduled'],
            ['sort' => ['scheduled_at' => 1]]
        )->toArray();
        Response::success(array_map(fn($m) => $this->format($m), $meetings));
    }

    // POST /client/checkins/:id/respond
    public function respond(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $meetingId = new ObjectId($params['id']);
        $body = Request::body();
        $response = $body['client_response'] ?? null;

        if (!in_array($response, ['accepted', 'declined'], true)) {
            Response::error('Validation failed', 422, ['client_response' => ['Must be accepted or declined']]);
        }

        $meeting = Database::collection('checkin_meetings')->findOne([
            '_id' => $meetingId,
            'client_id' => $clientId,
            'status' => 'scheduled',
        ]);

        if (!$meeting) Response::error('Meeting not found', 404);

        Database::collection('checkin_meetings')->updateOne(
            ['_id' => $meetingId],
            ['$set' => [
                'client_response' => $response,
                'client_response_note' => $body['note'] ?? null,
                'client_responded_at' => new UTCDateTime(),
                'proposed_scheduled_at' => null,
                'updated_at' => new UTCDateTime(),
            ]]
        );

        $updatedMeeting = Database::collection('checkin_meetings')->findOne(['_id' => $meetingId]);
        if (!$updatedMeeting) Response::error('Meeting not found', 404);

        Response::success($this->format($updatedMeeting), 'Check-in response saved');
    }

    // POST /client/checkins/:id/reschedule
    public function reschedule(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $meetingId = new ObjectId($params['id']);
        $body = Request::body();
        $errors = Request::validate($body, ['proposed_scheduled_at' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $timestamp = strtotime($body['proposed_scheduled_at']);
        if ($timestamp === false) {
            Response::error('Validation failed', 422, ['proposed_scheduled_at' => ['Invalid date']]);
        }

        $meeting = Database::collection('checkin_meetings')->findOne([
            '_id' => $meetingId,
            'client_id' => $clientId,
            'status' => 'scheduled',
        ]);

        if (!$meeting) Response::error('Meeting not found', 404);

        Database::collection('checkin_meetings')->updateOne(
            ['_id' => $meetingId],
            ['$set' => [
                'client_response' => 'reschedule_requested',
                'client_response_note' => $body['note'] ?? null,
                'client_responded_at' => new UTCDateTime(),
                'proposed_scheduled_at' => new UTCDateTime($timestamp * 1000),
                'updated_at' => new UTCDateTime(),
            ]]
        );

        $updatedMeeting = Database::collection('checkin_meetings')->findOne(['_id' => $meetingId]);
        if (!$updatedMeeting) Response::error('Meeting not found', 404);

        Response::success($this->format($updatedMeeting), 'Reschedule requested');
    }

    private function formatNullableDate(mixed $value): ?string
    {
        if (!$value) return null;

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : date(DATE_ATOM, $timestamp);
    }

    private function format(object $doc): array
    {
        $data = (array) $doc;
        $scheduledAt = $data['scheduled_at'] ?? null;
        $clientResponse = $data['client_response'] ?? 'pending';

        return [
            'id'           => isset($data['_id']) ? (string) $data['_id'] : '',
            'client_id'    => isset($data['client_id']) ? (string) $data['client_id'] : '',
            'scheduled_at' => $scheduledAt ? date('c', (int) ((string) $scheduledAt) / 1000) : null,
            'type'         => $data['type'] ?? 'chat',
            'meeting_link' => $data['meeting_link'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'status'       => $data['status'] ?? 'scheduled',
            'client_response' => in_array($clientResponse, self::CLIENT_RESPONSES, true)
                ? $clientResponse
                : 'pending',
            'client_response_note' => $data['client_response_note'] ?? null,
            'client_responded_at' => $this->formatNullableDate($data['client_responded_at'] ?? null),
            'proposed_scheduled_at' => $this->formatNullableDate($data['proposed_scheduled_at'] ?? null),
        ];
    }
}
