<?php
declare(strict_types=1);

use App\Controllers\{
    AuthController,
    ClientController,
    CoachController,
    WorkoutPlanController,
    WorkoutLogController,
    NutritionController,
    CheckinController,
    MessageController,
    MediaController,
    AnalyticsController,
    GroupChatController,
    LiveTrainingController,
    SubscriptionController,
    NotificationsController
};
use App\Middleware\{AuthMiddleware, CoachMiddleware, ClientMiddleware, AuthRateLimitMiddleware, SubscriptionMiddleware, SetupAuthMiddleware};

// ─── Health Check ─────────────────────────────────────────────────────────────
$router->get('/health', function () {
    try {
        \App\Config\Database::collection('coaches')->findOne([], ['projection' => ['_id' => 1], 'limit' => 1]);
        \App\Helpers\Response::json(['status' => 'ok', 'version' => '1.0.0']);
    } catch (\Throwable $e) {
        error_log('Health check failed: ' . $e->getMessage());
        \App\Helpers\Response::json(['status' => 'degraded', 'error' => 'Database unreachable'], 503);
    }
});

// ─── Auth (rate-limited) ──────────────────────────────────────────────────────
$router->post('/auth/coach/register', [AuthController::class, 'coachRegister'], [AuthRateLimitMiddleware::class]);
$router->post('/auth/coach/login',    [AuthController::class, 'coachLogin'],    [AuthRateLimitMiddleware::class]);
$router->post('/auth/client/login',   [AuthController::class, 'clientLogin'],   [AuthRateLimitMiddleware::class]);
$router->post('/auth/refresh',        [AuthController::class, 'refresh'],       [AuthRateLimitMiddleware::class]);
$router->post('/auth/logout',         [AuthController::class, 'logout']);

// ─── Coach: Profile ──────────────────────────────────────────────────────────
$router->get('/coach/profile',         [CoachController::class, 'me'],             [CoachMiddleware::class]);
$router->put('/coach/profile',         [CoachController::class, 'update'],         [CoachMiddleware::class]);
$router->post('/coach/profile/photo',  [CoachController::class, 'uploadPhoto'],    [CoachMiddleware::class]);
$router->delete('/coach/profile/photo',[CoachController::class, 'deletePhoto'],    [CoachMiddleware::class]);
$router->put('/coach/profile/password',[CoachController::class, 'changePassword'], [CoachMiddleware::class]);


// ─── Coach: Registered Coaches ──────────────────────────────────────────────
$router->get('/coaches/registered', [CoachController::class, 'index'], [CoachMiddleware::class]);


// ─── Coach: Clients ───────────────────────────────────────────────────────────
$router->get('/coach/clients',                  [ClientController::class, 'index'],          [CoachMiddleware::class]);
$router->post('/coach/clients',                 [ClientController::class, 'store'],          [CoachMiddleware::class, SubscriptionMiddleware::class]);
$router->get('/coach/clients/:id',              [ClientController::class, 'show'],           [CoachMiddleware::class]);
$router->put('/coach/clients/:id',              [ClientController::class, 'update'],         [CoachMiddleware::class]);
$router->delete('/coach/clients/:id',           [ClientController::class, 'destroy'],        [CoachMiddleware::class]);
$router->post('/coach/clients/:id/regenerate-code', [ClientController::class, 'regenerateCode'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/block',   [ClientController::class, 'block'],   [CoachMiddleware::class]);
$router->post('/coach/clients/:id/unblock', [ClientController::class, 'unblock'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/analytics',   [ClientController::class, 'analytics'],      [CoachMiddleware::class]);
$router->get('/coach/clients/:id/logs',        [WorkoutLogController::class, 'clientLogs'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/workout-progress', [WorkoutLogController::class, 'clientWorkoutProgress'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/measurements',[MediaController::class, 'storeMeasurement'],[CoachMiddleware::class]);
$router->get('/coach/media/:clientId',         [MediaController::class, 'clientMedia'],     [CoachMiddleware::class]);

// ─── Coach: Workout Plans ─────────────────────────────────────────────────────
$router->get('/workout-plans',         [WorkoutPlanController::class, 'index'],   [CoachMiddleware::class]);
$router->get('/workout-plans/saved',   [WorkoutPlanController::class, 'savedPlans'], [CoachMiddleware::class]);
$router->post('/workout-plans',        [WorkoutPlanController::class, 'store'],   [CoachMiddleware::class]);
$router->post('/workout-plans/import', [WorkoutPlanController::class, 'import'],  [CoachMiddleware::class]);
$router->post('/workout-plans/import-drive', [WorkoutPlanController::class, 'importDrive'], [CoachMiddleware::class]);
$router->get('/workout-plans/:id',     [WorkoutPlanController::class, 'show'],    [CoachMiddleware::class]);
$router->put('/workout-plans/:id',     [WorkoutPlanController::class, 'update'],  [CoachMiddleware::class]);
$router->delete('/workout-plans/:id',  [WorkoutPlanController::class, 'destroy'], [CoachMiddleware::class]);
$router->post('/workout-plans/:id/assign', [WorkoutPlanController::class, 'assign'], [CoachMiddleware::class]);

// ─── Coach: Nutrition Plans ───────────────────────────────────────────────────
$router->get('/nutrition-plans',     [NutritionController::class, 'index'],  [CoachMiddleware::class]);
$router->post('/nutrition-plans',    [NutritionController::class, 'store'],  [CoachMiddleware::class]);
$router->get('/nutrition-plans/:id',    [NutritionController::class, 'show'],    [CoachMiddleware::class]);
$router->put('/nutrition-plans/:id',    [NutritionController::class, 'update'],  [CoachMiddleware::class]);
$router->delete('/nutrition-plans/:id', [NutritionController::class, 'destroy'], [CoachMiddleware::class]);

// ─── Coach: Check-ins ─────────────────────────────────────────────────────────
$router->get('/checkins',      [CheckinController::class, 'index'],   [CoachMiddleware::class]);
$router->post('/checkins',     [CheckinController::class, 'store'],   [CoachMiddleware::class]);
$router->put('/checkins/:id',  [CheckinController::class, 'update'],  [CoachMiddleware::class]);
$router->delete('/checkins/:id',[CheckinController::class, 'destroy'],[CoachMiddleware::class]);

// ─── Coach: Messages ──────────────────────────────────────────────────────────
$router->get('/messages/:clientId/typing', [MessageController::class, 'coachTypingStatus'], [CoachMiddleware::class]);
$router->post('/messages/:clientId/typing', [MessageController::class, 'updateCoachTyping'], [CoachMiddleware::class]);
$router->get('/messages/:clientId', [MessageController::class, 'coachThread'], [CoachMiddleware::class]);
$router->post('/messages',          [MessageController::class, 'send'],        [CoachMiddleware::class]);
$router->post('/messages/upload-media', [MessageController::class, 'uploadMedia'], [CoachMiddleware::class]);

// ─── Client Routes ────────────────────────────────────────────────────────────
$router->get('/client/profile',               [ClientController::class, 'clientProfile'], [ClientMiddleware::class]);
$router->put('/client/profile',               [ClientController::class, 'updateClientProfile'], [ClientMiddleware::class]);
$router->put('/client/profile/photo',         [ClientController::class, 'updateClientProfilePhoto'], [ClientMiddleware::class]);
$router->post('/client/fcm-token',            [ClientController::class, 'updateFcmToken'], [ClientMiddleware::class]);
$router->get('/client/workout-plan/current',  [WorkoutLogController::class, 'currentPlan'], [ClientMiddleware::class]);
$router->get('/client/workout-plan/history',  [WorkoutLogController::class, 'history'],     [ClientMiddleware::class]);
$router->get('/client/group-workout-plans',   [WorkoutLogController::class, 'clientGroupPlans'], [ClientMiddleware::class]);
$router->post('/client/workout-logs',         [WorkoutLogController::class, 'store'],       [ClientMiddleware::class]);
$router->put('/client/workout-logs/:id',      [WorkoutLogController::class, 'update'],      [ClientMiddleware::class]);
$router->post('/client/workout-logs/:id/media',[WorkoutLogController::class, 'addMedia'],   [ClientMiddleware::class]);
$router->get('/client/nutrition-plan',        [NutritionController::class, 'clientPlan'],   [ClientMiddleware::class]);
$router->get('/client/checkins',              [CheckinController::class, 'clientCheckins'], [ClientMiddleware::class]);
$router->post('/client/checkins/:id/respond', [CheckinController::class, 'respond'], [ClientMiddleware::class]);
$router->post('/client/checkins/:id/reschedule', [CheckinController::class, 'reschedule'], [ClientMiddleware::class]);
$router->get('/client/messages/typing',       [MessageController::class, 'clientTypingStatus'], [ClientMiddleware::class]);
$router->post('/client/messages/typing',      [MessageController::class, 'updateClientTyping'], [ClientMiddleware::class]);
$router->get('/client/messages',              [MessageController::class, 'clientThread'],   [ClientMiddleware::class]);
$router->post('/client/messages',             [MessageController::class, 'send'],           [ClientMiddleware::class]);
$router->post('/client/messages/upload-media',[MessageController::class, 'uploadMedia'],    [ClientMiddleware::class]);
// ─── Client: Group Chat ──────────────────────────────────────────────────────
$router->get('/client/group-workout-plans/:planId/messages',  [GroupChatController::class, 'messages'], [ClientMiddleware::class]);
$router->post('/client/group-workout-plans/:planId/messages', [GroupChatController::class, 'send'],     [ClientMiddleware::class]);

$router->get('/client/media',                 [MediaController::class, 'myMedia'],          [ClientMiddleware::class]);
$router->post('/client/media/upload',         [MediaController::class, 'uploadFile'],        [ClientMiddleware::class]);
$router->post('/media/presigned-url',         [MediaController::class, 'presignedUrl'],     [ClientMiddleware::class]);

// ─── Coach: Live Training ─────────────────────────────────────────────────────
$router->get('/live-training',                         [LiveTrainingController::class, 'index'],          [CoachMiddleware::class]);
$router->post('/live-training',                        [LiveTrainingController::class, 'store'],          [CoachMiddleware::class]);
$router->get('/live-training/:id',                     [LiveTrainingController::class, 'show'],           [CoachMiddleware::class]);
$router->put('/live-training/:id',                     [LiveTrainingController::class, 'update'],         [CoachMiddleware::class]);
$router->delete('/live-training/:id',                  [LiveTrainingController::class, 'destroy'],        [CoachMiddleware::class]);
$router->post('/live-training/:id/go-live',            [LiveTrainingController::class, 'goLive'],         [CoachMiddleware::class]);
$router->post('/live-training/:id/end',                [LiveTrainingController::class, 'endSession'],     [CoachMiddleware::class]);
$router->get('/live-training/:id/requests',            [LiveTrainingController::class, 'listRequests'],   [CoachMiddleware::class]);
$router->post('/live-training/:id/requests',           [LiveTrainingController::class, 'handleRequest'],  [CoachMiddleware::class]);
$router->get('/live-training/:id/participants',        [LiveTrainingController::class, 'participants'],   [CoachMiddleware::class]);
$router->get('/live-training/:id/chat',                [LiveTrainingController::class, 'getChat'],        [CoachMiddleware::class]);
$router->post('/live-training/:id/chat',               [LiveTrainingController::class, 'sendChat'],       [CoachMiddleware::class]);

// ─── Client: Live Training ────────────────────────────────────────────────────
$router->get('/client/live-training',                  [LiveTrainingController::class, 'clientIndex'],          [ClientMiddleware::class]);
$router->get('/client/live-training/:id',              [LiveTrainingController::class, 'clientShow'],           [ClientMiddleware::class]);
$router->post('/client/live-training/:id/join',        [LiveTrainingController::class, 'clientJoin'],           [ClientMiddleware::class]);
$router->get('/client/live-training/:id/participants', [LiveTrainingController::class, 'clientParticipants'],   [ClientMiddleware::class]);
$router->get('/client/live-training/:id/chat',         [LiveTrainingController::class, 'clientGetChat'],        [ClientMiddleware::class]);
$router->post('/client/live-training/:id/chat',        [LiveTrainingController::class, 'clientSendChat'],       [ClientMiddleware::class]);

// ─── Client: Analytics ────────────────────────────────────────────────────────
$router->get('/client/analytics',                   [AnalyticsController::class, 'clientSelfAnalytics'],    [ClientMiddleware::class]);

// ─── Subscription ─────────────────────────────────────────────────────────────
$router->post('/subscription/select-plan', [SubscriptionController::class, 'selectPlan'], [SetupAuthMiddleware::class]);
$router->get('/subscription',          [SubscriptionController::class, 'status'],   [CoachMiddleware::class]);
$router->post('/subscription/checkout',[SubscriptionController::class, 'checkout'], [CoachMiddleware::class]);
$router->post('/subscription/portal',  [SubscriptionController::class, 'portal'],   [CoachMiddleware::class]);
$router->post('/subscription/cancel',  [SubscriptionController::class, 'cancel'],   [CoachMiddleware::class]);
$router->post('/subscription/webhook', [SubscriptionController::class, 'webhook']);

// ─── Notifications ──────────────────────────────────────────────────────────────
require_once BASE_PATH . '/routes/notifications.php';

// ─── Client: Notifications ────────────────────────────────────────────────────
$router->get('/client/notifications',               [NotificationsController::class, 'clientIndex'],       [ClientMiddleware::class]);
$router->get('/client/notifications/unread-count',  [NotificationsController::class, 'clientUnreadCount'], [ClientMiddleware::class]);
$router->post('/client/notifications/:id/read',     [NotificationsController::class, 'clientMarkRead'],    [ClientMiddleware::class]);
$router->post('/client/notifications/read-all',     [NotificationsController::class, 'clientMarkAllRead'], [ClientMiddleware::class]);
