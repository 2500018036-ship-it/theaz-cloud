<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class CloudDrive extends BaseConfig
{
    public string $storagePath = WRITEPATH . 'cloud-drive';

    public int $maxUploadSizeBytes = 104_857_600;

    public int $pageSize = 24;

    public int $maxPageSize = 100;

    public int $tempFileMaxAgeSeconds = 86_400;

    public int $chunkSizeBytes = 2_097_152;

    public int $chunkMaxAgeSeconds = 86_400;

    public int $maxBulkDownloadFiles = 100;

    public int $maxBulkDownloadBytes = 524_288_000;

    public int $thumbnailMaxWidth = 240;

    public int $thumbnailMaxHeight = 240;

    public int $thumbnailQuality = 80;

    public int $maxVersionsPerFile = 10;

    public int $versionRetentionDays = 90;

    public array $allowedExtensions = [
        'csv',
        'doc',
        'docx',
        'gif',
        'jpeg',
        'jpg',
        'json',
        'md',
        'mp3',
        'mp4',
        'pdf',
        'png',
        'ppt',
        'pptx',
        'txt',
        'webp',
        'xls',
        'xlsx',
        'zip',
    ];

    public array $allowedMimeTypes = [
        'application/json',
        'application/msword',
        'application/octet-stream',
        'application/pdf',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'audio/mpeg',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/csv',
        'text/markdown',
        'text/plain',
        'video/mp4',
    ];

    public array $sensitiveLogKeys = [
        'api_key',
        'authorization',
        'cookie',
        'csrf',
        'database_default_password',
        'mariadb_password',
        'mariadb_root_password',
        'password',
        'secret',
        'session',
        'token',
    ];
}
