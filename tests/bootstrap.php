<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';

require_once BASE_PATH . '/config/Database.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH, '.env.testing');
$dotenv->safeLoad();

\App\Helpers\Response::enableTestMode(true);
