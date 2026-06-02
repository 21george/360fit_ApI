<?php
declare(strict_types=1);

namespace Tests;

use App\Config\Database;
use App\Helpers\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected static ?\MongoDB\Database $testDb = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        try {
            Database::resetInstance();
            self::$testDb = Database::getInstance()->getDB();
            // Ping to confirm connectivity
            self::$testDb->adminCommand(['ping' => 1]);
        } catch (\Throwable $e) {
            self::$testDb = null;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$testDb !== null) {
            try {
                foreach (self::$testDb->listCollectionNames() as $name) {
                    self::$testDb->dropCollection($name);
                }
            } catch (\Throwable $e) {
                // Ignore connection errors during cleanup
            }
        }
    }

    /**
     * Call a controller method and capture the ResponseHaltException.
     */
    protected function callController(string $controllerClass, string $method, array $params = []): \App\Exceptions\ResponseHaltException
    {
        $controller = new $controllerClass();
        try {
            $controller->$method($params);
        } catch (\App\Exceptions\ResponseHaltException $e) {
            return $e;
        }
        $this->fail('Expected ResponseHaltException was not thrown');
    }
}
