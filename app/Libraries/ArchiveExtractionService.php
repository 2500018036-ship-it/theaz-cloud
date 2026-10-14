<?php

namespace App\Libraries;

use Config\CloudDrive;

class ArchiveExtractionService
{
    public function __construct(private CloudDrive $config)
    {
    }

    /** Validate the complete archive before decompressing into private temporary files. */
    public function unpack(string $sourcePath, int $availableBytes): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(lang('App.transfers.zipUnavailable'));
        }

        $zip = new \ZipArchive();
        if ($zip->open($sourcePath, \ZipArchive::RDONLY) !== true) {
            throw new \DomainException(lang('App.transfers.invalidArchive'));
        }

        $directory = null;
        try {
            if ($zip->numFiles === 0 || $zip->numFiles > $this->config->maxArchiveEntries) {
                throw new \DomainException(lang('App.transfers.archiveEntryLimit'));
            }

            $entries = [];
            $paths = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new \DomainException(lang('App.transfers.invalidArchive'));
                }

                $name = str_replace('\\', '/', $stat['name']);
                $isDirectory = str_ends_with($name, '/');
                $path = rtrim($name, '/');
                $parts = explode('/', $path);
                if (count($parts) > 32) {
                    throw new \DomainException(lang('App.transfers.unsafeArchivePath'));
                }
                foreach ($parts as $part) {
                    if ($part === '' || $part === '.' || $part === '..' || mb_strlen($part) > 190
                        || trim($part, " \t\n\r\0\x0B.") !== $part
                        || preg_match('/[\x00-\x1F\x7F<>:"|?*]/u', $part) !== 0) {
                        throw new \DomainException(lang('App.transfers.unsafeArchivePath'));
                    }
                }

                $system = $attributes = 0;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (($type !== 0 && $type !== 0100000 && $type !== 0040000)
                    || ($type === 0040000 && ! $isDirectory)
                    || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new \DomainException(lang('App.transfers.unsupportedArchiveEntry'));
                }

                $key = mb_strtolower($path);
                if (isset($paths[$key])) {
                    throw new \DomainException(lang('App.transfers.duplicateArchivePath'));
                }
                $paths[$key] = $isDirectory;
                $size = (int) $stat['size'];
                if ($size < 0 || $size > $this->config->maxUploadSizeBytes
                    || $size > $this->config->maxArchiveExtractBytes - $total) {
                    throw new \DomainException(lang('App.transfers.archiveSizeLimit'));
                }
                if (! $isDirectory) {
                    $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (! in_array($extension, $this->config->allowedExtensions, true)) {
                        throw new \DomainException(lang('App.transfers.archiveFileNotAllowed', [$path]));
                    }
                    $total += $size;
                }
                $entries[] = ['path' => $path, 'name' => $stat['name'], 'directory' => $isDirectory,
                    'size' => $size, 'crc' => $stat['crc'], 'source' => null];
            }

            // Reject a file used as a parent, including case-insensitive collisions.
            $nodePaths = $paths;
            foreach ($entries as $entry) {
                $parent = dirname($entry['path']);
                while ($parent !== '.') {
                    if (isset($paths[mb_strtolower($parent)]) && ! $paths[mb_strtolower($parent)]) {
                        throw new \DomainException(lang('App.transfers.duplicateArchivePath'));
                    }
                    $nodePaths[mb_strtolower($parent)] = true;
                    $parent = dirname($parent);
                }
            }
            if (count($nodePaths) > $this->config->maxArchiveEntries) {
                throw new \DomainException(lang('App.transfers.archiveEntryLimit'));
            }
            if ($total > $availableBytes) {
                throw new \DomainException('Storage quota is full.');
            }

            $directory = rtrim($this->config->storagePath, '/\\') . DIRECTORY_SEPARATOR . 'tmp'
                . DIRECTORY_SEPARATOR . 'extract-' . bin2hex(random_bytes(16));
            if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new \RuntimeException(lang('App.transfers.extractionFailed'));
            }

            foreach ($entries as $index => &$entry) {
                if ($entry['directory']) {
                    continue;
                }
                $stream = $zip->getStream($entry['name']);
                $entry['source'] = $directory . DIRECTORY_SEPARATOR . $index . '.part';
                $target = fopen($entry['source'], 'xb');
                if ($stream === false || $target === false) {
                    if (is_resource($stream)) fclose($stream);
                    if (is_resource($target)) fclose($target);
                    throw new \RuntimeException(lang('App.transfers.extractionFailed'));
                }
                $written = 0;
                $crc = hash_init('crc32b');
                try {
                    while (! feof($stream)) {
                        $buffer = fread($stream, 1_048_576);
                        if ($buffer === false || ($buffer === '' && ! feof($stream))) {
                            throw new \RuntimeException(lang('App.transfers.extractionFailed'));
                        }
                        $written += strlen($buffer);
                        if ($written > $entry['size'] || fwrite($target, $buffer) !== strlen($buffer)) {
                            throw new \DomainException(lang('App.transfers.invalidArchive'));
                        }
                        hash_update($crc, $buffer);
                    }
                    if ($written !== $entry['size'] || hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
                        throw new \DomainException(lang('App.transfers.invalidArchive'));
                    }
                } finally {
                    fclose($stream);
                    fclose($target);
                }
            }
            unset($entry);

            return ['directory' => $directory, 'entries' => $entries, 'size_bytes' => $total];
        } catch (\Throwable $exception) {
            if ($directory !== null) {
                $this->cleanup($directory);
            }
            throw $exception;
        } finally {
            $zip->close();
        }
    }

    public function cleanup(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.part') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($directory);
    }
}
