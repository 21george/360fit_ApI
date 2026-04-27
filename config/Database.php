<?php

namespace App\Config;

use MongoDB\Client;

class Database
{
    private static ?Database $instance = null;
    private Client $client;
    private \MongoDB\Database $db;

    private function __construct()
    {
        $uri    = $_ENV['MONGO_URI'] ?? 'mongodb://localhost:27017';
        $dbName = $_ENV['MONGO_DB'] ?? 'coaching_platform';

        $uriOptions = [];
        $caFile = $this->resolveCABundle();
        if ($caFile !== null) {
            $uriOptions['tlsCAFile'] = $caFile;
        }

        $this->client = new Client($uri, $uriOptions);
        $this->db     = $this->client->selectDatabase($dbName);
    }

    private function resolveCABundle(): ?string
    {
        // Explicit override via environment variable
        if (!empty($_ENV['MONGO_TLS_CA_FILE'])) {
            return $_ENV['MONGO_TLS_CA_FILE'];
        }

        // Well-known paths (macOS Homebrew Apple Silicon / Intel, then Linux)
        $candidates = [
            '/opt/homebrew/etc/openssl@3/cert.pem',
            '/opt/homebrew/etc/openssl@1.1/cert.pem',
            '/usr/local/etc/openssl@3/cert.pem',
            '/usr/local/etc/openssl/cert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getDB(): \MongoDB\Database
    {
        return $this->db;
    }

    public function getCollection(string $name): \MongoDB\Collection
    {
        return $this->db->selectCollection($name);
    }

    /**
     * Static shortcut: Database::collection('name')
     */
    public static function collection(string $name): \MongoDB\Collection
    {
        return self::getInstance()->getCollection($name);
    }
}
