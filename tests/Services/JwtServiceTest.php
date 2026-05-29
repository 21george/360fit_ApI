<?php
use PHPUnit\Framework\TestCase;

class JwtServiceTest extends TestCase
{
    public function testJwtCreation()
    {
        $jwtService = new JwtService();
        $token = $jwtService->createToken(['user_id' => 1]);
        $this->assertNotEmpty($token);
    }

    public function testJwtValidation()
    {
        $jwtService = new JwtService();
        $token = $jwtService->createToken(['user_id' => 1]);
        $isValid = $jwtService->validateToken($token);
        $this->assertTrue($isValid);
    }

    public function testJwtInvalidation()
    {
        $jwtService = new JwtService();
        $isValid = $jwtService->validateToken('invalid.token.string');
        $this->assertFalse($isValid);
    }
}