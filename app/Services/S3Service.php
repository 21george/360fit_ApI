<?php
declare(strict_types=1);

namespace App\Services;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class S3Service
{
    private static ?S3Client $client = null;

    public static function isConfigured(): bool
    {
        return !empty($_ENV['AWS_ACCESS_KEY_ID'])
            && !empty($_ENV['AWS_SECRET_ACCESS_KEY'])
            && !empty($_ENV['AWS_S3_BUCKET']);
    }

    private static function client(): S3Client
    {
        if (self::$client === null) {
            $config = [
                'version'     => 'latest',
                'region'      => $_ENV['AWS_REGION'] ?? 'eu-central-1',
                'credentials' => [
                    'key'    => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
                    'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
                ],
            ];
            if (!empty($_ENV['AWS_ENDPOINT'])) {
                $config['endpoint'] = $_ENV['AWS_ENDPOINT'];
                $config['use_path_style_endpoint'] = true;
            }
            self::$client = new S3Client($config);
        }
        return self::$client;
    }

    public static function getPresignedUploadUrl(string $key, string $contentType, int $expiryMinutes = 15): string
    {
        $bucket  = $_ENV['AWS_S3_BUCKET'] ?? '';
        $cmd     = self::client()->getCommand('PutObject', [
            'Bucket'      => $bucket,
            'Key'         => $key,
            'ContentType' => $contentType,
        ]);
        $request = self::client()->createPresignedRequest($cmd, "+{$expiryMinutes} minutes");
        return (string) $request->getUri();
    }

    public static function getPresignedDownloadUrl(string $key, int $expiryMinutes = 60): string
    {
        $bucket  = $_ENV['AWS_S3_BUCKET'] ?? '';
        $cmd     = self::client()->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);
        $request = self::client()->createPresignedRequest($cmd, "+{$expiryMinutes} minutes");
        return (string) $request->getUri();
    }

    public static function delete(string $key): void
    {
        try {
            self::client()->deleteObject([
                'Bucket' => $_ENV['AWS_S3_BUCKET'] ?? '',
                'Key'    => $key,
            ]);
        } catch (S3Exception $e) {
            error_log('S3 delete error: ' . $e->getMessage());
        }
    }

    public static function buildKey(string $clientId, string $type, string $filename): string
    {
        $ext  = pathinfo($filename, PATHINFO_EXTENSION);
        $uuid = bin2hex(random_bytes(8));
        return "clients/{$clientId}/{$type}/{$uuid}.{$ext}";
    }
}
