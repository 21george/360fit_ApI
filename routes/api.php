<?php
declare(strict_types=1);

use App\Controllers\{
    AuthController,
    ClientController,
    CoachController,
    WorkoutPlanController,
    WorkoutLogController,
    WorkoutAnalysisController,
    NutritionController,
    FoodController,
    CheckinController,
    MessageController,
    MediaController,
    AnalyticsController,
    GroupChatController,
    LiveTrainingController,
    CoachingSessionController,
    SubscriptionController,
    SettingsController,
    NotificationsController,
    MealLogController,
    WearableController,
    PredictionController,
    StaffController,
    AssessmentController,
    StudioController,
    RehabController
};
use App\Middleware\{CoachMiddleware, ClientMiddleware, AuthRateLimitMiddleware, SubscriptionMiddleware, SetupAuthMiddleware, StaffMiddleware};

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
$router->post('/auth/verify-email', [AuthController::class, 'verifyEmail'], [AuthRateLimitMiddleware::class]);
$router->post('/auth/resend-verification', [AuthController::class, 'resendVerification'], [AuthRateLimitMiddleware::class]);
$router->post('/auth/coach/login',    [AuthController::class, 'coachLogin'],    [AuthRateLimitMiddleware::class]);
$router->post('/auth/client/login',   [AuthController::class, 'clientLogin'],   [AuthRateLimitMiddleware::class]);
$router->post('/auth/refresh',        [AuthController::class, 'refresh'],       [AuthRateLimitMiddleware::class]);
$router->post('/auth/staff/login',    [AuthController::class, 'staffLogin'],    [AuthRateLimitMiddleware::class]);
$router->post('/auth/logout',         [AuthController::class, 'logout']);

// ─── Coach: Profile ──────────────────────────────────────────────────────────
$router->get('/coach/profile',         [CoachController::class, 'me'],             [CoachMiddleware::class]);
$router->put('/coach/profile',         [CoachController::class, 'update'],         [CoachMiddleware::class]);
$router->post('/coach/profile/photo',  [CoachController::class, 'uploadPhoto'],    [CoachMiddleware::class]);
$router->delete('/coach/profile/photo',[CoachController::class, 'deletePhoto'],    [CoachMiddleware::class]);
$router->put('/coach/profile/password',[CoachController::class, 'changePassword'], [CoachMiddleware::class]);
$router->delete('/coach/profile',      [CoachController::class, 'deleteAccount'],  [CoachMiddleware::class]);


// ─── Coach: Registered Coaches ──────────────────────────────────────────────
$router->get('/coaches/registered', [CoachController::class, 'index'], [CoachMiddleware::class]);


// ─── Coach: Analytics ────────────────────────────────────────────────────────
$router->get('/analytics/coach',    [AnalyticsController::class, 'coachAnalytics'],   [CoachMiddleware::class]);
$router->get('/analytics/sessions', [AnalyticsController::class, 'sessionAnalytics'], [CoachMiddleware::class]);


// ─── Coach: Clients ───────────────────────────────────────────────────────────
$router->get('/coach/clients',                  [ClientController::class, 'index'],          [CoachMiddleware::class]);
$router->post('/coach/clients',                 [ClientController::class, 'store'],          [CoachMiddleware::class, SubscriptionMiddleware::class]);
$router->post('/coach/clients/import',          [ClientController::class, 'import'],         [CoachMiddleware::class]);
$router->get('/coach/clients/:id',              [ClientController::class, 'show'],           [CoachMiddleware::class]);
$router->put('/coach/clients/:id',              [ClientController::class, 'update'],         [CoachMiddleware::class]);
$router->delete('/coach/clients/:id',           [ClientController::class, 'destroy'],        [CoachMiddleware::class]);
$router->delete('/coach/clients/:id/permanent',  [ClientController::class, 'permanentDelete'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/photo',         [ClientController::class, 'uploadClientPhoto'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/regenerate-code', [ClientController::class, 'regenerateCode'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/block',   [ClientController::class, 'block'],   [CoachMiddleware::class]);
$router->post('/coach/clients/:id/unblock', [ClientController::class, 'unblock'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/analytics',   [ClientController::class, 'analytics'],      [CoachMiddleware::class]);
$router->get('/coach/clients/:id/logs',        [WorkoutLogController::class, 'clientLogs'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/live-progress',    [WorkoutLogController::class, 'liveProgress'],         [CoachMiddleware::class]);
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

// ─── Coach: Workout Analyses ──────────────────────────────────────────────────
$router->get('/coach/clients/:id/workout-analyses', [WorkoutAnalysisController::class, 'index'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses', [WorkoutAnalysisController::class, 'create'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/workout-analyses/recommendations', [WorkoutAnalysisController::class, 'recommendations'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/workout-analyses/:analysisId', [WorkoutAnalysisController::class, 'show'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses/:analysisId/approve', [WorkoutAnalysisController::class, 'approve'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses/:analysisId/reject', [WorkoutAnalysisController::class, 'reject'], [CoachMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses/:analysisId/assign', [WorkoutAnalysisController::class, 'assign'], [CoachMiddleware::class]);

// ─── Coach: Foods ────────────────────────────────────────────────────────────
$router->get('/foods/search',        [FoodController::class, 'search'],    [CoachMiddleware::class]);
$router->get('/foods/calculate',     [FoodController::class, 'calculate'], [CoachMiddleware::class]);
$router->get('/foods/fdc/:fdcId',    [FoodController::class, 'details'],   [CoachMiddleware::class]);
$router->get('/foods',               [FoodController::class, 'index'],     [CoachMiddleware::class]);
$router->get('/foods/:id',           [FoodController::class, 'show'],      [CoachMiddleware::class]);
$router->post('/foods',              [FoodController::class, 'store'],     [CoachMiddleware::class]);

// ─── Coach: Nutrition Plans ───────────────────────────────────────────────────
$router->get('/nutrition-plans',        [NutritionController::class, 'index'],  [CoachMiddleware::class]);
$router->post('/nutrition-plans',       [NutritionController::class, 'store'],  [CoachMiddleware::class]);
$router->get('/nutrition-plans/:id',    [NutritionController::class, 'show'],    [CoachMiddleware::class]);
$router->put('/nutrition-plans/:id',    [NutritionController::class, 'update'],  [CoachMiddleware::class]);
$router->delete('/nutrition-plans/:id', [NutritionController::class, 'destroy'], [CoachMiddleware::class]);
$router->post('/nutrition-plans/:id/assign', [NutritionController::class, 'assign'], [CoachMiddleware::class]);

// ─── Coach: Check-ins ─────────────────────────────────────────────────────────
$router->get('/checkins',      [CheckinController::class, 'index'],   [CoachMiddleware::class]);
$router->post('/checkins',     [CheckinController::class, 'store'],   [CoachMiddleware::class]);
$router->put('/checkins/:id',  [CheckinController::class, 'update'],  [CoachMiddleware::class]);
$router->delete('/checkins/:id',[CheckinController::class, 'destroy'],[CoachMiddleware::class]);
$router->post('/checkins/:id/status',[CheckinController::class, 'updateStatus'],[CoachMiddleware::class]);

// ─── Coach: Messages ──────────────────────────────────────────────────────────
// Static routes MUST be registered before the dynamic :clientId route to prevent
// "unread" and "read-all" from being parsed as a MongoDB ObjectId.
$router->get('/messages/unread',         [MessageController::class, 'unreadMessages'],  [CoachMiddleware::class]);
$router->get('/messages/unread-count',   [MessageController::class, 'unreadCount'],     [CoachMiddleware::class]);
$router->post('/messages/read-all',      [MessageController::class, 'markAllRead'],     [CoachMiddleware::class]);
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

// ─── Coach: Coaching Sessions ─────────────────────────────────────────────────
$router->get('/coaching-sessions',                         [CoachingSessionController::class, 'index'],        [CoachMiddleware::class]);
$router->post('/coaching-sessions',                        [CoachingSessionController::class, 'store'],         [CoachMiddleware::class]);
$router->get('/coaching-sessions/:id',                     [CoachingSessionController::class, 'show'],          [CoachMiddleware::class]);
$router->put('/coaching-sessions/:id',                     [CoachingSessionController::class, 'update'],        [CoachMiddleware::class]);
$router->delete('/coaching-sessions/:id',                [CoachingSessionController::class, 'destroy'],       [CoachMiddleware::class]);
$router->post('/coaching-sessions/:id/status',           [CoachingSessionController::class, 'updateStatus'],   [CoachMiddleware::class]);
$router->post('/coaching-sessions/:id/token',            [CoachingSessionController::class, 'getToken'],      [CoachMiddleware::class]);
$router->post('/coaching-sessions/:id/notes',            [CoachingSessionController::class, 'saveNotes'],      [CoachMiddleware::class]);

// ─── Client: Coaching Sessions ──────────────────────────────────────────────────
$router->get('/client/coaching-sessions',                  [CoachingSessionController::class, 'clientIndex'],       [ClientMiddleware::class]);
$router->get('/client/coaching-sessions/:id',              [CoachingSessionController::class, 'clientShow'],        [ClientMiddleware::class]);
$router->post('/client/coaching-sessions/:id/token',       [CoachingSessionController::class, 'clientToken'],       [ClientMiddleware::class]);

// ─── Client: Analytics ────────────────────────────────────────────────────────
$router->get('/client/analytics',                   [AnalyticsController::class, 'clientSelfAnalytics'],    [ClientMiddleware::class]);

// ─── Client: Meal Completion ──────────────────────────────────────────────────
$router->post('/client/meal-logs',   [MealLogController::class, 'store'],   [ClientMiddleware::class]);
$router->delete('/client/meal-logs', [MealLogController::class, 'destroy'], [ClientMiddleware::class]);

// ─── Coach: Meal Logs + Adherence ─────────────────────────────────────────────
$router->get('/coach/clients/:id/meal-logs', [MealLogController::class, 'clientLogs'], [CoachMiddleware::class]);
$router->get('/coach/clients/:id/adherence', [MealLogController::class, 'adherence'],  [CoachMiddleware::class]);

// ─── Client: Wearable ─────────────────────────────────────────────────────────
$router->post('/client/wearable/data',           [WearableController::class, 'pushData'],      [ClientMiddleware::class]);
$router->get('/client/wearable/status',          [WearableController::class, 'status'],        [ClientMiddleware::class]);
$router->delete('/client/wearable/disconnect',   [WearableController::class, 'disconnect'],    [ClientMiddleware::class]);
$router->get('/client/wearable/fitbit/connect',  [WearableController::class, 'fitbitConnect'], [ClientMiddleware::class]);
$router->get('/client/wearable/fitbit/callback', [WearableController::class, 'fitbitCallback']);

// ─── Coach: Client Wearable Data ─────────────────────────────────────────────
$router->get('/coach/clients/:id/wearable', [WearableController::class, 'clientData'], [CoachMiddleware::class]);

// ─── Progress Prediction ──────────────────────────────────────────────────────
$router->get('/coach/clients/:id/prediction', [PredictionController::class, 'show'], [CoachMiddleware::class]);
$router->get('/client/prediction',            [PredictionController::class, 'show'], [ClientMiddleware::class]);

// ─── Subscription ─────────────────────────────────────────────────────────────
$router->post('/subscription/select-plan',   [SubscriptionController::class, 'selectPlan'],   [SetupAuthMiddleware::class]);
$router->post('/subscription/setup-trial',   [SubscriptionController::class, 'setupTrial'],   [SetupAuthMiddleware::class]);
$router->post('/subscription/confirm-trial', [SubscriptionController::class, 'confirmTrial'], [SetupAuthMiddleware::class]);
$router->get('/subscription',          [SubscriptionController::class, 'status'],   [CoachMiddleware::class]);
$router->post('/subscription/checkout',[SubscriptionController::class, 'checkout'], [CoachMiddleware::class]);
$router->post('/subscription/portal',  [SubscriptionController::class, 'portal'],   [CoachMiddleware::class]);
$router->post('/subscription/cancel',  [SubscriptionController::class, 'cancel'],   [CoachMiddleware::class]);
$router->post('/subscription/upgrade', [SubscriptionController::class, 'upgrade'],  [CoachMiddleware::class]);
$router->get('/subscription/invoices', [SubscriptionController::class, 'invoices'], [CoachMiddleware::class]);
$router->get('/subscription/invoices/:id/download', [SubscriptionController::class, 'downloadInvoice'], [CoachMiddleware::class]);
$router->post('/subscription/webhook', [SubscriptionController::class, 'webhook']);

// ─── Payment Methods ──────────────────────────────────────────────────────────
$router->get('/payment-methods',              [SubscriptionController::class, 'listPaymentMethods'],   [CoachMiddleware::class]);
$router->post('/payment-methods',             [SubscriptionController::class, 'addPaymentMethod'],     [CoachMiddleware::class]);
$router->post('/payment-methods/setup-intent',[SubscriptionController::class, 'createSetupIntent'],   [CoachMiddleware::class]);
$router->delete('/payment-methods/:id',       [SubscriptionController::class, 'deletePaymentMethod'],  [CoachMiddleware::class]);
$router->post('/payment-methods/:id/default', [SubscriptionController::class, 'setDefaultPaymentMethod'], [CoachMiddleware::class]);

// ─── Settings ─────────────────────────────────────────────────────────────────
$router->get('/integrations/settings',  [SettingsController::class, 'getIntegrations'],          [CoachMiddleware::class]);
$router->put('/integrations/settings',  [SettingsController::class, 'updateIntegrations'],       [CoachMiddleware::class]);
$router->get('/notification-settings',  [SettingsController::class, 'getNotificationSettings'],  [CoachMiddleware::class]);
$router->put('/notification-settings',  [SettingsController::class, 'updateNotificationSettings'],[CoachMiddleware::class]);
$router->post('/support/contact',       [SettingsController::class, 'createSupportTicket'],      [CoachMiddleware::class]);

// ─── Notifications ──────────────────────────────────────────────────────────────
require_once BASE_PATH . '/routes/notifications.php';

// ─── Organizations & Staff / RBAC (Feature 6) ────────────────────────────────
// StaffMiddleware accepts either a legacy coach JWT (implicit "owner") or a
// staff JWT, resolving org_id + role onto $params for every route below.
$router->post('/org/staff/invite',        [StaffController::class, 'invite'],       [StaffMiddleware::class]);
$router->get('/org/staff',                [StaffController::class, 'index'],        [StaffMiddleware::class]);
$router->post('/org/staff/accept-invite', [StaffController::class, 'acceptInvite']);
$router->patch('/org/staff/:id/role',     [StaffController::class, 'updateRole'],    [StaffMiddleware::class]);
$router->post('/org/staff/:id/deactivate',[StaffController::class, 'deactivate'],    [StaffMiddleware::class]);
$router->patch('/org/clients/:id/assignment', [StaffController::class, 'assignClient'], [StaffMiddleware::class]);

// ─── Sports-science Assessments (Feature 7) ──────────────────────────────────
$router->post('/org/clients/:id/assessments',        [AssessmentController::class, 'store'],  [StaffMiddleware::class]);
$router->get('/org/clients/:id/assessments',          [AssessmentController::class, 'index'],  [StaffMiddleware::class]);
$router->get('/org/clients/:id/assessments/latest',   [AssessmentController::class, 'latest'], [StaffMiddleware::class]);

// ─── Studio Operations (Feature 9) ───────────────────────────────────────────
$router->post('/org/classes',                 [StudioController::class, 'createClass'],  [StaffMiddleware::class]);
$router->get('/org/classes',                  [StudioController::class, 'index'],        [StaffMiddleware::class]);
$router->get('/client/classes',               [StudioController::class, 'indexForClient'],[ClientMiddleware::class]);
$router->post('/client/classes/:id/book',     [StudioController::class, 'bookAsClient'], [ClientMiddleware::class]);
$router->post('/org/classes/:id/book',        [StudioController::class, 'bookAsStaff'],  [StaffMiddleware::class]);
$router->post('/client/bookings/:id/cancel',  [StudioController::class, 'cancelAsClient'],[ClientMiddleware::class]);
$router->post('/org/bookings/:id/cancel',     [StudioController::class, 'cancelAsStaff'], [StaffMiddleware::class]);
$router->post('/org/memberships',             [StudioController::class, 'createMembership'],       [StaffMiddleware::class]);
$router->get('/org/memberships',              [StudioController::class, 'listMemberships'],        [StaffMiddleware::class]);
$router->post('/org/memberships/:id/status',  [StudioController::class, 'updateMembershipStatus'],  [StaffMiddleware::class]);
$router->post('/client/consent-documents',    [StudioController::class, 'signConsentAsClient'],     [ClientMiddleware::class]);
$router->post('/org/clients/:id/checkin',     [StudioController::class, 'checkinAsStaff'],  [StaffMiddleware::class]);
$router->post('/client/checkin',              [StudioController::class, 'checkinAsClient'], [ClientMiddleware::class]);
$router->get('/org/reports/utilization',      [StudioController::class, 'utilizationReport'],[StaffMiddleware::class]);

// ─── Physiotherapy / Rehab Track (Feature 8) ─────────────────────────────────
$router->post('/client/parq',                       [RehabController::class, 'submitParq'], [ClientMiddleware::class]);
$router->get('/org/clients/:id/parq',                [RehabController::class, 'getParq'],    [StaffMiddleware::class]);
$router->post('/org/clients/:id/rehab-track/start',  [RehabController::class, 'startTrack'], [StaffMiddleware::class]);
$router->get('/org/clients/:id/rehab-track',         [RehabController::class, 'getTrack'],   [StaffMiddleware::class]);
$router->post('/org/rehab-tracks/:id/proms',         [RehabController::class, 'recordProm'], [StaffMiddleware::class]);
$router->post('/org/rehab-tracks/:id/pain-logs',     [RehabController::class, 'logPainAsStaff'],  [StaffMiddleware::class]);
$router->post('/client/rehab-tracks/:id/pain-logs',  [RehabController::class, 'logPainAsClient'], [ClientMiddleware::class]);
$router->post('/org/rehab-tracks/:id/soap-notes',    [RehabController::class, 'addSoapNote'],   [StaffMiddleware::class]);
$router->get('/org/rehab-tracks/:id/soap-notes',     [RehabController::class, 'listSoapNotes'], [StaffMiddleware::class]);

// ─── Client: Notifications ────────────────────────────────────────────────────
$router->get('/client/notifications',               [NotificationsController::class, 'clientIndex'],       [ClientMiddleware::class]);
$router->get('/client/notifications/unread-count',  [NotificationsController::class, 'clientUnreadCount'], [ClientMiddleware::class]);
$router->post('/client/notifications/:id/read',     [NotificationsController::class, 'clientMarkRead'],    [ClientMiddleware::class]);
$router->post('/client/notifications/read-all',     [NotificationsController::class, 'clientMarkAllRead'], [ClientMiddleware::class]);
