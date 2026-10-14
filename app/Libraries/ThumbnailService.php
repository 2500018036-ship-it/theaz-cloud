<?php

namespace App\Libraries;

use Config\CloudDrive;

/**
 * Generates and caches image thumbnails using GD.
 */
class ThumbnailService
{
    private DriveService $drive;

    private CloudDrive $config;

    public function __construct(?DriveService $drive = null, ?CloudDrive $config = null)
    {
        $this->drive  = $drive ?? new DriveService();
        $this->config = $config ?? config(CloudDrive::class);
    }

    /**
     * Get or generate a thumbnail for the given file item.
     *
     * @return array{path: string, mime: string}|null
     */
    public function getThumbnail(int $userId, int $itemId): ?array
    {
        $download   = $this->drive->downloadableFile($userId, $itemId);
        $item       = $download['item'];
        $sourcePath = $download['path'];
        $mimeType   = (string) ($item['mime_type'] ?? '');

        if (! $this->isThumbnailable($mimeType)) {
            return null;
        }

        $cachePath = $this->cachePath((int) $item['id']);

        // Serve cache if still fresh
        if (is_file($cachePath)) {
            $sourceTime = filemtime($sourcePath);
            $cacheTime  = filemtime($cachePath);

            if ($sourceTime !== false && $cacheTime !== false && $sourceTime <= $cacheTime) {
                return ['path' => $cachePath, 'mime' => 'image/jpeg'];
            }
        }

        if ($this->generate($sourcePath, $cachePath, $mimeType)) {
            return ['path' => $cachePath, 'mime' => 'image/jpeg'];
        }

        return null;
    }

    /**
     * Remove a cached thumbnail.
     */
    public function deleteCached(int $itemId): void
    {
        $path = $this->cachePath($itemId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------

    private function generate(string $sourcePath, string $cachePath, string $mimeType): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }

        // Limit source file size to avoid memory exhaustion (20 MB)
        if (filesize($sourcePath) > 20_971_520) {
            return false;
        }
        $dimensions = @getimagesize($sourcePath);
        if ($dimensions === false || $dimensions[0] * $dimensions[1] > 32_000_000) return false;

        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png'  => @imagecreatefrompng($sourcePath),
            'image/gif'  => @imagecreatefromgif($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default      => false,
        };

        if ($source === false) {
            return false;
        }

        $origW = imagesx($source);
        $origH = imagesy($source);

        if ($origW <= 0 || $origH <= 0) {
            imagedestroy($source);

            return false;
        }

        $maxW  = $this->config->thumbnailMaxWidth;
        $maxH  = $this->config->thumbnailMaxHeight;
        $ratio = min($maxW / $origW, $maxH / $origH, 1.0);
        $newW  = max(1, (int) round($origW * $ratio));
        $newH  = max(1, (int) round($origH * $ratio));

        $thumb = imagecreatetruecolor($newW, $newH);
        if ($thumb === false) {
            imagedestroy($source);

            return false;
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($source);

        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0775, true) && ! is_dir($cacheDir)) {
            imagedestroy($thumb);

            return false;
        }

        $result = imagejpeg($thumb, $cachePath, $this->config->thumbnailQuality);
        imagedestroy($thumb);

        return $result;
    }

    private function isThumbnailable(string $mimeType): bool
    {
        return in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ], true);
    }

    private function cachePath(int $itemId): string
    {
        return $this->drive->storageRoot()
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'thumbs'
            . DIRECTORY_SEPARATOR . $itemId . '.jpg';
    }
}
