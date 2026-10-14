<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\CloudDrive;
use Config\Database;

/** Upload input files into private staging, then store a single ZIP. */
class BatchUploadService
{
    private BaseConnection $db;
    private DriveService $drive;
    private ChunkedUploadService $chunks;
    private CloudDrive $config;

    public function __construct(?BaseConnection $db = null, ?DriveService $drive = null, ?CloudDrive $config = null)
    {
        $this->db = $db ?? Database::connect();
        $this->config = $config ?? config(CloudDrive::class);
        $this->drive = $drive ?? new DriveService();
        $this->chunks = new ChunkedUploadService($this->db, $this->drive, $this->config);
    }

    public function init(int $userId, ?int $parentId, array $files): array
    {
        if (! class_exists(\ZipArchive::class)) throw new \RuntimeException(lang('App.transfers.zipUnavailable'));
        if (count($files) <= $this->config->autoZipThreshold || count($files) > $this->config->maxZipUploadFiles) {
            throw new \DomainException(lang('App.transfers.batchFileLimit', [$this->config->maxZipUploadFiles]));
        }
        $destination = $this->drive->uploadDestination($userId, $parentId);
        $total = 0;
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null) || ! is_int($file['size'] ?? null)) {
                throw new \DomainException('Invalid upload manifest.');
            }
            $this->drive->validateUploadMetadata($file['name'], $file['size']);
            $total += $file['size'] + 512 + strlen($file['name']) * 2; // ZIP/ZIP64 entry headers.
            if ($total > $this->drive->uploadSizeLimit()) throw new \DomainException('File exceeds the maximum upload size.');
        }
        if ($total > $destination['available']) throw new \DomainException('Not enough storage quota.');

        $batchId = bin2hex(random_bytes(32));
        $archiveName = 'Upload-' . date('Ymd-His') . '.zip';
        $this->drive->validateUploadMetadata($archiveName, $total);
        $now = Time::now()->toDateTimeString();
        $sessions = [];
        $this->db->transBegin();
        try {
            $this->db->table('upload_batches')->insert([
                'batch_id' => $batchId, 'user_id' => $userId, 'parent_id' => $parentId,
                'archive_name' => $archiveName, 'file_count' => count($files), 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($files as $file) {
                $sessions[] = $this->chunks->initUpload($userId, $parentId, $file['name'], $file['size'], null, $batchId);
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            foreach ($sessions as $session) $this->chunks->cancelUpload($userId, $session['upload_id']);
            $this->db->transRollback();
            throw $exception;
        }

        return ['batch_id' => $batchId, 'archive_name' => $archiveName, 'sessions' => $sessions];
    }

    public function stage(int $userId, string $batchId, string $uploadId): array
    {
        $this->find($userId, $batchId);
        $result = $this->chunks->stageUpload($userId, $uploadId, $batchId);
        $this->db->table('upload_batches')->where('batch_id', $batchId)->update(['updated_at' => Time::now()->toDateTimeString()]);

        return $result;
    }

    public function complete(int $userId, string $batchId): array
    {
        $batch = $this->find($userId, $batchId);
        if ($batch['status'] === 'completed') return $this->drive->readableFile($userId, (int) $batch['item_id'])['item'];
        $files = $this->db->table('chunked_uploads')->where('batch_id', $batchId)->where('user_id', $userId)->orderBy('id')->get()->getResultArray();
        if (count($files) !== (int) $batch['file_count'] || array_filter($files, static fn ($file) => $file['status'] !== 'staged')) {
            throw new \DomainException(lang('App.transfers.batchIncomplete'));
        }
        $this->db->table('upload_batches')->where('batch_id', $batchId)->where('status', 'pending')
            ->update(['status' => 'assembling', 'updated_at' => Time::now()->toDateTimeString()]);
        if ($this->db->affectedRows() !== 1) throw new \DomainException('Upload session is not active.');

        $path = $this->drive->storageRoot() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'batch-' . $batchId . '.zip';
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException(lang('App.transfers.extractionFailed'));
            $opened = true;
            $names = [];
            foreach ($files as $file) {
                $source = $this->drive->storageRoot() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $file['upload_id'] . '_assembled';
                if (! is_file($source)) throw new \RuntimeException('Staged file is missing.');
                $name = $file['file_name'];
                $original = $name;
                for ($suffix = 2; isset($names[mb_strtolower($name)]); $suffix++) {
                    $extension = pathinfo($original, PATHINFO_EXTENSION);
                    $name = mb_substr(pathinfo($original, PATHINFO_FILENAME), 0, 165) . ' (' . $suffix . ').' . $extension;
                }
                $names[mb_strtolower($name)] = true;
                if (! $zip->addFile($source, $name) || ! $zip->setCompressionName($name, \ZipArchive::CM_STORE)) {
                    throw new \RuntimeException('Could not create ZIP archive.');
                }
            }
            $closed = $zip->close(); $opened = false;
            if (! $closed) throw new \RuntimeException('Could not create ZIP archive.');
            $parent = $batch['parent_id'] === null ? null : (int) $batch['parent_id'];
            $item = $this->drive->importLocalFile($userId, $parent, $path, $batch['archive_name'], null, true);
            $this->db->table('upload_batches')->where('batch_id', $batchId)->update([
                'status' => 'completed', 'item_id' => $item['id'], 'updated_at' => Time::now()->toDateTimeString(),
            ]);
            foreach ($files as $file) $this->chunks->cancelUpload($userId, $file['upload_id']);

            return $item;
        } catch (\Throwable $exception) {
            if ($opened) $zip->close();
            @unlink($path);
            $this->db->table('upload_batches')->where('batch_id', $batchId)->update(['status' => 'pending']);
            throw $exception;
        }
    }

    public function cancel(int $userId, string $batchId): void
    {
        $batch = $this->find($userId, $batchId);
        if ($batch['status'] === 'assembling') throw new \DomainException('Upload session is processing.');
        if ($batch['status'] === 'completed') return;
        foreach ($this->db->table('chunked_uploads')->where('batch_id', $batchId)->where('user_id', $userId)->get()->getResultArray() as $file) {
            $this->chunks->cancelUpload($userId, $file['upload_id']);
        }
        $this->db->table('upload_batches')->where('batch_id', $batchId)->delete();
    }

    public function cleanupExpired(): int
    {
        $cutoff = Time::now()->subSeconds($this->config->chunkMaxAgeSeconds)->toDateTimeString();
        $batches = $this->db->table('upload_batches')->where('updated_at <', $cutoff)->where('status !=', 'completed')->get()->getResultArray();
        foreach ($batches as $batch) {
            $this->db->table('upload_batches')->where('batch_id', $batch['batch_id'])->update(['status' => 'pending']);
            $this->cancel((int) $batch['user_id'], $batch['batch_id']);
        }

        return count($batches);
    }

    private function find(int $userId, string $batchId): array
    {
        $batch = $this->db->table('upload_batches')->where('batch_id', $batchId)->where('user_id', $userId)->get()->getRowArray();
        if ($batch === null) throw new \DomainException('Upload session was not found.');

        return $batch;
    }
}
