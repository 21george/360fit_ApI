<?php
declare(strict_types=1);

use App\Controllers\NotificationsController;
use App\Middleware\CoachMiddleware;

// Notifications are a coach-owned resource; require a coach token
// and populate RequestContext so RLS can scope by coach_id.
$router->get('/notifications', [NotificationsController::class, 'index'], [CoachMiddleware::class]);
$router->get('/notifications/unread-count', [NotificationsController::class, 'unreadCount'], [CoachMiddleware::class]);
$router->post('/notifications/:id/read', [NotificationsController::class, 'markRead'], [CoachMiddleware::class]);
$router->post('/notifications/read-all', [NotificationsController::class, 'markAllRead'], [CoachMiddleware::class]);
$router->delete('/notifications/:id', [NotificationsController::class, 'destroy'], [CoachMiddleware::class]);
