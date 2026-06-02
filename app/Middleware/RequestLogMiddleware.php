<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\Logger;

class RequestLogMiddleware
{
    public function handle(array &$params): void
    {
        $start = microtime(true);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        register_shutdown_function(function () use ($start, $method, $uri) {
            $duration = (microtime(true) - $start) * 1000;
            $status = http_response_code() ?: 200;

            Logger::info('HTTP request', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
                'duration_ms' => round($duration, 2),
                'request_id' => $_SERVER['REQUEST_ID'] ?? null,
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);
        });
    }
}
