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
    Response::json(['error' => 'Internal server error'], 500);
});

// Route
$router = new Router();
$router->setPrefix('/v1');
require_once BASE_PATH . '/routes/api.php';
$router->dispatch();
