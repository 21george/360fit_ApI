<?php
/**
 * PHP built-in dev-server router.
 * Usage: php -S 0.0.0.0:8000 -t public public/router.php
 *
 * Serves real static files as-is; routes everything else through index.php.
 */
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $requestPath;

    // Serve existing static files directly (CSS, JS, images, etc.)
    if (is_file($file) && $requestPath !== '/index.php' && $requestPath !== '/router.php') {
        return false;
    }
}

require __DIR__ . '/index.php';
