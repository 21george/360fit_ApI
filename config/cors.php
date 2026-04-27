<?php

$isDev = ($_ENV['APP_ENV'] ?? '') === 'development';

$origins = array_filter([
    $_ENV['FRONTEND_URL'] ?? null,
    'https://frontend-g-delta.vercel.app',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:19006',
]);

// Normalize: strip trailing slashes from all origins
$origins = array_map(fn(string $o) => rtrim($o, '/'), $origins);

// Add dev-only origins
if ($isDev) {
    $origins[] = 'http://localhost:3000';
    $origins[] = 'http://localhost:3001';
    $origins[] = 'http://localhost:19006';
    $origins[] = 'exp://localhost:19000';
}

return [
    'allowed_origins'  => array_values(array_unique($origins)),
    'allowed_methods'  => ['GET','POST','PUT','DELETE','OPTIONS','PATCH'],
    'allowed_headers'  => ['Content-Type','Authorization','X-Requested-With','Accept'],
    'allow_credentials' => true,
    'max_age'          => 86400,
];
