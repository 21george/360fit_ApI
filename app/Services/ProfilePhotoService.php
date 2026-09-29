<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Shared profile photo upload/delete for coaches and clients.
 * Handles both S3/R2 presigned-URL mode and local filesystem mode.
 */
class ProfilePhotoService
{
    private const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MIME_MAP = [
        'jpg'    => 'image/jpeg',
        'jpeg'   => 'image/jpeg',
        'png'    => 'image/png',
        'webp'   => 'image/webp',
    ];

    public static function validateExt(string $ext): bool
    {
        return in_array(strtolower($ext), self::ALLOWED_EXTS, true);
    }

    /**
     * Upload (or generate presigned URL for) a profile photo.
     *
     * @return array{upload_url?: string, url: string, key: string}
     * @throws \RuntimeException on missing file (local mode) or failed save.
     */
    public static function upload(string $entityId, string $folderPrefix, string $ext, ?string $existingKey = null): array
    {
        if ($existingKey) {
            self::deleteByKey($existingKey);
        }

        $uuid = bin2hex(random_bytes(8));
        $ext  = strtolower($ext);

        if (S3Service::isConfigured()) {
            $mime = self::MIME_MAP[$ext] ?? 'image/jpeg';
            $key  = "{$folderPrefix}/{$entityId}/profile/{$uuid}.{$ext}";

            return [
                'upload_url' => S3Service::getPresignedUploadUrl($key, $mime),
                'url'        => S3Service::getPresignedDownloadUrl($key, 1440),
                'key'        => $key,
            ];
        }

        // Local mode
        if (empty($_FILES['photo']['tmp_name'])) {
            throw new \RuntimeException('No photo file provided');
        }

        $relPath = "uploads/{$folderPrefix}/{$entityId}";
        $dir     = BASE_PATH . '/public/' . $relPath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = "{$uuid}.{$ext}";
        $dest     = "{$dir}/{$filename}";

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            throw new \RuntimeException('Failed to save photo');
        }

        $appUrl   = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
        $photoUrl = "{$appUrl}/{$relPath}/{$filename}";

        return [
            'url' => $photoUrl,
            'key' => "local:{$relPath}/{$filename}",
        ];
    }

    /**
     * Delete a photo by its key (S3 or local).
     */
    public static function deleteByKey(?string $key): void
    {
        if (!$key) {
            return;
        }

        if (str_starts_with($key, 'local:')) {
            $path = BASE_PATH . '/public/' . substr($key, 6);
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }

        if (S3Service::isConfigured()) {
            S3Service::delete($key);
        }
    }

    /**
     * Resolve a photo key to an accessible URL.
     */
    public static function resolveUrl(?string $key): ?string
    {
        if (!$key) {
            return null;
        }

        if (str_starts_with($key, 'local:')) {
            $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
            return $appUrl . '/' . substr($key, 6);
        }

        if (S3Service::isConfigured()) {
            return S3Service::getPresignedDownloadUrl($key, 1440);
        }

        return null;
    }
}
