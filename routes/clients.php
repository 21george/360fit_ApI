<?php
// routes/clients.php
use App\Controllers\ClientController;
use App\Middleware\AuthMiddleware;
use League\Route\Router;

/** @var Router $router */

$coachAuth  = new AuthMiddleware(['coach']);
$clientAuth = new AuthMiddleware(['client']);
$anyAuth    = new AuthMiddleware(['coach', 'client']);

$ctrl = new ClientController();

$router->get('/coach/clients',                         [$ctrl, 'index']          )->middleware($coachAuth);
$router->post('/coach/clients',                        [$ctrl, 'store']          )->middleware($coachAuth);
$router->get('/coach/clients/{id}',                    [$ctrl, 'show']           )->middleware($coachAuth);
$router->put('/coach/clients/{id}',                    [$ctrl, 'update']         )->middleware($coachAuth);
$router->delete('/coach/clients/{id}',                 [$ctrl, 'destroy']        )->middleware($coachAuth);
$router->post('/coach/clients/{id}/regenerate-code',   [$ctrl, 'regenerateCode'] )->middleware($coachAuth);

$router->post('/coach/clients/{id}/block',   [$ctrl, 'block'])->middleware($coachAuth);
$router->post('/coach/clients/{id}/unblock', [$ctrl, 'unblock'])->middleware($coachAuth);

$router->get('/client/profile', [$ctrl, 'clientProfile'])->middleware($clientAuth);
