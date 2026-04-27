<?php
declare(strict_types=1);

namespace App\Services;

class CodeService
{
    private const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $part1 = self::randomString(4);
        $part2 = self::randomString(2);
        return "FIT-{$part1}-{$part2}";
    }

    private static function randomString(int $length): string
    {
        $chars  = self::CHARS;
        $result = '';
        $max    = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }

    public static function hash(string $code): string
    {
        return password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * SHA-256 lookup hash for O(1) client login queries.
     * Stored alongside bcrypt hash to avoid full-table scan.
     */
    public static function lookupHash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    public static function verify(string $code, string $hash): bool
    {
        return password_verify($code, $hash);
    }
}
