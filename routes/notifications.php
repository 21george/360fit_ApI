<?php
declare(strict_types=1);

use App\Controllers\NotificationsController;
use App\Middleware\{StaffMiddleware, ClientMiddleware};

// ── Coach notifications ────────────────────────────────────────────────────
$router->get('/notifications', [NotificationsController::class, 'index'], [StaffMiddleware::class]);
$router->get('/notifications/unread-count', [NotificationsController::class, 'unreadCount'], [StaffMiddleware::class]);
$router->post('/notifications/:id/read', [NotificationsController::class, 'markRead'], [StaffMiddleware::class]);
$router->post('/notifications/read-all', [NotificationsController::class, 'markAllRead'], [StaffMiddleware::class]);
$router->delete('/notifications/:id', [NotificationsController::class, 'destroy'], [StaffMiddleware::class]);

// ── Client notifications ─────────────────────────────────────────────────────
$router->get('/client/notifications', [NotificationsController::class, 'clientIndex'], [ClientMiddleware::class]);
$router->get('/client/notifications/unread-count', [NotificationsController::class, 'clientUnreadCount'], [ClientMiddleware::class]);
$router->post('/client/notifications/:id/read', [NotificationsController::class, 'clientMarkRead'], [ClientMiddleware::class]);
$router->post('/client/notifications/read-all', [NotificationsController::class, 'clientMarkAllRead'], [ClientMiddleware::class]);
