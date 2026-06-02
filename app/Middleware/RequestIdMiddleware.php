<?php
declare(strict_types=1);

namespace App\Middleware;

class RequestIdMiddleware
{
    public function handle(array &$params): void
    {
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        $_SERVER['REQUEST_ID'] = $requestId;
        header('X-Request-ID: ' . $requestId);
    }
}
