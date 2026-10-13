<?= $this->extend('layouts/user') ?>

<?php
$section = $section ?? 'drive';
$folderId = $folderId ?? null;
$items = $items ?? [];
$pager = $pager ?? ['page' => 1, 'perPage' => 24, 'total' => 0, 'totalPages' => 1];
$storage = $storage ?? ['used' => 0, 'quota' => 1, 'percentage' => 0];
$folderOptions = $folderOptions ?? [];
$query = $query ?? ['q' => '', 'sort' => 'name', 'dir' => 'asc'];
$viewMode = $viewMode ?? 'list';
$ownerLabel = 'You';
$canCreate = (bool) ($canCreate ?? $section === 'drive');
$currentUserId = (int) session('user_id');
$activeModal = (string) (session('modal') ?? '');
$modalError = (string) (session('modal_error') ?? '');
$modalErrors = session('modal_errors');
$modalErrors = is_array($modalErrors) ? $modalErrors : [];
$shareLink = (string) (session('share_link') ?? '');
$shareItemName = (string) (session('share_item_name') ?? '');

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' B';
};

$formatDate = static function ($value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp);
};

$iconFor = static fn (array $item): string => \App\Libraries\FileIcon::forItem($item);

$isPreviewable = static function (array $item): bool {
    $mimeType = (string) ($item['mime_type'] ?? '');

    if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'text/')) {
        return true;
    }

    return in_array($mimeType, [
        'application/json',
        'application/pdf',
        'audio/mpeg',
        'video/mp4',
    ], true);
};

$sectionLabel = match ($section) {
    'recent' => 'Recent Files',
    'starred' => 'Starred Files',
    'trash' => 'Trash',
    'shared-with-me' => 'Shared with me',
    'shared-by-me' => 'Shared by me',
    default => 'My Drive',
};

$itemHref = static function (array $item) use ($isPreviewable, $section, $currentUserId): string {
    $itemId = (int) $item['id'];

    if ($item['type'] === 'folder') {
        $isSharedContext = $section === 'shared-with-me' || (int) ($item['user_id'] ?? $currentUserId) !== $currentUserId;

        return site_url(($isSharedContext ? 'drive/shared-with-me' : 'drive') . '?folder=' . $itemId);
    }

    return $isPreviewable($item)
        ? site_url('drive/items/' . $itemId . '/preview')
        : site_url('drive/items/' . $itemId . '/download');
};

$basePath = match ($section) {
    'recent' => site_url('drive/recent'),
    'starred' => site_url('drive/starred'),
    'trash' => site_url('drive/trash'),
    'shared-with-me' => site_url('drive/shared-with-me'),
    'shared-by-me' => site_url('drive/shared-by-me'),
    default => site_url('drive'),
};

$urlWith = static function (array $overrides = []) use ($basePath, $folderId, $section, $query, $viewMode): string {
    $params = array_merge([
        'q' => $query['q'] ?? '',
        'sort' => $query['sort'] ?? 'name',
        'dir' => $query['dir'] ?? 'asc',
        'view' => $viewMode,
    ], $overrides);

    if (in_array($section, ['drive', 'shared-with-me'], true) && $folderId !== null) {
        $params['folder'] = $folderId;
    }

    $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

    return $basePath . ($params === [] ? '' : '?' . http_build_query($params));
};
?>

<?= $this->section('content') ?>
<div id="drive-page-state" data-active-modal="<?= esc($activeModal, 'attr') ?>"></div>



<?php if ($canCreate): ?>
  <div class="card">
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#upload-modal">
            <i class="ti ti-upload"></i> Upload
          </button>
          <button type="button" class="btn btn-light-primary" data-bs-toggle="modal" data-bs-target="#folder-modal">
            <i class="ti ti-folder-plus"></i> New Folder
          </button>
        </div>
        <div class="btn-group" role="group" aria-label="View mode">
          <a class="btn btn-light<?= $viewMode === 'list' ? ' active' : '' ?>" href="<?= esc($urlWith(['view' => 'list'])) ?>"><i class="ti ti-list"></i></a>
          <a class="btn btn-light<?= $viewMode === 'grid' ? ' active' : '' ?>" href="<?= esc($urlWith(['view' => 'grid'])) ?>"><i class="ti ti-layout-grid"></i></a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card drive-list-card">
  <div class="card-header">
    <div class="row g-3 align-items-center">
      <div class="col-lg-5">
        <div class="d-flex align-items-center gap-2">
          <h5 class="mb-0"><?= esc($sectionLabel) ?></h5>
          <span class="badge bg-light-secondary"><?= esc((string) $pager['total']) ?></span>
          <button class="btn btn-sm btn-light-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#drive-filters" aria-expanded="false" title="Filters">
            <i class="ti ti-filter f-16"></i>
          </button>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
          <?php foreach (['name' => 'Name', 'type' => 'Type', 'size' => 'Size', 'updated' => 'Updated'] as $key => $label): ?>
            <?php $nextDir = ($query['sort'] ?? 'name') === $key && ($query['dir'] ?? 'asc') === 'asc' ? 'desc' : 'asc'; ?>
            <a class="btn btn-sm btn-light<?= ($query['sort'] ?? 'name') === $key ? ' active' : '' ?>" href="<?= esc($urlWith(['sort' => $key, 'dir' => $nextDir])) ?>">
              <?= esc($label) ?>
              <?php if (($query['sort'] ?? 'name') === $key): ?>
                <i class="ti ti-chevron-<?= ($query['dir'] ?? 'asc') === 'asc' ? 'up' : 'down' ?> f-12"></i>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="collapse<?= ($query['ext'] ?? '') !== '' || ($query['type'] ?? '') !== '' || ($query['date_from'] ?? '') !== '' || ($query['date_to'] ?? '') !== '' ? ' show' : '' ?>" id="drive-filters">
      <form action="<?= esc($basePath) ?>" method="get" class="row g-2 mt-2 align-items-end">
        <?php if (in_array($section, ['drive', 'shared-with-me'], true) && $folderId !== null): ?>
          <input type="hidden" name="folder" value="<?= esc((string) $folderId) ?>">
        <?php endif; ?>
        <input type="hidden" name="view" value="<?= esc($viewMode) ?>">
        <input type="hidden" name="sort" value="<?= esc($query['sort'] ?? 'name') ?>">
        <input type="hidden" name="dir" value="<?= esc($query['dir'] ?? 'asc') ?>">
        <div class="col-auto">
          <label class="form-label small mb-1">Type</label>
          <select name="type" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="file"<?= ($query['type'] ?? '') === 'file' ? ' selected' : '' ?>>Files</option>
            <option value="folder"<?= ($query['type'] ?? '') === 'folder' ? ' selected' : '' ?>>Folders</option>
          </select>
        </div>
        <div class="col-auto">
          <label class="form-label small mb-1">Extension</label>
          <input type="text" name="ext" class="form-control form-control-sm" value="<?= esc($query['ext'] ?? '') ?>" placeholder="e.g. pdf,docx" style="width:120px">
        </div>
        <div class="col-auto">
          <label class="form-label small mb-1">From</label>
          <input type="date" name="date_from" class="form-control form-control-sm" value="<?= esc($query['date_from'] ?? '') ?>">
        </div>
        <div class="col-auto">
          <label class="form-label small mb-1">To</label>
          <input type="date" name="date_to" class="form-control form-control-sm" value="<?= esc($query['date_to'] ?? '') ?>">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Apply</button>
          <a href="<?= esc($basePath . (in_array($section, ['drive', 'shared-with-me'], true) && $folderId !== null ? '?folder=' . $folderId : '')) ?>" class="btn btn-sm btn-light-secondary"><i class="ti ti-x me-1"></i>Clear</a>
        </div>
      </form>
    </div>
  </div>
  <div class="card-body table-border-style">
    <?php if ($items === []): ?>
      <?= view('components/ui/empty_state', [
          'icon' => $section === 'trash' ? 'ti ti-trash' : 'ti ti-folder-open',
          'title' => 'No items found',
          'message' => $canCreate ? 'Create a folder or upload files to start organizing this space.' : 'Nothing to show here yet.',
      ]) ?>
      <?php if ($canCreate): ?>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#upload-modal">
            <i class="ti ti-upload"></i> Upload
          </button>
          <button type="button" class="btn btn-light-primary" data-bs-toggle="modal" data-bs-target="#folder-modal">
            <i class="ti ti-folder-plus"></i> New Folder
          </button>
        </div>
      <?php endif; ?>
    <?php elseif ($viewMode === 'grid'): ?>
      <div class="row g-3">
        <?php foreach ($items as $item): ?>
          <div class="col-sm-6 col-xl-3">
            <div class="card border drive-item-card mb-0">
              <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-3">
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light-secondary text-secondary"><?= esc($item['type']) ?></span>
                    <?php if (($item['access_role'] ?? 'owner') !== 'owner'): ?>
                      <span class="badge bg-light-info text-info"><?= esc(ucfirst((string) $item['access_role'])) ?></span>
                    <?php elseif (($item['share_count'] ?? 0) > 0): ?>
                      <span class="badge bg-light-primary text-primary">Shared</span>
                    <?php endif; ?>
                  </div>
                  <?= view('drive/partials/item_actions', [
                      'item' => $item,
                      'section' => $section,
                      'folderId' => $folderId,
                      'folderOptions' => $folderOptions,
                      'formatBytes' => $formatBytes,
                      'formatDate' => $formatDate,
                      'isPreviewable' => $isPreviewable,
                      'ownerLabel' => (string) ($item['owner_label'] ?? $ownerLabel),
                  ]) ?>
                </div>
                <a class="d-inline-flex avtar bg-light-primary text-primary mb-3 text-decoration-none" href="<?= esc($itemHref($item)) ?>"<?= $item['type'] === 'file' && $isPreviewable($item) ? ' data-drive-preview' : '' ?>>
                  <i class="<?= esc($iconFor($item)) ?> f-22"></i>
                </a>
                <h6 class="drive-grid-name text-truncate mb-1">
                  <a class="fw-semibold" href="<?= esc($itemHref($item)) ?>"<?= $item['type'] === 'file' && $isPreviewable($item) ? ' data-drive-preview' : '' ?>>
                    <?= esc($item['name']) ?>
                  </a>
                  <?php if (! empty($item['is_starred'])): ?>
                    <i class="ti ti-star-filled text-warning"></i>
                  <?php endif; ?>
                </h6>
                <p class="text-muted small mb-0"><?= esc($item['type'] === 'file' ? $formatBytes((int) $item['size_bytes']) : 'Folder') ?></p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Size</th>
              <th>Updated</th>
              <th>Owner</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <a class="avtar avtar-xs bg-light-primary text-primary text-decoration-none" href="<?= esc($itemHref($item)) ?>"<?= $item['type'] === 'file' && $isPreviewable($item) ? ' data-drive-preview' : '' ?>>
                      <i class="<?= esc($iconFor($item)) ?>"></i>
                    </a>
                    <div class="drive-item-name text-truncate">
                      <a class="fw-semibold" href="<?= esc($itemHref($item)) ?>"<?= $item['type'] === 'file' && $isPreviewable($item) ? ' data-drive-preview' : '' ?>>
                        <?= esc($item['name']) ?>
                      </a>
                      <?php if (! empty($item['is_starred'])): ?>
                        <i class="ti ti-star-filled text-warning"></i>
                      <?php endif; ?>
                      <?php if (($item['access_role'] ?? 'owner') !== 'owner'): ?>
                        <span class="badge bg-light-info text-info ms-1"><?= esc(ucfirst((string) $item['access_role'])) ?></span>
                      <?php elseif (($item['share_count'] ?? 0) > 0): ?>
                        <span class="badge bg-light-primary text-primary ms-1">Shared</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><?= esc(ucfirst((string) $item['type'])) ?></td>
                <td><?= esc($item['type'] === 'file' ? $formatBytes((int) $item['size_bytes']) : '-') ?></td>
                <td><?= esc($formatDate($item['updated_at'] ?? null)) ?></td>
                <td><?= esc((string) ($item['owner_label'] ?? $ownerLabel)) ?></td>
                <td class="text-end">
                  <?= view('drive/partials/item_actions', [
                      'item' => $item,
                      'section' => $section,
                      'folderId' => $folderId,
                      'folderOptions' => $folderOptions,
                      'formatBytes' => $formatBytes,
                      'formatDate' => $formatDate,
                      'isPreviewable' => $isPreviewable,
                      'ownerLabel' => (string) ($item['owner_label'] ?? $ownerLabel),
                  ]) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (($pager['totalPages'] ?? 1) > 1): ?>
    <div class="card-footer">
      <div class="d-flex justify-content-between align-items-center">
        <span class="text-muted small">Page <?= esc((string) $pager['page']) ?> of <?= esc((string) $pager['totalPages']) ?></span>
        <div class="btn-group">
          <a class="btn btn-light<?= $pager['page'] <= 1 ? ' disabled' : '' ?>" href="<?= esc($urlWith(['page' => max(1, $pager['page'] - 1)])) ?>"><i class="ti ti-chevron-left"></i></a>
          <a class="btn btn-light<?= $pager['page'] >= $pager['totalPages'] ? ' disabled' : '' ?>" href="<?= esc($urlWith(['page' => min($pager['totalPages'], $pager['page'] + 1)])) ?>"><i class="ti ti-chevron-right"></i></a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="folder-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form action="<?= site_url('drive/folders') ?>" method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
      <input type="hidden" name="context" value="<?= esc($section) ?>">
      <div class="modal-header">
        <h5 class="modal-title">New Folder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($activeModal === 'folder-modal' && $modalError !== ''): ?>
          <div class="alert alert-danger"><?= esc($modalError) ?></div>
        <?php endif; ?>
        <label class="form-label" for="folder-name">Folder name</label>
        <input type="text" class="form-control" id="folder-name" name="name" value="<?= esc(old('name')) ?>" maxlength="190" required>
        <div class="form-text">The folder will be created in the current location.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal"><i class="ti ti-x me-1"></i>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="ti ti-folder-plus me-1"></i>Create</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="upload-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form action="<?= site_url('drive/upload') ?>" method="post" enctype="multipart/form-data" class="modal-content" id="upload-form">
      <?= csrf_field() ?>
      <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
      <input type="hidden" name="context" value="<?= esc($section) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Upload Files</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($activeModal === 'upload-modal' && ($modalError !== '' || $modalErrors !== [])): ?>
          <div class="alert alert-danger">
            <?php if ($modalError !== ''): ?>
              <?= esc($modalError) ?>
            <?php else: ?>
              <ul class="mb-0 ps-3">
                <?php foreach ($modalErrors as $error): ?>
                  <li><?= esc($error) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <label class="form-label" for="drive-files">Files</label>
        <input type="file" class="form-control" id="drive-files" name="files[]" multiple required>
        <div class="form-text">Files are stored privately and checked against your quota before saving.</div>
        <div id="upload-file-list" class="mt-3 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal"><i class="ti ti-x me-1"></i>Cancel</button>
        <button type="submit" class="btn btn-primary" id="upload-submit"><i class="ti ti-upload me-1"></i>Upload</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="rename-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" id="rename-form">
      <?= csrf_field() ?>
      <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
      <input type="hidden" name="context" value="<?= esc($section) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Rename</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($activeModal === 'rename-modal' && $modalError !== ''): ?>
          <div class="alert alert-danger"><?= esc($modalError) ?></div>
        <?php endif; ?>
        <label class="form-label" for="rename-name">Name</label>
        <input type="text" class="form-control" id="rename-name" name="name" maxlength="190" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal"><i class="ti ti-x me-1"></i>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="target-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" id="target-form">
      <?= csrf_field() ?>
      <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
      <input type="hidden" name="context" value="<?= esc($section) ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="target-title">Move</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($activeModal === 'target-modal' && $modalError !== ''): ?>
          <div class="alert alert-danger"><?= esc($modalError) ?></div>
        <?php endif; ?>
        <label class="form-label" for="target-folder">Folder</label>
        <select class="form-select" id="target-folder" name="target_folder_id">
          <?php foreach ($folderOptions as $option): ?>
            <option value="<?= esc((string) $option['id']) ?>"><?= esc($option['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal"><i class="ti ti-x me-1"></i>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="confirm-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" id="confirm-form">
      <?= csrf_field() ?>
      <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="confirm-title">Confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0" id="confirm-message"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal"><i class="ti ti-x me-1"></i>Cancel</button>
        <button type="submit" class="btn btn-danger" id="confirm-submit"><i class="ti ti-alert-triangle me-1"></i>Confirm</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="share-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Share Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($activeModal === 'share-modal' && $modalError !== ''): ?>
          <div class="alert alert-danger"><?= esc($modalError) ?></div>
        <?php endif; ?>
        <p class="text-muted small mb-3" id="share-file-name"><?= esc($shareItemName) ?></p>

        <form method="post" id="share-form" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
          <input type="hidden" name="context" value="<?= esc($section) ?>">
          <div class="col-md-7">
            <label class="form-label" for="share-recipient">User email or username</label>
            <input type="text" class="form-control" id="share-recipient" name="recipient" autocomplete="off" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="share-permission">Permission</label>
            <select class="form-select" id="share-permission" name="permission">
              <option value="viewer">Viewer</option>
              <option value="editor">Editor</option>
            </select>
          </div>
          <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary" id="share-submit"><i class="ti ti-share me-1"></i>Share</button>
          </div>
        </form>

        <hr>
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="mb-0">People with access</h6>
          <span class="badge bg-light-secondary" id="share-count">0</span>
        </div>
        <div id="share-recipient-list" class="vstack gap-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal"><i class="ti ti-check me-1"></i>Done</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="drive-preview-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
    <div class="modal-content drive-preview-modal-content">
      <div class="modal-header">
        <div class="min-w-0">
          <h5 class="modal-title text-truncate mb-0" id="drive-preview-title">Preview</h5>
          <div class="text-muted small text-truncate" id="drive-preview-meta"></div>
        </div>
        <div class="d-flex align-items-center gap-2 ms-auto">
          <a class="btn btn-sm btn-light-primary d-none" id="drive-preview-download" href="#"><i class="ti ti-download"></i></a>
          <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>
      <div class="modal-body drive-preview-body">
        <div id="drive-preview-loading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
        </div>
        <div id="drive-preview-error" class="alert alert-danger d-none mb-0"></div>
        <img id="drive-preview-image" class="drive-preview-media d-none" alt="">
        <iframe id="drive-preview-frame" class="drive-preview-frame d-none" title="File preview"></iframe>
        <pre id="drive-preview-text" class="drive-preview-text d-none"></pre>
        <audio id="drive-preview-audio" class="w-100 d-none" controls></audio>
        <video id="drive-preview-video" class="drive-preview-video d-none" controls></video>
        <div id="drive-preview-unsupported" class="text-center py-5 d-none">
          <div class="avtar avtar-s bg-light-secondary text-secondary mx-auto mb-3">
            <i class="ti ti-file-off f-20"></i>
          </div>
          <h6 class="mb-1">Preview unavailable</h6>
          <p class="text-muted mb-0">Download this file to open it with a compatible app.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="details-panel" aria-labelledby="details-panel-title">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="details-panel-title">File Details</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="text-center mb-3" id="details-thumb-wrap">
      <img id="details-thumb" class="img-fluid rounded d-none" alt="Thumbnail" style="max-height:200px">
    </div>
    <dl class="row mb-0">
      <dt class="col-sm-4">Name</dt>
      <dd class="col-sm-8 text-break" id="details-name"></dd>
      <dt class="col-sm-4">Type</dt>
      <dd class="col-sm-8" id="details-type"></dd>
      <dt class="col-sm-4">Size</dt>
      <dd class="col-sm-8" id="details-size"></dd>
      <dt class="col-sm-4">Updated</dt>
      <dd class="col-sm-8" id="details-updated"></dd>
      <dt class="col-sm-4">Owner</dt>
      <dd class="col-sm-8" id="details-owner"></dd>
      <dt class="col-sm-4">MIME</dt>
      <dd class="col-sm-8" id="details-mime"></dd>
      <dt class="col-sm-4">Extension</dt>
      <dd class="col-sm-8" id="details-extension"></dd>
    </dl>
    <hr>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-primary d-none" id="details-download" href="#"><i class="ti ti-download me-1"></i>Download</a>
      <a class="btn btn-sm btn-light-primary d-none" id="details-preview" href="#" data-drive-preview><i class="ti ti-eye me-1"></i>Preview</a>
    </div>
  </div>
</div>

<!-- Keep the modal ID reference for backwards compat with item_actions partial -->
<div class="modal fade" id="details-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-body p-3 text-center">
        <p class="mb-0 small text-muted">Loading details…</p>
      </div>
    </div>
  </div>
</div>

<?= $this->endSection() ?>
