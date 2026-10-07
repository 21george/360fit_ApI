<?php
/**
 * Diagnostic: verify or reset a staff member's password.
 * Run with: php scripts/verify_staff_password.php <email> [new_password]
 *
 * Without a new_password argument, it just shows the hash info.
 * With a new_password argument, it resets the password.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$email = strtolower(trim($argv[1] ?? ''));
$newPassword = $argv[2] ?? '';

if (empty($email)) {
    echo "Usage: php verify_staff_password.php <email> [new_password]\n";
    exit(1);
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$mongoUri = $_ENV['MONGO_URI'] ?? $_ENV['MONGODB_URI'] ?? 'mongodb://localhost:27017';
$dbName   = $_ENV['MONGO_DB'] ?? $_ENV['DB_NAME'] ?? 'coachpro';

$client = new MongoDB\Client($mongoUri);
$db     = $client->selectDatabase($dbName);

$staff = $db->selectCollection('staff')->findOne(['email' => $email]);

if (!$staff) {
    echo "❌ Staff member not found: {$email}\n";
    exit(1);
}

echo "=== Staff Password Check ===\n";
echo "Email:    {$email}\n";
echo "Status:   " . ($staff['status'] ?? 'unknown') . "\n";
echo "Name:     " . ($staff['name'] ?? 'NOT SET') . "\n";

$hash = $staff['password_hash'] ?? '';
if (empty($hash)) {
    echo "Password: NOT SET (empty hash)\n";
} else {
    echo "Password: HASH EXISTS (length " . strlen($hash) . ")\n";
    echo "Hash prefix: " . substr($hash, 0, 7) . "\n";
}

// If a new password is provided, reset it
if (!empty($newPassword)) {
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->selectCollection('staff')->updateOne(
        ['_id' => $staff['_id']],
        ['$set' => [
            'password_hash' => $newHash,
            'updated_at'    => new MongoDB\BSON\UTCDateTime(),
        ]]
    );
    echo "\n✅ Password RESET to: {$newPassword}\n";
    echo "New hash prefix: " . substr($newHash, 0, 7) . "\n";

    // Verify it was saved correctly
    $updated = $db->selectCollection('staff')->findOne(['_id' => $staff['_id']]);
    $verify = password_verify($newPassword, $updated['password_hash'] ?? '');
    echo "Verification test: " . ($verify ? 'PASS ✅' : 'FAIL ❌') . "\n";
} else {
    echo "\nℹ️  To reset the password, run:\n";
    echo "   php verify_staff_password.php {$email} 'NewPassword123'\n";
}
