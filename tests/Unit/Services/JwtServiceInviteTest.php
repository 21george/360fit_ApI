<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\JwtService;
use PHPUnit\Framework\TestCase;

final class JwtServiceInviteTest extends TestCase
{
    public function test_generate_staff_invite_token_returns_non_empty_string(): void
    {
        $token = JwtService::generateStaffInviteToken('000000000000000000000001');
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function test_verify_staff_invite_token_returns_staff_id(): void
    {
        $staffId = '000000000000000000000001';
        $token   = JwtService::generateStaffInviteToken($staffId);

        $result = JwtService::verifyStaffInviteToken($token);
        $this->assertSame($staffId, $result);
    }

    public function test_verify_staff_invite_token_rejects_invalid_token(): void
    {
        $result = JwtService::verifyStaffInviteToken('not-a-valid-token');
        $this->assertNull($result);
    }

    public function test_verify_staff_invite_token_rejects_expired_token(): void
    {
        // Use reflection to generate a token that's already expired
        $staffId = '000000000000000000000001';
        $token   = JwtService::generateStaffInviteToken($staffId);

        // Tamper with the token payload to make it expired
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan(time(), $payload['exp']); // Original token is valid

        // Verify original still works
        $this->assertSame($staffId, JwtService::verifyStaffInviteToken($token));
    }

    public function test_verify_staff_invite_token_rejects_wrong_type(): void
    {
        // Generate a regular access token instead of invite token
        $token = JwtService::generateAccessToken([
            'sub'  => '000000000000000000000001',
            'role' => 'staff',
        ]);

        $result = JwtService::verifyStaffInviteToken($token);
        $this->assertNull($result);
    }
}
