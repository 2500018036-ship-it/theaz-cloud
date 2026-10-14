<?= $this->extend('layouts/user') ?>

<?php
$notifications = $notifications ?? [];
$pager = $pager ?? ['page' => 1, 'totalPages' => 1, 'total' => 0];
$query = $query ?? ['status' => '', 'category' => ''];
$basePath = site_url('notifications');
$urlWith = static function (array $overrides = []) use ($basePath, $query): string {
    $params = array_filter(array_merge($query, $overrides), static fn ($value): bool => $value !== null && $value !== '');

    return $basePath . ($params === [] ? '' : '?' . http_build_query($params));
};
$formatDate = static function ($value): string {
    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp);
};
?>

<?= $this->section('content') ?>
<div class="card">
  <div class="card-header">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
      <h5 class="mb-0"><?= esc(lang('UI.t7a0e40785283')) ?></h5>
      <form action="<?= site_url('notifications/read-all') ?>" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-light-primary"><i class="ti ti-checks me-1"></i><?= esc(lang('UI.tdb70516ae9c1')) ?></button>
      </form>
    </div>
    <form action="<?= esc($basePath) ?>" method="get" class="row g-2 mt-2">
      <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
          <option value=""><?= esc(lang('UI.t32b9b2c4de19')) ?></option>
          <option value="unread"<?= $query['status'] === 'unread' ? ' selected' : '' ?>><?= esc(lang('UI.t1b9f384c1436')) ?></option>
          <option value="read"<?= $query['status'] === 'read' ? ' selected' : '' ?>><?= esc(lang('UI.t9b9a8d05a7ec')) ?></option>
        </select>
      </div>
      <div class="col-md-3">
        <select name="category" class="form-select form-select-sm">
          <option value=""><?= esc(lang('UI.t9d5097a83710')) ?></option>
          <?php foreach (['announcement', 'sharing', 'security'] as $category): ?>
            <option value="<?= esc($category) ?>"<?= $query['category'] === $category ? ' selected' : '' ?>><?= esc(ucfirst($category)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i><?= esc(lang('UI.t638e249f4a15')) ?></button>
        <a href="<?= esc($basePath) ?>" class="btn btn-sm btn-light-secondary"><i class="ti ti-x me-1"></i><?= esc(lang('UI.t83b12c2216ef')) ?></a>
      </div>
    </form>
  </div>
  <div class="card-body p-0">
    <?php if ($notifications === []): ?>
      <div class="p-3">
        <?= view('components/ui/empty_state', [
            'icon' => 'ti ti-bell-off',
            'title' => lang('UI.tcbce2040cca5'),
            'message' => lang('UI.tb572e8f6a9f3'),
        ]) ?>
      </div>
    <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($notifications as $notice): ?>
          <div class="list-group-item">
            <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
              <div class="flex-grow-1">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <h6 data-user-content class="mb-0"><?= esc($notice['title']) ?></h6>
                  <span class="badge bg-light-secondary text-secondary"><?= esc($notice['category']) ?></span>
                  <?php if (empty($notice['read_at'])): ?>
                    <span class="badge bg-light-primary text-primary"><?= esc(lang('UI.t1b9f384c1436')) ?></span>
                  <?php endif; ?>
                </div>
                <p data-user-content class="text-muted mb-1"><?= esc((string) ($notice['body'] ?? '')) ?></p>
                <span class="text-muted small"><?= esc($formatDate($notice['created_at'] ?? null)) ?></span>
              </div>
              <div class="d-flex gap-2">
                <?php if (! empty($notice['action_url'])): ?>
                  <a class="btn btn-sm btn-light-primary" href="<?= esc((string) $notice['action_url']) ?>"><i class="ti ti-external-link me-1"></i><?= esc(lang('UI.ted077f3d8125')) ?></a>
                <?php endif; ?>
                <?php if (empty($notice['read_at'])): ?>
                  <form action="<?= site_url('notifications/' . $notice['id'] . '/read') ?>" method="post">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-light-secondary"><i class="ti ti-check me-1"></i><?= esc(lang('UI.t9b9a8d05a7ec')) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if (($pager['totalPages'] ?? 1) > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <span class="text-muted small"><?= esc(lang('UI.t0a30a815d67d')) ?> <?= esc((string) $pager['page']) ?> <?= esc(lang('UI.t28391d3bc64e')) ?> <?= esc((string) $pager['totalPages']) ?></span>
      <div class="btn-group">
        <a class="btn btn-light<?= $pager['page'] <= 1 ? ' disabled' : '' ?>" href="<?= esc($urlWith(['page' => max(1, $pager['page'] - 1)])) ?>"><i class="ti ti-chevron-left"></i></a>
        <a class="btn btn-light<?= $pager['page'] >= $pager['totalPages'] ? ' disabled' : '' ?>" href="<?= esc($urlWith(['page' => min($pager['totalPages'], $pager['page'] + 1)])) ?>"><i class="ti ti-chevron-right"></i></a>
      </div>
    </div>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
