<?php
require_once __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

$db = \App\Config\Database::getInstance()->getDB();
$db->coaches->createIndex(['email' => 1], ['unique' => true]);
$db->clients->createIndex(['coach_id' => 1, 'active' => 1]);
$db->workout_plans->createIndex(['client_id' => 1, 'week_start' => -1]);
$db->workout_logs->createIndex(['client_id' => 1, 'completed_at' => -1]);
$db->messages->createIndex(['client_id' => 1, 'sent_at' => -1]);
$db->refresh_tokens->createIndex(['token' => 1], ['unique' => true]);
$db->refresh_tokens->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0]);
echo "All indexes created!\n";
