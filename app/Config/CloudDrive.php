<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class CloudDrive extends BaseConfig
{
    public string $storagePath = WRITEPATH . 'cloud-drive';

    public int $maxUploadSizeBytes = 128_849_018_880; // 120 GiB, displayed as GB in the UI.

    public int $pageSize = 24;

    public int $maxPageSize = 100;

    public int $tempFileMaxAgeSeconds = 86_400;

    // Keep all chunk indexes for a maximum-size upload within the database TEXT field.
    public int $chunkSizeBytes = 16_777_216;

    public int $chunkMaxAgeSeconds = 86_400;

    public int $maxBulkDownloadFiles = 2000;

    public int $maxBulkDownloadBytes = 128_849_018_880;

    public int $autoZipThreshold = 10;

    public int $maxZipUploadFiles = 2000;

    public int $maxArchiveEntries = 2000;

    public int $maxArchiveExtractBytes = 1_073_741_824;

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
