<?php
/**
 * Run once: php setup-indexes.php
 * Creates all MongoDB indexes for optimal performance
 */
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Database;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$db = Database::getInstance();

echo "Creating indexes...\n";

// coaches
$db->getCollection('coaches')->createIndex(['email' => 1], ['unique' => true]);

// clients
$db->getCollection('clients')->createIndex(['coach_id' => 1, 'active' => 1]);
$db->getCollection('clients')->createIndex(['coach_id' => 1, 'name' => 1]);
$db->getCollection('clients')->createIndex(['code_lookup' => 1], ['unique' => true, 'sparse' => true]);

// workout_plans
$db->getCollection('workout_plans')->createIndex(['client_id' => 1, 'status' => 1]);
$db->getCollection('workout_plans')->createIndex(['coach_id' => 1, 'week_start' => -1]);
$db->getCollection('workout_plans')->createIndex(['client_ids' => 1]);

// workout_logs
$db->getCollection('workout_logs')->createIndex(['client_id' => 1, 'completed_at' => -1]);
$db->getCollection('workout_logs')->createIndex(['workout_plan_id' => 1]);

// nutrition_plans
$db->getCollection('nutrition_plans')->createIndex(['client_id' => 1, 'week_start' => -1]);
$db->getCollection('nutrition_plans')->createIndex(['coach_id' => 1]);

// checkin_meetings
$db->getCollection('checkin_meetings')->createIndex(['coach_id' => 1, 'scheduled_at' => 1]);
$db->getCollection('checkin_meetings')->createIndex(['client_id' => 1, 'status' => 1]);

// messages
$db->getCollection('messages')->createIndex(['coach_id' => 1, 'client_id' => 1, 'sent_at' => 1]);

// message_typing_status
$db->getCollection('message_typing_status')->createIndex(['coach_id' => 1, 'client_id' => 1], ['unique' => true]);
$db->getCollection('message_typing_status')->createIndex(['updated_at' => 1], ['expireAfterSeconds' => 30]);

// group_messages
$db->getCollection('group_messages')->createIndex(['plan_id' => 1, 'sent_at' => 1]);

// media_uploads
$db->getCollection('media_uploads')->createIndex(['client_id' => 1, 'type' => 1]);

// refresh_tokens
$db->getCollection('refresh_tokens')->createIndex(['token_hash' => 1], ['unique' => true]);
$db->getCollection('refresh_tokens')->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0]);

// body_measurements
$db->getCollection('body_measurements')->createIndex(['client_id' => 1, 'measured_at' => -1]);

// live_training_sessions
$db->getCollection('live_training_sessions')->createIndex(['coach_id' => 1, 'status' => 1]);
$db->getCollection('live_training_sessions')->createIndex(['scheduled_at' => 1]);

// live_training_requests
$db->getCollection('live_training_requests')->createIndex(['session_id' => 1, 'client_id' => 1], ['unique' => true]);

// live_training_chat
$db->getCollection('live_training_chat')->createIndex(['session_id' => 1, 'sent_at' => 1]);

// rate_limits
$db->getCollection('rate_limits')->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0]);

echo "All indexes created successfully!\n";
