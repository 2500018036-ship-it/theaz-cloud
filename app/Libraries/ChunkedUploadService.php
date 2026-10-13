<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\CloudDrive;
use Config\Database;

class ChunkedUploadService
{
    private BaseConnection $db;

    private CloudDrive $config;

    private DriveService $drive;

    public function __construct(
        ?BaseConnection $db = null,
        ?DriveService $drive = null,
        ?CloudDrive $config = null,
    ) {
        $this->db     = $db ?? Database::connect();
        $this->drive  = $drive ?? new DriveService();
        $this->config = $config ?? config(CloudDrive::class);
    }

    /**
     * Start a chunked upload session.
     *
     * @return array{upload_id: string, chunk_size: int, total_chunks: int}
     */
    public function initUpload(int $userId, ?int $parentId, string $fileName, int $fileSize, ?string $mimeType = null): array
    {
        if ($fileSize <= 0) {
            throw new \DomainException('File size must be positive.');
        }

        if ($fileSize > $this->config->maxUploadSizeBytes) {
            throw new \DomainException('File exceeds the maximum upload size.');
        }

        // Check user quota before starting
        $storage = $this->drive->userStorageSummary($userId);
        if ($fileSize > $storage['available']) {
            throw new \DomainException('Not enough storage quota.');
        }

        $uploadId    = bin2hex(random_bytes(32));
        $chunkSize   = $this->config->chunkSizeBytes;
        $totalChunks = (int) ceil($fileSize / $chunkSize);
        $storagePath = 'tmp/chunks/' . $uploadId;

        $absDir = $this->absolutePath($storagePath);
        if (! is_dir($absDir) && ! mkdir($absDir, 0775, true) && ! is_dir($absDir)) {
            throw new \RuntimeException('Could not create chunk storage directory.');
        }

        $now = Time::now()->toDateTimeString();

        $this->db->table('chunked_uploads')->insert([
            'user_id'         => $userId,
            'parent_id'       => $parentId,
            'upload_id'       => $uploadId,
            'file_name'       => $fileName,
            'file_size'       => $fileSize,
            'mime_type'       => $mimeType ?: null,
            'chunk_size'      => $chunkSize,
            'total_chunks'    => $totalChunks,
            'received_chunks' => '[]',
            'status'          => 'pending',
            'storage_path'    => $storagePath,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return [
            'upload_id'    => $uploadId,
            'chunk_size'   => $chunkSize,
            'total_chunks' => $totalChunks,
        ];
    }

    /**
     * Receive and store a single chunk.
     *
     * @return array{chunk_index: int, received: int, total: int, complete: bool}
     */
    public function receiveChunk(int $userId, string $uploadId, int $chunkIndex, string $chunkTmpPath): array
    {
        $session = $this->findSession($userId, $uploadId);

        if ($session['status'] !== 'pending') {
            throw new \DomainException('Upload session is not active.');
        }

        $totalChunks = (int) $session['total_chunks'];

        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new \DomainException('Chunk index out of range.');
        }

        $received = json_decode($session['received_chunks'] ?: '[]', true);
        if (! is_array($received)) {
            $received = [];
        }

        // Write chunk to disk (idempotent — duplicate chunks overwrite safely)
        $chunkPath = $this->chunkFilePath($session, $chunkIndex);
        $chunkDir  = dirname($chunkPath);

        if (! is_dir($chunkDir) && ! mkdir($chunkDir, 0775, true) && ! is_dir($chunkDir)) {
            throw new \RuntimeException('Could not create chunk directory.');
        }

        if (! copy($chunkTmpPath, $chunkPath)) {
            throw new \RuntimeException('Could not write chunk to disk.');
        }

        if (! in_array($chunkIndex, $received, true)) {
            $received[] = $chunkIndex;
            sort($received);
        }

        $this->db->table('chunked_uploads')
            ->where('upload_id', $uploadId)
            ->where('user_id', $userId)
            ->update([
                'received_chunks' => json_encode($received),
                'updated_at'      => Time::now()->toDateTimeString(),
            ]);

        return [
            'chunk_index' => $chunkIndex,
            'received'    => count($received),
            'total'       => $totalChunks,
            'complete'    => count($received) === $totalChunks,
        ];
    }

    /**
     * Assemble all chunks and import the final file via DriveService.
     */
    public function completeUpload(int $userId, string $uploadId): array
    {
        $session = $this->findSession($userId, $uploadId);

        if ($session['status'] !== 'pending') {
            throw new \DomainException('Upload session is not active.');
        }

        $received    = json_decode($session['received_chunks'] ?: '[]', true);
        $totalChunks = (int) $session['total_chunks'];

        if (! is_array($received) || count($received) !== $totalChunks) {
            throw new \DomainException('Not all chunks received (' . (is_array($received) ? count($received) : 0) . '/' . $totalChunks . ').');
        }

        // Mark as assembling to prevent duplicate completions
        $this->db->table('chunked_uploads')
            ->where('upload_id', $uploadId)
            ->update(['status' => 'assembling', 'updated_at' => Time::now()->toDateTimeString()]);

        $tmpDir        = $this->drive->storageRoot() . DIRECTORY_SEPARATOR . 'tmp';
        $assembledPath = $tmpDir . DIRECTORY_SEPARATOR . $uploadId . '_assembled';

        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            throw new \RuntimeException('Temp directory could not be created.');
        }

        try {
            $out = fopen($assembledPath, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Could not create assembled file.');
            }

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $this->chunkFilePath($session, $i);
                if (! is_file($chunkPath)) {
                    fclose($out);

                    throw new \RuntimeException('Chunk ' . $i . ' is missing from disk.');
                }

                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    fclose($out);

                    throw new \RuntimeException('Could not read chunk ' . $i . '.');
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fclose($out);

            // Import via DriveService (deleteSource=true so assembled file is cleaned up)
            $parentId = $session['parent_id'] === null ? null : (int) $session['parent_id'];
            $item = $this->drive->importLocalFile(
                $userId,
                $parentId,
                $assembledPath,
                $session['file_name'],
                $session['mime_type'] ?: null,
                true,
            );

            // Cleanup chunk files
            $this->deleteChunkFiles($session);

            $this->db->table('chunked_uploads')
                ->where('upload_id', $uploadId)
                ->update(['status' => 'completed', 'updated_at' => Time::now()->toDateTimeString()]);

            return $item;
        } catch (\Throwable $exception) {
            @unlink($assembledPath);

            // Revert to pending so user can retry
            $this->db->table('chunked_uploads')
                ->where('upload_id', $uploadId)
                ->update(['status' => 'pending', 'updated_at' => Time::now()->toDateTimeString()]);

            throw $exception;
        }
    }

    /**
     * Cancel an upload and delete all chunks.
     */
    public function cancelUpload(int $userId, string $uploadId): void
    {
        $session = $this->findSession($userId, $uploadId);

        $this->deleteChunkFiles($session);

        $this->db->table('chunked_uploads')
            ->where('upload_id', $uploadId)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Delete expired chunk sessions and their files.
     */
    public function cleanupExpiredChunks(): int
    {
        $cutoff = Time::now()->subSeconds($this->config->chunkMaxAgeSeconds)->toDateTimeString();

        $expired = $this->db->table('chunked_uploads')
            ->where('status !=', 'completed')
            ->where('updated_at <', $cutoff)
            ->get()
            ->getResultArray();

        $deleted = 0;

        foreach ($expired as $session) {
            $this->deleteChunkFiles($session);

            $this->db->table('chunked_uploads')
                ->where('id', $session['id'])
                ->delete();

            $deleted++;
        }

        return $deleted;
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function findSession(int $userId, string $uploadId): array
    {
        $session = $this->db->table('chunked_uploads')
            ->where('upload_id', $uploadId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        if ($session === null) {
            throw new \DomainException('Upload session was not found.');
        }

        return $session;
    }

    private function chunkFilePath(array $session, int $index): string
    {
        return $this->absolutePath($session['storage_path'] . '/' . $index . '.part');
    }

    private function absolutePath(string $relative): string
    {
        return $this->drive->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function deleteChunkFiles(array $session): void
    {
        $dir = $this->absolutePath($session['storage_path']);
        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.part');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        // Also remove the assembled temp file if it exists
        $assembled = $this->drive->storageRoot() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $session['upload_id'] . '_assembled';
        if (is_file($assembled)) {
            @unlink($assembled);
        }

        @rmdir($dir);
    }
}
