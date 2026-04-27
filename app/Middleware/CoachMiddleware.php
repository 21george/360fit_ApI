<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class CoachMiddleware extends AuthMiddleware
{
    public function handle(array &$params): void
    {
        parent::handle($params);
        if (($params['_auth']['role'] ?? '') !== 'coach') {
            Response::error('Forbidden: Coach access required', 403);
        }
    }
}
