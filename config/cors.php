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
    $origins[] = 'http://10.0.2.2:19000';   // Android emulator (Expo Go)
    $origins[] = 'http://10.0.2.2:8081';    // Android emulator (Metro)
    $origins[] = 'http://192.168.1.100:19000'; // Physical device (adjust IP as needed)
    $origins[] = 'http://192.168.1.100:8081';  // Physical device Metro
    $origins[] = 'http://192.168.1.100:19006'; // Physical device web
}

return [
    'allowed_origins'  => array_values(array_unique($origins)),
    'allowed_methods'  => ['GET','POST','PUT','DELETE','OPTIONS','PATCH'],
    'allowed_headers'  => ['Content-Type','Authorization','X-Requested-With','Accept'],
    'allow_credentials' => true,
    'max_age'          => 86400,
];
