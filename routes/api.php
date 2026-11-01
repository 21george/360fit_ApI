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
    StudioClassController,
    StudioBookingController,
    StudioMembershipController,
    RehabController,
    ExerciseController,
    ProgressPhotoController,
    FoodDiaryController,
    HabitController,
    GoalController,
    SessionPackageController,
    PrRecordController,
    TeamMeetingController,
    DashboardController
};
use App\Middleware\{CoachMiddleware, ClientMiddleware, AuthRateLimitMiddleware, SubscriptionMiddleware, SetupAuthMiddleware, StaffMiddleware, RoleGateMiddleware};

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
$router->post('/auth/complete-onboarding', [AuthController::class, 'completeOnboarding'], [AuthRateLimitMiddleware::class]);
$router->post('/auth/forgot-password',  [AuthController::class, 'forgotPassword'],  [AuthRateLimitMiddleware::class]);
$router->post('/auth/reset-password',   [AuthController::class, 'resetPassword'],    [AuthRateLimitMiddleware::class]);
$router->post('/auth/logout',         [AuthController::class, 'logout']);

// ─── Coach: Profile ──────────────────────────────────────────────────────────
$router->get('/coach/profile',         [CoachController::class, 'me'],             [CoachMiddleware::class]);
$router->put('/coach/profile',         [CoachController::class, 'update'],         [CoachMiddleware::class]);
$router->post('/coach/profile/photo',  [CoachController::class, 'uploadPhoto'],    [CoachMiddleware::class]);
$router->delete('/coach/profile/photo',[CoachController::class, 'deletePhoto'],    [CoachMiddleware::class]);
$router->put('/coach/profile/password',[CoachController::class, 'changePassword'], [CoachMiddleware::class]);
$router->put('/coach/fcm-token',        [CoachController::class, 'updateFcmToken'], [CoachMiddleware::class]);
$router->put('/coach/branding',        [CoachController::class, 'updateBranding'], [CoachMiddleware::class]);
$router->delete('/coach/profile',      [CoachController::class, 'deleteAccount'],  [CoachMiddleware::class]);


// ─── Coach: Registered Coaches ──────────────────────────────────────────────
$router->get('/coaches/registered', [CoachController::class, 'index'], [CoachMiddleware::class]);


// ─── Coach: Analytics ────────────────────────────────────────────────────────
$router->get('/analytics/coach',    [AnalyticsController::class, 'coachAnalytics'],   [StaffMiddleware::class]);
$router->get('/analytics/sessions', [AnalyticsController::class, 'sessionAnalytics'], [StaffMiddleware::class]);

// ─── Team Meetings ───────────────────────────────────────────────────────────
$router->get('/team-meetings', [TeamMeetingController::class, 'index'], [StaffMiddleware::class]);
$router->post('/team-meetings', [TeamMeetingController::class, 'store'], [StaffMiddleware::class]);
$router->get('/team-meetings/:id', [TeamMeetingController::class, 'show'], [StaffMiddleware::class]);
$router->put('/team-meetings/:id', [TeamMeetingController::class, 'update'], [StaffMiddleware::class]);
$router->delete('/team-meetings/:id', [TeamMeetingController::class, 'destroy'], [StaffMiddleware::class]);
$router->post('/team-meetings/:id/status', [TeamMeetingController::class, 'updateStatus'], [StaffMiddleware::class]);

// ─── Dashboard ─────────────────────────────────────────────────────────────────
$router->get('/dashboard/weekly-sessions', [DashboardController::class, 'weeklySessions'], [StaffMiddleware::class]);


// ─── Coach: Clients ───────────────────────────────────────────────────────────
$router->get('/coach/clients',                  [ClientController::class, 'index'],          [StaffMiddleware::class]);
$router->post('/coach/clients',                 [ClientController::class, 'store'],          [StaffMiddleware::class, SubscriptionMiddleware::class]);
$router->post('/coach/clients/import',          [ClientController::class, 'import'],         [StaffMiddleware::class, SubscriptionMiddleware::class]);
$router->get('/coach/clients/:id',              [ClientController::class, 'show'],           [StaffMiddleware::class]);
$router->put('/coach/clients/:id',              [ClientController::class, 'update'],         [StaffMiddleware::class]);
$router->delete('/coach/clients/:id',           [ClientController::class, 'destroy'],        [StaffMiddleware::class]);
$router->delete('/coach/clients/:id/permanent',  [ClientController::class, 'permanentDelete'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/photo',         [ClientController::class, 'uploadClientPhoto'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/regenerate-code', [ClientController::class, 'regenerateCode'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/block',   [ClientController::class, 'block'],   [StaffMiddleware::class]);
$router->post('/coach/clients/:id/unblock', [ClientController::class, 'unblock'], [StaffMiddleware::class]);
$router->get('/coach/clients/:id/analytics',   [ClientController::class, 'analytics'],      [StaffMiddleware::class]);
$router->get('/coach/clients/:id/logs',        [WorkoutLogController::class, 'clientLogs'], [StaffMiddleware::class]);
$router->get('/coach/clients/:id/live-progress',    [WorkoutLogController::class, 'liveProgress'],         [StaffMiddleware::class]);
$router->get('/coach/clients/:id/workout-progress', [WorkoutLogController::class, 'clientWorkoutProgress'], [StaffMiddleware::class]);
$router->get('/coach/workout-logs/pending-review',    [WorkoutLogController::class, 'pendingReview'], [StaffMiddleware::class]);
$router->post('/coach/workout-logs/:id/review',       [WorkoutLogController::class, 'reviewLog'], [StaffMiddleware::class]);
$router->get('/client/workout-logs/:id/review',       [WorkoutLogController::class, 'clientReview'], [ClientMiddleware::class]);
$router->post('/coach/clients/:id/measurements',[MediaController::class, 'storeMeasurement'],[StaffMiddleware::class]);
$router->get('/coach/media/:clientId',         [MediaController::class, 'clientMedia'],     [StaffMiddleware::class]);

// ─── Coach: Workout Plans ─────────────────────────────────────────────────────
$router->get('/workout-plans',         [WorkoutPlanController::class, 'index'],   [StaffMiddleware::class]);
$router->get('/workout-plans/saved',   [WorkoutPlanController::class, 'savedPlans'], [StaffMiddleware::class]);
$router->post('/workout-plans',        [WorkoutPlanController::class, 'store'],   [StaffMiddleware::class]);
$router->post('/workout-plans/import', [WorkoutPlanController::class, 'import'],  [StaffMiddleware::class]);
$router->post('/workout-plans/import-drive', [WorkoutPlanController::class, 'importDrive'], [StaffMiddleware::class]);
$router->get('/workout-plans/:id',     [WorkoutPlanController::class, 'show'],    [StaffMiddleware::class]);
$router->put('/workout-plans/:id',     [WorkoutPlanController::class, 'update'],  [StaffMiddleware::class]);
$router->delete('/workout-plans/:id',  [WorkoutPlanController::class, 'destroy'], [StaffMiddleware::class]);
$router->post('/workout-plans/:id/assign', [WorkoutPlanController::class, 'assign'], [StaffMiddleware::class]);

// ─── Exercise Library ─────────────────────────────────────────────────────────
$router->get('/exercises',                 [ExerciseController::class, 'index'],      [StaffMiddleware::class]);
$router->get('/exercises/:id',             [ExerciseController::class, 'show'],       [StaffMiddleware::class]);
$router->post('/exercises/sync',            [ExerciseController::class, 'sync'],        [StaffMiddleware::class]);
$router->post('/exercises/:id/sync-images', [ExerciseController::class, 'syncImages'], [StaffMiddleware::class]);

// ─── Progress Photos ───────────────────────────────────────────────────────────
$router->get('/clients/:id/progress-photos',  [ProgressPhotoController::class, 'index'],   [StaffMiddleware::class]);
$router->post('/clients/:id/progress-photos', [ProgressPhotoController::class, 'store'],  [StaffMiddleware::class]);
$router->delete('/clients/:id/progress-photos/:photoId', [ProgressPhotoController::class, 'destroy'], [StaffMiddleware::class]);

// ─── Client: Progress Photos ──────────────────────────────────────────────────
$router->get('/client/progress-photos',  [ProgressPhotoController::class, 'clientIndex'],   [ClientMiddleware::class]);
$router->post('/client/progress-photos', [ProgressPhotoController::class, 'clientStore'],  [ClientMiddleware::class]);
$router->delete('/client/progress-photos/:photoId', [ProgressPhotoController::class, 'clientDestroy'], [ClientMiddleware::class]);

// ─── Food Diary ────────────────────────────────────────────────────────────────
$router->get('/client/food-diary',         [FoodDiaryController::class, 'clientIndex'], [ClientMiddleware::class]);
$router->post('/client/food-diary',         [FoodDiaryController::class, 'store'],       [ClientMiddleware::class]);
$router->delete('/client/food-diary/:entryId', [FoodDiaryController::class, 'destroy'],    [ClientMiddleware::class]);
$router->get('/coach/clients/:id/food-diary', [FoodDiaryController::class, 'coachIndex'],  [StaffMiddleware::class]);

// ─── Habits ────────────────────────────────────────────────────────────────────
$router->get('/client/habits',                [HabitController::class, 'clientIndex'], [ClientMiddleware::class]);
$router->post('/client/habits/:habitId/log',    [HabitController::class, 'log'],         [ClientMiddleware::class]);
$router->delete('/client/habits/:habitId/log',  [HabitController::class, 'unlog'],       [ClientMiddleware::class]);
$router->get('/clients/:id/habits',             [HabitController::class, 'index'],       [StaffMiddleware::class]);
$router->post('/clients/:id/habits',             [HabitController::class, 'store'],        [StaffMiddleware::class]);
$router->put('/clients/:id/habits/:habitId',    [HabitController::class, 'update'],       [StaffMiddleware::class]);
$router->delete('/clients/:id/habits/:habitId',  [HabitController::class, 'destroy'],      [StaffMiddleware::class]);

// ─── Goals ─────────────────────────────────────────────────────────────────────
$router->get('/client/goals',                 [GoalController::class, 'clientIndex'], [ClientMiddleware::class]);
$router->get('/clients/:id/goals',            [GoalController::class, 'index'],       [StaffMiddleware::class]);
$router->post('/clients/:id/goals',            [GoalController::class, 'store'],       [StaffMiddleware::class]);
$router->put('/clients/:id/goals/:goalId',    [GoalController::class, 'update'],        [StaffMiddleware::class]);
$router->delete('/clients/:id/goals/:goalId',  [GoalController::class, 'destroy'],      [StaffMiddleware::class]);

// ─── Session Packages ──────────────────────────────────────────────────────────
$router->get('/client/session-packages',            [SessionPackageController::class, 'clientIndex'], [ClientMiddleware::class]);
$router->get('/clients/:id/session-packages',        [SessionPackageController::class, 'index'],       [StaffMiddleware::class]);
$router->post('/clients/:id/session-packages',        [SessionPackageController::class, 'store'],       [StaffMiddleware::class]);
$router->post('/session-packages/:id/use',            [SessionPackageController::class, 'use'],         [StaffMiddleware::class]);
$router->delete('/session-packages/:id',              [SessionPackageController::class, 'destroy'],     [StaffMiddleware::class]);

// ─── Coach: Workout Analyses ──────────────────────────────────────────────────
$router->get('/coach/clients/:id/workout-analyses', [WorkoutAnalysisController::class, 'index'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses', [WorkoutAnalysisController::class, 'create'], [StaffMiddleware::class]);
$router->get('/coach/clients/:id/workout-analyses/recommendations', [WorkoutAnalysisController::class, 'recommendations'], [StaffMiddleware::class]);
$router->get('/coach/clients/:id/workout-analyses/:analysisId', [WorkoutAnalysisController::class, 'show'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses/:analysisId/approve', [WorkoutAnalysisController::class, 'approve'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses/:analysisId/reject', [WorkoutAnalysisController::class, 'reject'], [StaffMiddleware::class]);
$router->post('/coach/clients/:id/workout-analyses/:analysisId/assign', [WorkoutAnalysisController::class, 'assign'], [StaffMiddleware::class]);

// ─── Coach: Foods ────────────────────────────────────────────────────────────
$router->get('/foods/search',        [FoodController::class, 'search'],    [StaffMiddleware::class]);
$router->get('/foods/calculate',     [FoodController::class, 'calculate'], [StaffMiddleware::class]);
$router->get('/foods/fdc/:fdcId',    [FoodController::class, 'details'],   [StaffMiddleware::class]);
$router->get('/foods',               [FoodController::class, 'index'],     [StaffMiddleware::class]);
$router->get('/foods/:id',           [FoodController::class, 'show'],      [StaffMiddleware::class]);
$router->post('/foods',              [FoodController::class, 'store'],     [StaffMiddleware::class]);

// ─── Coach: Nutrition Plans ───────────────────────────────────────────────────
$router->get('/nutrition-plans',        [NutritionController::class, 'index'],  [StaffMiddleware::class]);
$router->post('/nutrition-plans',       [NutritionController::class, 'store'],  [StaffMiddleware::class]);
$router->get('/nutrition-plans/:id',    [NutritionController::class, 'show'],    [StaffMiddleware::class]);
$router->put('/nutrition-plans/:id',    [NutritionController::class, 'update'],  [StaffMiddleware::class]);
$router->delete('/nutrition-plans/:id', [NutritionController::class, 'destroy'], [StaffMiddleware::class]);
$router->post('/nutrition-plans/:id/assign', [NutritionController::class, 'assign'], [StaffMiddleware::class]);

// ─── Coach: Check-ins ─────────────────────────────────────────────────────────
$router->get('/checkins',      [CheckinController::class, 'index'],   [StaffMiddleware::class]);
$router->post('/checkins',     [CheckinController::class, 'store'],   [StaffMiddleware::class]);
$router->put('/checkins/:id',  [CheckinController::class, 'update'],  [StaffMiddleware::class]);
$router->delete('/checkins/:id',[CheckinController::class, 'destroy'],[StaffMiddleware::class]);
$router->post('/checkins/:id/status',[CheckinController::class, 'updateStatus'],[StaffMiddleware::class]);
$router->post('/checkins/:id/reschedule-action',[CheckinController::class, 'rescheduleAction'],[StaffMiddleware::class]);
$router->post('/checkins/:id/client-request-action', [CheckinController::class, 'clientRequestAction'], [StaffMiddleware::class]);

// ─── Coach: Messages ──────────────────────────────────────────────────────────
// Static routes MUST be registered before the dynamic :clientId route to prevent
// "unread" and "read-all" from being parsed as a MongoDB ObjectId.
$router->get('/messages/unread',         [MessageController::class, 'unreadMessages'],  [StaffMiddleware::class]);
$router->get('/messages/unread-count',   [MessageController::class, 'unreadCount'],     [StaffMiddleware::class]);
$router->post('/messages/read-all',      [MessageController::class, 'markAllRead'],     [StaffMiddleware::class]);
$router->get('/messages/:clientId/typing', [MessageController::class, 'coachTypingStatus'], [StaffMiddleware::class]);
$router->post('/messages/:clientId/typing', [MessageController::class, 'updateCoachTyping'], [StaffMiddleware::class]);
$router->get('/messages/:clientId', [MessageController::class, 'coachThread'], [StaffMiddleware::class]);
$router->post('/messages',          [MessageController::class, 'send'],        [StaffMiddleware::class]);
$router->post('/messages/upload-media', [MessageController::class, 'uploadMedia'], [StaffMiddleware::class]);

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
$router->post('/client/checkins/request', [CheckinController::class, 'clientRequest'], [ClientMiddleware::class]);
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
$router->post('/client/measurements',         [MediaController::class, 'storeClientMeasurement'], [ClientMiddleware::class]);
$router->get('/client/measurements',          [MediaController::class, 'clientMeasurements'],     [ClientMiddleware::class]);

// ─── Coach: Live Training ─────────────────────────────────────────────────────
$router->get('/live-training',                         [LiveTrainingController::class, 'index'],          [StaffMiddleware::class]);
$router->post('/live-training',                        [LiveTrainingController::class, 'store'],          [StaffMiddleware::class]);
$router->get('/live-training/:id',                     [LiveTrainingController::class, 'show'],           [StaffMiddleware::class]);
$router->put('/live-training/:id',                     [LiveTrainingController::class, 'update'],         [StaffMiddleware::class]);
$router->delete('/live-training/:id',                  [LiveTrainingController::class, 'destroy'],        [StaffMiddleware::class]);
$router->post('/live-training/:id/go-live',            [LiveTrainingController::class, 'goLive'],         [StaffMiddleware::class]);
$router->post('/live-training/:id/end',                [LiveTrainingController::class, 'endSession'],     [StaffMiddleware::class]);
$router->get('/live-training/:id/requests',            [LiveTrainingController::class, 'listRequests'],   [StaffMiddleware::class]);
$router->post('/live-training/:id/requests',           [LiveTrainingController::class, 'handleRequest'],  [StaffMiddleware::class]);
$router->get('/live-training/:id/participants',        [LiveTrainingController::class, 'participants'],   [StaffMiddleware::class]);
$router->get('/live-training/:id/chat',                [LiveTrainingController::class, 'getChat'],        [StaffMiddleware::class]);
$router->post('/live-training/:id/chat',               [LiveTrainingController::class, 'sendChat'],       [StaffMiddleware::class]);

// ─── Client: Live Training ────────────────────────────────────────────────────
$router->get('/client/live-training',                  [LiveTrainingController::class, 'clientIndex'],          [ClientMiddleware::class]);
$router->get('/client/live-training/:id',              [LiveTrainingController::class, 'clientShow'],           [ClientMiddleware::class]);
$router->post('/client/live-training/:id/join',        [LiveTrainingController::class, 'clientJoin'],           [ClientMiddleware::class]);
$router->get('/client/live-training/:id/participants', [LiveTrainingController::class, 'clientParticipants'],   [ClientMiddleware::class]);
$router->get('/client/live-training/:id/chat',         [LiveTrainingController::class, 'clientGetChat'],        [ClientMiddleware::class]);
$router->post('/client/live-training/:id/chat',        [LiveTrainingController::class, 'clientSendChat'],       [ClientMiddleware::class]);

// ─── Coach: Coaching Sessions ─────────────────────────────────────────────────
$router->get('/coaching-sessions',                         [CoachingSessionController::class, 'index'],        [StaffMiddleware::class]);
$router->post('/coaching-sessions',                        [CoachingSessionController::class, 'store'],         [StaffMiddleware::class]);
$router->get('/coaching-sessions/:id',                     [CoachingSessionController::class, 'show'],          [StaffMiddleware::class]);
$router->put('/coaching-sessions/:id',                     [CoachingSessionController::class, 'update'],        [StaffMiddleware::class]);
$router->delete('/coaching-sessions/:id',                [CoachingSessionController::class, 'destroy'],       [StaffMiddleware::class]);
$router->post('/coaching-sessions/:id/status',           [CoachingSessionController::class, 'updateStatus'],   [StaffMiddleware::class]);
$router->post('/coaching-sessions/:id/token',            [CoachingSessionController::class, 'getToken'],      [StaffMiddleware::class]);
$router->post('/coaching-sessions/:id/notes',            [CoachingSessionController::class, 'saveNotes'],      [StaffMiddleware::class]);

// ─── Client: Coaching Sessions ──────────────────────────────────────────────────
$router->get('/client/coaching-sessions',                  [CoachingSessionController::class, 'clientIndex'],       [ClientMiddleware::class]);
$router->get('/client/coaching-sessions/:id',              [CoachingSessionController::class, 'clientShow'],        [ClientMiddleware::class]);
$router->post('/client/coaching-sessions/:id/token',       [CoachingSessionController::class, 'clientToken'],       [ClientMiddleware::class]);

// ─── Client: PR Records ─────────────────────────────────────────────────────────
$router->get('/client/prs',                              [PrRecordController::class, 'clientIndex'],          [ClientMiddleware::class]);
$router->get('/client/prs/:exerciseId',                  [PrRecordController::class, 'clientExerciseHistory'],  [ClientMiddleware::class]);
$router->post('/client/prs',                              [PrRecordController::class, 'store'],                  [ClientMiddleware::class]);
$router->get('/coach/clients/:id/prs',                   [PrRecordController::class, 'coachIndex'],             [StaffMiddleware::class]);
$router->get('/coach/clients/:id/prs/:exerciseId',       [PrRecordController::class, 'coachExerciseHistory'],   [StaffMiddleware::class]);

// ─── Client: Analytics ────────────────────────────────────────────────────────
$router->get('/client/analytics',                   [AnalyticsController::class, 'clientSelfAnalytics'],    [ClientMiddleware::class]);

// ─── Client: Meal Completion ──────────────────────────────────────────────────
$router->post('/client/meal-logs',   [MealLogController::class, 'store'],   [ClientMiddleware::class]);
$router->delete('/client/meal-logs', [MealLogController::class, 'destroy'], [ClientMiddleware::class]);

// ─── Coach: Meal Logs + Adherence ─────────────────────────────────────────────
$router->get('/coach/clients/:id/meal-logs', [MealLogController::class, 'clientLogs'], [StaffMiddleware::class]);
$router->get('/coach/clients/:id/adherence', [MealLogController::class, 'adherence'],  [StaffMiddleware::class]);

// ─── Client: Wearable ─────────────────────────────────────────────────────────
$router->post('/client/wearable/data',           [WearableController::class, 'pushData'],      [ClientMiddleware::class]);
$router->get('/client/wearable/data',            [WearableController::class, 'clientSelfData'], [ClientMiddleware::class]);
$router->get('/client/wearable/status',          [WearableController::class, 'status'],        [ClientMiddleware::class]);
$router->delete('/client/wearable/disconnect',   [WearableController::class, 'disconnect'],    [ClientMiddleware::class]);
$router->get('/client/wearable/fitbit/connect',  [WearableController::class, 'fitbitConnect'], [ClientMiddleware::class]);
$router->post('/client/wearable/fitbit/sync',    [WearableController::class, 'fitbitSync'],   [ClientMiddleware::class]);
$router->get('/client/wearable/fitbit/callback', [WearableController::class, 'fitbitCallback']);
$router->post('/client/wearable/fitbit/exchange', [WearableController::class, 'fitbitExchange'], [ClientMiddleware::class]);
$router->post('/client/wearable/apple-watch/push', [WearableController::class, 'appleWatchPush'], [ClientMiddleware::class]);
$router->get('/client/wearable/sharing',            [WearableController::class, 'getSharing'],     [ClientMiddleware::class]);
$router->put('/client/wearable/sharing',            [WearableController::class, 'updateSharing'],  [ClientMiddleware::class]);

$router->get('/coach/clients/:id/wearable',         [WearableController::class, 'clientData'], [StaffMiddleware::class]);
$router->get('/coach/clients/:id/wearable/trend',   [WearableController::class, 'trend'],      [StaffMiddleware::class]);
$router->get('/coach/clients/:id/wearable/sessions',[WearableController::class, 'sessions'],   [StaffMiddleware::class]);

// ─── Progress Prediction ──────────────────────────────────────────────────────
$router->get('/coach/clients/:id/prediction', [PredictionController::class, 'show'], [StaffMiddleware::class]);
$router->get('/client/prediction',            [PredictionController::class, 'show'], [ClientMiddleware::class]);

// ─── Subscription ─────────────────────────────────────────────────────────────
$router->post('/subscription/select-plan',   [SubscriptionController::class, 'selectPlan'],   [SetupAuthMiddleware::class]);
$router->post('/subscription/setup-trial',   [SubscriptionController::class, 'setupTrial'],   [SetupAuthMiddleware::class]);
$router->post('/subscription/confirm-trial', [SubscriptionController::class, 'confirmTrial'], [SetupAuthMiddleware::class]);
$router->get('/subscription',          [SubscriptionController::class, 'status'],   [StaffMiddleware::class]);
$router->get('/subscription/billing-information', [SubscriptionController::class, 'billingInformation'], [StaffMiddleware::class]);
$router->put('/subscription/billing-information', [SubscriptionController::class, 'updateBillingInformation'], [StaffMiddleware::class]);
$router->post('/subscription/checkout',[SubscriptionController::class, 'checkout'], [StaffMiddleware::class]);
$router->post('/subscription/portal',  [SubscriptionController::class, 'portal'],   [StaffMiddleware::class]);
$router->post('/subscription/cancel',  [SubscriptionController::class, 'cancel'],   [StaffMiddleware::class]);
$router->post('/subscription/upgrade', [SubscriptionController::class, 'upgrade'],  [StaffMiddleware::class]);
$router->get('/subscription/invoices', [SubscriptionController::class, 'invoices'], [StaffMiddleware::class]);
$router->get('/subscription/invoices/:id/download', [SubscriptionController::class, 'downloadInvoice'], [StaffMiddleware::class]);
$router->post('/subscription/webhook', [SubscriptionController::class, 'webhook']);

// ─── Payment Methods ──────────────────────────────────────────────────────────
$router->get('/payment-methods',              [SubscriptionController::class, 'listPaymentMethods'],   [StaffMiddleware::class]);
$router->post('/payment-methods',             [SubscriptionController::class, 'addPaymentMethod'],     [StaffMiddleware::class]);
$router->post('/payment-methods/setup-intent',[SubscriptionController::class, 'createSetupIntent'],   [StaffMiddleware::class]);
$router->delete('/payment-methods/:id',       [SubscriptionController::class, 'deletePaymentMethod'],  [StaffMiddleware::class]);
$router->post('/payment-methods/:id/default', [SubscriptionController::class, 'setDefaultPaymentMethod'], [StaffMiddleware::class]);

// ─── Settings ─────────────────────────────────────────────────────────────────
$router->get('/integrations/settings',  [SettingsController::class, 'getIntegrations'],          [StaffMiddleware::class]);
$router->put('/integrations/settings',  [SettingsController::class, 'updateIntegrations'],       [StaffMiddleware::class]);
$router->get('/notification-settings',  [SettingsController::class, 'getNotificationSettings'],  [StaffMiddleware::class]);
$router->put('/notification-settings',  [SettingsController::class, 'updateNotificationSettings'],[StaffMiddleware::class]);
$router->post('/support/contact',       [SettingsController::class, 'createSupportTicket'],      [StaffMiddleware::class]);

// ─── Notifications ──────────────────────────────────────────────────────────────
require_once BASE_PATH . '/routes/notifications.php';

// ─── Organizations & Staff / RBAC (Feature 6) ────────────────────────────────
// StaffMiddleware accepts either a legacy coach JWT (implicit "owner") or a
// staff JWT, resolving org_id + role onto $params for every route below.
//
// NOTE: Static routes (e.g. /activities) MUST be registered before the dynamic
// /:id route so "activities" is not parsed as a MongoDB ObjectId.
$router->post('/org/staff/invite',        [StaffController::class, 'invite'],       [StaffMiddleware::class]);
$router->get('/org/staff',                [StaffController::class, 'index'],        [StaffMiddleware::class]);
$router->get('/org/staff/activities',     [StaffController::class, 'activities'], [StaffMiddleware::class]);
$router->get('/org/staff/:id',            [StaffController::class, 'show'],         [StaffMiddleware::class]);
$router->post('/org/staff/accept-invite', [StaffController::class, 'acceptInvite'], [AuthRateLimitMiddleware::class]);
$router->patch('/org/staff/:id/role',     [StaffController::class, 'updateRole'],    [StaffMiddleware::class]);
$router->put('/org/staff/:id',            [StaffController::class, 'updateStaff'],  [StaffMiddleware::class]);
$router->post('/org/staff/:id/deactivate',[StaffController::class, 'deactivate'],    [StaffMiddleware::class]);
$router->patch('/org/clients/:id/assignment', [StaffController::class, 'assignClient'], [StaffMiddleware::class]);
$router->post('/org/clients/:id/take-on',    [StaffController::class, 'takeOnClient'], [StaffMiddleware::class]);
$router->get('/staff/profile',             [StaffController::class, 'getProfile'],      [StaffMiddleware::class]);
$router->put('/staff/profile',              [StaffController::class, 'updateProfile'],  [StaffMiddleware::class]);
$router->post('/staff/profile/photo',        [StaffController::class, 'uploadProfilePhoto'], [StaffMiddleware::class]);
$router->delete('/staff/profile/photo',      [StaffController::class, 'deleteProfilePhoto'], [StaffMiddleware::class]);

// ─── Sports-science Assessments (Feature 7) ──────────────────────────────────
// front_desk must not create/assess clinical assessments; restrict to
// owner, admin, and instructor_coach. Reads are gated the same way for
// consistency since assessments contain sensitive sports-science data.
$router->post('/org/clients/:id/assessments',        [AssessmentController::class, 'store'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->get('/org/clients/:id/assessments',          [AssessmentController::class, 'index'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->get('/org/clients/:id/assessments/latest',   [AssessmentController::class, 'latest'], [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);

// ─── Studio Operations (Feature 9) ───────────────────────────────────────────
// Studio class creation + staff-side bookings/checkin/membership management
// are available to all active staff roles (owner, admin, manager,
// front_desk) — front desk staff handle walk-in bookings and checkin.
// Membership status changes and utilization reports are restricted to
// owner/admin/manager since they affect billing and reporting.
$router->post('/org/classes',                 [StudioClassController::class, 'createClass'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager', 'front_desk'])]);
$router->get('/org/classes',                  [StudioClassController::class, 'index'],        [StaffMiddleware::class]);
$router->get('/client/classes',               [StudioClassController::class, 'indexForClient'],[ClientMiddleware::class]);
$router->post('/client/classes/:id/book',     [StudioBookingController::class, 'bookAsClient'], [ClientMiddleware::class]);
$router->post('/org/classes/:id/book',        [StudioBookingController::class, 'bookAsStaff'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager', 'front_desk'])]);
$router->post('/client/bookings/:id/cancel',  [StudioBookingController::class, 'cancelAsClient'],[ClientMiddleware::class]);
$router->post('/org/bookings/:id/cancel',     [StudioBookingController::class, 'cancelAsStaff'], [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager', 'front_desk'])]);
$router->post('/org/memberships',             [StudioMembershipController::class, 'createMembership'],       [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager'])]);
$router->get('/org/memberships',              [StudioMembershipController::class, 'listMemberships'],        [StaffMiddleware::class]);
$router->post('/org/memberships/:id/status',  [StudioMembershipController::class, 'updateMembershipStatus'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager'])]);
$router->post('/client/consent-documents',    [StudioController::class, 'signConsentAsClient'],     [ClientMiddleware::class]);
$router->post('/org/clients/:id/checkin',     [StudioController::class, 'checkinAsStaff'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager', 'front_desk'])]);
$router->post('/client/checkin',              [StudioController::class, 'checkinAsClient'], [ClientMiddleware::class]);
$router->get('/org/reports/utilization',      [StudioController::class, 'utilizationReport'],[StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'manager'])]);

// ─── Physiotherapy / Rehab Track (Feature 8) ─────────────────────────────────
// Clinical rehab actions (start track, record PROMs, log pain as staff,
// write SOAP notes) are restricted to owner/admin/instructor_coach —
// front_desk does not handle clinical documentation.
$router->post('/client/parq',                       [RehabController::class, 'submitParq'], [ClientMiddleware::class]);
$router->get('/org/clients/:id/parq',                [RehabController::class, 'getParq'],    [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->post('/org/clients/:id/rehab-track/start',  [RehabController::class, 'startTrack'], [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->get('/org/clients/:id/rehab-track',         [RehabController::class, 'getTrack'],   [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->post('/org/rehab-tracks/:id/proms',         [RehabController::class, 'recordProm'], [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->post('/org/rehab-tracks/:id/pain-logs',     [RehabController::class, 'logPainAsStaff'],  [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->post('/client/rehab-tracks/:id/pain-logs',  [RehabController::class, 'logPainAsClient'], [ClientMiddleware::class]);
$router->post('/org/rehab-tracks/:id/soap-notes',    [RehabController::class, 'addSoapNote'],   [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);
$router->get('/org/rehab-tracks/:id/soap-notes',     [RehabController::class, 'listSoapNotes'], [StaffMiddleware::class, new RoleGateMiddleware(['owner', 'admin', 'instructor_coach'])]);


