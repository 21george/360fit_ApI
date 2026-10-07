<?php
/**
 * Diagnostic: check all staff records in the database.
 * Run with: php scripts/check_staff_db.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Connect to MongoDB
$mongoUri = $_ENV['MONGO_URI'] ?? $_ENV['MONGODB_URI'] ?? 'mongodb://localhost:27017';
$dbName = $_ENV['MONGO_DB'] ?? $_ENV['DB_NAME'] ?? 'coachpro';

echo "Connecting to: " . preg_replace('/:\/\/[^:]+:[^@]+@/', '://***@', $mongoUri) . "\n";
echo "Database: {$dbName}\n\n";

$client = new MongoDB\Client($mongoUri);
$db = $client->selectDatabase($dbName);

echo "=== Staff Collection Diagnostic ===\n\n";

$staffCollection = $db->selectCollection('staff');
$allStaff = $staffCollection->find([])->toArray();

echo "Total staff records: " . count($allStaff) . "\n\n";

if (count($allStaff) === 0) {
    echo "❌ No staff records found in the database!\n";
    echo "   Staff must be invited via POST /org/staff/invite first.\n";
    exit(1);
}

foreach ($allStaff as $staff) {
    $id = (string)$staff['_id'];
    $email = $staff['email'] ?? 'NO EMAIL';
    $name = $staff['name'] ?? 'NOT SET';
    $status = $staff['status'] ?? 'unknown';
    $role = $staff['role'] ?? 'unknown';
    $hasPassword = !empty($staff['password_hash']);
    $orgId = isset($staff['org_id']) ? (string)$staff['org_id'] : 'NONE';

    echo "Staff Record:\n";
    echo "  ID:       {$id}\n";
    echo "  Email:    {$email}\n";
    echo "  Name:     {$name}\n";
    echo "  Status:   {$status}\n";
    echo "  Role:     {$role}\n";
    echo "  Password: " . ($hasPassword ? 'SET ✅' : 'NOT SET ❌') . "\n";
    echo "  Org ID:   {$orgId}\n";
    echo "\n";
}

echo "=== Summary ===\n";
$withPassword = count(array_filter($allStaff, fn($s) => !empty($s['password_hash'])));
$withoutPassword = count($allStaff) - $withPassword;
echo "Staff with password:    {$withPassword}\n";
echo "Staff without password: {$withoutPassword}\n";

if ($withoutPassword > 0) {
    echo "\n⚠️  Staff without passwords are likely 'invited' status.\n";
    echo "   They can set a password by:\n";
    echo "   1. Logging in via /staff/login (password becomes their login password)\n";
    echo "   2. Or accepting the invite via /staff/accept-invite\n";
}
