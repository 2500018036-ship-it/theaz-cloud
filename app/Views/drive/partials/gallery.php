<?php
$section = $section ?? 'drive';
$folderId = $folderId ?? null;
?>
<?= view('components/app/bulk_toolbar', [
    'id' => 'drive-bulk', 'action' => site_url('drive/bulk'), 'downloadAction' => $section !== 'trash' ? site_url('drive/bulk-download') : null,
    'hidden' => ['parent_id' => $folderId, 'context' => $section],
    'options' => $section === 'trash' ? ['restore' => lang('UI.ta76e13b98392')] : ['download' => lang('App.transfers.downloadZip'), 'delete' => lang('UI.tb250a454877d')],
]) ?>
<div class="drive-photo-gallery" data-drive-gallery>
  <?php foreach ($items as $photo): ?>
    <?php if (! str_starts_with((string) ($photo['mime_type'] ?? ''), 'image/')) continue; ?>
    <article class="drive-photo-card">
      <input class="form-check-input drive-photo-select" type="checkbox" id="drive-select-<?= (int) $photo['id'] ?>" form="drive-bulk" name="selected[]" value="<?= (int) $photo['id'] ?>" data-select-item data-select-actions="<?= ($photo['access_role'] ?? 'owner') === 'owner' ? 'download delete restore' : 'download' ?>" aria-label="<?= esc(lang('App.actions.select') . ' ' . $photo['name'], 'attr') ?>">
      <?php if ($section !== 'trash'): ?>
        <a href="<?= site_url('drive/items/' . (int) $photo['id'] . '/preview') ?>" data-drive-preview>
          <img src="<?= site_url('drive/items/' . (int) $photo['id'] . '/thumb') ?>" alt="<?= esc($photo['name'], 'attr') ?>" loading="lazy" decoding="async">
        </a>
      <?php else: ?><div class="drive-photo-unavailable"><i class="ti ti-photo-off"></i></div><?php endif; ?>
      <div class="drive-photo-caption text-truncate" data-user-content><?= esc($photo['name']) ?></div>
    </article>
  <?php endforeach; ?>
</div>
