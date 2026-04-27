<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class MediaService
{
    private static ?S3Client $client = null;

    private static function getClient(): S3Client
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

            // Support Cloudflare R2 custom endpoint
            if (!empty($_ENV['AWS_ENDPOINT'])) {
                $config['endpoint']                = $_ENV['AWS_ENDPOINT'];
                $config['use_path_style_endpoint'] = true;
            }

            self::$client = new S3Client($config);
        }

        return self::$client;
    }

    /**
     * Generate a presigned upload URL (15 min expiry)
     */
    public static function getPresignedUploadUrl(
        string $key,
        string $contentType = 'video/mp4',
        int $expiryMinutes = 15
    ): string {
        $cmd = self::getClient()->getCommand('PutObject', [
            'Bucket'      => $_ENV['AWS_S3_BUCKET'],
            'Key'         => $key,
            'ContentType' => $contentType,
        ]);

        $request = self::getClient()->createPresignedRequest($cmd, "+{$expiryMinutes} minutes");
        return (string) $request->getUri();
    }

    /**
     * Generate a presigned GET URL (1 hour expiry)
     */
    public static function getPresignedViewUrl(string $key, int $expiryMinutes = 60): string
    {
        $cmd = self::getClient()->getCommand('GetObject', [
            'Bucket' => $_ENV['AWS_S3_BUCKET'],
            'Key'    => $key,
        ]);

        $request = self::getClient()->createPresignedRequest($cmd, "+{$expiryMinutes} minutes");
        return (string) $request->getUri();
    }

    /**
     * Delete a file from S3
     */
    public static function delete(string $key): bool
    {
        try {
            self::getClient()->deleteObject([
                'Bucket' => $_ENV['AWS_S3_BUCKET'],
                'Key'    => $key,
            ]);
            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    /**
     * Build an S3 key for client media
     */
    public static function buildKey(string $clientId, string $type, string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $uuid = bin2hex(random_bytes(8));
        return "clients/{$clientId}/{$type}/{$uuid}.{$ext}";
    }
}
