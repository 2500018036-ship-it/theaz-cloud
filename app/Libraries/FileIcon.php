<?php

namespace App\Libraries;

class FileIcon
{
    /**
     * Return a local Tabler icon class for a drive item or version row.
     */
    public static function forItem(array $item): string
    {
        if ((string) ($item['type'] ?? 'file') === 'folder') {
            return 'ti ti-folder';
        }

        return self::forFile(
            (string) ($item['extension'] ?? ''),
            (string) ($item['mime_type'] ?? ''),
            (string) ($item['name'] ?? ''),
        );
    }

    public static function forFile(string $extension = '', string $mimeType = '', string $name = ''): string
    {
        $extension = strtolower(trim($extension));
        if ($extension === '' && $name !== '') {
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        }

        $mimeType = strtolower(trim($mimeType));

        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            return 'ti ti-file-type-pdf';
        }

        if (in_array($extension, ['doc', 'docx', 'odt', 'rtf'], true)) {
            return 'ti ti-file-type-doc';
        }

        if (in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true)) {
            return 'ti ti-file-spreadsheet';
        }

        if (in_array($extension, ['ppt', 'pptx', 'odp'], true)) {
            return 'ti ti-presentation';
        }

        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tif', 'tiff', 'avif'], true)) {
            return 'ti ti-photo';
        }

        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mkv', 'mov', 'webm', 'avi', 'm4v'], true)) {
            return 'ti ti-video';
        }

        if (str_starts_with($mimeType, 'audio/') || in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'], true)) {
            return 'ti ti-music';
        }

        if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2'], true)) {
            return 'ti ti-file-zip';
        }

        if (str_starts_with($mimeType, 'text/') || in_array($extension, [
            'txt',
            'md',
            'json',
            'xml',
            'html',
            'htm',
            'css',
            'js',
            'ts',
            'php',
            'py',
            'rb',
            'go',
            'java',
            'c',
            'cpp',
            'cs',
            'sql',
            'yml',
            'yaml',
            'ini',
            'env',
        ], true)) {
            return in_array($extension, ['txt', 'md'], true) ? 'ti ti-file-text' : 'ti ti-file-code';
        }

        return 'ti ti-file';
    }
}
