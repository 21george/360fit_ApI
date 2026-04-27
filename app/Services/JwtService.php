<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private static ?self $instance = null;

    private string $secret;
    private string $refreshSecret;
    private int $expiry;
    private int $refreshExpiry;

    private function __construct()
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        $refreshSecret = $_ENV['JWT_REFRESH_SECRET'] ?? '';

        if ($secret === '' || $refreshSecret === '') {
            throw new \RuntimeException('JWT_SECRET and JWT_REFRESH_SECRET environment variables must be set');
        }

        $this->secret        = $secret;
        $this->refreshSecret = $refreshSecret;
        $this->expiry        = (int) ($_ENV['JWT_EXPIRY']          ?? 900);
        $this->refreshExpiry = (int) ($_ENV['JWT_REFRESH_EXPIRY']  ?? 2592000);
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate an access token from a payload array (keys: sub, role, name, email, coach_id, ...).
     */
    public static function generateAccessToken(array $payload): string
    {
        $self = self::getInstance();
        $claims = array_merge([
            'iss' => 'coaching-platform',
            'iat' => time(),
            'exp' => time() + $self->expiry,
        ], $payload);
        return JWT::encode($claims, $self->secret, 'HS256');
    }

    /**
     * Generate a refresh token from a payload array.
     */
    public static function generateRefreshToken(array $payload): string
    {
        $self = self::getInstance();
        $claims = array_merge([
            'iss'  => 'coaching-platform',
            'iat'  => time(),
            'exp'  => time() + $self->refreshExpiry,
            'type' => 'refresh',
        ], $payload);
        return JWT::encode($claims, $self->refreshSecret, 'HS256');
    }

    /**
     * Verify and decode an access token. Returns payload array or null on failure.
     */
    public static function verifyAccessToken(string $token): ?array
    {
        $self = self::getInstance();
        try {
            $decoded = JWT::decode($token, new Key($self->secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Verify and decode a refresh token. Returns payload array or null on failure.
     */
    public static function verifyRefreshToken(string $token): ?array
    {
        $self = self::getInstance();
        try {
            $decoded = JWT::decode($token, new Key($self->refreshSecret, 'HS256'));
            $data = (array) $decoded;
            if (($data['type'] ?? '') !== 'refresh') {
                return null;
            }
            return $data;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Alias for verifyRefreshToken — kept for backward compatibility.
     */
    public static function decodeRefresh(string $token): ?array
    {
        return self::verifyRefreshToken($token);
    }

    /**
     * Extract bearer token from the current HTTP request headers.
     */
    public static function getTokenFromRequest(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }
        return null;
    }

    /**
     * Generate a short-lived setup token for subscription selection during signup.
     * Valid for 5 minutes only.
     */
    public static function generateSetupToken(string $coachId): string
    {
        $self = self::getInstance();
        $claims = [
            'iss'  => 'coaching-platform',
            'iat'  => time(),
            'exp'  => time() + 300,  // 5 minutes
            'sub'  => $coachId,
            'type' => 'setup',
        ];
        return JWT::encode($claims, $self->secret, 'HS256');
    }

    /**
     * Verify and decode a setup token. Returns coach ID or null on failure.
     */
    public static function verifySetupToken(string $token): ?string
    {
        $self = self::getInstance();
        try {
            $decoded = JWT::decode($token, new Key($self->secret, 'HS256'));
            $data = (array) $decoded;
            if (($data['type'] ?? '') !== 'setup') {
                return null;
            }
            // Check if expired (extra safety)
            if (isset($data['exp']) && $data['exp'] < time()) {
                return null;
            }
            return $data['sub'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }
}
