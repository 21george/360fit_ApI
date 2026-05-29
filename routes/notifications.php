<?php
declare(strict_types=1);

use App\Controllers\NotificationsController;
use App\Middleware\CoachMiddleware;

// GET /notifications - List coach's notifications
$router->get('/notifications', [NotificationsController::class, 'index'], [CoachMiddleware::class]);

// GET /notifications/unread-count - Get unread count for badge
$router->get('/notifications/unread-count', [NotificationsController::class, 'unreadCount'], [CoachMiddleware::class]);

// POST /notifications/:id/read - Mark single notification as read
$router->post('/notifications/:id/read', [NotificationsController::class, 'markRead'], [CoachMiddleware::class]);

// POST /notifications/read-all - Mark all notifications as read
$router->post('/notifications/read-all', [NotificationsController::class, 'markAllRead'], [CoachMiddleware::class]);

// DELETE /notifications/:id - Delete notification
$router->delete('/notifications/:id', [NotificationsController::class, 'destroy'], [CoachMiddleware::class]);
