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

// Error handler
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    $errorMsg = sprintf(
        "%s [%s:%d] %s",
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
