<?= $this->extend('layouts/admin') ?>

<?php
$announcements = $announcements ?? [];
$users = $users ?? [];
$pager = $pager ?? ['page' => 1, 'totalPages' => 1, 'total' => 0];
$query = $query ?? ['status' => ''];
$basePath = site_url('admin/announcements');
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
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= esc(lang('UI.t377884d2c62a')) ?></h5>
      </div>
      <form action="<?= site_url('admin/announcements') ?>" method="post" class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="request_key" value="<?= esc((string) (old('request_key') ?: bin2hex(random_bytes(32)))) ?>">
        <div class="mb-3">
          <label class="form-label" for="announcement-title"><?= esc(lang('UI.t7e8cd2056da7')) ?></label>
          <input type="text" class="form-control" id="announcement-title" name="title" maxlength="190" value="<?= esc(old('title')) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="announcement-body"><?= esc(lang('UI.t2f77668a9dfb')) ?></label>
          <textarea class="form-control" id="announcement-body" name="body" rows="5" maxlength="4000" required><?= esc(old('body')) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label" for="announcement-channels"><?= esc(lang('UI.t4c8906cf76f5')) ?></label>
          <select class="form-select" id="announcement-channels" name="channels">
            <option value="notification"><?= esc(lang('UI.t7d31b8331399')) ?></option>
            <option value="email"><?= esc(lang('UI.t621f30a05932')) ?></option>
            <option value="notification,email"><?= esc(lang('UI.te4bc37cdf27e')) ?></option>
          </select>
          <p class="form-text"><?= esc(lang('App.actions.emailPreference')) ?></p>
        </div>
        <div class="mb-3">
          <label class="form-label" for="announcement-target"><?= esc(lang('UI.t978354db0c00')) ?></label>
          <select class="form-select" id="announcement-target" name="target_type">
            <option value="all"><?= esc(lang('UI.tf31880b8f48b')) ?></option>
            <option value="user"><?= esc(lang('UI.tf71658f4bbc6')) ?></option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="announcement-user"><?= esc(lang('UI.tf71658f4bbc6')) ?></label>
          <select class="form-select" id="announcement-user" name="target_user_id">
            <option value=""><?= esc(lang('UI.t217e627e4fb4')) ?></option>
            <?php foreach ($users as $user): ?>
              <option value="<?= esc((string) $user['id']) ?>"><?= esc($user['username'] . ' <' . $user['email'] . '>') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="publish-now" name="publish_now" value="1">
          <label class="form-check-label" for="publish-now"><?= esc(lang('UI.tbf74ddc5486e')) ?></label>
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-device-floppy me-1"></i><?= esc(lang('UI.t329ec7701625')) ?></button>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
          <h5 class="mb-0"><?= esc(lang('UI.tfe02680f2479')) ?></h5>
          <form action="<?= esc($basePath) ?>" method="get" class="d-flex gap-2">
            <input type="search" name="q" class="form-control form-control-sm" value="<?= esc($query['q'] ?? '') ?>" placeholder="<?= esc(lang('UI.t054efefc16c2'), 'attr') ?>">
            <select name="status" class="form-select form-select-sm">
              <option value=""><?= esc(lang('UI.ta52ace420f21')) ?></option>
              <?php foreach (['draft', 'published', 'archived'] as $status): ?>
                <option value="<?= esc($status) ?>"<?= $query['status'] === $status ? ' selected' : '' ?>><?= esc(ucfirst($status)) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i><?= esc(lang('UI.t638e249f4a15')) ?></button>
          </form>
        </div>
      </div>
      <div class="card-body table-border-style">
        <?= view('components/app/bulk_toolbar', ['id' => 'announcement-bulk', 'action' => site_url('admin/announcements/bulk'),
            'options' => ['delete' => lang('UI.te2d0a54968ea'), 'archive' => lang('UI.t66f4804ee23d')]]) ?>
        <?php if ($announcements === []): ?>
          <?= view('components/ui/empty_state', [
              'icon' => 'ti ti-speakerphone',
              'title' => lang('UI.t8762f38ae3d5'),
              'message' => lang('UI.t7f973c97a56d'),
          ]) ?>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th><?= esc(lang('App.actions.select')) ?></th>
                  <th><?= esc(lang('UI.t7e8cd2056da7')) ?></th>
                  <th><?= esc(lang('UI.t920e413c7d41')) ?></th>
                  <th><?= esc(lang('UI.t978354db0c00')) ?></th>
                  <th><?= esc(lang('UI.t4c8906cf76f5')) ?></th>
                  <th><?= esc(lang('UI.t2ef42ec21d93')) ?></th>
                  <th class="text-end"><?= esc(lang('UI.t64cff1319d2f')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($announcements as $announcement): ?>
                  <tr>
                    <td><input class="form-check-input" type="checkbox" id="announcement-select-<?= (int) $announcement['id'] ?>" data-select-item data-select-actions="delete archive" form="announcement-bulk" name="selected[]" value="<?= (int) $announcement['id'] ?>" aria-label="<?= esc(lang('App.actions.select'), 'attr') ?>"></td>
                    <td>
                      <div class="fw-semibold"><?= esc($announcement['title']) ?></div>
                      <div class="text-muted small text-truncate" style="max-width:280px"><?= esc($announcement['body']) ?></div>
                    </td>
                    <td><span class="badge bg-light-secondary text-secondary"><?= esc(lang('App.actions.' . $announcement['status'])) ?></span></td>
                    <td><?= esc($announcement['target_type'] === 'user' ? (($announcement['target_username'] ?? 'User') . ' <' . ($announcement['target_email'] ?? '') . '>') : 'All active users') ?></td>
                    <td>
                      <div><?= esc($announcement['channels']) ?></div>
                      <div class="text-muted small">N: <?= esc($announcement['notification_status']) ?></div>
                      <div class="small" data-delivery-id="<?= (int) $announcement['id'] ?>" data-delivery-pending="<?= $announcement['email_status'] === 'queued' ? '1' : '0' ?>"><?= esc(lang('App.actions.delivery')) ?>: <?= esc(lang('App.actions.' . ($announcement['status'] === 'draft' ? 'pending' : $announcement['delivery']['status']))) ?> (<?= (int) $announcement['delivery']['sent'] ?>/<?= (int) $announcement['delivery']['total'] ?>)</div>
                      <?php if ($announcement['delivery']['error']): ?><div class="text-danger small"><?= esc(lang($announcement['delivery']['failed'] > 0 ? 'App.actions.emailFailed' : 'App.actions.emailRetry')) ?></div><?php endif; ?>
                      <?php if ($announcement['email_status'] === 'skipped'): ?><div class="text-muted small"><?= esc(lang('App.actions.noEmailRecipients')) ?></div><?php endif; ?>
                    </td>
                    <td><?= esc($formatDate($announcement['published_at'] ?? null)) ?></td>
                    <td class="text-end">
                      <div class="d-inline-flex gap-2">
                        <form action="<?= site_url('admin/announcements/' . $announcement['id'] . '/delete') ?>" method="post" data-confirm="Delete this announcement? Delivery history will be retained.">
                          <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-light-danger"><?= esc(lang('UI.te2d0a54968ea')) ?></button>
                        </form>
                        <?php if ($announcement['status'] === 'draft'): ?>
                          <form action="<?= site_url('admin/announcements/' . $announcement['id'] . '/publish') ?>" method="post">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-light-primary"><i class="ti ti-send me-1"></i><?= esc(lang('UI.t859390eb495b')) ?></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($announcement['status'] !== 'archived'): ?>
                          <form action="<?= site_url('admin/announcements/' . $announcement['id'] . '/archive') ?>" method="post" data-admin-confirm="Archive this announcement?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-light-secondary"><i class="ti ti-archive me-1"></i><?= esc(lang('UI.t66f4804ee23d')) ?></button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
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
  </div>
</div>
<?= $this->endSection() ?>
