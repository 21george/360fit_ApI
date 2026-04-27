<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class GroupChatController
{
    // GET /client/group-workout-plans/:planId/messages
    public function messages(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $plan     = $this->verifyMembership($clientId, $params['planId']);

        $page    = max(1, (int) Request::get('page', 1));
        $perPage = 60;
        $col     = Database::collection('group_messages');
        $filter  = ['plan_id' => $plan['_id']];

        $total = $col->countDocuments($filter);
        $msgs  = $col->find($filter, [
            'sort'  => ['sent_at' => -1],
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
        ])->toArray();

        $result = array_reverse(array_map(fn($m) => [
            'id'          => (string) $m['_id'],
            'sender_id'   => (string) $m['sender_id'],
            'sender_name' => $m['sender_name'],
            'sender_photo'=> $m['sender_photo'] ?? null,
            'content'     => $m['content'],
            'sent_at'     => $this->formatDate($m['sent_at'] ?? null),
        ], $msgs));

        Response::paginated($result, $total, $page, $perPage);
    }

    // POST /client/group-workout-plans/:planId/messages
    public function send(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $plan     = $this->verifyMembership($clientId, $params['planId']);

        $body    = Request::body();
        $content = trim($body['content'] ?? '');

        if ($content === '') {
            Response::error('content is required', 422);
        }

        // Fetch sender profile for name / photo
        $sender = Database::collection('clients')->findOne(
            ['_id' => $clientId],
            ['projection' => ['name' => 1, 'profile_photo_url' => 1]]
        );

        $doc = [
            'plan_id'     => $plan['_id'],
            'sender_id'   => $clientId,
            'sender_name' => $sender['name'] ?? $params['_auth']['name'] ?? 'Member',
            'sender_photo'=> $sender['profile_photo_url'] ?? null,
            'content'     => $content,
            'sent_at'     => new UTCDateTime(),
        ];

        $inserted = Database::collection('group_messages')->insertOne($doc);

        Response::success([
            'id'          => (string) $inserted->getInsertedId(),
            'sender_id'   => (string) $clientId,
            'sender_name' => $doc['sender_name'],
            'sender_photo'=> $doc['sender_photo'],
            'content'     => $doc['content'],
            'sent_at'     => date('c'),
        ], 'Message sent', 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Verify the client is a member of the group/team plan; returns the plan document. */
    private function verifyMembership(ObjectId $clientId, string $rawPlanId): object
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $rawPlanId)) {
            Response::error('Invalid plan ID', 400);
        }

        $planId = new ObjectId($rawPlanId);
        $plan   = Database::collection('workout_plans')->findOne([
            '_id'        => $planId,
            'plan_type'  => ['$in' => ['group', 'team']],
            'client_ids' => ['$in' => [$clientId]],
        ]);

        if (!$plan) {
            Response::error('Group workout plan not found or access denied', 404);
        }

        return $plan;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }
        if (is_numeric($value)) {
            return date(DATE_ATOM, (int) $value);
        }
        return null;
    }
}
