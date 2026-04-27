<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Run this once on deployment to create all required indexes
 * php -r "require 'vendor/autoload.php'; (new App\Services\MongoIndexes())->create();"
 */
class MongoIndexes
{
    public function create(): void
    {
        $db = Database::getInstance();

        // coaches
        $db->getCollection('coaches')->createIndexes([
            ['key' => ['email' => 1], 'unique' => true],
        ]);

        // clients
        $db->getCollection('clients')->createIndexes([
            ['key' => ['coach_id' => 1]],
            ['key' => ['coach_id' => 1, 'active' => 1]],
        ]);

        // workout_plans
        $db->getCollection('workout_plans')->createIndexes([
            ['key' => ['client_id' => 1, 'week_start' => -1]],
            ['key' => ['coach_id' => 1, 'status' => 1]],
        ]);

        // workout_logs
        $db->getCollection('workout_logs')->createIndexes([
            ['key' => ['client_id' => 1, 'completed_at' => -1]],
            ['key' => ['client_id' => 1, 'workout_plan_id' => 1, 'day' => 1], 'unique' => true],
        ]);

        // nutrition_plans
        $db->getCollection('nutrition_plans')->createIndexes([
            ['key' => ['client_id' => 1, 'week_start' => -1]],
        ]);

        // checkin_meetings
        $db->getCollection('checkin_meetings')->createIndexes([
            ['key' => ['coach_id' => 1, 'scheduled_at' => 1]],
            ['key' => ['client_id' => 1, 'scheduled_at' => 1]],
        ]);

        // messages
        $db->getCollection('messages')->createIndexes([
            ['key' => ['client_id' => 1, 'coach_id' => 1, 'sent_at' => -1]],
        ]);

        // media_uploads
        $db->getCollection('media_uploads')->createIndexes([
            ['key' => ['client_id' => 1, 'uploaded_at' => -1]],
        ]);

        // body_measurements
        $db->getCollection('body_measurements')->createIndexes([
            ['key' => ['client_id' => 1, 'recorded_at' => -1]],
        ]);

        echo "All indexes created successfully.\n";
    }
}
