<?php

declare(strict_types=1);

// Explicitly require config classes (not under app/ so PSR-4 can't find them)
require_once BASE_PATH . '/config/Database.php';

use App\Helpers\Response;
use App\Helpers\Router;

// CORS Headers
$corsConfig = require BASE_PATH . '/config/cors.php';
$allowedOrigins = $corsConfig['allowed_origins'];

// Also merge env-based origins (comma-separated ALLOWED_ORIGINS)
if (!empty($_ENV['ALLOWED_ORIGINS'])) {
    $envOrigins = array_map('trim', explode(',', $_ENV['ALLOWED_ORIGINS']));
    $allowedOrigins = array_unique(array_merge($allowedOrigins, $envOrigins));
}

// Normalise: strip trailing slashes so origin matching works
$allowedOrigins = array_map(fn(string $o) => rtrim($o, '/'), $allowedOrigins);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$originAllowed = in_array($origin, $allowedOrigins, true);
if ($originAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: ' . implode(', ', $corsConfig['allowed_methods']));
header('Access-Control-Allow-Headers: ' . implode(', ', $corsConfig['allowed_headers']));
header('Access-Control-Max-Age: ' . ($corsConfig['max_age'] ?? 86400));
header('Content-Type: application/json; charset=utf-8');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (($_ENV['APP_ENV'] ?? '') === 'production') {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
}

// Content-Security-Policy for API responses (prevents XSS, clickjacking via frames, etc.)
$apiOrigin = $_ENV['APP_URL'] ?? '';
$cspConnectSrc = $apiOrigin ? "'self' {$apiOrigin}" : "'self'";
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src {$cspConnectSrc}; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode([]);
    exit;
}

// ── Global rate limiting ───────────────────────────────────────────────
// Applies a per-IP cap to ALL non-OPTIONS requests (auth endpoints get a
// stricter per-route limit via AuthRateLimitMiddleware on top of this).
// Health checks are exempt so monitoring never gets throttled.
$__requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$__isHealth    = str_ends_with($__requestPath, '/health');
if (!$__isHealth) {
    $__rlMax     = (int) ($_ENV['RATE_LIMIT_REQUESTS']  ?? 300);
    $__rlWindow  = (int) ($_ENV['RATE_LIMIT_WINDOW']    ?? 900);
    $__rlIp      = filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $__rlTrusted = array_filter(
        array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))),
        fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
    );
    if (
        $__rlIp !== '0.0.0.0'
        && in_array($__rlIp, $__rlTrusted, true)
        && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
    ) {
        $__fwd = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $__fwdIp = filter_var(end($__fwd), FILTER_VALIDATE_IP);
        if ($__fwdIp !== false) $__rlIp = $__fwdIp;
    }
    try {
        $__rlCol = \App\Config\Database::collection('rate_limits');
        $__rlKey = 'rate_limit:' . $__rlIp;
        $__rlNow = time();
        $__rlRec = $__rlCol->findOne(['key' => $__rlKey]);
        if (!$__rlRec) {
            $__rlCol->insertOne([
                'key'          => $__rlKey,
                'requests'     => 1,
                'window_start' => $__rlNow,
                'expires_at'   => new \MongoDB\BSON\UTCDateTime(($__rlNow + $__rlWindow) * 1000),
            ]);
        } elseif ($__rlNow - $__rlRec['window_start'] > $__rlWindow) {
            $__rlCol->updateOne(['key' => $__rlKey], ['$set' => [
                'requests'     => 1,
                'window_start' => $__rlNow,
                'expires_at'   => new \MongoDB\BSON\UTCDateTime(($__rlNow + $__rlWindow) * 1000),
            ]]);
        } elseif ($__rlRec['requests'] >= $__rlMax) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
            exit;
        } else {
            $__rlCol->updateOne(['key' => $__rlKey], ['$inc' => ['requests' => 1]]);
        }
    } catch (\Throwable $__rlErr) {
        // If rate limiting fails (e.g. DB down), fail open — the request
        // continues. The exception handler below will surface DB issues as 503.
        error_log('[rate-limit] ' . $__rlErr->getMessage());
    }
}

// ── Ensure MongoDB indexes on first request after deploy ───────────────
// MongoIndexes::create() is idempotent — running it on every cold start
// would be wasteful, so we gate it with a flag file that records the last
// run. To force re-creation (e.g. after adding a new index), delete
// `storage/.indexes_applied`. This guarantees the load-bearing
// stripe_webhook_events.event_id unique index exists before any webhook
// is processed.
$__indexFlag = BASE_PATH . '/storage/.indexes_applied';
if (!file_exists($__indexFlag)) {
    try {
        require_once BASE_PATH . '/app/Services/MongoIndexes.php';
        (new \App\Services\MongoIndexes())->create();
        @file_put_contents($__indexFlag, date('c'));
        error_log('[indexes] MongoDB indexes ensured by bootstrap');
    } catch (\Throwable $__idxErr) {
        error_log('[indexes] Failed to ensure indexes: ' . $__idxErr->getMessage());
    }
}

// Request correlation ID for tracing errors across logs and client reports
$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
Response::setRequestId($requestId);

// Error handler
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) use ($requestId) {
    $errorMsg = sprintf(
        "[%s] %s [%s:%d] %s",
        $requestId,
        get_class($e),
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    );
    error_log($errorMsg);
    if (php_sapi_name() !== 'cli-server') {
        error_log('Stack trace: ' . $e->getTraceAsString());
    }

    // Log to file for debugging
    $logFile = BASE_PATH . '/storage/logs/error.log';
    if (is_writable(dirname($logFile))) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $errorMsg . PHP_EOL, FILE_APPEND);
    }

    // ErrorException from the custom error handler: warnings/notices
    // should be logged but not crash the request as 500s.
    if ($e instanceof \ErrorException) {
        $severity = $e->getSeverity();
        // Warnings and notices are client/data issues, not server crashes
        if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING || $severity === E_USER_NOTICE) {
            Response::json(['error' => 'Invalid request: ' . $e->getMessage()], 400);
        }
    }

    // Bad inputs from the client should be 4xx, not 5xx. Map the
    // Mongo and JSON-parse exceptions to 400 so a malformed id or
    // body never looks like a server failure.
    if ($e instanceof \MongoDB\Driver\Exception\InvalidArgumentException
        || $e instanceof \JsonException
        || ($e instanceof \UnexpectedValueException && str_contains($e->getMessage(), 'ObjectId'))
    ) {
        Response::json(['error' => 'Invalid request: ' . $e->getMessage()], 400);
    }

    // Transient MongoDB issues (connection loss, timeouts, etc.) should
    // be surfaced as 503 so callers know to retry.
    if ($e instanceof \MongoDB\Driver\Exception\RuntimeException
        || $e instanceof \MongoDB\Driver\Exception\ConnectionTimeoutException
        || $e instanceof \MongoDB\Driver\Exception\ExecutionTimeoutException
    ) {
        Response::json(['error' => 'Service temporarily unavailable'], 503);
    }

    Response::json(['error' => 'Internal server error'], 500);
});

// Route
$router = new Router();
$router->setPrefix('/v1');
require BASE_PATH . '/routes/api.php';

// RequestContext is process-wide static state; reset it on every
// request boundary (success OR failure) so PHP-FPM workers can't
// leak authenticated context between requests.
try {
    $router->dispatch();
} finally {
    \App\Helpers\RequestContext::clear();
}
