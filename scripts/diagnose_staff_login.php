<?php
/**
 * Diagnostic script for staff login issues.
 * Run with: php scripts/diagnose_staff_login.php <email>
 */

require_once __DIR__ . '/../vendor/autoload.php';

$email = $argv[1] ?? '';
if (empty($email)) {
    echo "Usage: php diagnose_staff_login.php <email>\n";
    exit(1);
}

$email = strtolower(trim($email));

// Load env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Connect to MongoDB (match the backend config/Database.php env vars)
$mongoUri = $_ENV['MONGO_URI'] ?? $_ENV['MONGODB_URI'] ?? 'mongodb://localhost:27017';
$dbName = $_ENV['MONGO_DB'] ?? $_ENV['DB_NAME'] ?? 'coachpro';

$client = new MongoDB\Client($mongoUri);
$db = $client->selectDatabase($dbName);

echo "=== Staff Login Diagnostic ===\n";
echo "Looking up email: {$email}\n\n";

// Check staff collection
$staff = $db->selectCollection('staff')->findOne(['email' => $email]);

if (!$staff) {
    echo "❌ Staff member NOT FOUND in 'staff' collection\n";

    // Check coaches collection (maybe they registered as a coach?)
    $coach = $db->selectCollection('coaches')->findOne(['email' => $email]);
    if ($coach) {
        echo "⚠️  BUT found in 'coaches' collection! This is a coach account, not a staff account.\n";
        echo "    Coach ID: " . (string)$coach['_id'] . "\n";
        echo "    Coach name: " . ($coach['name'] ?? 'N/A') . "\n";
    }

    // Check all staff emails
    echo "\n📋 All staff emails in database:\n";
    $allStaff = $db->selectCollection('staff')->find([], ['limit' => 20]);
    foreach ($allStaff as $s) {
        echo "    - " . ($s['email'] ?? 'NO EMAIL') . " (status: " . ($s['status'] ?? 'unknown') . ")\n";
    }
    exit(1);
}

echo "✅ Staff member FOUND\n";
echo "    ID: " . (string)$staff['_id'] . "\n";
echo "    Email: " . ($staff['email'] ?? 'N/A') . "\n";
echo "    Name: " . ($staff['name'] ?? 'NOT SET') . "\n";
echo "    Status: " . ($staff['status'] ?? 'unknown') . "\n";
echo "    Role: " . ($staff['role'] ?? 'unknown') . "\n";
echo "    Has password_hash: " . (empty($staff['password_hash']) ? 'NO ❌' : 'YES ✅') . "\n";
echo "    Org ID: " . (string)($staff['org_id'] ?? 'N/A') . "\n";

// Check organization
if (!empty($staff['org_id'])) {
    $org = $db->selectCollection('coaches')->findOne(['_id' => $staff['org_id']]);
    if ($org) {
        echo "\n📋 Organization exists:\n";
        echo "    Name: " . ($org['name'] ?? 'N/A') . "\n";
        echo "    Active: " . (($org['active'] ?? true) ? 'YES ✅' : 'NO ❌') . "\n";
    } else {
        echo "\n❌ Organization NOT FOUND (org_id: {$staff['org_id']})\n";
    }
}

echo "\n=== End Diagnostic ===\n";
