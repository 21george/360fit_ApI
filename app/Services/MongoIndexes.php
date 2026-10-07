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
            ['key' => ['stripe_customer_id' => 1], 'unique' => true, 'sparse' => true],
            ['key' => ['stripe_subscription_id' => 1], 'unique' => true, 'sparse' => true],
            ['key' => ['subscription_status' => 1, 'subscription_period_end' => 1]],
            ['key' => ['subscription_tier' => 1, 'subscription_status' => 1]],
            ['key' => ['trial_ends_at' => 1, 'subscription_status' => 1]],
        ]);

        // Stripe event receipt and retry-safe webhook idempotency.
        $db->getCollection('stripe_webhook_events')->createIndexes([
            ['key' => ['event_id' => 1], 'unique' => true],
            ['key' => ['type' => 1, 'received_at' => -1]],
        ]);

        // clients
        $db->getCollection('clients')->createIndexes([
            ['key' => ['coach_id' => 1]],
            ['key' => ['coach_id' => 1, 'active' => 1]],
            ['key' => ['coach_id' => 1, 'is_blocked' => 1]],
            ['key' => ['coach_id' => 1, 'deleted_at' => 1]],
            ['key' => ['coach_id' => 1, 'name' => 1]],
            ['key' => ['email' => 1]],
            ['key' => ['phone' => 1]],
            ['key' => ['login_code_hash' => 1]],
            ['key' => ['code_lookup' => 1], 'unique' => true, 'sparse' => true],
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
            ['key' => ['client_id' => 1, 'review_status' => 1]],
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
            ['key' => ['stale_at' => 1, 'refresh_status' => 1]],
        ]);

        // exercises (cached WGER exercise library)
        $db->getCollection('exercises')->createIndexes([
            ['key' => ['external_id' => 1], 'unique' => true],
            ['key' => ['name' => 'text']],
            ['key' => ['category' => 1]],
            ['key' => ['muscles' => 1]],
            ['key' => ['updated_at' => 1]],
        ]);

        // progress_photos
        $db->getCollection('progress_photos')->createIndexes([
            ['key' => ['client_id' => 1, 'taken_at' => -1]],
            ['key' => ['client_id' => 1, 'type' => 1]],
            ['key' => ['client_id' => 1, 'label' => 1]],
        ]);

        // food_diary_entries
        $db->getCollection('food_diary_entries')->createIndexes([
            ['key' => ['client_id' => 1, 'logged_at' => -1]],
            ['key' => ['client_id' => 1, 'food_name' => 'text']],
        ]);

        // habits
        $db->getCollection('habits')->createIndexes([
            ['key' => ['client_id' => 1, 'created_at' => -1]],
        ]);

        // habit_logs
        $db->getCollection('habit_logs')->createIndexes([
            ['key' => ['habit_id' => 1, 'client_id' => 1, 'date' => -1], 'unique' => true],
        ]);

        // goals
        $db->getCollection('goals')->createIndexes([
            ['key' => ['client_id' => 1, 'status' => 1]],
            ['key' => ['client_id' => 1, 'created_at' => -1]],
        ]);

        // session_packages
        $db->getCollection('session_packages')->createIndexes([
            ['key' => ['client_id' => 1, 'status' => 1]],
            ['key' => ['client_id' => 1, 'created_at' => -1]],
        ]);

        // cache_refresh_jobs (nutrition cache background refresh queue)
        $db->getCollection('cache_refresh_jobs')->createIndexes([
            ['key' => ['status' => 1, 'requested_at' => 1]],
            ['key' => ['food_id' => 1, 'source' => 1]],
        ]);

        // wearable_trend_cache (wearable trend cache)
        $db->getCollection('wearable_trend_cache')->createIndexes([
            ['key' => ['client_id' => 1, 'metric' => 1, 'window_days' => 1], 'unique' => true],
        ]);

        // wearable_sync_jobs (wearable trend cache background refresh queue)
        $db->getCollection('wearable_sync_jobs')->createIndexes([
            ['key' => ['status' => 1, 'requested_at' => 1]],
            ['key' => ['client_id' => 1, 'type' => 1]],
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

        // workout_plans
        $db->getCollection('workout_plans')->createIndexes([
            ['key' => ['client_ids' => 1]],
        ]);

        // workout_logs
        $db->getCollection('workout_logs')->createIndexes([
            ['key' => ['workout_plan_id' => 1]],
        ]);

        // nutrition_plans
        $db->getCollection('nutrition_plans')->createIndexes([
            ['key' => ['client_ids' => 1]],
            ['key' => ['coach_id' => 1]],
        ]);

        // checkin_meetings
        $db->getCollection('checkin_meetings')->createIndexes([
            ['key' => ['client_id' => 1, 'status' => 1]],
        ]);

        // message_typing_status
        $db->getCollection('message_typing_status')->createIndexes([
            ['key' => ['coach_id' => 1, 'client_id' => 1], 'unique' => true],
            ['key' => ['updated_at' => 1], 'expireAfterSeconds' => 30],
        ]);

        // group_messages
        $db->getCollection('group_messages')->createIndexes([
            ['key' => ['plan_id' => 1, 'sent_at' => 1]],
        ]);

        // refresh_tokens
        $db->getCollection('refresh_tokens')->createIndexes([
            ['key' => ['token_hash' => 1], 'unique' => true],
            ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
        ]);

        // live_training_sessions
        $db->getCollection('live_training_sessions')->createIndexes([
            ['key' => ['coach_id' => 1, 'status' => 1]],
            ['key' => ['scheduled_at' => 1]],
        ]);

        // live_training_requests
        $db->getCollection('live_training_requests')->createIndexes([
            ['key' => ['session_id' => 1, 'client_id' => 1], 'unique' => true],
        ]);

        // live_training_chat
        $db->getCollection('live_training_chat')->createIndexes([
            ['key' => ['session_id' => 1, 'sent_at' => 1]],
        ]);

        // notifications
        $db->getCollection('notifications')->createIndexes([
            ['key' => ['user_id' => 1, 'read' => 1]],
            ['key' => ['user_id' => 1, 'user_type' => 1, 'read' => 1]],
            ['key' => ['user_id' => 1, 'sent_at' => -1]],
            ['key' => ['user_id' => 1, 'user_type' => 1, 'sent_at' => -1]],
        ]);

        // rate_limits
        $db->getCollection('rate_limits')->createIndexes([
            ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
        ]);

        // team_meetings (admin-staff scheduling)
        $db->getCollection('team_meetings')->createIndexes([
            ['key' => ['org_id' => 1, 'scheduled_at' => 1]],
            ['key' => ['participant_ids' => 1, 'scheduled_at' => 1]],
            ['key' => ['org_id' => 1, 'status' => 1]],
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
            ['key' => ['client_id' => 1, 'recovery_score' => -1]],
        ]);

        // wearable_tokens (encrypted OAuth tokens per client per provider)
        $db->getCollection('wearable_tokens')->createIndexes([
            ['key' => ['client_id' => 1, 'provider' => 1], 'unique' => true],
            ['key' => ['provider' => 1, 'status' => 1]],
            ['key' => ['last_sync_at' => 1]],
        ]);

        // wearable_connections (pairing state + per-metric privacy settings)
        $db->getCollection('wearable_connections')->createIndexes([
            ['key' => ['client_id' => 1, 'provider' => 1], 'unique' => true],
            ['key' => ['status' => 1, 'provider' => 1]],
        ]);

        // wearable_sessions (device workout sessions imported from Fitbit / Apple Watch)
        $db->getCollection('wearable_sessions')->createIndexes([
            ['key' => ['client_id' => 1, 'start_time' => -1]],
            ['key' => ['client_id' => 1, 'provider' => 1, 'session_id' => 1], 'unique' => true],
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
