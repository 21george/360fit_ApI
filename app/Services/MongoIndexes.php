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
            ['key' => ['coach_id' => 1, 'is_blocked' => 1]],
            ['key' => ['coach_id' => 1, 'deleted_at' => 1]],
            ['key' => ['email' => 1]],
            ['key' => ['phone' => 1]],
            ['key' => ['login_code_hash' => 1]],
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

        // foods
        $db->getCollection('foods')->createIndexes([
            ['key' => ['name' => 'text']],
            ['key' => ['coach_id' => 1]],
            ['key' => ['source' => 1, 'fdc_id' => 1], 'unique' => true, 'sparse' => true],
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

        // ── New feature indexes ────────────────────────────────────────────────────

        // meal_logs (meal completion tracking)
        $db->getCollection('meal_logs')->createIndexes([
            ['key' => ['client_id' => 1, 'completed_at' => -1]],
            ['key' => ['client_id' => 1, 'nutrition_plan_id' => 1, 'day' => 1, 'meal_name' => 1], 'unique' => true],
        ]);

        // wearable_data (HealthKit / Fitbit / Health Connect daily summaries)
        $db->getCollection('wearable_data')->createIndexes([
            ['key' => ['client_id' => 1, 'date' => -1]],
            ['key' => ['client_id' => 1, 'date' => 1], 'unique' => true],
        ]);

        // wearable_tokens (encrypted OAuth tokens per client per provider)
        $db->getCollection('wearable_tokens')->createIndexes([
            ['key' => ['client_id' => 1, 'provider' => 1], 'unique' => true],
        ]);

        // wearable_oauth_states (short-lived CSRF state tokens)
        $db->getCollection('wearable_oauth_states')->createIndexes([
            ['key' => ['state' => 1], 'unique' => true],
            ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
        ]);

        // predictions (2-month predictions + accuracy tracking)
        $db->getCollection('predictions')->createIndexes([
            ['key' => ['client_id' => 1, 'month_key' => 1], 'unique' => true],
            ['key' => ['client_id' => 1, 'computed_at' => -1]],
        ]);

        // ── v1.2 feature indexes ────────────────────────────────────────────────

        // staff (multi-trainer organizations / RBAC — Feature 6)
        $db->getCollection('staff')->createIndexes([
            ['key' => ['org_id' => 1, 'status' => 1]],
            ['key' => ['org_id' => 1, 'email' => 1], 'unique' => true],
            ['key' => ['email' => 1]],
        ]);

        // assessments (sports-science — Feature 7)
        $db->getCollection('assessments')->createIndexes([
            ['key' => ['org_id' => 1, 'client_id' => 1, 'type' => 1, 'recorded_at' => -1]],
        ]);

        // class_schedules / class_bookings / memberships / checkins (studio ops — Feature 9)
        $db->getCollection('class_schedules')->createIndexes([
            ['key' => ['org_id' => 1, 'starts_at' => 1]],
        ]);
        $db->getCollection('class_bookings')->createIndexes([
            ['key' => ['class_schedule_id' => 1, 'status' => 1, 'waitlist_position' => 1]],
            ['key' => ['client_id' => 1, 'status' => 1]],
        ]);
        $db->getCollection('memberships')->createIndexes([
            ['key' => ['org_id' => 1, 'client_id' => 1]],
            ['key' => ['org_id' => 1, 'status' => 1]],
        ]);
        $db->getCollection('checkins')->createIndexes([
            ['key' => ['org_id' => 1, 'checked_in_at' => -1]],
            ['key' => ['client_id' => 1, 'checked_in_at' => -1]],
        ]);

        // consent_documents (shared by studio ops + rehab track)
        $db->getCollection('consent_documents')->createIndexes([
            ['key' => ['org_id' => 1, 'client_id' => 1, 'document_type' => 1]],
        ]);

        // parq_screenings / rehab_tracks / proms / pain_logs / soap_notes (rehab track — Feature 8)
        $db->getCollection('parq_screenings')->createIndexes([
            ['key' => ['org_id' => 1, 'client_id' => 1, 'submitted_at' => -1]],
        ]);
        $db->getCollection('rehab_tracks')->createIndexes([
            ['key' => ['org_id' => 1, 'client_id' => 1, 'status' => 1]],
        ]);
        $db->getCollection('proms')->createIndexes([
            ['key' => ['rehab_track_id' => 1, 'recorded_at' => -1]],
        ]);
        $db->getCollection('pain_logs')->createIndexes([
            ['key' => ['rehab_track_id' => 1, 'logged_at' => -1]],
        ]);
        $db->getCollection('soap_notes')->createIndexes([
            ['key' => ['rehab_track_id' => 1, 'authored_at' => -1]],
        ]);
    }
}
