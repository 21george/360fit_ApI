<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuthController;
use App\Config\Database;
use App\Helpers\Request;
use App\Helpers\Response;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Response::enableTestMode(true);
    }

    public function testCoachRegisterSuccess(): void
    {
        $this->setRequestBody([
            'email' => 'newcoach@example.com',
            'password' => 'securePassword123',
            'name' => 'Jane',
            'surname' => 'Doe',
        ]);

        $e = $this->callController(AuthController::class, 'coachRegister', []);
        $this->assertSame(200, $e->httpCode);
        $this->assertTrue($e->data['success']);
        $this->assertArrayHasKey('access_token', $e->data['data']);
    }

    public function testCoachRegisterDuplicateEmail(): void
    {
        Database::collection('coaches')->insertOne([
            'email' => 'duplicate@example.com',
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'name' => 'Existing',
        ]);

        $this->setRequestBody([
            'email' => 'duplicate@example.com',
            'password' => 'securePassword123',
            'name' => 'Jane',
            'surname' => 'Doe',
        ]);

        $e = $this->callController(AuthController::class, 'coachRegister', []);
        $this->assertSame(409, $e->httpCode);
        $this->assertFalse($e->data['success']);
    }

    public function testCoachLoginSuccess(): void
    {
        Database::collection('coaches')->insertOne([
            'email' => 'coach@example.com',
            'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
            'name' => 'Test',
            'surname' => 'Coach',
            'subscription_status' => 'active',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ]);

        $this->setRequestBody([
            'email' => 'coach@example.com',
            'password' => 'password123',
        ]);

        $e = $this->callController(AuthController::class, 'coachLogin', []);
        $this->assertSame(200, $e->httpCode);
        $this->assertTrue($e->data['success']);
        $this->assertArrayHasKey('access_token', $e->data['data']);
    }

    public function testCoachLoginInvalidCredentials(): void
    {
        $this->setRequestBody([
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $e = $this->callController(AuthController::class, 'coachLogin', []);
        $this->assertSame(401, $e->httpCode);
        $this->assertFalse($e->data['success']);
    }

    public function testRefreshToken(): void
    {
        $coachId = (string) new \MongoDB\BSON\ObjectId();
        Database::collection('coaches')->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($coachId),
            'email' => 'refresh@example.com',
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'name' => 'Refresh',
            'subscription_status' => 'active',
        ]);

        $refreshToken = \App\Services\JwtService::generateRefreshToken([
            'sub' => $coachId,
            'role' => 'coach',
        ]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;
        $e = $this->callController(AuthController::class, 'refresh', []);
        $this->assertSame(200, $e->httpCode);
        $this->assertTrue($e->data['success']);
        $this->assertArrayHasKey('access_token', $e->data['data']);
    }

    public function testLogout(): void
    {
        $coachId = (string) new \MongoDB\BSON\ObjectId();
        Database::collection('coaches')->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($coachId),
            'email' => 'logout@example.com',
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'name' => 'Logout',
        ]);

        $token = \App\Services\JwtService::generateAccessToken([
            'sub' => $coachId,
            'role' => 'coach',
        ]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $e = $this->callController(AuthController::class, 'logout', []);
        $this->assertSame(200, $e->httpCode);
        $this->assertTrue($e->data['success']);
    }

    private function setRequestBody(array $data): void
    {
        Request::setTestBody(json_encode($data));
    }
}
