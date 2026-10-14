<?php
$item = $item ?? [];
$section = $section ?? 'drive';
$folderId = $folderId ?? null;
$itemId = (int) ($item['id'] ?? 0);
$ownerLabel = $ownerLabel ?? 'You';
$isPreviewable = $isPreviewable ?? static fn (array $item): bool => true;

$formatBytes = $formatBytes ?? static function (int $bytes): string {
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

$formatDate = $formatDate ?? static function ($value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp);
};

$itemName = (string) ($item['name'] ?? '');
$itemType = (string) ($item['type'] ?? '');
$accessRole = (string) ($item['access_role'] ?? 'owner');
$isOwner = $accessRole === 'owner';
$canEdit = in_array($accessRole, ['owner', 'editor'], true);
$canManageShares = $isOwner && $section !== 'trash';
$folderOpenPath = ($section === 'shared-with-me' || ! $isOwner) ? 'drive/shared-with-me' : 'drive';
$sharesJson = json_encode($item['shares'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
$itemSize = $itemType === 'file' ? $formatBytes((int) ($item['size_bytes'] ?? 0)) : 'Folder';
$itemUpdated = $formatDate($item['updated_at'] ?? null);
$itemMime = (string) ($item['mime_type'] ?? '-');
$itemExtension = (string) ($item['extension'] ?? '-');
$detailsAttributes = sprintf(
    'data-id="%d" data-name="%s" data-type="%s" data-size="%s" data-updated="%s" data-owner="%s" data-mime="%s" data-extension="%s"',
    $itemId,
    esc($itemName, 'attr'),
    esc(ucfirst($itemType), 'attr'),
    esc($itemSize, 'attr'),
    esc($itemUpdated, 'attr'),
    esc($ownerLabel, 'attr'),
    esc($itemMime !== '' ? $itemMime : '-', 'attr'),
    esc($itemExtension !== '' ? $itemExtension : '-', 'attr'),
);
?>
<div class="dropdown d-inline-block drive-action-menu">
  <button class="btn btn-sm btn-light-secondary" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="<?= esc(lang('UI.ta145120d719e'), 'attr') ?>">
    <i class="ti ti-dots-vertical"></i>
  </button>
  <ul class="dropdown-menu dropdown-menu-end">
    <?php if ($section === 'trash'): ?>
      <li>
        <button type="button" class="dropdown-item" data-drive-details <?= $detailsAttributes ?>>
          <i class="ti ti-info-circle me-2"></i><?= esc(lang('UI.t45989de49fb7')) ?>
        </button>
      </li>
      <li>
        <button type="button" class="dropdown-item text-success" data-bs-toggle="modal" data-bs-target="#confirm-modal" data-drive-confirm data-action="<?= site_url('drive/items/' . $itemId . '/restore') ?>" data-title="<?= esc(lang('UI.t49abac3764b4'), 'attr') ?>" data-message="Restore <?= esc($itemName, 'attr') ?> to My Drive?" data-submit-label="Restore" data-submit-class="btn-success">
          <i class="ti ti-rotate-clockwise me-2"></i><?= esc(lang('UI.ta76e13b98392')) ?>
        </button>
      </li>
      <li><hr class="dropdown-divider"></li>
      <li>
        <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#confirm-modal" data-drive-confirm data-action="<?= site_url('drive/items/' . $itemId . '/destroy') ?>" data-title="<?= esc(lang('UI.t280b10c89cc4'), 'attr') ?>" data-message="Permanently delete <?= esc($itemName, 'attr') ?>? This cannot be undone." data-submit-label="Delete permanently" data-submit-class="btn-danger">
          <i class="ti ti-trash-x me-2"></i><?= esc(lang('UI.t280b10c89cc4')) ?>
        </button>
      </li>
    <?php else: ?>
      <?php if ($itemType === 'folder'): ?>
        <li>
          <a class="dropdown-item" href="<?= site_url($folderOpenPath . '?folder=' . $itemId) ?>">
            <i class="ti ti-folder-open me-2"></i><?= esc(lang('UI.ted077f3d8125')) ?>
          </a>
        </li>
      <?php endif; ?>
      <?php if ($itemType === 'file' && $isPreviewable($item)): ?>
        <li>
          <a class="dropdown-item" href="<?= site_url('drive/items/' . $itemId . '/preview') ?>" data-drive-preview>
            <i class="ti ti-eye me-2"></i><?= esc(lang('UI.t324b134f57c7')) ?>
          </a>
        </li>
      <?php endif; ?>
      <?php if ($itemType === 'file'): ?>
        <li>
          <a class="dropdown-item" href="<?= site_url('drive/items/' . $itemId . '/download') ?>">
            <i class="ti ti-download me-2"></i><?= esc(lang('UI.td6eafe823591')) ?>
          </a>
        </li>
        <li>
          <a class="dropdown-item" href="<?= site_url('drive/items/' . $itemId . '/versions') ?>">
            <i class="ti ti-history me-2"></i><?= esc(lang('UI.tf89ea27036ac')) ?>
          </a>
        </li>
      <?php endif; ?>
      <?php if ($isOwner): ?>
        <li>
          <form action="<?= site_url('drive/items/' . $itemId . (! empty($item['is_starred']) ? '/unstar' : '/star')) ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="parent_id" value="<?= esc((string) $folderId) ?>">
            <input type="hidden" name="context" value="<?= esc($section) ?>">
            <button type="submit" class="dropdown-item w-100 text-start">
              <i class="ti <?= ! empty($item['is_starred']) ? 'ti-star-filled' : 'ti-star' ?> me-2"></i><?= ! empty($item['is_starred']) ? 'Remove from Starred' : 'Add to Starred' ?>
            </button>
          </form>
        </li>
      <?php endif; ?>
      <?php if ($canManageShares): ?>
        <li>
          <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#share-modal" data-drive-share data-action="<?= site_url('drive/items/' . $itemId . '/shares') ?>" data-name="<?= esc($itemName, 'attr') ?>" data-shares="<?= esc((string) $sharesJson, 'attr') ?>">
            <i class="ti ti-share me-2"></i><?= esc(lang('UI.t29887a5ff984')) ?>
          </button>
        </li>
      <?php endif; ?>
      <?php if ($canEdit || $isOwner): ?>
        <li><hr class="dropdown-divider"></li>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <li>
          <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#rename-modal" data-drive-rename data-action="<?= site_url('drive/items/' . $itemId . '/rename') ?>" data-name="<?= esc($itemName, 'attr') ?>">
            <i class="ti ti-pencil me-2"></i><?= esc(lang('UI.t3064d79a295c')) ?>
          </button>
        </li>
      <?php endif; ?>
      <?php if ($isOwner): ?>
        <li>
          <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#target-modal" data-drive-target data-title="<?= esc(lang('UI.t6ecc3df6bffd'), 'attr') ?>" data-action="<?= site_url('drive/items/' . $itemId . '/move') ?>">
            <i class="ti ti-arrows-move me-2"></i><?= esc(lang('UI.t6ecc3df6bffd')) ?>
          </button>
        </li>
        <li>
          <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#target-modal" data-drive-target data-title="<?= esc(lang('UI.te21f935f11d7'), 'attr') ?>" data-action="<?= site_url('drive/items/' . $itemId . '/copy') ?>">
            <i class="ti ti-copy me-2"></i><?= esc(lang('UI.te21f935f11d7')) ?>
          </button>
        </li>
      <?php endif; ?>
      <li>
        <button type="button" class="dropdown-item" data-drive-details <?= $detailsAttributes ?>>
          <i class="ti ti-info-circle me-2"></i><?= esc(lang('UI.t45989de49fb7')) ?>
        </button>
      </li>
      <?php if ($canEdit): ?>
        <li><hr class="dropdown-divider"></li>
        <li>
          <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#confirm-modal" data-drive-confirm data-action="<?= site_url('drive/items/' . $itemId . '/delete') ?>" data-title="<?= esc(lang('UI.tb250a454877d'), 'attr') ?>" data-message="Move <?= esc($itemName, 'attr') ?> to Trash?" data-submit-label="Move to trash" data-submit-class="btn-danger">
            <i class="ti ti-trash me-2"></i><?= esc(lang('UI.tc560122ac470')) ?>
          </button>
        </li>
      <?php endif; ?>
    <?php endif; ?>
  </ul>
</div>
