<?= $this->extend(session('role') === 'super_admin' ? 'layouts/admin' : 'layouts/user') ?>
<?= $this->section('content') ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 data-user-content class="mb-0"><?= esc($notice['title']) ?></h5>
    <a data-cloud-back href="<?= site_url('notifications') ?>" class="btn btn-sm btn-light-secondary"><?= esc(lang('App.header.notifications')) ?></a>
  </div>
  <div class="card-body">
    <p data-user-content style="white-space:pre-wrap"><?= esc($notice['body']) ?></p>
    <p class="text-muted small"><?= esc($notice['created_at']) ?></p>
    <?php if (empty($notice['read_at'])): ?>
      <form action="<?= site_url('notifications/' . $notice['id'] . '/read') ?>" method="post" class="d-inline">
        <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-primary"><?= esc(lang('UI.t9b9a8d05a7ec')) ?></button>
      </form>
    <?php endif; ?>
    <?php
      $action = (string) ($notice['action_url'] ?? '');
      $base = rtrim(site_url(), '/');
      $safeAction = str_starts_with($action, $base . '/drive') || str_starts_with($action, $base . '/settings');
    ?>
    <?php if ($safeAction): ?>
      <a href="<?= esc($action) ?>" class="btn btn-sm btn-light-primary"><?= esc(lang('UI.ted077f3d8125')) ?></a>
    <?php endif; ?>
  </div>
</div>
<?= $this->endSection() ?>
