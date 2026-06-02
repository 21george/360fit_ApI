<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\JwtService;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    public function testGenerateAndVerifyAccessToken(): void
    {
        $token = JwtService::generateAccessToken([
            'sub' => 'coach_123',
            'role' => 'coach',
            'name' => 'Test Coach',
        ]);
        $this->assertNotEmpty($token);

        $decoded = JwtService::verifyAccessToken($token);
        $this->assertNotNull($decoded);
        $this->assertSame('coach_123', $decoded['sub']);
        $this->assertSame('coach', $decoded['role']);
        $this->assertSame('Test Coach', $decoded['name']);
    }

    public function testVerifyInvalidTokenReturnsNull(): void
    {
        $this->assertNull(JwtService::verifyAccessToken('invalid.token.string'));
    }

    public function testGenerateAndVerifyRefreshToken(): void
    {
        $token = JwtService::generateRefreshToken(['sub' => 'coach_123']);
        $decoded = JwtService::verifyRefreshToken($token);
        $this->assertNotNull($decoded);
        $this->assertSame('coach_123', $decoded['sub']);
        $this->assertSame('refresh', $decoded['type']);
    }

    public function testAccessTokenFailsRefreshVerification(): void
    {
        $accessToken = JwtService::generateAccessToken(['sub' => 'coach_123']);
        $this->assertNull(JwtService::verifyRefreshToken($accessToken));
    }

    public function testGenerateAndVerifySetupToken(): void
    {
        $token = JwtService::generateSetupToken('coach_456');
        $coachId = JwtService::verifySetupToken($token);
        $this->assertSame('coach_456', $coachId);
    }

    public function testSetupTokenExpiredReturnsNull(): void
    {
        // Create a token with a past expiry by manipulating the payload manually
        $secret = $_ENV['JWT_SECRET'];
        $claims = [
            'iss'  => 'coaching-platform',
            'iat'  => time() - 600,
            'exp'  => time() - 300,
            'sub'  => 'coach_789',
            'type' => 'setup',
        ];
        $token = \Firebase\JWT\JWT::encode($claims, $secret, 'HS256');
        $this->assertNull(JwtService::verifySetupToken($token));
    }

    public function testGetTokenFromRequest(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';
        $this->assertSame('test-token-123', JwtService::getTokenFromRequest());

        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertNull(JwtService::getTokenFromRequest());
    }
}
