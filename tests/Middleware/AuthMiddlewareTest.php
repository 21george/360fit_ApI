<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\AuthMiddleware;
use App\Middleware\CoachMiddleware;
use App\Config\Database;
use App\Helpers\Response;
use App\Services\JwtService;
use Tests\TestCase;

class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Response::enableTestMode(true);
    }

    public function testAuthMiddlewareAllowsValidToken(): void
    {
        $coachId = (string) new \MongoDB\BSON\ObjectId();
        Database::collection('coaches')->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($coachId),
            'email' => 'auth@example.com',
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'name' => 'Auth',
            'subscription_status' => 'active',
        ]);

        $token = JwtService::generateAccessToken([
            'sub' => $coachId,
            'role' => 'coach',
        ]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $params = [];
        $middleware = new AuthMiddleware(['coach']);
        $middleware->handle($params);

        $this->assertArrayHasKey('_auth', $params);
        $this->assertSame($coachId, $params['_auth']['sub']);
    }

    public function testAuthMiddlewareRejectsMissingToken(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $params = [];
        $middleware = new AuthMiddleware(['coach']);

        try {
            $middleware->handle($params);
            $this->fail('Expected ResponseHaltException');
        } catch (\App\Exceptions\ResponseHaltException $e) {
            $this->assertSame(401, $e->httpCode);
        }
    }

    public function testAuthMiddlewareRejectsInvalidToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
        $params = [];
        $middleware = new AuthMiddleware(['coach']);

        try {
            $middleware->handle($params);
            $this->fail('Expected ResponseHaltException');
        } catch (\App\Exceptions\ResponseHaltException $e) {
            $this->assertSame(401, $e->httpCode);
        }
    }

    public function testCoachMiddlewareRejectsClientRole(): void
    {
        $coachId = (string) new \MongoDB\BSON\ObjectId();
        Database::collection('coaches')->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($coachId),
            'email' => 'coach@example.com',
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'name' => 'Coach',
            'subscription_status' => 'active',
        ]);

        $token = JwtService::generateAccessToken([
            'sub' => $coachId,
            'role' => 'client',
        ]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $params = [];
        $middleware = new CoachMiddleware();

        try {
            $middleware->handle($params);
            $this->fail('Expected ResponseHaltException');
        } catch (\App\Exceptions\ResponseHaltException $e) {
            $this->assertSame(403, $e->httpCode);
        }
    }

    public function testCoachMiddlewareAllowsCoachRole(): void
    {
        $coachId = (string) new \MongoDB\BSON\ObjectId();
        Database::collection('coaches')->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($coachId),
            'email' => 'coach@example.com',
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'name' => 'Coach',
            'subscription_status' => 'active',
        ]);

        $token = JwtService::generateAccessToken([
            'sub' => $coachId,
            'role' => 'coach',
        ]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $params = [];
        $middleware = new CoachMiddleware();
        $middleware->handle($params);

        $this->assertArrayHasKey('_coach', $params);
    }
}
