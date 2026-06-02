<?php
declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\Router;
use App\Helpers\Response;
use Tests\TestCase;

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Response::enableTestMode(true);
    }

    public function testGetRouteMatching(): void
    {
        $router = new Router();
        $router->setPrefix('/v1');

        $matched = false;
        $router->get('/users/:id', function (array $params) use (&$matched) {
            $matched = $params['id'];
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/v1/users/42';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $router->dispatch();
        $this->assertSame('42', $matched);
    }

    public function testPostRouteMatching(): void
    {
        $router = new Router();
        $router->setPrefix('/v1');

        $matched = false;
        $router->post('/users', function () use (&$matched) {
            $matched = true;
        });

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/v1/users';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $router->dispatch();
        $this->assertTrue($matched);
    }

    public function testRouteNotFoundReturns404(): void
    {
        $router = new Router();
        $router->setPrefix('/v1');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/v1/nonexistent';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        try {
            $router->dispatch();
            $this->fail('Expected ResponseHaltException');
        } catch (\App\Exceptions\ResponseHaltException $e) {
            $this->assertSame(404, $e->httpCode);
            $this->assertSame('Route not found', $e->data['error']);
        }
    }

    public function testControllerRouteInvocation(): void
    {
        $router = new Router();
        $router->setPrefix('/v1');

        $controller = new class {
            public function show(array $params): void
            {
                Response::success(['id' => $params['id']]);
            }
        };

        $router->get('/items/:id', [$controller::class, 'show']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/v1/items/99';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        try {
            $router->dispatch();
            $this->fail('Expected ResponseHaltException');
        } catch (\App\Exceptions\ResponseHaltException $e) {
            $this->assertSame(200, $e->httpCode);
            $this->assertSame('99', $e->data['data']['id']);
        }
    }
}
