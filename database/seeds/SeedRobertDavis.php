<?php
/**
 * Seed script: Creates Robert Davis as a fully functional demo client
 * with real data in MongoDB for the FitApp to consume via APIs.
 *
 * Usage: php database/seeds/SeedRobertDavis.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
// Config classes live outside app/ so PSR-4 cannot resolve them
require_once __DIR__ . '/../../config/Database.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

use App\Config\Database;
use App\Services\CodeService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$db = Database::getInstance()->getDB();
$now = new UTCDateTime();
$today = '2026-10-10';
$coachEmail = 'demo.coach@example.com';

// ─── 1. Upsert Demo Coach ────────────────────────────────────────────────────
$coach = $db->coaches->findOne(['email' => $coachEmail]);
if (!$coach) {
    $coachId = new ObjectId();
    $db->coaches->insertOne([
        '_id' => $coachId,
        'name' => 'Coach Mike',
        'email' => $coachEmail,
        'password_hash' => password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]),
        'language' => 'en',
        'client_count' => 1,
        'subscription_tier' => 'pro',
        'subscription_status' => 'active',
        'email_verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo "Coach created: {$coachId}\n";
} else {
    $coachId = $coach['_id'];
    echo "Coach found: {$coachId}\n";
}
$coachIdObj = $coachId instanceof ObjectId ? $coachId : new ObjectId((string)$coachId);

// ─── 2. Remove existing Robert Davis (clean slate) ───────────────────────────
$existing = $db->clients->findOne(['email' => 'robert.davis@email.com']);
if ($existing) {
    $clientIdObj = $existing['_id'];
    $db->workout_plans->deleteMany(['client_id' => $clientIdObj]);
    $db->workout_logs->deleteMany(['client_id' => $clientIdObj]);
    $db->nutrition_plans->deleteMany(['client_id' => $clientIdObj]);
    $db->habits->deleteMany(['client_id' => $clientIdObj]);
    $db->goals->deleteMany(['client_id' => $clientIdObj]);
    $db->pr_records->deleteMany(['client_id' => $clientIdObj]);
    $db->body_measurements->deleteMany(['client_id' => $clientIdObj]);
    $db->wearable_data->deleteMany(['client_id' => $clientIdObj]);
    $db->wearable_connections->deleteMany(['client_id' => $clientIdObj]);
    $db->wearable_tokens->deleteMany(['client_id' => $clientIdObj]);
    $db->messages->deleteMany(['client_id' => $clientIdObj]);
    $db->checkin_meetings->deleteMany(['client_id' => $clientIdObj]);
    $db->clients->deleteOne(['_id' => $clientIdObj]);
    echo "Existing Robert Davis data cleared.\n";
}

// ─── 3. Create Robert Davis Client ───────────────────────────────────────────
$loginCode = 'FIT-DEMO-01';
$clientIdObj = new ObjectId();
$clientDoc = [
    '_id' => $clientIdObj,
    'coach_id' => $coachIdObj,
    'name' => 'Robert Davis',
    'email' => 'robert.davis@email.com',
    'phone' => '+1 555-0142',
    'login_code_hash' => CodeService::hash($loginCode),
    'code_lookup' => CodeService::lookupHash($loginCode),
    'active' => true,
    'is_blocked' => false,
    'token_version' => 0,
    'fcm_token' => null,
    'language' => 'en',
    'profile_photo_url' => 'https://i.pravatar.cc/150?u=robert_davis',
    'address' => '742 Evergreen Terrace',
    'city' => 'Springfield',
    'postal_code' => '62701',
    'nationality' => 'American',
    'occupation' => 'Software Engineer',
    'gender' => 'male',
    'date_of_birth' => '1992-03-15',
    'current_weight_kg' => 82.5,
    'height_cm' => 183.0,
    'chest_cm' => 104.0,
    'waist_cm' => 86.0,
    'body_fat_pct' => 14.2,
    'bmi' => 24.6,
    'lean_mass_kg' => 70.8,
    'sickness' => null,
    'notes' => 'Former college athlete. Prefers morning workouts. Sensitive lower back — avoid heavy deadlifts.',
    'created_at' => new UTCDateTime(strtotime('2026-09-01T10:00:00Z') * 1000),
    'updated_at' => $now,
];
$db->clients->insertOne($clientDoc);
echo "Client created: {$clientIdObj} (login code: {$loginCode})\n";

// ─── Helper: UTCDateTime from date string ──────────────────────────────────
function ts(string $iso): UTCDateTime {
    return new UTCDateTime(strtotime($iso) * 1000);
}

// ─── 4. Workout Plans (4 weeks) ──────────────────────────────────────────────
$planWeeks = [
    ['title' => 'Hypertrophy Block — Week 1', 'start' => '2026-10-05', 'status' => 'completed'],
    ['title' => 'Hypertrophy Block — Week 2', 'start' => '2026-10-12', 'status' => 'active'],
    ['title' => 'Hypertrophy Block — Week 3', 'start' => '2026-10-19', 'status' => 'draft'],
    ['title' => 'Hypertrophy Block — Week 4', 'start' => '2026-10-26', 'status' => 'draft'],
];

$planIds = [];
foreach ($planWeeks as $pw) {
    $planId = new ObjectId();
    $days = [
        [
            'day' => 'Monday — Push',
            'exercises' => [
                ['name' => 'Barbell Bench Press', 'sets' => 4, 'reps' => '8', 'rest_seconds' => 120, 'notes' => null],
                ['name' => 'Overhead Press', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Incline Dumbbell Press', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Cable Fly', 'sets' => 3, 'reps' => '12', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Lateral Raise', 'sets' => 4, 'reps' => '15', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Tricep Pushdown', 'sets' => 3, 'reps' => '12', 'rest_seconds' => 60, 'notes' => null],
            ],
        ],
        [
            'day' => 'Tuesday — Pull',
            'exercises' => [
                ['name' => 'Barbell Row', 'sets' => 4, 'reps' => '8', 'rest_seconds' => 120, 'notes' => null],
                ['name' => 'Lat Pulldown', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Face Pull', 'sets' => 3, 'reps' => '15', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Barbell Curl', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Hammer Curl', 'sets' => 3, 'reps' => '12', 'rest_seconds' => 60, 'notes' => null],
            ],
        ],
        [
            'day' => 'Wednesday — Legs',
            'exercises' => [
                ['name' => 'Squat', 'sets' => 4, 'reps' => '6', 'rest_seconds' => 180, 'notes' => null],
                ['name' => 'Romanian Deadlift', 'sets' => 3, 'reps' => '8', 'rest_seconds' => 120, 'notes' => null],
                ['name' => 'Leg Press', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 120, 'notes' => null],
                ['name' => 'Leg Curl', 'sets' => 3, 'reps' => '12', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Calf Raise', 'sets' => 4, 'reps' => '15', 'rest_seconds' => 60, 'notes' => null],
            ],
        ],
        [
            'day' => 'Thursday — Rest',
            'exercises' => [],
        ],
        [
            'day' => 'Friday — Push',
            'exercises' => [
                ['name' => 'Dumbbell Bench Press', 'sets' => 4, 'reps' => '8', 'rest_seconds' => 120, 'notes' => null],
                ['name' => 'Arnold Press', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Chest Dip', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Pec Deck', 'sets' => 3, 'reps' => '12', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Lateral Raise', 'sets' => 4, 'reps' => '15', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Tricep Pushdown', 'sets' => 3, 'reps' => '12', 'rest_seconds' => 60, 'notes' => null],
            ],
        ],
        [
            'day' => 'Saturday — Pull',
            'exercises' => [
                ['name' => 'Deadlift', 'sets' => 3, 'reps' => '5', 'rest_seconds' => 180, 'notes' => 'Keep back neutral'],
                ['name' => 'Pull-Up', 'sets' => 4, 'reps' => '8', 'rest_seconds' => 120, 'notes' => null],
                ['name' => 'Seated Row', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 90, 'notes' => null],
                ['name' => 'Reverse Fly', 'sets' => 3, 'reps' => '15', 'rest_seconds' => 60, 'notes' => null],
                ['name' => 'Preacher Curl', 'sets' => 3, 'reps' => '10', 'rest_seconds' => 60, 'notes' => null],
            ],
        ],
    ];

    $db->workout_plans->insertOne([
        '_id' => $planId,
        'coach_id' => $coachIdObj,
        'client_id' => $clientIdObj,
        'client_ids' => [$clientIdObj],
        'plan_type' => 'individual',
        'title' => $pw['title'],
        'week_start' => ts($pw['start'] . 'T00:00:00Z'),
        'status' => $pw['status'],
        'days' => $days,
        'notes' => null,
        'imported_from_excel' => false,
        'created_at' => ts('2026-09-15T10:00:00Z'),
        'updated_at' => $now,
    ]);
    $planIds[] = $planId;
    echo "Workout plan created: {$planId} ({$pw['title']})\n";
}

// ─── 5. Workout Logs (complete Week 1 + first 5 days of Week 2) ─────────────
$completedDays = [
    [$planIds[0], 'Monday — Push', '2026-10-05', [
        ['name' => 'Barbell Bench Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 8, 'kg' => 80],
            ['set_number' => 2, 'reps' => 8, 'kg' => 80],
            ['set_number' => 3, 'reps' => 7, 'kg' => 82.5],
            ['set_number' => 4, 'reps' => 6, 'kg' => 82.5],
        ]],
        ['name' => 'Overhead Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 50],
            ['set_number' => 2, 'reps' => 10, 'kg' => 50],
            ['set_number' => 3, 'reps' => 9, 'kg' => 50],
        ]],
        ['name' => 'Incline Dumbbell Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 28],
            ['set_number' => 2, 'reps' => 10, 'kg' => 28],
            ['set_number' => 3, 'reps' => 10, 'kg' => 28],
        ]],
        ['name' => 'Cable Fly', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 15],
            ['set_number' => 2, 'reps' => 12, 'kg' => 15],
            ['set_number' => 3, 'reps' => 11, 'kg' => 15],
        ]],
        ['name' => 'Lateral Raise', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 15, 'kg' => 10],
            ['set_number' => 2, 'reps' => 15, 'kg' => 10],
            ['set_number' => 3, 'reps' => 15, 'kg' => 10],
            ['set_number' => 4, 'reps' => 14, 'kg' => 10],
        ]],
        ['name' => 'Tricep Pushdown', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 25],
            ['set_number' => 2, 'reps' => 12, 'kg' => 25],
            ['set_number' => 3, 'reps' => 12, 'kg' => 25],
        ]],
    ]],
    [$planIds[0], 'Tuesday — Pull', '2026-10-06', [
        ['name' => 'Barbell Row', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 8, 'kg' => 70],
            ['set_number' => 2, 'reps' => 8, 'kg' => 70],
            ['set_number' => 3, 'reps' => 8, 'kg' => 70],
            ['set_number' => 4, 'reps' => 7, 'kg' => 72.5],
        ]],
        ['name' => 'Lat Pulldown', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 55],
            ['set_number' => 2, 'reps' => 10, 'kg' => 55],
            ['set_number' => 3, 'reps' => 10, 'kg' => 55],
        ]],
        ['name' => 'Face Pull', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 15, 'kg' => 20],
            ['set_number' => 2, 'reps' => 15, 'kg' => 20],
            ['set_number' => 3, 'reps' => 15, 'kg' => 20],
        ]],
        ['name' => 'Barbell Curl', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 35],
            ['set_number' => 2, 'reps' => 10, 'kg' => 35],
            ['set_number' => 3, 'reps' => 9, 'kg' => 35],
        ]],
        ['name' => 'Hammer Curl', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 14],
            ['set_number' => 2, 'reps' => 12, 'kg' => 14],
            ['set_number' => 3, 'reps' => 12, 'kg' => 14],
        ]],
    ]],
    [$planIds[0], 'Wednesday — Legs', '2026-10-07', [
        ['name' => 'Squat', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 6, 'kg' => 120],
            ['set_number' => 2, 'reps' => 6, 'kg' => 120],
            ['set_number' => 3, 'reps' => 5, 'kg' => 125],
            ['set_number' => 4, 'reps' => 5, 'kg' => 125],
        ]],
        ['name' => 'Romanian Deadlift', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 8, 'kg' => 80],
            ['set_number' => 2, 'reps' => 8, 'kg' => 80],
            ['set_number' => 3, 'reps' => 8, 'kg' => 80],
        ]],
        ['name' => 'Leg Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 180],
            ['set_number' => 2, 'reps' => 10, 'kg' => 180],
            ['set_number' => 3, 'reps' => 10, 'kg' => 180],
        ]],
        ['name' => 'Leg Curl', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 45],
            ['set_number' => 2, 'reps' => 12, 'kg' => 45],
            ['set_number' => 3, 'reps' => 11, 'kg' => 45],
        ]],
        ['name' => 'Calf Raise', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 15, 'kg' => 60],
            ['set_number' => 2, 'reps' => 15, 'kg' => 60],
            ['set_number' => 3, 'reps' => 15, 'kg' => 60],
            ['set_number' => 4, 'reps' => 14, 'kg' => 60],
        ]],
    ]],
    [$planIds[0], 'Friday — Push', '2026-10-09', [
        ['name' => 'Dumbbell Bench Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 8, 'kg' => 32],
            ['set_number' => 2, 'reps' => 8, 'kg' => 32],
            ['set_number' => 3, 'reps' => 7, 'kg' => 34],
            ['set_number' => 4, 'reps' => 7, 'kg' => 34],
        ]],
        ['name' => 'Arnold Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 20],
            ['set_number' => 2, 'reps' => 10, 'kg' => 20],
            ['set_number' => 3, 'reps' => 10, 'kg' => 20],
        ]],
        ['name' => 'Chest Dip', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 0],
            ['set_number' => 2, 'reps' => 10, 'kg' => 0],
            ['set_number' => 3, 'reps' => 9, 'kg' => 0],
        ]],
        ['name' => 'Pec Deck', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 45],
            ['set_number' => 2, 'reps' => 12, 'kg' => 45],
            ['set_number' => 3, 'reps' => 12, 'kg' => 45],
        ]],
        ['name' => 'Lateral Raise', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 15, 'kg' => 10],
            ['set_number' => 2, 'reps' => 15, 'kg' => 10],
            ['set_number' => 3, 'reps' => 15, 'kg' => 10],
            ['set_number' => 4, 'reps' => 15, 'kg' => 10],
        ]],
        ['name' => 'Tricep Pushdown', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 27.5],
            ['set_number' => 2, 'reps' => 12, 'kg' => 27.5],
            ['set_number' => 3, 'reps' => 12, 'kg' => 27.5],
        ]],
    ]],
    [$planIds[1], 'Monday — Push', '2026-10-12', [
        ['name' => 'Barbell Bench Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 8, 'kg' => 82.5],
            ['set_number' => 2, 'reps' => 8, 'kg' => 82.5],
            ['set_number' => 3, 'reps' => 8, 'kg' => 82.5],
            ['set_number' => 4, 'reps' => 7, 'kg' => 85],
        ]],
        ['name' => 'Overhead Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 52.5],
            ['set_number' => 2, 'reps' => 10, 'kg' => 52.5],
            ['set_number' => 3, 'reps' => 10, 'kg' => 52.5],
        ]],
        ['name' => 'Incline Dumbbell Press', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 10, 'kg' => 30],
            ['set_number' => 2, 'reps' => 10, 'kg' => 30],
            ['set_number' => 3, 'reps' => 9, 'kg' => 30],
        ]],
        ['name' => 'Cable Fly', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 15],
            ['set_number' => 2, 'reps' => 12, 'kg' => 15],
            ['set_number' => 3, 'reps' => 12, 'kg' => 15],
        ]],
        ['name' => 'Lateral Raise', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 15, 'kg' => 10],
            ['set_number' => 2, 'reps' => 15, 'kg' => 10],
            ['set_number' => 3, 'reps' => 15, 'kg' => 10],
            ['set_number' => 4, 'reps' => 15, 'kg' => 10],
        ]],
        ['name' => 'Tricep Pushdown', 'sets_completed' => [
            ['set_number' => 1, 'reps' => 12, 'kg' => 27.5],
            ['set_number' => 2, 'reps' => 12, 'kg' => 27.5],
            ['set_number' => 3, 'reps' => 12, 'kg' => 27.5],
        ]],
    ]],
];

$logIds = [];
foreach ($completedDays as $cd) {
    [$planId, $day, $date, $exercises] = $cd;
    $logId = new ObjectId();
    $db->workout_logs->insertOne([
        '_id' => $logId,
        'client_id' => $clientIdObj,
        'workout_plan_id' => $planId,
        'day' => $day,
        'exercises' => $exercises,
        'notes' => 'Felt strong today. Progressive overload on bench.',
        'session_rpe' => rand(7, 9),
        'media_uploads' => [],
        'completed_at' => ts($date . 'T08:00:00Z'),
    ]);
    $logIds[] = $logId;
}
echo "Workout logs created: " . count($logIds) . "\n";

// ─── 6. Nutrition Plan ───────────────────────────────────────────────────────
$nutritionPlanId = new ObjectId();
$nutritionDays = [];
$mealTemplates = [
    [
        'name' => 'Breakfast',
        'foods' => [
            ['name' => 'Oatmeal', 'quantity_g' => 80, 'calculated_nutrients' => ['calories' => 300, 'protein_g' => 10, 'carbs_g' => 54, 'fat_g' => 6]],
            ['name' => 'Whey Protein Shake', 'quantity_g' => 30, 'calculated_nutrients' => ['calories' => 120, 'protein_g' => 24, 'carbs_g' => 2, 'fat_g' => 1]],
            ['name' => 'Banana', 'quantity_g' => 120, 'calculated_nutrients' => ['calories' => 105, 'protein_g' => 1, 'carbs_g' => 27, 'fat_g' => 0]],
        ],
    ],
    [
        'name' => 'Lunch',
        'foods' => [
            ['name' => 'Grilled Chicken Breast', 'quantity_g' => 200, 'calculated_nutrients' => ['calories' => 330, 'protein_g' => 62, 'carbs_g' => 0, 'fat_g' => 7]],
            ['name' => 'Brown Rice', 'quantity_g' => 150, 'calculated_nutrients' => ['calories' => 195, 'protein_g' => 4, 'carbs_g' => 42, 'fat_g' => 1]],
            ['name' => 'Mixed Vegetables', 'quantity_g' => 150, 'calculated_nutrients' => ['calories' => 80, 'protein_g' => 4, 'carbs_g' => 16, 'fat_g' => 0]],
        ],
    ],
    [
        'name' => 'Snack',
        'foods' => [
            ['name' => 'Greek Yogurt', 'quantity_g' => 200, 'calculated_nutrients' => ['calories' => 150, 'protein_g' => 20, 'carbs_g' => 8, 'fat_g' => 0]],
            ['name' => 'Almonds', 'quantity_g' => 30, 'calculated_nutrients' => ['calories' => 170, 'protein_g' => 6, 'carbs_g' => 6, 'fat_g' => 15]],
        ],
    ],
    [
        'name' => 'Dinner',
        'foods' => [
            ['name' => 'Salmon Fillet', 'quantity_g' => 200, 'calculated_nutrients' => ['calories' => 350, 'protein_g' => 40, 'carbs_g' => 0, 'fat_g' => 20]],
            ['name' => 'Sweet Potato', 'quantity_g' => 200, 'calculated_nutrients' => ['calories' => 180, 'protein_g' => 4, 'carbs_g' => 40, 'fat_g' => 0]],
            ['name' => 'Broccoli', 'quantity_g' => 150, 'calculated_nutrients' => ['calories' => 50, 'protein_g' => 4, 'carbs_g' => 10, 'fat_g' => 0]],
        ],
    ],
];

$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
foreach ($dayNames as $dName) {
    $totals = ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];
    foreach ($mealTemplates as $meal) {
        foreach ($meal['foods'] as $food) {
            $n = $food['calculated_nutrients'];
            $totals['calories'] += $n['calories'];
            $totals['protein_g'] += $n['protein_g'];
            $totals['carbs_g'] += $n['carbs_g'];
            $totals['fat_g'] += $n['fat_g'];
        }
    }
    $nutritionDays[] = [
        'day' => $dName,
        'meals' => $mealTemplates,
        'day_totals' => $totals,
    ];
}

$db->nutrition_plans->insertOne([
    '_id' => $nutritionPlanId,
    'coach_id' => $coachIdObj,
    'client_id' => $clientIdObj,
    'client_ids' => [$clientIdObj],
    'plan_type' => 'individual',
    'title' => 'Lean Bulk Nutrition Plan',
    'week_start' => ts('2026-10-05T00:00:00Z'),
    'daily_totals' => ['calories' => 2300, 'protein_g' => 180, 'carbs_g' => 220, 'fat_g' => 65],
    'days' => $nutritionDays,
    'notes' => 'High protein, moderate carbs. Adjust based on weekly weigh-ins.',
    'status' => 'active',
    'created_at' => ts('2026-09-20T10:00:00Z'),
    'updated_at' => $now,
]);
echo "Nutrition plan created: {$nutritionPlanId}\n";

// ─── 7. Habits ───────────────────────────────────────────────────────────────
$habits = [
    ['title' => 'Drink 3L water', 'frequency' => 'daily', 'target_count' => 1],
    ['title' => 'Sleep 8 hours', 'frequency' => 'daily', 'target_count' => 1],
    ['title' => 'Morning stretch', 'frequency' => 'daily', 'target_count' => 1],
    ['title' => 'Protein shake post-workout', 'frequency' => 'daily', 'target_count' => 1],
    ['title' => 'No sugar snacks', 'frequency' => 'daily', 'target_count' => 1],
];
foreach ($habits as $h) {
    $db->habits->insertOne([
        'client_id' => $clientIdObj,
        'coach_id' => $coachIdObj,
        'title' => $h['title'],
        'frequency' => $h['frequency'],
        'target_count' => $h['target_count'],
        'created_at' => ts('2026-09-15T10:00:00Z'),
        'updated_at' => $now,
    ]);
}
echo "Habits created: " . count($habits) . "\n";

// ─── 8. Goals ────────────────────────────────────────────────────────────────
$goals = [
    ['title' => 'Bench Press 100kg', 'target_value' => 100, 'unit' => 'kg', 'deadline' => '2026-12-31'],
    ['title' => 'Bodyweight 80kg', 'target_value' => 80, 'unit' => 'kg', 'deadline' => '2026-11-30'],
    ['title' => 'Squat 140kg', 'target_value' => 140, 'unit' => 'kg', 'deadline' => '2026-12-31'],
    ['title' => 'Run 5k under 25 min', 'target_value' => 25, 'unit' => 'min', 'deadline' => '2026-11-15'],
];
foreach ($goals as $g) {
    $db->goals->insertOne([
        'client_id' => $clientIdObj,
        'coach_id' => $coachIdObj,
        'title' => $g['title'],
        'target_value' => $g['target_value'],
        'current_value' => 0,
        'unit' => $g['unit'],
        'deadline' => new UTCDateTime(strtotime($g['deadline']) * 1000),
        'status' => 'active',
        'created_at' => ts('2026-09-15T10:00:00Z'),
        'updated_at' => $now,
    ]);
}
echo "Goals created: " . count($goals) . "\n";

// ─── 9. PR Records ───────────────────────────────────────────────────────────
$prs = [
    ['exercise_id' => 'ex_bench', 'exercise_name' => 'Barbell Bench Press', 'reps' => 1, 'weight' => 90, 'unit' => 'kg', 'date' => '2026-09-20'],
    ['exercise_id' => 'ex_squat', 'exercise_name' => 'Squat', 'reps' => 1, 'weight' => 130, 'unit' => 'kg', 'date' => '2026-09-18'],
    ['exercise_id' => 'ex_deadlift', 'exercise_name' => 'Deadlift', 'reps' => 1, 'weight' => 150, 'unit' => 'kg', 'date' => '2026-09-15'],
    ['exercise_id' => 'ex_ohp', 'exercise_name' => 'Overhead Press', 'reps' => 1, 'weight' => 60, 'unit' => 'kg', 'date' => '2026-09-22'],
    ['exercise_id' => 'ex_row', 'exercise_name' => 'Barbell Row', 'reps' => 1, 'weight' => 80, 'unit' => 'kg', 'date' => '2026-09-19'],
];
foreach ($prs as $pr) {
    $oneRm = $pr['weight'] * (1 + $pr['reps'] / 30);
    $db->pr_records->insertOne([
        'client_id' => $clientIdObj,
        'exercise_id' => $pr['exercise_id'],
        'exercise_name' => $pr['exercise_name'],
        'reps' => $pr['reps'],
        'weight' => $pr['weight'],
        'unit' => $pr['unit'],
        'one_rm' => round($oneRm, 1),
        'date' => new UTCDateTime(strtotime($pr['date']) * 1000),
        'created_at' => ts($pr['date'] . 'T10:00:00Z'),
    ]);
}
echo "PR records created: " . count($prs) . "\n";

// ─── 10. Body Measurements ───────────────────────────────────────────────────
$measurements = [
    ['date' => '2026-09-01', 'weight_kg' => 84.0, 'chest_cm' => 103, 'waist_cm' => 88, 'hips_cm' => 98, 'body_fat_pct' => 15.5],
    ['date' => '2026-09-15', 'weight_kg' => 83.2, 'chest_cm' => 103.5, 'waist_cm' => 87, 'hips_cm' => 97.5, 'body_fat_pct' => 14.8],
    ['date' => '2026-10-01', 'weight_kg' => 82.5, 'chest_cm' => 104, 'waist_cm' => 86, 'hips_cm' => 97, 'body_fat_pct' => 14.2],
];
foreach ($measurements as $m) {
    $db->body_measurements->insertOne([
        'client_id' => $clientIdObj,
        'recorded_at' => ts($m['date'] . 'T10:00:00Z'),
        'weight_kg' => $m['weight_kg'],
        'chest_cm' => $m['chest_cm'],
        'waist_cm' => $m['waist_cm'],
        'hips_cm' => $m['hips_cm'],
        'body_fat_pct' => $m['body_fat_pct'],
        'source' => 'coach',
        'created_at' => ts($m['date'] . 'T10:00:00Z'),
    ]);
}
echo "Body measurements created: " . count($measurements) . "\n";

// ─── 11. Wearable Data (Apple Watch) ─────────────────────────────────────────
$wearableDates = [];
for ($i = 29; $i >= 0; $i--) {
    $wearableDates[] = date('Y-m-d', strtotime("-{$i} days", strtotime($today)));
}

foreach ($wearableDates as $wd) {
    $steps = rand(7000, 12000);
    $activeCal = rand(350, 650);
    $distance = round($steps * 0.00076, 2);
    $hydration = rand(2000, 3500);
    $restingHr = rand(52, 62);
    $sleepMin = rand(390, 480);
    $sleepScore = rand(70, 92);
    $hrv = round(rand(35, 55) + mt_rand() / mt_getrandmax(), 1);

    $db->wearable_data->updateOne(
        ['client_id' => $clientIdObj, 'date' => $wd],
        ['$set' => [
            'steps' => $steps,
            'active_calories' => $activeCal,
            'distance_km' => $distance,
            'hydration_ml' => $hydration,
            'resting_hr' => $restingHr,
            'sleep_minutes' => $sleepMin,
            'sleep_score' => $sleepScore,
            'hrv_ms' => $hrv,
            'source' => 'healthkit',
            'updated_at' => $now,
        ], '$setOnInsert' => ['created_at' => $now]],
        ['upsert' => true]
    );
}
echo "Wearable data created for 30 days\n";

// ─── 12. Wearable Connection + Token ─────────────────────────────────────────
$db->wearable_connections->deleteMany(['client_id' => $clientIdObj]);
$db->wearable_connections->insertOne([
    'client_id' => $clientIdObj,
    'provider' => 'apple_watch',
    'status' => 'active',
    'created_at' => ts('2026-09-20T10:00:00Z'),
    'updated_at' => $now,
]);

$db->wearable_tokens->deleteMany(['client_id' => $clientIdObj]);
$db->wearable_tokens->insertOne([
    'client_id' => $clientIdObj,
    'provider' => 'apple_watch',
    'token_encrypted' => 'DEMO_TOKEN_PLACEHOLDER',
    'sync_status' => 'idle',
    'last_sync_at' => $now,
    'last_sync_error' => null,
    'created_at' => ts('2026-09-20T10:00:00Z'),
    'updated_at' => $now,
]);
echo "Wearable connection created (Apple Watch)\n";

// ─── 13. Messages ────────────────────────────────────────────────────────────
$messages = [
    ['sender_role' => 'coach', 'content' => 'Hey Robert! Ready to crush Week 2?', 'sent_at' => '2026-10-11T09:00:00Z'],
    ['sender_role' => 'client', 'content' => 'Absolutely coach! Feeling stronger every day.', 'sent_at' => '2026-10-11T09:15:00Z'],
    ['sender_role' => 'coach', 'content' => 'Great form on bench yesterday. Keep the elbow tuck.', 'sent_at' => '2026-10-09T18:00:00Z'],
    ['sender_role' => 'client', 'content' => 'Thanks! Will focus on that next session.', 'sent_at' => '2026-10-09T18:30:00Z'],
    ['sender_role' => 'coach', 'content' => 'Your nutrition logs look solid. Protein intake is on point.', 'sent_at' => '2026-10-08T10:00:00Z'],
    ['sender_role' => 'client', 'content' => 'Trying to hit 180g daily. The meal plan helps a lot.', 'sent_at' => '2026-10-08T10:20:00Z'],
];
foreach ($messages as $msg) {
    $senderId = $msg['sender_role'] === 'coach' ? $coachIdObj : $clientIdObj;
    $db->messages->insertOne([
        'coach_id' => $coachIdObj,
        'client_id' => $clientIdObj,
        'sender_id' => $senderId,
        'sender_role' => $msg['sender_role'],
        'content' => $msg['content'],
        'media_url' => null,
        'media_type' => null,
        'read' => true,
        'sent_at' => ts($msg['sent_at']),
        'created_at' => ts($msg['sent_at']),
    ]);
}
echo "Messages created: " . count($messages) . "\n";

// ─── 14. Checkins ────────────────────────────────────────────────────────────
$checkins = [
    ['scheduled_at' => '2026-10-15T18:00:00Z', 'type' => 'video', 'status' => 'scheduled', 'notes' => 'Weekly progress review — upper body focus'],
    ['scheduled_at' => '2026-10-08T18:00:00Z', 'type' => 'video', 'status' => 'scheduled', 'notes' => 'Discuss squat form and nutrition adjustments'],
    ['scheduled_at' => '2026-10-01T18:00:00Z', 'type' => 'video', 'status' => 'scheduled', 'notes' => 'Initial check-in and goal setting'],
];
foreach ($checkins as $c) {
    $db->checkin_meetings->insertOne([
        'coach_id' => $coachIdObj,
        'client_id' => $clientIdObj,
        'scheduled_at' => ts($c['scheduled_at']),
        'type' => $c['type'],
        'meeting_link' => null,
        'notes' => $c['notes'],
        'status' => $c['status'],
        'source' => 'coach',
        'client_response' => 'accepted',
        'client_response_note' => null,
        'client_responded_at' => ts($c['scheduled_at']),
        'proposed_scheduled_at' => null,
        'created_at' => ts($c['scheduled_at']),
        'updated_at' => $now,
    ]);
}
echo "Checkins created: " . count($checkins) . "\n";

// ─── 15. Meal Logs (adherence data) ──────────────────────────────────────────
$mealLogDates = ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12'];
foreach ($mealLogDates as $mld) {
    $meals = ['Breakfast', 'Lunch', 'Snack', 'Dinner'];
    foreach ($meals as $mealName) {
        $db->meal_logs->insertOne([
            'client_id' => $clientIdObj,
            'coach_id' => $coachIdObj,
            'day' => $mld,
            'meal_name' => $mealName,
            'completed_at' => ts($mld . 'T' . sprintf('%02d', rand(7, 20)) . ':00:00Z'),
            'created_at' => ts($mld . 'T10:00:00Z'),
        ]);
    }
}
echo "Meal logs created for " . count($mealLogDates) . " days\n";

// ─── Done ────────────────────────────────────────────────────────────────────
echo "\n✅ Robert Davis seeded successfully!\n";
echo "   Client ID: {$clientIdObj}\n";
echo "   Login Code: {$loginCode}\n";
echo "   Coach: {$coachEmail}\n";
