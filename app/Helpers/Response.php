<?php

declare(strict_types=1);

namespace App\Helpers;

class Response
{
    public static function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $code = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $code);
    }

    public static function error(string $message, int $code = 400, mixed $errors = null): never
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors !== null) $body['errors'] = $errors;
        self::json($body, $code);
    }

    public static function notFound(string $message = 'Not found'): never
    {
        self::error($message, 404);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    public static function paginated(array $data, int $total, int $page, int $perPage): never
    {
        self::json([
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int) ceil($total / $perPage),
            ]
        ]);
    }
}
