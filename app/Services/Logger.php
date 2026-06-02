<?php
declare(strict_types=1);

namespace App\Services;

class Logger
{
    private static function write(string $level, string $message, array $context = []): void
    {
        $entry = json_encode([
            'level'   => $level,
            'message' => $message,
            'context' => $context,
            'time'    => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $logFile = BASE_PATH . '/storage/logs/app.log';
        @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
        error_log('[ERROR] ' . $message . ' ' . json_encode($context));
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
        error_log('[WARNING] ' . $message . ' ' . json_encode($context));
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
            self::write('DEBUG', $message, $context);
        }
    }
}
