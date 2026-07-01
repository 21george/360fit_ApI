<?php
declare(strict_types=1);

use App\Controllers\NotificationsController;
use App\Middleware\AuthMiddleware;

$router->get('/notifications', [NotificationsController::class, 'index'], [AuthMiddleware::class]);
$router->get('/notifications/unread-count', [NotificationsController::class, 'unreadCount'], [AuthMiddleware::class]);
$router->post('/notifications/:id/read', [NotificationsController::class, 'markRead'], [AuthMiddleware::class]);
$router->post('/notifications/read-all', [NotificationsController::class, 'markAllRead'], [AuthMiddleware::class]);
$router->delete('/notifications/:id', [NotificationsController::class, 'destroy'], [AuthMiddleware::class]);
