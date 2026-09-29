<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    Dotenv::createImmutable(dirname($envPath))->load();
}
