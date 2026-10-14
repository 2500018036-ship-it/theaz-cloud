<?= $this->extend('layouts/user') ?>

<?php
$item = $item ?? [];
$versions = $versions ?? [];
$pager = $pager ?? ['page' => 1, 'total' => 0, 'totalPages' => 1];
$canManage = (bool) ($item['can_manage_versions'] ?? false);

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
    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp);
};

$basePath = site_url('drive/items/' . (int) ($item['id'] ?? 0) . '/versions');
$fileIcon = \App\Libraries\FileIcon::forItem($item);
?>

<?= $this->section('content') ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= esc(lang('UI.tc5617ee2f63a')) ?></h5>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <div class="avtar avtar-s bg-light-primary text-primary">
            <i class="<?= esc($fileIcon) ?> f-22"></i>
          </div>
          <div class="min-w-0">
            <h6 data-user-content class="mb-1 text-break"><?= esc((string) ($item['name'] ?? 'File')) ?></h6>
            <p class="text-muted mb-1"><?= esc($formatBytes((int) ($item['size_bytes'] ?? 0))) ?></p>
            <p class="text-muted mb-0"><?= esc((string) ($item['mime_type'] ?? '-')) ?></p>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <a href="<?= site_url('drive/items/' . (int) $item['id'] . '/download') ?>" class="btn btn-sm btn-light-primary">
            <i class="ti ti-download me-1"></i><?= esc(lang('UI.td6eafe823591')) ?>
          </a>
          <a href="<?= site_url('drive') ?>" class="btn btn-sm btn-light-secondary">
            <i class="ti ti-arrow-left me-1"></i><?= esc(lang('UI.t6312b4b9baf1')) ?>
          </a>
        </div>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0"><?= esc(lang('UI.tf4a0065c59c0')) ?></h5>
        </div>
        <div class="card-body">
          <form action="<?= esc($basePath) ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="mb-3">
              <input type="file" name="file" class="form-control" required>
              <div class="form-text"><?= esc(lang('UI.t50cccb6474fc')) ?></div>
            </div>
            <button type="submit" class="btn btn-primary">
              <i class="ti ti-upload me-1"></i><?= esc(lang('UI.tf1a97c9b45b2')) ?>
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div>
          <h5 class="mb-1"><?= esc(lang('UI.t2eba52c01705')) ?></h5>
          <p class="text-muted mb-0"><?= esc(lang('UI.tc4787533df4e')) ?></p>
        </div>
        <span class="badge bg-light-secondary"><?= esc((string) $pager['total']) ?></span>
      </div>
      <div class="card-body table-border-style">
        <?php if ($versions === []): ?>
          <?= view('components/ui/empty_state', [
              'icon' => 'ti ti-history',
              'title' => lang('UI.t94f7594f716f'),
              'message' => lang('UI.t7cb5d6d8fa8f'),
          ]) ?>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th><?= esc(lang('UI.tdd167905de0d')) ?></th>
                  <th><?= esc(lang('UI.t1af851907331')) ?></th>
                  <th><?= esc(lang('UI.td70b9e24bca2')) ?></th>
                  <th><?= esc(lang('UI.tb1a75baafbfa')) ?></th>
                  <th class="text-end"><?= esc(lang('UI.t64cff1319d2f')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($versions as $version): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold">v<?= esc((string) $version['version_number']) ?></div>
                      <div class="text-muted small text-break"><?= esc((string) $version['name']) ?></div>
                    </td>
                    <td><?= esc($formatBytes((int) $version['size_bytes'])) ?></td>
                    <td class="text-nowrap"><?= esc($formatDate($version['created_at'] ?? null)) ?></td>
                    <td><?= esc((string) ($version['created_by_username'] ?? $version['created_by_email'] ?? '-')) ?></td>
                    <td class="text-end">
                      <?php if ($canManage): ?>
                        <form action="<?= site_url('drive/items/' . (int) $item['id'] . '/versions/' . (int) $version['id'] . '/restore') ?>" method="post" class="d-inline">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-sm btn-light-primary">
                            <i class="ti ti-rotate-clockwise me-1"></i><?= esc(lang('UI.ta76e13b98392')) ?>
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small"><?= esc(lang('UI.t678bfa6af48b')) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span class="text-muted small"><?= esc((string) $pager['total']) ?> <?= esc(lang('UI.t0c63da471e98')) ?></span>
        <?php if ((int) $pager['totalPages'] > 1): ?>
          <ul class="pagination mb-0">
            <?php for ($page = 1; $page <= (int) $pager['totalPages']; $page++): ?>
              <li class="page-item<?= $page === (int) $pager['page'] ? ' active' : '' ?>">
                <a class="page-link" href="<?= esc($basePath . '?page=' . $page) ?>"><?= $page ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
