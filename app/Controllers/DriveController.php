<?php

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ChunkedUploadService;
use App\Libraries\DriveService;
use App\Libraries\SystemSettingsService;
use App\Libraries\ThumbnailService;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class DriveController extends BaseController
{
    private DriveService $drive;

    public function __construct()
    {
        $this->drive = new DriveService();
    }

    // ------------------------------------------------------------------
    //  Page handlers
    // ------------------------------------------------------------------

    public function index(): string|RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getGet('folder'));

        try {
            return $this->renderDrive('drive', $folderId);
        } catch (\Throwable $exception) {
            return redirect()->to('/drive')->with('error', $exception->getMessage());
        }
    }

    public function recent(): string
    {
        return $this->renderDrive('recent');
    }

    public function starred(): string
    {
        return $this->renderDrive('starred');
    }

    public function trash(): string
    {
        return $this->renderDrive('trash');
    }

    public function sharedWithMe(): string
    {
        return $this->renderDrive('shared-with-me', $this->nullableInt($this->request->getGet('folder')));
    }

    public function sharedByMe(): string
    {
        return $this->renderDrive('shared-by-me');
    }

    public function activity(): string
    {
        $filters = [
            'q'       => $this->request->getGet('q'),
            'action'  => $this->request->getGet('action'),
            'status'  => $this->request->getGet('status'),
            'page'    => $this->request->getGet('page'),
            'perPage' => $this->request->getGet('perPage'),
        ];
        $result = (new ActivityLogger())->listForUser($this->currentUserId(), (string) session('role'), $filters);

        return view('drive/activity', [
            'title'       => 'Activity',
            'pageTitle'   => 'Activity',
            'activeMenu'  => 'activity',
            'breadcrumbs' => [
                ['label' => lang('UI.t87bb59ba2f92')],
                ['label' => lang('UI.t38da1505ca83')],
            ],
            'currentUser' => $this->currentUser(),
            'logs'        => $result['items'],
            'pager'       => $result['pager'],
            'query'       => [
                'q'      => (string) ($this->request->getGet('q') ?? ''),
                'action' => (string) ($this->request->getGet('action') ?? ''),
                'status' => (string) ($this->request->getGet('status') ?? ''),
            ],
        ]);
    }

    public function versions(int $id): string|RedirectResponse
    {
        try {
            $result = $this->drive->listFileVersions($this->currentUserId(), $id, [
                'page'    => $this->request->getGet('page'),
                'perPage' => $this->request->getGet('perPage'),
            ]);

            return view('drive/versions', [
                'title'       => 'Version History',
                'pageTitle'   => 'Version History',
                'activeMenu'  => 'drive',
                'breadcrumbs' => [
                    ['label' => lang('UI.t428d9cf8159f'), 'url' => site_url('drive')],
                    ['label' => $result['item']['name']],
                    ['label' => lang('UI.tf89ea27036ac')],
                ],
                'currentUser' => $this->currentUser(),
                'item'        => $result['item'],
                'versions'    => $result['versions'],
                'pager'       => $result['pager'],
            ]);
        } catch (\Throwable $exception) {
            return redirect()->to('/drive')->with('error', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Folder / single-file upload
    // ------------------------------------------------------------------

    public function createFolder(): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();
        $name = (string) $this->request->getPost('name');

        try {
            $this->drive->createFolder($this->currentUserId(), $folderId, $name);

            return $this->redirectToFolder($folderId, $context)->with('message', 'Folder created.');
        } catch (\Throwable $exception) {
            return $this->redirectToFolder($folderId, $context)
                ->withInput()
                ->with('modal', 'folder-modal')
                ->with('modal_error', $exception->getMessage())
                ->with('error', $exception->getMessage());
        }
    }

    public function upload(): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();
        $files = $this->request->getFileMultiple('files') ?? [];
        $created = 0;
        $errors = [];

        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                $this->drive->storeUploadedFile($this->currentUserId(), $folderId, $file);
                $created++;
            } catch (\Throwable $exception) {
                $errors[] = $file->getClientName() . ': ' . $exception->getMessage();
            }
        }

        $redirect = $this->redirectToFolder($folderId, $context);

        if ($created > 0) {
            $redirect->with('message', $created . ' file(s) uploaded.');
        }

        if ($errors !== []) {
            $redirect
                ->with('errors', $errors)
                ->with('modal', 'upload-modal')
                ->with('modal_errors', $errors);
        } elseif ($created === 0) {
            $redirect
                ->with('error', 'No files were uploaded.')
                ->with('modal', 'upload-modal')
                ->with('modal_error', 'No files were uploaded.');
        }

        return $redirect;
    }

    public function uploadVersion(int $id): RedirectResponse
    {
        try {
            $file = $this->request->getFile('file');
            if ($file === null) {
                throw new \DomainException('No file was selected.');
            }

            $this->drive->storeUploadedVersionFile($this->currentUserId(), $id, $file);

            return redirect()
                ->to('/drive/items/' . $id . '/versions')
                ->with('message', 'New version uploaded.');
        } catch (\Throwable $exception) {
            return redirect()
                ->to('/drive/items/' . $id . '/versions')
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Chunked upload endpoints (JSON API)
    // ------------------------------------------------------------------

    public function uploadInit(): ResponseInterface
    {
        try {
            $chunked = new ChunkedUploadService();
            $result = $chunked->initUpload(
                $this->currentUserId(),
                $this->nullableInt($this->request->getPost('parent_id')),
                (string) $this->request->getPost('file_name'),
                (int) $this->request->getPost('file_size'),
                (string) $this->request->getPost('mime_type') ?: null,
            );

            return $this->jsonResponse(['success' => true] + $result);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    public function uploadChunk(): ResponseInterface
    {
        try {
            $chunk = $this->request->getFile('chunk');
            if ($chunk === null || ! $chunk->isValid()) {
                throw new \DomainException('Chunk data is missing.');
            }

            $chunked = new ChunkedUploadService();
            $result = $chunked->receiveChunk(
                $this->currentUserId(),
                (string) $this->request->getPost('upload_id'),
                (int) $this->request->getPost('chunk_index'),
                $chunk->getTempName(),
            );

            return $this->jsonResponse(['success' => true] + $result);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    public function uploadComplete(): ResponseInterface
    {
        try {
            $chunked = new ChunkedUploadService();
            $item = $chunked->completeUpload(
                $this->currentUserId(),
                (string) $this->request->getPost('upload_id'),
            );

            return $this->jsonResponse(['success' => true, 'item' => $item]);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    public function uploadCancel(): ResponseInterface
    {
        try {
            $chunked = new ChunkedUploadService();
            $chunked->cancelUpload(
                $this->currentUserId(),
                (string) $this->request->getPost('upload_id'),
            );

            return $this->jsonResponse(['success' => true]);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Download / Preview / Thumbnail
    // ------------------------------------------------------------------

    public function download(int $id): DownloadResponse|RedirectResponse
    {
        try {
            $download = $this->drive->downloadableFile($this->currentUserId(), $id);

            return $this->response
                ->download($download['path'], null, true)
                ->setFileName($download['item']['name']);
        } catch (\Throwable $exception) {
            return redirect()->to('/drive')->with('error', $exception->getMessage());
        }
    }

    public function preview(int $id): DownloadResponse|RedirectResponse
    {
        try {
            $download = $this->drive->readableFile($this->currentUserId(), $id);
            $item = $download['item'];
            $mimeType = (string) ($item['mime_type'] ?? '');

            if (! $this->isPreviewableMime($mimeType)) {
                return redirect()->to('/drive')->with('error', 'Preview is not available for this file type.');
            }

            // Security headers: prevent untrusted content from executing scripts
            $this->response->setHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; object-src 'self'; sandbox");
            $this->response->setHeader('X-Content-Type-Options', 'nosniff');
            $this->response->setHeader('X-Frame-Options', 'SAMEORIGIN');
            $this->response->setHeader('Cache-Control', 'private, max-age=3600');

            return $this->response
                ->download($download['path'], null, true)
                ->setFileName($item['name'])
                ->inline();
        } catch (\Throwable $exception) {
            return redirect()->to('/drive')->with('error', $exception->getMessage());
        }
    }

    public function previewInfo(int $id): ResponseInterface
    {
        try {
            $download = $this->drive->readableFile($this->currentUserId(), $id);
            $item = $download['item'];
            $mimeType = (string) ($item['mime_type'] ?? '');
            $kind = $this->previewKind($mimeType);

            return $this->jsonResponse([
                'success' => true,
                'item' => [
                    'id'           => (int) $item['id'],
                    'name'         => (string) $item['name'],
                    'mime_type'    => $mimeType,
                    'extension'    => (string) ($item['extension'] ?? ''),
                    'size_bytes'   => (int) ($item['size_bytes'] ?? 0),
                    'owner_label'  => (string) ($item['owner_label'] ?? 'Owner'),
                    'updated_at'   => (string) ($item['updated_at'] ?? ''),
                    'preview_kind' => $kind,
                    'previewable'  => $kind !== 'unsupported',
                    'preview_url'  => site_url('drive/items/' . $id . '/preview'),
                    'text_url'     => site_url('drive/items/' . $id . '/preview-text'),
                    'download_url' => site_url('drive/items/' . $id . '/download'),
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 403);
        }
    }

    public function previewText(int $id): ResponseInterface
    {
        try {
            $download = $this->drive->readableFile($this->currentUserId(), $id);
            $item = $download['item'];
            $mimeType = (string) ($item['mime_type'] ?? '');
            if ($this->previewKind($mimeType) !== 'text') {
                throw new \DomainException('Text preview is not available for this file type.');
            }

            $maxBytes = (int) (new SystemSettingsService())->get('max_preview_text_bytes', '524288');
            $maxBytes = min(max($maxBytes, 1024), 2_097_152);
            $handle = fopen($download['path'], 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Stored file could not be opened.');
            }

            $content = fread($handle, $maxBytes + 1);
            fclose($handle);

            if ($content === false) {
                $content = '';
            }

            $truncated = strlen($content) > $maxBytes;
            if ($truncated) {
                $content = substr($content, 0, $maxBytes);
            }

            if (! mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }

            return $this->jsonResponse([
                'success'   => true,
                'content'   => $content,
                'truncated' => $truncated,
                'name'      => (string) $item['name'],
            ]);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 403);
        }
    }

    public function thumbnail(int $id): DownloadResponse|RedirectResponse
    {
        try {
            $thumbService = new ThumbnailService($this->drive);
            $thumb = $thumbService->getThumbnail($this->currentUserId(), $id);

            if ($thumb === null) {
                return redirect()->to('/drive')->with('error', 'Thumbnail not available.');
            }

            $this->response->setHeader('Cache-Control', 'public, max-age=86400');
            $this->response->setHeader('X-Content-Type-Options', 'nosniff');

            return $this->response
                ->download($thumb['path'], null, true)
                ->setFileName('thumb.jpg')
                ->inline();
        } catch (\Throwable $exception) {
            return redirect()->to('/drive')->with('error', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Item actions
    // ------------------------------------------------------------------

    public function rename(int $id): RedirectResponse
    {
        return $this->itemAction(function (DriveService $drive, int $userId) use ($id): void {
            $drive->renameItem($userId, $id, (string) $this->request->getPost('name'));
        }, 'Item renamed.', 'rename-modal');
    }

    public function move(int $id): RedirectResponse
    {
        return $this->itemAction(function (DriveService $drive, int $userId) use ($id): void {
            $drive->moveItem($userId, $id, $this->nullableInt($this->request->getPost('target_folder_id')));
        }, 'Item moved.', 'target-modal');
    }

    public function copy(int $id): RedirectResponse
    {
        return $this->itemAction(function (DriveService $drive, int $userId) use ($id): void {
            $drive->copyItem($userId, $id, $this->nullableInt($this->request->getPost('target_folder_id')));
        }, 'Item copied.', 'target-modal');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->itemAction(function (DriveService $drive, int $userId) use ($id): void {
            $drive->trashItems($userId, [$id], $userId);
        }, 'Item moved to trash.');
    }

    public function restore(int $id): RedirectResponse
    {
        return $this->trashAction(static function (DriveService $drive, int $userId) use ($id): void {
            $drive->restoreItems($userId, [$id]);
        }, 'Item restored.');
    }

    public function restoreVersion(int $id, int $versionId): RedirectResponse
    {
        try {
            $this->drive->restoreFileVersion($this->currentUserId(), $id, $versionId);

            return redirect()
                ->to('/drive/items/' . $id . '/versions')
                ->with('message', 'Version restored.');
        } catch (\Throwable $exception) {
            return redirect()
                ->to('/drive/items/' . $id . '/versions')
                ->with('error', $exception->getMessage());
        }
    }

    public function destroy(int $id): RedirectResponse
    {
        return $this->trashAction(static function (DriveService $drive, int $userId) use ($id): void {
            $drive->destroyItems($userId, [$id]);
        }, 'Item permanently deleted.');
    }

    public function star(int $id): RedirectResponse
    {
        return $this->itemAction(static function (DriveService $drive, int $userId) use ($id): void {
            $drive->starItem($userId, $id);
        }, 'Item starred.');
    }

    public function unstar(int $id): RedirectResponse
    {
        return $this->itemAction(static function (DriveService $drive, int $userId) use ($id): void {
            $drive->unstarItem($userId, $id);
        }, 'Item unstarred.');
    }

    // ------------------------------------------------------------------
    //  Bulk actions + bulk download
    // ------------------------------------------------------------------

    public function bulk(): RedirectResponse
    {
        $action = (string) $this->request->getPost('bulk_action');
        $ids = $this->selectedIds();
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $targetFolderId = $this->nullableInt($this->request->getPost('target_folder_id'));
        $redirect = in_array($action, ['restore', 'destroy'], true)
            ? redirect()->to('/drive/trash')
            : $this->redirectToFolder($folderId);

        if ($ids === []) {
            return $redirect->with('error', 'Select at least one item.');
        }

        try {
            $userId = $this->currentUserId();
            $this->drive->validateBulkSelection($userId, $ids, $action);

            match ($action) {
                'star' => array_map(fn (int $id) => $this->drive->starItem($userId, $id), $ids),
                'unstar' => array_map(fn (int $id) => $this->drive->unstarItem($userId, $id), $ids),
                'move' => array_map(fn (int $id) => $this->drive->moveItem($userId, $id, $targetFolderId), $ids),
                'copy' => array_map(fn (int $id) => $this->drive->copyItem($userId, $id, $targetFolderId), $ids),
                'delete' => $this->drive->trashItems($userId, $ids, $userId),
                'restore' => $this->drive->restoreItems($userId, $ids),
                'destroy' => $this->drive->destroyItems($userId, $ids),
                default => throw new \DomainException('Bulk action is not available.'),
            };

            return $redirect->with('message', 'Bulk action completed.');
        } catch (\Throwable $exception) {
            return $redirect->with('error', $exception->getMessage());
        }
    }

    public function bulkDownload(): DownloadResponse|RedirectResponse|ResponseInterface
    {
        $ids = $this->selectedIds();
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));

        if ($ids === []) {
            return $this->redirectToFolder($folderId)->with('error', 'Select at least one item.');
        }

        try {
            $userId = $this->currentUserId();
            $collection = $this->drive->collectDownloadFiles($userId, $ids);
            $files = $collection['items'];

            $tmpPath = $this->drive->storageRoot() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'download_' . bin2hex(random_bytes(8)) . '.zip';
            $tmpDir = dirname($tmpPath);
            if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
                throw new \RuntimeException('Temp directory could not be created.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create ZIP archive.');
            }

            $names = [];
            foreach ($files as $entry) {
                $name = $entry['item']['name'];
                // Avoid duplicate names inside ZIP
                $suffix = 2;
                $original = $name;
                while (in_array($name, $names, true)) {
                    $ext = pathinfo($original, PATHINFO_EXTENSION);
                    $base = $ext !== '' ? substr($original, 0, -(strlen($ext) + 1)) : $original;
                    $name = $base . ' (' . $suffix . ')' . ($ext !== '' ? '.' . $ext : '');
                    $suffix++;
                }
                $names[] = $name;
                $zip->addFile($entry['path'], $name);
            }

            $zip->close();

            (new ActivityLogger())->record($userId, 'drive.bulk_download', 'success', [
                'type' => 'drive_item',
            ], [
                'file_count'  => count($files),
                'total_bytes' => $collection['total_bytes'],
            ]);

            // Stream ZIP and schedule cleanup
            $response = $this->response
                ->download($tmpPath, null, true)
                ->setFileName('drive-download.zip');

            // Delete the temp file after sending
            register_shutdown_function(static function () use ($tmpPath): void {
                if (is_file($tmpPath)) {
                    @unlink($tmpPath);
                }
            });

            return $response;
        } catch (\Throwable $exception) {
            (new ActivityLogger())->record($this->currentUserId(), 'drive.bulk_download', 'failure', [
                'type' => 'drive_item',
            ], [
                'selected' => $ids,
                'error'    => $exception->getMessage(),
            ]);

            return $this->redirectToFolder($folderId)->with('error', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Share
    // ------------------------------------------------------------------

    public function share(int $id): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();

        try {
            $result = $this->drive->createShareLink($this->currentUserId(), $id, $this->nullableInt($this->request->getPost('expires_days')));

            return $this->redirectToFolder($folderId, $context)
                ->with('message', 'Share link created.')
                ->with('modal', 'share-modal')
                ->with('share_link', site_url('s/' . $result['token']))
                ->with('share_item_name', $result['name']);
        } catch (\Throwable $exception) {
            return $this->redirectToFolder($folderId, $context)
                ->with('modal', 'share-modal')
                ->with('modal_error', $exception->getMessage())
                ->with('error', $exception->getMessage());
        }
    }

    public function revokePublicShare(int $id): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();

        try {
            $this->drive->revokePublicShareLink($this->currentUserId(), $id);

            return $this->redirectToFolder($folderId, $context)
                ->with('message', 'Public link revoked.');
        } catch (\Throwable $exception) {
            return $this->redirectToFolder($folderId, $context)
                ->with('error', $exception->getMessage());
        }
    }

    public function shareWithUser(int $id): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();

        try {
            $this->drive->shareItemWithUser(
                $this->currentUserId(),
                $id,
                (string) $this->request->getPost('recipient'),
                (string) $this->request->getPost('permission'),
                $this->currentUserId(),
            );

            return $this->redirectToFolder($folderId, $context)
                ->with('message', 'Access shared.');
        } catch (\Throwable $exception) {
            return $this->redirectToFolder($folderId, $context)
                ->with('error', $exception->getMessage());
        }
    }

    public function updateShare(int $shareId): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();

        try {
            $this->drive->updateSharePermission($this->currentUserId(), $shareId, (string) $this->request->getPost('permission'));

            return $this->redirectToFolder($folderId, $context)
                ->with('message', 'Permission updated.');
        } catch (\Throwable $exception) {
            return $this->redirectToFolder($folderId, $context)
                ->with('error', $exception->getMessage());
        }
    }

    public function revokeShare(int $shareId): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();

        try {
            $this->drive->revokeShare($this->currentUserId(), $shareId);

            return $this->redirectToFolder($folderId, $context)
                ->with('message', 'Access revoked.');
        } catch (\Throwable $exception) {
            return $this->redirectToFolder($folderId, $context)
                ->with('error', $exception->getMessage());
        }
    }

    public function sharedDownload(string $token): DownloadResponse|RedirectResponse
    {
        try {
            $download = $this->drive->downloadSharedFile($token);

            return $this->response
                ->download($download['path'], null, true)
                ->setFileName($download['item']['name']);
        } catch (\Throwable $exception) {
            return redirect()->to('/')->with('error', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function renderDrive(string $section, ?int $folderId = null): string
    {
        $userId = $this->currentUserId();
        $profile = db_connect()->table('user_profiles')
            ->select('default_drive_view, default_sort, default_sort_dir')
            ->where('user_id', $userId)
            ->get()
            ->getRowArray() ?? [];
        $defaultView = in_array((string) ($profile['default_drive_view'] ?? 'list'), ['list', 'grid'], true)
            ? (string) ($profile['default_drive_view'] ?? 'list')
            : 'list';
        $defaultSort = in_array((string) ($profile['default_sort'] ?? 'name'), ['name', 'updated', 'size', 'type'], true)
            ? (string) ($profile['default_sort'] ?? 'name')
            : 'name';
        $defaultDir = (string) ($profile['default_sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $filters = [
            'q'         => $this->request->getGet('q'),
            'sort'      => $this->request->getGet('sort') ?? $defaultSort,
            'dir'       => $this->request->getGet('dir') ?? $defaultDir,
            'page'      => $this->request->getGet('page'),
            'perPage'   => $this->request->getGet('perPage'),
            'ext'       => $this->request->getGet('ext'),
            'type'      => $this->request->getGet('type'),
            'size_min'  => $this->request->getGet('size_min'),
            'size_max'  => $this->request->getGet('size_max'),
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
        ];
        $result = match ($section) {
            'recent' => $this->drive->listRecent($userId, $filters),
            'starred' => $this->drive->listStarred($userId, $filters),
            'trash' => $this->drive->listTrash($userId, $filters),
            'shared-with-me' => $this->drive->listSharedWithMe($userId, $filters, $folderId),
            'shared-by-me' => $this->drive->listSharedByMe($userId, $filters),
            default => $this->drive->listFolder($userId, $folderId, $filters),
        };

        $pageTitles = [
            'drive'          => 'My Drive',
            'recent'         => 'Recent Files',
            'starred'        => 'Starred',
            'trash'          => 'Trash',
            'shared-with-me' => 'Shared with me',
            'shared-by-me'   => 'Shared by me',
        ];

        $breadcrumbs = match ($section) {
            'drive' => $this->drive->breadcrumbs($userId, $folderId),
            'shared-with-me' => $this->drive->sharedBreadcrumbs($userId, $folderId),
            default => [['label' => $pageTitles[$section] ?? 'Drive']],
        };

        return view('drive/index', [
            'title'         => $pageTitles[$section] ?? 'Drive',
            'pageTitle'     => $pageTitles[$section] ?? 'Drive',
            'activeMenu'    => $section,
            'breadcrumbs'   => $breadcrumbs,
            'currentUser'   => $this->currentUser(),
            'section'       => $section,
            'folderId'      => $folderId,
            'viewMode'      => $this->request->getGet('view') === null ? $defaultView : ($this->request->getGet('view') === 'grid' ? 'grid' : 'list'),
            'items'         => $result['items'],
            'pager'         => $result['pager'],
            'storage'       => $this->drive->userStorageSummary($userId),
            'folderOptions' => $this->drive->folderOptions($userId),
            'canCreate'     => in_array($section, ['drive', 'shared-with-me'], true) && $this->drive->canCreateInFolder($userId, $folderId),
            'query'         => [
                'q'         => (string) ($this->request->getGet('q') ?? ''),
                'sort'      => (string) ($this->request->getGet('sort') ?? $defaultSort),
                'dir'       => (string) ($this->request->getGet('dir') ?? $defaultDir),
                'ext'       => (string) ($this->request->getGet('ext') ?? ''),
                'type'      => (string) ($this->request->getGet('type') ?? ''),
                'size_min'  => (string) ($this->request->getGet('size_min') ?? ''),
                'size_max'  => (string) ($this->request->getGet('size_max') ?? ''),
                'date_from' => (string) ($this->request->getGet('date_from') ?? ''),
                'date_to'   => (string) ($this->request->getGet('date_to') ?? ''),
            ],
        ]);
    }

    private function itemAction(callable $action, string $message, ?string $modal = null): RedirectResponse
    {
        $folderId = $this->nullableInt($this->request->getPost('parent_id'));
        $context = $this->driveContext();

        try {
            $action($this->drive, $this->currentUserId());

            return $this->redirectToFolder($folderId, $context)->with('message', $message);
        } catch (\Throwable $exception) {
            $redirect = $this->redirectToFolder($folderId, $context)->with('error', $exception->getMessage());

            if ($modal !== null) {
                $redirect->with('modal', $modal)->with('modal_error', $exception->getMessage());
            }

            return $redirect;
        }
    }

    private function trashAction(callable $action, string $message): RedirectResponse
    {
        try {
            $action($this->drive, $this->currentUserId());

            return redirect()->to('/drive/trash')->with('message', $message);
        } catch (\Throwable $exception) {
            return redirect()->to('/drive/trash')->with('error', $exception->getMessage());
        }
    }

    private function redirectToFolder(?int $folderId, string $context = 'drive'): RedirectResponse
    {
        $base = $context === 'shared-with-me' ? '/drive/shared-with-me' : '/drive';

        return redirect()->to($folderId === null ? $base : $base . '?folder=' . $folderId);
    }

    private function driveContext(): string
    {
        return $this->request->getPost('context') === 'shared-with-me' ? 'shared-with-me' : 'drive';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function selectedIds(): array
    {
        $selected = $this->request->getPost('selected');
        if (! is_array($selected)) {
            return [];
        }

        try {
            return \App\Libraries\BulkSelection::ids($selected);
        } catch (\DomainException) {
            return [];
        }
    }

    private function currentUserId(): int
    {
        return (int) session('user_id');
    }

    private function currentUser(): array
    {
        return [
            'username' => session('username'),
            'email'    => session('email'),
            'role'     => session('role'),
        ];
    }

    private function isPreviewableMime(string $mimeType): bool
    {
        return $this->previewKind($mimeType) !== 'unsupported';
    }

    private function previewKind(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'text/')) {
            return str_starts_with($mimeType, 'image/') ? 'image' : 'text';
        }

        return match ($mimeType) {
            'application/json' => 'text',
            'application/pdf' => 'pdf',
            'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/ogg' => 'audio',
            'video/mp4', 'video/webm', 'video/ogg' => 'video',
            default => 'unsupported',
        };
    }

    private function jsonResponse(array $data, int $status = 200): ResponseInterface
    {
        $data['csrf_name'] = csrf_token();
        $data['csrf_hash'] = csrf_hash();

        return $this->response->setStatusCode($status)->setJSON($data);
    }

    private function jsonError(string $message, int $status = 422): ResponseInterface
    {
        return $this->jsonResponse(['success' => false, 'error' => $message], $status);
    }
}
