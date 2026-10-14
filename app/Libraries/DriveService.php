<?php

namespace App\Libraries;

use App\Models\DriveFavoriteModel;
use App\Models\DriveItemModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use Config\Auth;
use Config\CloudDrive;
use Config\Database;

class DriveService
{
    private const PERMISSION_VIEWER = 'viewer';

    private const PERMISSION_EDITOR = 'editor';

    private const ROLE_OWNER = 'owner';

    private BaseConnection $db;

    private DriveItemModel $items;

    private DriveFavoriteModel $favorites;

    private ActivityLogger $activity;

    private CloudDrive $config;

    public function __construct(
        ?BaseConnection $db = null,
        ?DriveItemModel $items = null,
        ?DriveFavoriteModel $favorites = null,
        ?CloudDrive $config = null,
        ?ActivityLogger $activity = null,
    ) {
        $this->db        = $db ?? Database::connect();
        $this->items     = $items ?? new DriveItemModel();
        $this->favorites = $favorites ?? new DriveFavoriteModel();
        $this->config    = $config ?? config(CloudDrive::class);
        if ($config === null) {
            $this->applyDynamicStorageSettings();
        }
        $this->activity  = $activity ?? new ActivityLogger($this->db, $this->config);
    }

    private function applyDynamicStorageSettings(): void
    {
        try {
            $settings = new SystemSettingsService($this->db);
            $this->config->maxUploadSizeBytes = $settings->getInt('max_upload_size_bytes', $this->config->maxUploadSizeBytes);
            $this->config->versionRetentionDays = $settings->getInt('version_retention_days', $this->config->versionRetentionDays);
        } catch (\Throwable) {
            return;
        }
    }

    public function storageRoot(): string
    {
        $path = $this->config->storagePath;

        if (! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $path)) {
            $path = ROOTPATH . trim($path, '\\/');
        }

        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new \RuntimeException('Private storage directory could not be created.');
        }

        $real = realpath($path);

        return $real === false ? rtrim($path, '\\/') : $real;
    }

    public function userStorageSummary(int $userId): array
    {
        $user = $this->db->table('users')
            ->select('storage_used_bytes, storage_quota_bytes')
            ->where('id', $userId)
            ->get()
            ->getRowArray();

        if ($user === null) {
            throw new \DomainException('User account was not found.');
        }

        $used = (int) $user['storage_used_bytes'];
        $quota = (int) ($user['storage_quota_bytes'] ?: config(Auth::class)->defaultQuotaBytes);

        return [
            'used'       => $used,
            'quota'      => $quota,
            'available'  => max(0, $quota - $used),
            'percentage' => $quota > 0 ? min(100, round(($used / $quota) * 100, 2)) : 0,
        ];
    }

    public function dashboardOverview(int $userId): array
    {
        return [
            'storage' => $this->userStorageSummary($userId),
            'recent'  => $this->listRecent($userId, ['page' => 1, 'perPage' => 5])['items'],
            'starred' => (int) $this->db->table('drive_favorites fav')
                ->join('drive_items item', 'item.id = fav.item_id')
                ->where('fav.user_id', $userId)
                ->where('item.status', 'active')
                ->countAllResults(),
            'trash'   => (int) $this->db->table('drive_items')
                ->where('user_id', $userId)
                ->where('status', 'trashed')
                ->countAllResults(),
        ];
    }

    public function listFolder(int $userId, ?int $parentId, array $filters = []): array
    {
        if ($parentId !== null) {
            $folder = $this->accessibleItem($userId, $parentId, self::PERMISSION_VIEWER);
            if ($folder['type'] !== 'folder') {
                throw new \DomainException('Target folder was not found.');
            }

            return $this->paginatedItems($userId, 'folder', $filters, $parentId, (int) $folder['user_id']);
        }

        return $this->paginatedItems($userId, 'folder', $filters, $parentId);
    }

    public function listSharedWithMe(int $userId, array $filters = [], ?int $folderId = null): array
    {
        if ($folderId !== null) {
            $folder = $this->accessibleItem($userId, $folderId, self::PERMISSION_VIEWER);
            if ((int) $folder['user_id'] === $userId || $folder['type'] !== 'folder') {
                throw new \DomainException('Shared folder was not found.');
            }

            return $this->paginatedItems($userId, 'folder', $filters, $folderId, (int) $folder['user_id']);
        }

        return $this->paginatedSharedWithMe($userId, $filters);
    }

    public function listSharedByMe(int $userId, array $filters = []): array
    {
        return $this->paginatedSharedByMe($userId, $filters);
    }

    public function listRecent(int $userId, array $filters = []): array
    {
        return $this->paginatedItems($userId, 'recent', $filters);
    }

    public function listStarred(int $userId, array $filters = []): array
    {
        return $this->paginatedItems($userId, 'starred', $filters);
    }

    public function listTrash(int $userId, array $filters = []): array
    {
        return $this->paginatedItems($userId, 'trash', $filters);
    }

    public function breadcrumbs(int $userId, ?int $folderId): array
    {
        $crumbs = [
            ['id' => null, 'name' => 'My Drive', 'label' => 'My Drive', 'url' => site_url('drive')],
        ];

        if ($folderId === null) {
            return $crumbs;
        }

        $folders = [];
        $current = $this->assertFolder($userId, $folderId);

        while ($current !== null) {
            array_unshift($folders, $current);
            $parentId = $current['parent_id'] === null ? null : (int) $current['parent_id'];
            $current = $parentId === null ? null : $this->assertFolder($userId, $parentId);
        }

        foreach ($folders as $folder) {
            $crumbs[] = [
                'id'    => (int) $folder['id'],
                'name'  => $folder['name'],
                'label' => $folder['name'],
                'url'   => site_url('drive?folder=' . $folder['id']),
            ];
        }

        return $crumbs;
    }

    public function sharedBreadcrumbs(int $userId, ?int $folderId): array
    {
        $crumbs = [
            ['id' => null, 'name' => 'Shared with me', 'label' => 'Shared with me', 'url' => site_url('drive/shared-with-me')],
        ];

        if ($folderId === null) {
            return $crumbs;
        }

        $folder = $this->accessibleItem($userId, $folderId, self::PERMISSION_VIEWER);
        if ((int) $folder['user_id'] === $userId || $folder['type'] !== 'folder') {
            return $crumbs;
        }

        $path = $this->pathToRoot($folder);
        $sharedRootId = $this->sharedRootIdForPath($userId, $path);
        $include = $sharedRootId === null;

        foreach ($path as $node) {
            if ((int) $node['id'] === $sharedRootId) {
                $include = true;
            }

            if (! $include) {
                continue;
            }

            $crumbs[] = [
                'id'    => (int) $node['id'],
                'name'  => $node['name'],
                'label' => $node['name'],
                'url'   => site_url('drive/shared-with-me?folder=' . $node['id']),
            ];
        }

        return $crumbs;
    }

    public function canCreateInFolder(int $userId, ?int $folderId): bool
    {
        if ($folderId === null) {
            return true;
        }

        try {
            $folder = $this->accessibleItem($userId, $folderId, self::PERMISSION_EDITOR);

            return $folder['type'] === 'folder';
        } catch (\Throwable) {
            return false;
        }
    }

    public function folderOptions(int $userId): array
    {
        $folders = $this->db->table('drive_items')
            ->select('id, parent_id, name')
            ->where('user_id', $userId)
            ->where('type', 'folder')
            ->where('status', 'active')
            ->orderBy('normalized_name', 'ASC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $byId = [];
        foreach ($folders as $folder) {
            $byId[(int) $folder['id']] = $folder;
        }

        $labels = [];
        $labelFor = static function (int $id) use (&$labelFor, &$labels, $byId): string {
            if (isset($labels[$id])) {
                return $labels[$id];
            }

            $folder = $byId[$id] ?? null;
            if ($folder === null) {
                return '';
            }

            $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
            $prefix = $parentId === null ? '' : $labelFor($parentId) . ' / ';

            return $labels[$id] = $prefix . $folder['name'];
        };

        $options = [
            ['id' => null, 'label' => 'My Drive'],
        ];

        foreach (array_keys($byId) as $id) {
            $options[] = ['id' => $id, 'label' => $labelFor($id)];
        }

        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    public function createFolder(int $userId, ?int $parentId, string $name): array
    {
        try {
            $parent = $this->writableParent($userId, $parentId);
            $ownerId = $parent['owner_id'];

            $cleanName = $this->nextAvailableName($ownerId, $parentId, $this->sanitizeName($name));
            $id = $this->items->insert([
                'user_id'         => $ownerId,
                'parent_id'       => $parentId,
                'type'            => 'folder',
                'name'            => $cleanName,
                'normalized_name' => $this->normalizeName($cleanName),
                'size_bytes'      => 0,
                'status'          => 'active',
            ], true);

            if (! is_int($id)) {
                throw new \RuntimeException('Folder could not be created.');
            }

            $folder = $this->accessibleItem($userId, $id, self::PERMISSION_VIEWER);
            $this->logActivity($userId, 'drive.folder.create', 'success', $folder, [
                'parent_id' => $parentId,
            ]);

            return $folder;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.folder.create', null, $exception, [
                'parent_id' => $parentId,
                'name'      => $this->sanitizeName($name),
            ]);

            throw $exception;
        }
    }

    public function storeUploadedFile(int $userId, ?int $parentId, UploadedFile $file): array
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new \DomainException('No file was selected.');
        }

        if (! $file->isValid()) {
            throw new \DomainException($file->getErrorString());
        }

        return $this->storeIncomingFile(
            $userId,
            $parentId,
            $file->getTempName(),
            $file->getClientName(),
            $file->getMimeType(),
            (int) $file->getSize(),
            static fn (string $targetDir, string $physicalName): bool => $file->move($targetDir, $physicalName, true),
        );
    }

    public function importLocalFile(int $userId, ?int $parentId, string $sourcePath, string $clientName, ?string $mimeType = null, bool $deleteSource = false): array
    {
        if (! is_file($sourcePath)) {
            throw new \DomainException('Source file was not found.');
        }

        return $this->storeIncomingFile(
            $userId,
            $parentId,
            $sourcePath,
            $clientName,
            $mimeType ?? $this->detectMimeType($sourcePath),
            filesize($sourcePath) ?: 0,
            static function (string $targetDir, string $physicalName) use ($sourcePath, $deleteSource): bool {
                $target = $targetDir . DIRECTORY_SEPARATOR . $physicalName;

                return $deleteSource ? rename($sourcePath, $target) : copy($sourcePath, $target);
            },
        );
    }

    public function fileForVersions(int $userId, int $itemId): array
    {
        $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
        if ($item['type'] !== 'file') {
            throw new \DomainException('Version history is only available for files.');
        }

        $item['can_manage_versions'] = $this->permissionRank((string) $item['access_role']) >= $this->permissionRank(self::PERMISSION_EDITOR);

        return $item;
    }

    public function listFileVersions(int $userId, int $itemId, array $filters = []): array
    {
        $item = $this->fileForVersions($userId, $itemId);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 10)), $this->config->maxPageSize);

        $builderFactory = function () use ($itemId) {
            return $this->db->table('drive_file_versions version')
                ->select('version.*, actor.username AS created_by_username, actor.email AS created_by_email')
                ->join('users actor', 'actor.id = version.created_by', 'left')
                ->where('version.item_id', $itemId);
        };

        $total = (int) $builderFactory()->countAllResults();
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $builderFactory()
            ->orderBy('version.created_at', 'DESC')
            ->orderBy('version.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'item'     => $item,
            'versions' => $rows,
            'pager'    => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function storeUploadedVersionFile(int $userId, int $itemId, UploadedFile $file): array
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new \DomainException('No file was selected.');
        }

        if (! $file->isValid()) {
            throw new \DomainException($file->getErrorString());
        }

        return $this->replaceIncomingFile(
            $userId,
            $itemId,
            $file->getTempName(),
            $file->getClientName(),
            $file->getMimeType(),
            (int) $file->getSize(),
            static fn (string $targetDir, string $physicalName): bool => $file->move($targetDir, $physicalName, true),
        );
    }

    public function importLocalVersion(int $userId, int $itemId, string $sourcePath, string $clientName, ?string $mimeType = null, bool $deleteSource = false): array
    {
        if (! is_file($sourcePath)) {
            throw new \DomainException('Source file was not found.');
        }

        return $this->replaceIncomingFile(
            $userId,
            $itemId,
            $sourcePath,
            $clientName,
            $mimeType ?? $this->detectMimeType($sourcePath),
            filesize($sourcePath) ?: 0,
            static function (string $targetDir, string $physicalName) use ($sourcePath, $deleteSource): bool {
                $target = $targetDir . DIRECTORY_SEPARATOR . $physicalName;

                return $deleteSource ? rename($sourcePath, $target) : copy($sourcePath, $target);
            },
        );
    }

    public function restoreFileVersion(int $userId, int $itemId, int $versionId): array
    {
        try {
            $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_EDITOR);
            if ($item['type'] !== 'file' || $item['status'] !== 'active') {
                throw new \DomainException('Version history is only available for active files.');
            }

            $version = $this->db->table('drive_file_versions')
                ->where('id', $versionId)
                ->where('item_id', $itemId)
                ->where('user_id', (int) $item['user_id'])
                ->get()
                ->getRowArray();

            if ($version === null) {
                throw new \DomainException('File version was not found.');
            }

            $versionPath = $this->absolutePhysicalPath($version);
            if (! is_file($versionPath)) {
                throw new \RuntimeException('Stored version file is missing from disk.');
            }

            $this->db->transBegin();
            $prunedPaths = [];

            try {
                $this->archiveCurrentVersion($item, $userId, $versionId);

                $this->items->update($itemId, [
                    'extension'       => $version['extension'],
                    'mime_type'       => $version['mime_type'],
                    'size_bytes'      => (int) $version['size_bytes'],
                    'physical_path'   => $version['physical_path'],
                    'physical_name'   => $version['physical_name'],
                    'checksum_sha256' => $version['checksum_sha256'],
                    'last_opened_at'  => null,
                ]);

                $this->db->table('drive_file_versions')
                    ->where('id', $versionId)
                    ->delete();

                $prunedPaths = $this->pruneFileVersions((int) $item['user_id'], $itemId);
                $this->db->transCommit();
            } catch (\Throwable $exception) {
                $this->db->transRollback();

                throw $exception;
            }

            $this->deletePaths($prunedPaths);
            $restored = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
            $this->logActivity($userId, 'drive.version.restore', 'success', $restored, [
                'restored_version_id' => $versionId,
                'size_bytes'          => (int) $version['size_bytes'],
            ]);

            return $restored;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.version.restore', $itemId, $exception, [
                'version_id' => $versionId,
            ]);

            throw $exception;
        }
    }

    public function downloadableFile(int $userId, int $itemId): array
    {
        try {
            $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
            if ($item['type'] !== 'file' || $item['status'] !== 'active') {
                throw new \DomainException('File is not available for download.');
            }

            $path = $this->absolutePhysicalPath($item);
            if (! is_file($path)) {
                throw new \RuntimeException(lang('App.errors.storedFileMissing'));
            }

            $this->items->update($itemId, ['last_opened_at' => Time::now()->toDateTimeString()]);
            $this->logActivity($userId, 'drive.download', 'success', $item, [
                'size_bytes' => (int) $item['size_bytes'],
            ]);

            return ['item' => $item, 'path' => $path];
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.download', $itemId, $exception);

            throw $exception;
        }
    }

    public function readableFile(int $userId, int $itemId): array
    {
        $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
        if ($item['type'] !== 'file' || $item['status'] !== 'active') {
            throw new \DomainException('File is not available.');
        }

        $path = $this->absolutePhysicalPath($item);
        if (! is_file($path)) {
            throw new \RuntimeException(lang('App.errors.storedFileMissing'));
        }

        $this->items->update($itemId, ['last_opened_at' => Time::now()->toDateTimeString()]);

        return ['item' => $item, 'path' => $path];
    }

    public function renameItem(int $userId, int $itemId, string $name): array
    {
        try {
            $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_EDITOR);
            if ($item['status'] !== 'active') {
                throw new \DomainException('Trashed items cannot be renamed.');
            }

            $ownerId = (int) $item['user_id'];
            $cleanName = $this->nextAvailableName(
                $ownerId,
                $item['parent_id'] === null ? null : (int) $item['parent_id'],
                $this->sanitizeName($name),
                $itemId,
            );

            $this->items->update($itemId, [
                'name'            => $cleanName,
                'normalized_name' => $this->normalizeName($cleanName),
                'extension'       => $item['type'] === 'file' ? $this->extensionFromName($cleanName) : null,
            ]);

            $renamed = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
            $this->logActivity($userId, 'drive.rename', 'success', $renamed, [
                'old_name' => $item['name'],
                'new_name' => $cleanName,
            ]);

            return $renamed;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.rename', $itemId, $exception, [
                'name' => $this->sanitizeName($name),
            ]);

            throw $exception;
        }
    }

    public function moveItem(int $userId, int $itemId, ?int $targetParentId): array
    {
        try {
            $item = $this->findOwnedItem($userId, $itemId);
            if ($item['status'] !== 'active') {
                throw new \DomainException('Trashed items cannot be moved.');
            }

            if ($targetParentId !== null) {
                $this->assertFolder($userId, $targetParentId);
            }

            if ($item['type'] === 'folder') {
                $this->assertNotMovingFolderIntoItself($userId, $itemId, $targetParentId);
            }

            $name = $this->nextAvailableName($userId, $targetParentId, $item['name'], $itemId);
            $this->items->update($itemId, [
                'parent_id'       => $targetParentId,
                'name'            => $name,
                'normalized_name' => $this->normalizeName($name),
            ]);

            $moved = $this->findOwnedItem($userId, $itemId);
            $this->logActivity($userId, 'drive.move', 'success', $moved, [
                'from_parent_id' => $item['parent_id'] === null ? null : (int) $item['parent_id'],
                'to_parent_id'   => $targetParentId,
            ]);

            return $moved;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.move', $itemId, $exception, [
                'target_parent_id' => $targetParentId,
            ]);

            throw $exception;
        }
    }

    public function copyItem(int $userId, int $itemId, ?int $targetParentId): array
    {
        try {
            $item = $this->findOwnedItem($userId, $itemId);
            if ($item['status'] !== 'active') {
                throw new \DomainException('Trashed items cannot be copied.');
            }

            if ($targetParentId !== null) {
                $this->assertFolder($userId, $targetParentId);
            }

            $subtree = $this->activeSubtree($userId, $itemId);
            $bytesNeeded = array_sum(array_map(static fn (array $row): int => (int) $row['size_bytes'], $subtree));
            $idMap = [];
            $copiedPaths = [];
            $newRoot = null;

            $this->db->transBegin();

            try {
                $this->reserveQuota($userId, $bytesNeeded);

                foreach ($subtree as $row) {
                    $oldParentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
                    $newParentId = (int) $row['id'] === $itemId ? $targetParentId : ($idMap[$oldParentId] ?? null);
                    $newName = (int) $row['id'] === $itemId
                        ? $this->nextAvailableName($userId, $targetParentId, $this->copyName($row['name']))
                        : $row['name'];

                    $insert = [
                        'user_id'         => $userId,
                        'parent_id'       => $newParentId,
                        'type'            => $row['type'],
                        'name'            => $newName,
                        'normalized_name' => $this->normalizeName($newName),
                        'extension'       => $row['extension'],
                        'mime_type'       => $row['mime_type'],
                        'size_bytes'      => (int) $row['size_bytes'],
                        'status'          => 'active',
                    ];

                    if ($row['type'] === 'file') {
                        $physical = $this->newPhysicalPath($userId, (string) $row['extension']);
                        $source = $this->absolutePhysicalPath($row);
                        $target = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $physical['relative']);

                        $this->ensureDirectory(dirname($target));
                        if (! is_file($source) || ! copy($source, $target)) {
                            throw new \RuntimeException('File copy failed.');
                        }

                        $copiedPaths[] = $target;
                        $insert['physical_path'] = $physical['relative'];
                        $insert['physical_name'] = $physical['name'];
                        $insert['checksum_sha256'] = $row['checksum_sha256'];
                    }

                    $newId = $this->items->insert($insert, true);
                    if (! is_int($newId)) {
                        throw new \RuntimeException('Drive item copy metadata could not be created.');
                    }

                    $idMap[(int) $row['id']] = $newId;
                    $newRoot ??= $newId;
                }

                $this->db->transCommit();
            } catch (\Throwable $exception) {
                $this->db->transRollback();
                foreach ($copiedPaths as $path) {
                    @unlink($path);
                }

                throw $exception;
            }

            $copy = $this->findOwnedItem($userId, (int) $newRoot);
            $this->logActivity($userId, 'drive.copy', 'success', $copy, [
                'source_item_id'   => $itemId,
                'target_parent_id' => $targetParentId,
                'bytes_copied'     => $bytesNeeded,
            ]);

            return $copy;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.copy', $itemId, $exception, [
                'target_parent_id' => $targetParentId,
            ]);

            throw $exception;
        }
    }

    public function starItem(int $userId, int $itemId): void
    {
        $item = $this->findOwnedItem($userId, $itemId);
        if ($item['status'] !== 'active') {
            throw new \DomainException('Only active items can be starred.');
        }

        if ($this->favorites->where('user_id', $userId)->where('item_id', $itemId)->first() !== null) {
            return;
        }

        $this->favorites->insert([
            'user_id' => $userId,
            'item_id' => $itemId,
        ]);
    }

    public function unstarItem(int $userId, int $itemId): void
    {
        $this->findOwnedItem($userId, $itemId, true);

        $favorite = $this->favorites->where('user_id', $userId)->where('item_id', $itemId)->first();
        if ($favorite !== null) {
            $this->favorites->delete($favorite['id']);
        }
    }

    public function validateBulkSelection(int $userId, array $ids, string $action): void
    {
        if (! in_array($action, ['star', 'unstar', 'delete', 'restore', 'destroy', 'move', 'copy'], true)) throw new \DomainException('Bulk action is not available.');
        foreach ($ids as $id) {
            $item = in_array($action, ['star', 'unstar', 'restore', 'destroy'], true)
                ? $this->findOwnedItem($userId, $id, in_array($action, ['restore', 'destroy'], true))
                : $this->accessibleItem($userId, $id, $action === 'copy' ? self::PERMISSION_VIEWER : self::PERMISSION_EDITOR);
            if ($item['status'] !== (in_array($action, ['restore', 'destroy'], true) ? 'trashed' : 'active')) {
                throw new \DomainException('Some items are no longer available for this action.');
            }
        }
    }

    public function trashItems(int $userId, array $itemIds, ?int $trashedBy = null): int
    {
        try {
            $now = Time::now()->toDateTimeString();
            $affected = 0;
            $byOwner = [];

            foreach (array_values(array_unique(array_filter(array_map('intval', $itemIds)))) as $itemId) {
                $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_EDITOR);
                $byOwner[(int) $item['user_id']][] = $itemId;
            }

            foreach ($byOwner as $ownerId => $idsForOwner) {
                $ids = $this->expandSubtreeIds((int) $ownerId, $idsForOwner, 'active');
                if ($ids === []) {
                    continue;
                }

                $this->db->table('drive_items')
                    ->set('status', 'trashed')
                    ->set('original_parent_id', new RawSql('parent_id'))
                    ->set('trashed_at', $now)
                    ->set('trashed_by', $trashedBy)
                    ->set('updated_at', $now)
                    ->where('user_id', (int) $ownerId)
                    ->whereIn('id', $ids)
                    ->where('status', 'active')
                    ->update();

                $affected += $this->db->affectedRows();
            }

            $this->activity->record($userId, 'drive.trash', 'success', ['type' => 'drive_item'], [
                'item_ids' => array_values(array_unique(array_filter(array_map('intval', $itemIds)))),
                'affected' => $affected,
            ]);

            return $affected;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.trash', null, $exception, [
                'item_ids' => array_values(array_unique(array_filter(array_map('intval', $itemIds)))),
            ]);

            throw $exception;
        }
    }

    public function restoreItems(int $userId, array $itemIds): int
    {
        try {
            $rootIds = $this->ownedIds($userId, $itemIds, 'trashed');
            $ids = $this->expandSubtreeIds($userId, $rootIds, 'trashed');
            if ($ids === []) {
                return 0;
            }

            $rootsNeedingNewParent = [];
            foreach ($rootIds as $rootId) {
                $item = $this->findOwnedItem($userId, $rootId, true);
                $parentId = $item['parent_id'] === null ? null : (int) $item['parent_id'];
                if ($parentId !== null && ! $this->isActiveFolder($userId, $parentId)) {
                    $rootsNeedingNewParent[] = $rootId;
                }
            }

            $now = Time::now()->toDateTimeString();
            $this->db->table('drive_items')
                ->set('status', 'active')
                ->set('trashed_at', null)
                ->set('trashed_by', null)
                ->set('updated_at', $now)
                ->where('user_id', $userId)
                ->whereIn('id', $ids)
                ->where('status', 'trashed')
                ->update();

            $restored = $this->db->affectedRows();

            if ($rootsNeedingNewParent !== []) {
                $this->db->table('drive_items')
                    ->set('parent_id', null)
                    ->where('user_id', $userId)
                    ->whereIn('id', $rootsNeedingNewParent)
                    ->update();
            }

            $this->activity->record($userId, 'drive.restore', 'success', ['type' => 'drive_item'], [
                'item_ids' => $rootIds,
                'affected' => $restored,
            ]);

            return $restored;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.restore', null, $exception, [
                'item_ids' => array_values(array_unique(array_filter(array_map('intval', $itemIds)))),
            ]);

            throw $exception;
        }
    }

    public function destroyItems(int $userId, array $itemIds): int
    {
        try {
            $ids = $this->expandSubtreeIds($userId, $itemIds, 'trashed');
            if ($ids === []) {
                return 0;
            }

            $rows = $this->db->table('drive_items')
                ->where('user_id', $userId)
                ->whereIn('id', $ids)
                ->get()
                ->getResultArray();

            $fileRows = array_filter($rows, static fn (array $row): bool => $row['type'] === 'file');
            $fileIds = array_map(static fn (array $row): int => (int) $row['id'], $fileRows);
            $versionRows = $fileIds === []
                ? []
                : $this->db->table('drive_file_versions')
                    ->where('user_id', $userId)
                    ->whereIn('item_id', $fileIds)
                    ->get()
                    ->getResultArray();

            $bytes = array_sum(array_map(static fn (array $row): int => (int) $row['size_bytes'], $fileRows))
                + array_sum(array_map(static fn (array $row): int => (int) $row['size_bytes'], $versionRows));
            $paths = array_merge(
                array_map(fn (array $row): string => $this->absolutePhysicalPath($row), $fileRows),
                array_map(fn (array $row): string => $this->absolutePhysicalPath($row), $versionRows),
            );

            foreach ($paths as $path) {
                if (is_file($path) && ! unlink($path)) {
                    throw new \RuntimeException('Stored file could not be deleted from disk.');
                }
            }

            $this->db->transBegin();

            try {
                $this->db->table('drive_favorites')
                    ->where('user_id', $userId)
                    ->whereIn('item_id', $ids)
                    ->delete();

                $this->db->table('drive_items')
                    ->where('user_id', $userId)
                    ->whereIn('id', $ids)
                    ->delete();

                $this->releaseQuota($userId, $bytes);
                $this->db->transCommit();
            } catch (\Throwable $exception) {
                $this->db->transRollback();

                throw $exception;
            }

            $this->activity->record($userId, 'drive.destroy', 'success', ['type' => 'drive_item'], [
                'item_ids'        => $ids,
                'affected'        => count($ids),
                'released_bytes'  => $bytes,
                'version_records' => count($versionRows),
            ]);

            return count($ids);
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.destroy', null, $exception, [
                'item_ids' => array_values(array_unique(array_filter(array_map('intval', $itemIds)))),
            ]);

            throw $exception;
        }
    }

    public function cleanupExpiredTempFiles(?int $maxAgeSeconds = null): int
    {
        $tmpDir = $this->storageRoot() . DIRECTORY_SEPARATOR . 'tmp';
        if (! is_dir($tmpDir)) {
            return 0;
        }

        $maxAgeSeconds ??= $this->config->tempFileMaxAgeSeconds;
        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getMTime() < $cutoff && @unlink($fileInfo->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function pruneStoredVersions(): int
    {
        $pairs = $this->db->table('drive_file_versions')
            ->select('user_id, item_id')
            ->groupBy('user_id, item_id')
            ->get()
            ->getResultArray();

        $deleted = 0;
        foreach ($pairs as $pair) {
            $paths = $this->pruneFileVersions((int) $pair['user_id'], (int) $pair['item_id']);
            $this->deletePaths($paths);
            $deleted += count($paths);
        }

        $this->activity->record(null, 'drive.version.prune', 'success', [], [
            'deleted_versions' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Collect downloadable files for bulk ZIP export.
     *
     * @return array{items: list<array{item: array, path: string}>, total_bytes: int}
     */
    public function collectDownloadFiles(int $userId, array $itemIds): array
    {
        $byOwner = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $itemIds)))) as $itemId) {
            $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
            $byOwner[(int) $item['user_id']][] = $itemId;
        }

        if ($byOwner === []) {
            throw new \DomainException('No items found.');
        }

        $maxFiles = $this->config->maxBulkDownloadFiles;
        $maxBytes = $this->config->maxBulkDownloadBytes;

        $rows = [];
        foreach ($byOwner as $ownerId => $idsForOwner) {
            $ids = $this->expandSubtreeIds((int) $ownerId, $idsForOwner, 'active');
            if ($ids === []) {
                continue;
            }

            $rows = array_merge($rows, $this->db->table('drive_items')
                ->where('user_id', (int) $ownerId)
                ->where('type', 'file')
                ->where('status', 'active')
                ->whereIn('id', $ids)
                ->get()
                ->getResultArray());
        }

        if ($rows === []) {
            throw new \DomainException('No downloadable files found in selection.');
        }

        if (count($rows) > $maxFiles) {
            throw new \DomainException('Selection exceeds the limit of ' . $maxFiles . ' files.');
        }

        $totalBytes = 0;
        $files = [];

        foreach ($rows as $row) {
            $totalBytes += (int) $row['size_bytes'];
            if ($totalBytes > $maxBytes) {
                throw new \DomainException('Selection exceeds the download size limit.');
            }

            $path = $this->absolutePhysicalPath($row);
            if (! is_file($path)) {
                continue;
            }

            $files[] = ['item' => $row, 'path' => $path];
        }

        if ($files === []) {
            throw new \DomainException('No downloadable files found on disk.');
        }

        return ['items' => $files, 'total_bytes' => $totalBytes];
    }

    public function shareItemWithUser(int $ownerId, int $itemId, string $recipientIdentifier, string $permission, int $actorId): array
    {
        try {
            if ($ownerId !== $actorId) {
                throw new \DomainException('Only the owner can share this item.');
            }

            $item = $this->findOwnedItem($ownerId, $itemId);
            if ($item['status'] !== 'active') {
                throw new \DomainException('Trashed items cannot be shared.');
            }

            $permission = $this->normalizePermission($permission);
            $recipient = $this->findActiveUserByIdentifier($recipientIdentifier);
            $recipientId = (int) $recipient['id'];

            if ($recipientId === $ownerId) {
                throw new \DomainException('Owners already have full access.');
            }

            $existing = $this->db->table('drive_shares')
                ->where('access_type', 'user')
                ->where('item_id', $itemId)
                ->where('shared_with_user_id', $recipientId)
                ->get()
                ->getRowArray();

            $now = Time::now()->toDateTimeString();

            if ($existing !== null && $existing['revoked_at'] === null) {
                throw new \DomainException('Access already exists for this user.');
            }

            if ($existing !== null) {
                $this->db->table('drive_shares')
                    ->where('id', (int) $existing['id'])
                    ->update([
                        'user_id'    => $ownerId,
                        'permission' => $permission,
                        'revoked_at' => null,
                        'created_by' => $actorId,
                        'updated_at' => $now,
                    ]);

                $share = $this->shareById($ownerId, (int) $existing['id']);
                $this->logActivity($actorId, 'drive.share.grant', 'success', $item, [
                    'permission' => $permission,
                    'share_id'   => (int) $share['id'],
                ], $recipientId);
                $this->notifyShareGranted($recipientId, $actorId, $item, $permission);

                return $share;
            }

            $this->db->table('drive_shares')->insert([
                'user_id'             => $ownerId,
                'item_id'             => $itemId,
                'shared_with_user_id' => $recipientId,
                'access_type'         => 'user',
                'permission'          => $permission,
                'created_by'          => $actorId,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $share = $this->shareById($ownerId, (int) $this->db->insertID());
            $this->logActivity($actorId, 'drive.share.grant', 'success', $item, [
                'permission' => $permission,
                'share_id'   => (int) $share['id'],
            ], $recipientId);
            $this->notifyShareGranted($recipientId, $actorId, $item, $permission);

            return $share;
        } catch (\Throwable $exception) {
            $this->logFailure($actorId, 'drive.share.grant', $itemId, $exception, [
                'recipient'  => $recipientIdentifier === '' ? null : hash('sha256', mb_strtolower(trim($recipientIdentifier))),
                'permission' => $permission,
            ]);

            throw $exception;
        }
    }

    public function updateSharePermission(int $ownerId, int $shareId, string $permission): array
    {
        try {
            $permission = $this->normalizePermission($permission);
            $share = $this->shareById($ownerId, $shareId);

            $this->db->table('drive_shares')
                ->where('id', $shareId)
                ->where('user_id', $ownerId)
                ->where('access_type', 'user')
                ->update([
                    'permission' => $permission,
                    'updated_at' => Time::now()->toDateTimeString(),
                ]);

            $updated = $this->shareById($ownerId, (int) $share['id']);
            $this->activity->record($ownerId, 'drive.share.permission', 'success', [
                'type'     => 'drive_item',
                'id'       => (int) $share['item_id'],
                'owner_id' => $ownerId,
                'name'     => (string) $share['item_name'],
            ], [
                'old_permission' => $share['permission'],
                'new_permission' => $permission,
                'share_id'       => $shareId,
            ], (int) $share['shared_with_user_id']);
            $this->notifySharePermissionChanged((int) $share['shared_with_user_id'], $ownerId, (int) $share['item_id'], (string) $share['item_name'], $permission);

            return $updated;
        } catch (\Throwable $exception) {
            $this->activity->record($ownerId, 'drive.share.permission', 'failure', [], [
                'share_id' => $shareId,
                'error'    => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function revokeShare(int $ownerId, int $shareId): void
    {
        try {
            $share = $this->shareById($ownerId, $shareId);

            $this->db->table('drive_shares')
                ->where('id', $shareId)
                ->where('user_id', $ownerId)
                ->where('access_type', 'user')
                ->update([
                    'revoked_at' => Time::now()->toDateTimeString(),
                    'updated_at' => Time::now()->toDateTimeString(),
                ]);

            $this->activity->record($ownerId, 'drive.share.revoke', 'success', [
                'type'     => 'drive_item',
                'id'       => (int) $share['item_id'],
                'owner_id' => $ownerId,
                'name'     => (string) $share['item_name'],
            ], [
                'share_id'   => $shareId,
                'permission' => $share['permission'],
            ], (int) $share['shared_with_user_id']);
            $this->notifyShareRevoked((int) $share['shared_with_user_id'], $ownerId, (int) $share['item_id'], (string) $share['item_name']);
        } catch (\Throwable $exception) {
            $this->activity->record($ownerId, 'drive.share.revoke', 'failure', [], [
                'share_id' => $shareId,
                'error'    => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function shareRecipients(int $ownerId, int $itemId): array
    {
        $this->findOwnedItem($ownerId, $itemId);

        return $this->db->table('drive_shares share')
            ->select('share.id, share.permission, share.created_at, share.updated_at, user.username, user.email')
            ->join('users user', 'user.id = share.shared_with_user_id')
            ->where('share.user_id', $ownerId)
            ->where('share.item_id', $itemId)
            ->where('share.access_type', 'user')
            ->where('share.revoked_at', null)
            ->orderBy('user.username', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function createShareLink(int $userId, int $itemId, ?int $expiresDays = null): array
    {
        try {
            $item = $this->findOwnedItem($userId, $itemId);

            if ($item['type'] !== 'file') {
                throw new \DomainException('Only files can be shared.');
            }

            if ($item['status'] !== 'active') {
                throw new \DomainException('Trashed items cannot be shared.');
            }

            $existing = $this->db->table('drive_shares')
                ->where('user_id', $userId)
                ->where('item_id', $itemId)
                ->where('access_type', 'public')
                ->where('revoked_at', null)
                ->get()
                ->getRowArray();

            if ($existing !== null) {
                return ['token' => $existing['token'], 'name' => $item['name']];
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = $expiresDays === null || $expiresDays <= 0
                ? null
                : Time::now()->addDays(min($expiresDays, 365))->toDateTimeString();

            $this->db->table('drive_shares')->insert([
                'user_id'     => $userId,
                'item_id'     => $itemId,
                'access_type' => 'public',
                'permission'  => self::PERMISSION_VIEWER,
                'token'       => $token,
                'expires_at'  => $expiresAt,
                'created_by'  => $userId,
                'created_at'  => Time::now()->toDateTimeString(),
                'updated_at'  => Time::now()->toDateTimeString(),
            ]);

            $this->logActivity($userId, 'drive.share.public_create', 'success', $item, [
                'expires_at' => $expiresAt,
            ]);

            return ['token' => $token, 'name' => $item['name']];
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.share.public_create', $itemId, $exception, [
                'expires_days' => $expiresDays,
            ]);

            throw $exception;
        }
    }

    public function revokePublicShareLink(int $userId, int $itemId): void
    {
        try {
            $item = $this->findOwnedItem($userId, $itemId);

            $this->db->table('drive_shares')
                ->where('user_id', $userId)
                ->where('item_id', $itemId)
                ->where('access_type', 'public')
                ->where('revoked_at', null)
                ->update([
                    'revoked_at' => Time::now()->toDateTimeString(),
                    'updated_at' => Time::now()->toDateTimeString(),
                ]);

            $this->logActivity($userId, 'drive.share.public_revoke', 'success', $item);
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.share.public_revoke', $itemId, $exception);

            throw $exception;
        }
    }

    public function downloadSharedFile(string $token): array
    {
        try {
            $share = $this->db->table('drive_shares')
                ->where('token', $token)
                ->where('access_type', 'public')
                ->where('revoked_at', null)
                ->get()
                ->getRowArray();

            if ($share === null) {
                throw new \DomainException('Share link is invalid or has expired.');
            }

            if ($share['expires_at'] !== null && strtotime((string) $share['expires_at']) < time()) {
                throw new \DomainException('Share link has expired.');
            }

            $item = $this->db->table('drive_items')
                ->where('id', (int) $share['item_id'])
                ->where('status', 'active')
                ->where('type', 'file')
                ->get()
                ->getRowArray();

            if ($item === null) {
                throw new \DomainException('Shared file is no longer available.');
            }

            $path = $this->absolutePhysicalPath($item);

            if (! is_file($path)) {
                throw new \RuntimeException(lang('App.errors.storedFileMissing'));
            }

            $this->logActivity(null, 'drive.share.public_download', 'success', $item, [
                'size_bytes' => (int) $item['size_bytes'],
            ]);

            return ['item' => $item, 'path' => $path];
        } catch (\Throwable $exception) {
            $this->activity->record(null, 'drive.share.public_download', 'failure', [], [
                'error'      => $exception->getMessage(),
                'token_hash' => hash('sha256', $token),
            ]);

            throw $exception;
        }
    }

    private function paginatedSharedWithMe(int $userId, array $filters): array
    {
        return $this->paginatedSharedItems($userId, $filters, 'with-me');
    }

    private function paginatedSharedByMe(int $userId, array $filters): array
    {
        return $this->paginatedSharedItems($userId, $filters, 'by-me');
    }

    private function paginatedSharedItems(int $userId, array $filters, string $mode): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? $this->config->pageSize)), $this->config->maxPageSize);
        $search = trim((string) ($filters['q'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'name');
        $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $builderFactory = function () use ($userId, $mode, $search) {
            $builder = $this->db->table('drive_items item')
                ->select('item.*, fav.id AS favorite_id, owner.username AS owner_username, owner.email AS owner_email')
                ->join('drive_favorites fav', 'fav.item_id = item.id AND fav.user_id = ' . $this->db->escape($userId), 'left')
                ->join('users owner', 'owner.id = item.user_id')
                ->where('item.status', 'active');

            if ($mode === 'with-me') {
                $builder->select('share.permission AS direct_permission, 1 AS share_count', false)
                    ->join('drive_shares share', 'share.item_id = item.id AND share.access_type = "user" AND share.revoked_at IS NULL')
                    ->where('share.shared_with_user_id', $userId)
                    ->where('item.user_id !=', $userId);
            } else {
                $builder->select('COUNT(share.id) AS share_count', false)
                    ->join('drive_shares share', 'share.item_id = item.id AND share.access_type = "user" AND share.revoked_at IS NULL')
                    ->where('item.user_id', $userId)
                    ->where('share.user_id', $userId)
                    ->groupBy('item.id');
            }

            if ($search !== '') {
                $builder->like('item.normalized_name', $this->normalizeName($search));
            }

            return $builder;
        };

        $total = count($builderFactory()->get()->getResultArray());
        $builder = $builderFactory();

        $builder->orderBy("CASE WHEN item.type = 'folder' THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy($this->sortColumn($sort), $dir)
            ->limit($perPage, ($page - 1) * $perPage);

        $rows = $builder->get()->getResultArray();

        return [
            'items' => $this->hydrateItems($userId, $rows),
            'pager' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    private function paginatedItems(int $userId, string $section, array $filters, ?int $parentId = null, ?int $ownerId = null): array
    {
        $ownerId ??= $userId;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? $this->config->pageSize)), $this->config->maxPageSize);
        $search = trim((string) ($filters['q'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'name');
        $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // Advanced filter values
        $filterExt      = trim((string) ($filters['ext'] ?? ''));
        $filterType     = trim((string) ($filters['type'] ?? ''));
        $filterSizeMin  = $filters['size_min'] ?? null;
        $filterSizeMax  = $filters['size_max'] ?? null;
        $filterDateFrom = trim((string) ($filters['date_from'] ?? ''));
        $filterDateTo   = trim((string) ($filters['date_to'] ?? ''));

        $builderFactory = function () use ($userId, $ownerId, $section, $parentId, $search, $filterExt, $filterType, $filterSizeMin, $filterSizeMax, $filterDateFrom, $filterDateTo) {
            $builder = $this->db->table('drive_items item')
                ->select('item.*, fav.id AS favorite_id, owner.username AS owner_username, owner.email AS owner_email')
                ->join('drive_favorites fav', 'fav.item_id = item.id AND fav.user_id = ' . $this->db->escape($userId), 'left')
                ->join('users owner', 'owner.id = item.user_id')
                ->where('item.user_id', $ownerId);

            if ($section === 'folder') {
                $builder->where('item.status', 'active');
                $parentId === null ? $builder->where('item.parent_id', null) : $builder->where('item.parent_id', $parentId);
            } elseif ($section === 'recent') {
                $builder->where('item.status', 'active')->where('item.type', 'file');
            } elseif ($section === 'starred') {
                $builder->where('item.status', 'active')->where('fav.id IS NOT NULL', null, false);
            } elseif ($section === 'trash') {
                $builder->where('item.status', 'trashed');
            }

            if ($search !== '') {
                $builder->like('item.normalized_name', $this->normalizeName($search));
            }

            // Extension filter (comma-separated list)
            if ($filterExt !== '') {
                $exts = array_filter(array_map('trim', explode(',', strtolower($filterExt))));
                if ($exts !== []) {
                    $builder->whereIn('item.extension', $exts);
                }
            }

            // Type filter
            if ($filterType === 'file' || $filterType === 'folder') {
                $builder->where('item.type', $filterType);
            }

            // Size range
            if ($filterSizeMin !== null && is_numeric($filterSizeMin) && (int) $filterSizeMin > 0) {
                $builder->where('item.size_bytes >=', (int) $filterSizeMin);
            }
            if ($filterSizeMax !== null && is_numeric($filterSizeMax) && (int) $filterSizeMax > 0) {
                $builder->where('item.size_bytes <=', (int) $filterSizeMax);
            }

            // Date range on updated_at
            if ($filterDateFrom !== '' && strtotime($filterDateFrom) !== false) {
                $builder->where('item.updated_at >=', $filterDateFrom . ' 00:00:00');
            }
            if ($filterDateTo !== '' && strtotime($filterDateTo) !== false) {
                $builder->where('item.updated_at <=', $filterDateTo . ' 23:59:59');
            }

            return $builder;
        };

        $total = (int) $builderFactory()->countAllResults();
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $builder = $builderFactory();

        if ($section === 'recent') {
            $builder->orderBy('COALESCE(item.last_opened_at, item.updated_at, item.created_at)', 'DESC', false);
        } elseif ($section === 'trash') {
            $builder->orderBy('item.trashed_at', 'DESC');
        } else {
            $builder->orderBy("CASE WHEN item.type = 'folder' THEN 0 ELSE 1 END", 'ASC', false);
            $builder->orderBy($this->sortColumn($sort), $dir);
        }

        $rows = $builder
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items' => $this->hydrateItems($userId, $rows),
            'pager' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    private function sortColumn(string $sort): string
    {
        return match ($sort) {
            'size' => 'item.size_bytes',
            'type' => 'item.type',
            'updated' => 'item.updated_at',
            default => 'item.normalized_name',
        };
    }

    private function hydrateItems(int $actorId, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);
        $shares = $this->shareRecipientsForItems($ids);

        return array_map(function (array $item) use ($actorId, $shares): array {
            $item['is_starred'] = ($item['favorite_id'] ?? null) !== null;
            unset($item['favorite_id']);

            $ownerId = (int) $item['user_id'];
            $item['access_role'] = $ownerId === $actorId
                ? self::ROLE_OWNER
                : ($this->inheritedPermission($actorId, $item) ?? self::PERMISSION_VIEWER);
            $item['owner_label'] = $ownerId === $actorId
                ? 'You'
                : (string) ($item['owner_username'] ?? $item['owner_email'] ?? 'Owner');
            $item['shares'] = $ownerId === $actorId ? ($shares[(int) $item['id']] ?? []) : [];
            $item['share_count'] = count($item['shares']);

            return $item;
        }, $items);
    }

    private function storeIncomingFile(int $userId, ?int $parentId, string $sourcePath, string $clientName, string $mimeType, int $size, callable $moveFile): array
    {
        try {
            $parent = $this->writableParent($userId, $parentId);
            $ownerId = $parent['owner_id'];

            $cleanName = $this->nextAvailableName($ownerId, $parentId, $this->sanitizeName($clientName));
            $extension = $this->extensionFromName($cleanName);
            $detectedMime = $this->detectMimeType($sourcePath) ?: $mimeType;
            $this->validateIncomingFile($cleanName, $extension, $detectedMime, $size);

            $physical = $this->newPhysicalPath($ownerId, $extension);
            $targetDir = $this->storageRoot() . DIRECTORY_SEPARATOR . dirname(str_replace('/', DIRECTORY_SEPARATOR, $physical['relative']));
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $physical['name'];
            $checksum = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null;

            $this->db->transBegin();

            try {
                $this->reserveQuota($ownerId, $size);
                $this->ensureDirectory($targetDir);

                $id = $this->items->insert([
                    'user_id'         => $ownerId,
                    'parent_id'       => $parentId,
                    'type'            => 'file',
                    'name'            => $cleanName,
                    'normalized_name' => $this->normalizeName($cleanName),
                    'extension'       => $extension,
                    'mime_type'       => $detectedMime,
                    'size_bytes'      => $size,
                    'physical_path'   => $physical['relative'],
                    'physical_name'   => $physical['name'],
                    'checksum_sha256' => $checksum,
                    'status'          => 'active',
                ], true);

                if (! is_int($id)) {
                    throw new \RuntimeException('File metadata could not be created.');
                }

                if (! $moveFile($targetDir, $physical['name']) || ! is_file($targetPath)) {
                    throw new \RuntimeException('Uploaded file could not be moved to private storage.');
                }

                $this->db->transCommit();
            } catch (\Throwable $exception) {
                $this->db->transRollback();
                @unlink($targetPath);

                throw $exception;
            }

            $item = $this->accessibleItem($userId, (int) $id, self::PERMISSION_VIEWER);
            $this->logActivity($userId, 'drive.upload', 'success', $item, [
                'parent_id'  => $parentId,
                'size_bytes' => $size,
                'mime_type'  => $detectedMime,
            ]);

            return $item;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.upload', null, $exception, [
                'parent_id'  => $parentId,
                'file_name'  => $this->sanitizeName($clientName),
                'size_bytes' => $size,
            ]);

            throw $exception;
        }
    }

    private function replaceIncomingFile(int $userId, int $itemId, string $sourcePath, string $clientName, string $mimeType, int $size, callable $moveFile): array
    {
        try {
            $item = $this->accessibleItem($userId, $itemId, self::PERMISSION_EDITOR);
            if ($item['type'] !== 'file' || $item['status'] !== 'active') {
                throw new \DomainException('Only active files can receive a new version.');
            }

            $clientName = $this->sanitizeName($clientName);
            $extension = $this->extensionFromName($clientName);
            $currentExtension = (string) ($item['extension'] ?? '');
            if ($extension !== $currentExtension) {
                throw new \DomainException('New versions must use the same file extension.');
            }

            $detectedMime = $this->detectMimeType($sourcePath) ?: $mimeType;
            $this->validateIncomingFile($clientName, $extension, $detectedMime, $size);

            $ownerId = (int) $item['user_id'];
            $physical = $this->newPhysicalPath($ownerId, $extension);
            $targetDir = $this->storageRoot() . DIRECTORY_SEPARATOR . dirname(str_replace('/', DIRECTORY_SEPARATOR, $physical['relative']));
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $physical['name'];
            $checksum = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null;
            $prunedPaths = [];

            $this->db->transBegin();

            try {
                $this->reserveQuota($ownerId, $size);
                $this->ensureDirectory($targetDir);

                if (! $moveFile($targetDir, $physical['name']) || ! is_file($targetPath)) {
                    throw new \RuntimeException('Uploaded file could not be moved to private storage.');
                }

                $this->archiveCurrentVersion($item, $userId);

                $this->items->update($itemId, [
                    'extension'       => $extension,
                    'mime_type'       => $detectedMime,
                    'size_bytes'      => $size,
                    'physical_path'   => $physical['relative'],
                    'physical_name'   => $physical['name'],
                    'checksum_sha256' => $checksum,
                    'last_opened_at'  => null,
                ]);

                $prunedPaths = $this->pruneFileVersions($ownerId, $itemId);
                $this->db->transCommit();
            } catch (\Throwable $exception) {
                $this->db->transRollback();
                @unlink($targetPath);

                throw $exception;
            }

            $this->deletePaths($prunedPaths);
            $updated = $this->accessibleItem($userId, $itemId, self::PERMISSION_VIEWER);
            $this->logActivity($userId, 'drive.version.upload', 'success', $updated, [
                'size_bytes' => $size,
                'mime_type'  => $detectedMime,
            ]);

            return $updated;
        } catch (\Throwable $exception) {
            $this->logFailure($userId, 'drive.version.upload', $itemId, $exception, [
                'file_name'  => $this->sanitizeName($clientName),
                'size_bytes' => $size,
            ]);

            throw $exception;
        }
    }

    private function archiveCurrentVersion(array $item, int $actorId, ?int $sourceVersionId = null): void
    {
        $path = $this->absolutePhysicalPath($item);
        if (! is_file($path)) {
            throw new \RuntimeException('Current file is missing from disk.');
        }

        $this->db->table('drive_file_versions')->insert([
            'item_id'           => (int) $item['id'],
            'user_id'           => (int) $item['user_id'],
            'version_number'    => $this->nextVersionNumber((int) $item['id']),
            'name'              => $item['name'],
            'extension'         => $item['extension'],
            'mime_type'         => $item['mime_type'],
            'size_bytes'        => (int) $item['size_bytes'],
            'physical_path'     => $item['physical_path'],
            'physical_name'     => $item['physical_name'],
            'checksum_sha256'   => $item['checksum_sha256'],
            'source_version_id' => $sourceVersionId,
            'created_by'        => $actorId,
            'created_at'        => Time::now()->toDateTimeString(),
        ]);
    }

    private function nextVersionNumber(int $itemId): int
    {
        $row = $this->db->table('drive_file_versions')
            ->selectMax('version_number', 'latest')
            ->where('item_id', $itemId)
            ->get()
            ->getRowArray();

        return ((int) ($row['latest'] ?? 0)) + 1;
    }

    /**
     * @return list<string>
     */
    private function pruneFileVersions(int $ownerId, int $itemId): array
    {
        $versions = $this->db->table('drive_file_versions')
            ->where('user_id', $ownerId)
            ->where('item_id', $itemId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $deleteById = [];
        $limit = max(0, $this->config->maxVersionsPerFile);
        foreach ($versions as $index => $version) {
            if ($index >= $limit) {
                $deleteById[(int) $version['id']] = $version;
            }
        }

        if ($this->config->versionRetentionDays > 0) {
            $cutoff = Time::now()->subDays($this->config->versionRetentionDays)->getTimestamp();
            foreach ($versions as $version) {
                $created = strtotime((string) ($version['created_at'] ?? ''));
                if ($created !== false && $created < $cutoff) {
                    $deleteById[(int) $version['id']] = $version;
                }
            }
        }

        if ($deleteById === []) {
            return [];
        }

        $paths = array_map(fn (array $version): string => $this->absolutePhysicalPath($version), array_values($deleteById));
        $bytes = array_sum(array_map(static fn (array $version): int => (int) $version['size_bytes'], array_values($deleteById)));

        $this->db->table('drive_file_versions')
            ->whereIn('id', array_keys($deleteById))
            ->delete();

        $this->releaseQuota($ownerId, $bytes);

        return $paths;
    }

    /**
     * @param list<string> $paths
     */
    private function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function logActivity(?int $actorId, string $action, string $status, ?array $item = null, array $metadata = [], ?int $subjectUserId = null): void
    {
        $this->activity->record($actorId, $action, $status, $item === null ? [] : $this->itemActivityTarget($item), $metadata, $subjectUserId);
    }

    private function logFailure(?int $actorId, string $action, ?int $itemId, \Throwable $exception, array $metadata = [], ?int $subjectUserId = null): void
    {
        $target = $itemId === null ? [] : [
            'type' => 'drive_item',
            'id'   => $itemId,
        ];

        if ($itemId !== null) {
            $item = $this->db->table('drive_items')
                ->select('user_id, name')
                ->where('id', $itemId)
                ->get()
                ->getRowArray();

            if ($item !== null) {
                $target['owner_id'] = (int) $item['user_id'];
                if ($actorId !== null && (int) $item['user_id'] === $actorId) {
                    $target['name'] = $item['name'];
                }
            }
        }

        $this->activity->record($actorId, $action, 'failure', $target, [
            'error' => $exception->getMessage(),
        ] + $metadata, $subjectUserId);
    }

    private function notifyShareGranted(int $recipientId, int $actorId, array $item, string $permission): void
    {
        try {
            (new NotificationService($this->db))->notifyShareGranted($recipientId, $actorId, $item, $permission);
        } catch (\Throwable $exception) {
            log_message('error', 'Share notification failed: {message}', ['message' => $exception->getMessage()]);
        }
    }

    private function notifySharePermissionChanged(int $recipientId, int $actorId, int $itemId, string $itemName, string $permission): void
    {
        try {
            (new NotificationService($this->db))->notifySharePermissionChanged($recipientId, $actorId, $itemId, $itemName, $permission);
        } catch (\Throwable $exception) {
            log_message('error', 'Share permission notification failed: {message}', ['message' => $exception->getMessage()]);
        }
    }

    private function notifyShareRevoked(int $recipientId, int $actorId, int $itemId, string $itemName): void
    {
        try {
            (new NotificationService($this->db))->notifyShareRevoked($recipientId, $actorId, $itemId, $itemName);
        } catch (\Throwable $exception) {
            log_message('error', 'Share revoke notification failed: {message}', ['message' => $exception->getMessage()]);
        }
    }

    private function itemActivityTarget(array $item): array
    {
        return [
            'type'     => 'drive_item',
            'id'       => (int) $item['id'],
            'owner_id' => (int) $item['user_id'],
            'name'     => (string) $item['name'],
        ];
    }

    private function validateIncomingFile(string $name, string $extension, string $mimeType, int $size): void
    {
        if ($size < 0) {
            throw new \DomainException('File size is invalid.');
        }

        if ($size > $this->config->maxUploadSizeBytes) {
            throw new \DomainException('File exceeds the maximum upload size.');
        }

        if ($extension === '' || ! in_array($extension, $this->config->allowedExtensions, true)) {
            throw new \DomainException('File extension is not allowed.');
        }

        if (! in_array($mimeType, $this->config->allowedMimeTypes, true)) {
            throw new \DomainException('File type is not allowed.');
        }

        if ($this->sanitizeName($name) !== $name) {
            throw new \DomainException('File name is invalid.');
        }
    }

    private function reserveQuota(int $userId, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->db->table('users')
            ->set('storage_used_bytes', new RawSql('storage_used_bytes + ' . $bytes))
            ->where('id', $userId)
            ->where('storage_used_bytes + ' . $bytes . ' <= storage_quota_bytes', null, false)
            ->update();

        if ($this->db->affectedRows() !== 1) {
            throw new \DomainException('Storage quota is full.');
        }
    }

    private function releaseQuota(int $userId, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->db->table('users')
            ->set('storage_used_bytes', new RawSql('CASE WHEN storage_used_bytes >= ' . $bytes . ' THEN storage_used_bytes - ' . $bytes . ' ELSE 0 END'))
            ->where('id', $userId)
            ->update();
    }

    private function writableParent(int $actorId, ?int $parentId): array
    {
        if ($parentId === null) {
            return ['owner_id' => $actorId, 'parent_id' => null];
        }

        $folder = $this->accessibleItem($actorId, $parentId, self::PERMISSION_EDITOR);
        if ($folder['type'] !== 'folder') {
            throw new \DomainException('Target folder was not found.');
        }

        return ['owner_id' => (int) $folder['user_id'], 'parent_id' => $parentId];
    }

    private function accessibleItem(int $actorId, int $itemId, string $minimumPermission = self::PERMISSION_VIEWER, bool $includeTrashed = false): array
    {
        $builder = $this->db->table('drive_items item')
            ->select('item.*, owner.username AS owner_username, owner.email AS owner_email')
            ->join('users owner', 'owner.id = item.user_id')
            ->where('item.id', $itemId);

        if (! $includeTrashed) {
            $builder->where('item.status', 'active');
        }

        $item = $builder->get()->getRowArray();
        if ($item === null) {
            throw new \DomainException('Drive item was not found.');
        }

        if ((int) $item['user_id'] === $actorId) {
            $item['access_role'] = self::ROLE_OWNER;
            $item['owner_label'] = 'You';

            return $item;
        }

        if ($item['status'] !== 'active') {
            throw new \DomainException('Drive item was not found.');
        }

        $permission = $this->inheritedPermission($actorId, $item);
        if ($permission === null || $this->permissionRank($permission) < $this->permissionRank($minimumPermission)) {
            throw new \DomainException('You do not have permission to access this item.');
        }

        $item['access_role'] = $permission;
        $item['owner_label'] = (string) ($item['owner_username'] ?? $item['owner_email'] ?? 'Owner');

        return $item;
    }

    private function inheritedPermission(int $actorId, array $item): ?string
    {
        if ((int) $item['user_id'] === $actorId) {
            return self::ROLE_OWNER;
        }

        if (($item['status'] ?? 'active') !== 'active') {
            return null;
        }

        $pathIds = array_map(static fn (array $node): int => (int) $node['id'], $this->pathToRoot($item));
        if ($pathIds === []) {
            return null;
        }

        $shares = $this->db->table('drive_shares')
            ->select('permission')
            ->where('access_type', 'user')
            ->where('shared_with_user_id', $actorId)
            ->where('revoked_at', null)
            ->whereIn('item_id', $pathIds)
            ->get()
            ->getResultArray();

        $best = null;
        foreach ($shares as $share) {
            $permission = $this->normalizePermission((string) $share['permission']);
            if ($best === null || $this->permissionRank($permission) > $this->permissionRank($best)) {
                $best = $permission;
            }
        }

        return $best;
    }

    private function pathToRoot(array $item): array
    {
        $ownerId = (int) $item['user_id'];
        $path = [$item];
        $seen = [(int) $item['id'] => true];
        $parentId = $item['parent_id'] === null ? null : (int) $item['parent_id'];

        while ($parentId !== null) {
            if (isset($seen[$parentId])) {
                break;
            }

            $parent = $this->db->table('drive_items item')
                ->select('item.*, owner.username AS owner_username, owner.email AS owner_email')
                ->join('users owner', 'owner.id = item.user_id')
                ->where('item.id', $parentId)
                ->where('item.user_id', $ownerId)
                ->where('item.status', 'active')
                ->get()
                ->getRowArray();

            if ($parent === null) {
                break;
            }

            array_unshift($path, $parent);
            $seen[(int) $parent['id']] = true;
            $parentId = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
        }

        return $path;
    }

    private function sharedRootIdForPath(int $actorId, array $path): ?int
    {
        $ids = array_map(static fn (array $node): int => (int) $node['id'], $path);
        if ($ids === []) {
            return null;
        }

        $shares = $this->db->table('drive_shares')
            ->select('item_id')
            ->where('access_type', 'user')
            ->where('shared_with_user_id', $actorId)
            ->where('revoked_at', null)
            ->whereIn('item_id', $ids)
            ->get()
            ->getResultArray();

        $sharedIds = array_flip(array_map('intval', array_column($shares, 'item_id')));
        foreach ($path as $node) {
            $id = (int) $node['id'];
            if (isset($sharedIds[$id])) {
                return $id;
            }
        }

        return null;
    }

    private function normalizePermission(string $permission): string
    {
        $permission = strtolower(trim($permission));

        return in_array($permission, [self::PERMISSION_VIEWER, self::PERMISSION_EDITOR], true)
            ? $permission
            : throw new \DomainException('Permission is not available.');
    }

    private function permissionRank(string $permission): int
    {
        return match ($permission) {
            self::ROLE_OWNER => 3,
            self::PERMISSION_EDITOR => 2,
            self::PERMISSION_VIEWER => 1,
            default => 0,
        };
    }

    private function findActiveUserByIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \DomainException('Recipient is required.');
        }

        $user = $this->db->table('users')
            ->where('status', 'active')
            ->groupStart()
                ->where('email', $identifier)
                ->orWhere('username', $identifier)
            ->groupEnd()
            ->get()
            ->getRowArray();

        if ($user === null) {
            throw new \DomainException('User was not found.');
        }

        return $user;
    }

    private function shareById(int $ownerId, int $shareId): array
    {
        $share = $this->db->table('drive_shares share')
            ->select('share.*, item.name AS item_name, user.username, user.email')
            ->join('drive_items item', 'item.id = share.item_id')
            ->join('users user', 'user.id = share.shared_with_user_id', 'left')
            ->where('share.id', $shareId)
            ->where('share.user_id', $ownerId)
            ->where('share.access_type', 'user')
            ->get()
            ->getRowArray();

        if ($share === null) {
            throw new \DomainException('Shared access was not found.');
        }

        return $share;
    }

    private function shareRecipientsForItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }

        $rows = $this->db->table('drive_shares share')
            ->select('share.id, share.item_id, share.permission, share.created_at, share.updated_at, user.username, user.email')
            ->join('users user', 'user.id = share.shared_with_user_id')
            ->where('share.access_type', 'user')
            ->where('share.revoked_at', null)
            ->whereIn('share.item_id', $itemIds)
            ->orderBy('user.username', 'ASC')
            ->get()
            ->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $row['update_url'] = site_url('drive/shares/' . $row['id'] . '/permission');
            $row['revoke_url'] = site_url('drive/shares/' . $row['id'] . '/revoke');
            $row['label'] = $row['username'] . ' <' . $row['email'] . '>';
            $grouped[(int) $row['item_id']][] = $row;
        }

        return $grouped;
    }

    private function findOwnedItem(int $userId, int $itemId, bool $includeTrashed = false): array
    {
        $builder = $this->db->table('drive_items')
            ->where('id', $itemId)
            ->where('user_id', $userId);

        if (! $includeTrashed) {
            $builder->where('status', 'active');
        }

        $item = $builder->get()->getRowArray();
        if ($item === null) {
            throw new \DomainException('Drive item was not found.');
        }

        return $item;
    }

    private function assertFolder(int $userId, int $folderId): array
    {
        $folder = $this->findOwnedItem($userId, $folderId);
        if ($folder['type'] !== 'folder') {
            throw new \DomainException('Target folder was not found.');
        }

        return $folder;
    }

    private function isActiveFolder(int $userId, int $folderId): bool
    {
        return $this->db->table('drive_items')
            ->where('id', $folderId)
            ->where('user_id', $userId)
            ->where('type', 'folder')
            ->where('status', 'active')
            ->countAllResults() === 1;
    }

    private function assertNotMovingFolderIntoItself(int $userId, int $folderId, ?int $targetParentId): void
    {
        $current = $targetParentId;

        while ($current !== null) {
            if ($current === $folderId) {
                throw new \DomainException('A folder cannot be moved into itself.');
            }

            $folder = $this->assertFolder($userId, $current);
            $current = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
        }
    }

    private function ownedIds(int $userId, array $itemIds, ?string $status = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($ids === []) {
            return [];
        }

        $builder = $this->db->table('drive_items')
            ->select('id')
            ->where('user_id', $userId)
            ->whereIn('id', $ids);

        if ($status !== null) {
            $builder->where('status', $status);
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'id'));
    }

    private function expandSubtreeIds(int $userId, array $itemIds, ?string $status = null): array
    {
        $rootIds = $this->ownedIds($userId, $itemIds, $status);
        $all = $rootIds;
        $frontier = $rootIds;

        while ($frontier !== []) {
            $builder = $this->db->table('drive_items')
                ->select('id')
                ->where('user_id', $userId)
                ->whereIn('parent_id', $frontier);

            if ($status !== null) {
                $builder->where('status', $status);
            }

            $children = array_map('intval', array_column($builder->get()->getResultArray(), 'id'));
            $children = array_values(array_diff($children, $all));
            $all = array_values(array_unique(array_merge($all, $children)));
            $frontier = $children;
        }

        return $all;
    }

    private function activeSubtree(int $userId, int $rootId): array
    {
        $rows = [];
        $frontier = [$rootId];
        $isRootBatch = true;

        while ($frontier !== []) {
            $builder = $this->db->table('drive_items')
                ->where('user_id', $userId)
                ->where('status', 'active');

            if ($isRootBatch) {
                $builder->where('id', $rootId);
            } else {
                $builder->whereIn('parent_id', $frontier)
                    ->orderBy('type', 'DESC')
                    ->orderBy('name', 'ASC');
            }

            $batch = $builder->get()->getResultArray();

            $frontier = [];
            foreach ($batch as $row) {
                $id = (int) $row['id'];
                if (isset($rows[$id])) {
                    continue;
                }

                $rows[$id] = $row;

                $frontier[] = $id;
            }

            $isRootBatch = false;
        }

        return array_values($rows);
    }

    private function nextAvailableName(int $userId, ?int $parentId, string $name, ?int $excludeId = null): string
    {
        $name = $this->sanitizeName($name);
        $candidate = $name;
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));

        for ($index = 2; $this->nameExists($userId, $parentId, $candidate, $excludeId); $index++) {
            $suffix = ' (' . $index . ')';
            $candidate = $extension === '' ? $base . $suffix : $base . $suffix . '.' . $extension;
        }

        return $candidate;
    }

    private function nameExists(int $userId, ?int $parentId, string $name, ?int $excludeId): bool
    {
        $builder = $this->db->table('drive_items')
            ->where('user_id', $userId)
            ->where('normalized_name', $this->normalizeName($name))
            ->where('status', 'active');

        $parentId === null ? $builder->where('parent_id', null) : $builder->where('parent_id', $parentId);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    private function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', $name) ?? '';
        $name = trim($name, " \t\n\r\0\x0B.");

        if ($name === '') {
            $name = 'Untitled';
        }

        return mb_substr($name, 0, 190);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function extensionFromName(string $name): string
    {
        return mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    private function copyName(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));

        return $extension === '' ? $base . ' copy' : $base . ' copy.' . $extension;
    }

    private function detectMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = is_file($path) ? $finfo->file($path) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function newPhysicalPath(int $userId, string $extension): array
    {
        $random = bin2hex(random_bytes(20));
        $name = $random . ($extension !== '' ? '.' . $extension : '');

        return [
            'name'     => $name,
            'relative' => 'users/' . $userId . '/files/' . $name,
        ];
    }

    private function absolutePhysicalPath(array $item): string
    {
        $relative = str_replace('\\', '/', trim((string) ($item['physical_path'] ?? '')));
        $segments = $relative === '' ? [] : explode('/', $relative);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException(lang('App.errors.storedFilePathInvalid'));
            }
        }

        if (
            $relative === ''
            || str_starts_with($relative, '/')
            || preg_match('/\A[A-Za-z]:/', $relative)
            || str_contains($relative, "\0")
        ) {
            throw new \RuntimeException(lang('App.errors.storedFilePathInvalid'));
        }

        $root = rtrim($this->storageRoot(), '\\/');
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = realpath(dirname($path));

        if ($directory !== false) {
            $candidate = $this->normalizePathForCompare($directory);
            $rootForCompare = $this->normalizePathForCompare($root);

            if (! $this->pathIsInside($candidate, $rootForCompare)) {
                throw new \RuntimeException(lang('App.errors.storedFilePathOutside'));
            }

            return $path;
        }

        $candidate = $this->normalizePathForCompare($path);
        $rootForCompare = $this->normalizePathForCompare($root);

        if (! $this->pathIsInside($candidate, $rootForCompare)) {
            throw new \RuntimeException(lang('App.errors.storedFilePathOutside'));
        }

        return $path;
    }

    private function normalizePathForCompare(string $path): string
    {
        $normalized = str_replace('\\', '/', rtrim($path, '\\/'));

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized) : $normalized;
    }

    private function pathIsInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Private storage directory could not be created.');
        }
    }
}
