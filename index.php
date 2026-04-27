<?php
/**
 * Fallback entry point.
 *
 * When the PHP built-in server is started from the backend root
 * (e.g. `php -S localhost:8000`) without `-t public`, this file
 * ensures requests still reach the real entry point in public/.
 *
 * Preferred start command:
 *   php -S 0.0.0.0:8000 -t public public/router.php
 */

// Serve static files as-is when running under PHP's built-in server
if (PHP_SAPI === 'cli-server') {
    $publicFile = __DIR__ . '/public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($publicFile)) {
        return false;
    }
}

require __DIR__ . '/public/index.php';
