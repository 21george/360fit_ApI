<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;

class NotificationsController
{
    /**
     * GET /notifications
     * List coach's notifications with pagination and optional filtering
     */
    public function index(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $collection = Database::collection('notifications');

        $page = max(1, (int) Request::get('page', 1));
        $perPage = 20;
        $type = Request::get('type');
        $unreadOnly = Request::get('unread') === 'true';

        $filter = ['user_id' => $coachId];
        if ($type) {
            $filter['type'] = $type;
        }
        if ($unreadOnly) {
            $filter['read'] = false;
        }

        $total = $collection->countDocuments($filter);
        $docs = $collection->find($filter, [
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort' => ['sent_at' => -1],
        ]);

        $notifications = [];
        foreach ($docs as $doc) {
            $notifications[] = $this->format($doc);
        }

        Response::paginated($notifications, $total, $page, $perPage);
    }

    /**
     * GET /notifications/unread-count
     * Get count of unread notifications for badge display
     */
    public function unreadCount(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $collection = Database::collection('notifications');

        $count = $collection->countDocuments([
            'user_id' => $coachId,
            'read' => false,
        ]);

        Response::success(['count' => $count]);
    }

    /**
     * POST /notifications/:id/read
     * Mark a single notification as read
     */
    public function markRead(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $notificationId = new ObjectId($params['id']);
        $collection = Database::collection('notifications');

        $notification = $collection->findOne([
            '_id' => $notificationId,
            'user_id' => $coachId,
        ]);

        if (!$notification) {
            Response::error('Notification not found', 404);
            return;
        }

        $collection->updateOne(
            ['_id' => $notificationId],
            ['$set' => ['read' => true]]
        );

        Response::success(null, 'Notification marked as read');
    }

    /**
     * POST /notifications/read-all
     * Mark all notifications as read for the authenticated coach
     */
    public function markAllRead(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $collection = Database::collection('notifications');

        $result = $collection->updateMany(
            ['user_id' => $coachId, 'read' => false],
            ['$set' => ['read' => true]]
        );

        Response::success(['marked' => $result->getModifiedCount()], 'All notifications marked as read');
    }

    /**
     * DELETE /notifications/:id
     * Delete a notification
     */
    public function destroy(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $notificationId = new ObjectId($params['id']);
        $collection = Database::collection('notifications');

        $result = $collection->deleteOne([
            '_id' => $notificationId,
            'user_id' => $coachId,
        ]);

        if ($result->getDeletedCount() === 0) {
            Response::error('Notification not found', 404);
            return;
        }

        Response::success(null, 'Notification deleted');
    }

    /**
     * Format notification document for API response
     */
    private function format(object $doc): array
    {
        return [
            'id' => (string) $doc['_id'],
            'type' => $doc['type'],
            'title' => $doc['title'],
            'body' => $doc['body'],
            'data' => $doc['data'] ?? [],
            'read' => $doc['read'] ?? false,
            'sent_at' => $doc['sent_at'] ? date('Y-m-d H:i:s', (int) ((string) $doc['sent_at']) / 1000) : null,
        ];
    }
}
