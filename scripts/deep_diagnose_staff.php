<?php
/**
 * Deep diagnostic for staff login issues.
 * Run with: php scripts/deep_diagnose_staff.php <email>
 */

require_once __DIR__ . '/../vendor/autoload.php';

$email = strtolower(trim($argv[1] ?? ''));
if (empty($email)) {
    echo "Usage: php deep_diagnose_staff.php <email>\n";
    exit(1);
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$mongoUri = $_ENV['MONGODB_URI'] ?? $_ENV['MONGO_URI'] ?? 'mongodb://localhost:27017';
$dbName   = $_ENV['DB_NAME'] ?? $_ENV['MONGO_DB'] ?? 'coachpro';

$client = new MongoDB\Client($mongoUri);
$db     = $client->selectDatabase($dbName);

echo "=== Deep Staff Diagnostic ===\n";
echo "Email: {$email}\n";
echo "DB: {$dbName}\n\n";

// Find ALL staff with this email
$allMatches = $db->selectCollection('staff')->find(['email' => $email])->toArray();
echo "Total matching staff records: " . count($allMatches) . "\n\n";

if (count($allMatches) === 0) {
    echo "❌ No staff found with this email.\n";
    exit(1);
}

foreach ($allMatches as $i => $staff) {
    $id = (string)$staff['_id'];
    echo "--- Record #" . ($i + 1) . " ---\n";
    echo "  _id:      {$id}\n";
    echo "  email:    " . ($staff['email'] ?? 'N/A') . "\n";
    echo "  name:     " . ($staff['name'] ?? 'NOT SET') . "\n";
    echo "  status:   " . ($staff['status'] ?? 'unknown') . "\n";
    echo "  role:     " . ($staff['role'] ?? 'unknown') . "\n";

    $hash = $staff['password_hash'] ?? '';
    echo "  password_hash: ";
    if (empty($hash)) {
        echo "EMPTY ❌\n";
    } else {
        echo "SET (length " . strlen($hash) . ", prefix " . substr($hash, 0, 7) . ")\n";

        // Verify the known password
        $testPassword = 'Baus123@321';
        $verifyResult = password_verify($testPassword, $hash);
        echo "  password_verify('{$testPassword}', hash): " . ($verifyResult ? 'TRUE ✅' : 'FALSE ❌') . "\n";

        // Also try with trimmed password
        $verifyTrimmed = password_verify(trim($testPassword), $hash);
        if ($verifyTrimmed !== $verifyResult) {
            echo "  password_verify(trim('{$testPassword}'), hash): " . ($verifyTrimmed ? 'TRUE ✅' : 'FALSE ❌') . "\n";
        }
    }

    echo "  org_id:   " . (string)($staff['org_id'] ?? 'N/A') . "\n";
    echo "  created_at: " . (isset($staff['created_at']) ? $staff['created_at']->toDateTime()->format('Y-m-d H:i:s') : 'N/A') . "\n";
    echo "\n";
}

// Check for org_id validity
$first = $allMatches[0];
if (!empty($first['org_id'])) {
    $org = $db->selectCollection('coaches')->findOne(['_id' => $first['org_id']]);
    echo "=== Organization Check ===\n";
    if ($org) {
        echo "  Org exists: YES ✅\n";
        echo "  Org name:   " . ($org['name'] ?? 'N/A') . "\n";
        echo "  Org active: " . (($org['active'] ?? true) ? 'YES ✅' : 'NO ❌') . "\n";
    } else {
        echo "  Org exists: NO ❌ (org_id: {$first['org_id']})\n";
    }
}

echo "\n=== End Diagnostic ===\n";
