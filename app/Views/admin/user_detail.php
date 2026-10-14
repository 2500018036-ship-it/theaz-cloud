<?= $this->extend('layouts/admin') ?>

<?php
$detail = $detail ?? [];
$user = $detail['user'] ?? [];
$items = $detail['items'] ?? ['total' => 0, 'files' => 0, 'folders' => 0, 'trashed' => 0];
$shares = $detail['shares'] ?? ['shared_by_user' => 0, 'shared_to_user' => 0];
$activity = $detail['activity'] ?? [];
$isSuperAdmin = (string) session('role') === 'super_admin';

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

$statusLabel = static fn (string $status): string => ucwords(str_replace('_', ' ', $status));
$statusBadge = static fn (string $status): string => match ($status) {
    'active' => 'success text-success',
    'pending_verification' => 'info text-info',
    'suspended' => 'danger text-danger',
    default => 'warning text-warning',
};

$quotaGb = ((int) ($user['storage_quota_bytes'] ?? 0)) / 1073741824;
$role = (string) ($user['role'] ?? 'user');
$roleLabel = ucwords(str_replace('_', ' ', $role));
$roleBadge = match ($role) {
    'super_admin' => 'danger text-danger',
    'admin' => 'primary text-primary',
    default => 'secondary text-secondary',
};
$canChangeStatus = $role !== 'super_admin';
$canChangeRole = $isSuperAdmin && (int) ($user['id'] ?? 0) !== (int) session('user_id');
$isStorageUser = $role === 'user';
?>

<?= $this->section('content') ?>
<div class="row">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= esc(lang('UI.t7e1b0d5641f2')) ?></h5>
      </div>
      <div class="card-body">
        <h5 class="mb-1"><?= esc((string) ($user['username'] ?? 'User')) ?></h5>
        <p class="text-muted"><?= esc((string) ($user['email'] ?? '-')) ?></p>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <span class="badge bg-light-<?= esc($roleBadge) ?>"><?= esc($roleLabel) ?></span>
          <span class="badge bg-light-<?= esc($statusBadge((string) ($user['status'] ?? 'inactive'))) ?>"><?= esc($statusLabel((string) ($user['status'] ?? 'inactive'))) ?></span>
        </div>
        <div class="text-muted small"><?= esc(lang('UI.td70b9e24bca2')) ?></div>
        <div class="mb-2"><?= esc($formatDate($user['created_at'] ?? null)) ?></div>
        <div class="text-muted small"><?= esc(lang('UI.tb953216efc4b')) ?></div>
        <div><?= esc($formatDate($user['last_login_at'] ?? null)) ?></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= esc(lang('UI.t799c26913574')) ?></h5>
      </div>
      <div class="card-body">
        <?php if ($canChangeStatus): ?>
          <form action="<?= site_url('admin/users/' . (int) $user['id'] . '/status') ?>" method="post" class="mb-3" data-admin-confirm="Change this user's status?">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="detail">
            <label class="form-label"><?= esc(lang('UI.t920e413c7d41')) ?></label>
            <div class="d-flex gap-2">
              <select name="status" class="form-select">
                <?php foreach (['active', 'inactive'] as $option): ?>
                  <option value="<?= esc($option) ?>"<?= ($user['status'] ?? '') === $option ? ' selected' : '' ?>><?= esc($statusLabel($option)) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= esc(lang('UI.t1509f561f241')) ?></button>
            </div>
          </form>
        <?php else: ?>
          <p class="text-muted mb-3"><?= esc(lang('UI.tc3d0157f1e68')) ?></p>
        <?php endif; ?>
        <?php if ($isStorageUser): ?>
          <form action="<?= site_url('admin/users/' . (int) $user['id'] . '/quota') ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="detail">
            <label class="form-label"><?= esc(lang('UI.t6c105cae1a20')) ?></label>
            <div class="d-flex gap-2">
              <input type="number" step="0.01" min="0" name="quota_value" class="form-control" value="<?= esc((string) round($quotaGb, 2)) ?>">
              <select name="quota_unit" class="form-select" style="max-width: 100px">
                <option value="gb">GB</option>
                <option value="mb">MB</option>
                <option value="tb">TB</option>
              </select>
              <button type="submit" class="btn btn-light-primary"><i class="ti ti-database-plus me-1"></i><?= esc(lang('UI.t1509f561f241')) ?></button>
            </div>
          </form>
        <?php else: ?>
          <div class="alert alert-light-info mb-0">
            <?= esc(lang('UI.t6d598c30ec47')) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="row">
      <?php foreach ([
          ['Files', $items['files'], 'ti ti-file'],
          ['Folders', $items['folders'], 'ti ti-folder'],
          ['Trash', $items['trashed'], 'ti ti-trash'],
          ['Shares', (int) $shares['shared_by_user'] + (int) $shares['shared_to_user'], 'ti ti-share'],
      ] as $metric): ?>
        <div class="col-md-6 col-xl-3">
          <div class="card">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <h6 class="mb-2 f-w-400 text-muted"><?= esc($metric[0]) ?></h6>
                  <h4 class="mb-0"><?= esc((string) $metric[1]) ?></h4>
                </div>
                <div class="avtar bg-light-secondary text-secondary"><i class="<?= esc($metric[2]) ?> f-22"></i></div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= esc(lang('UI.ta69c4dece144')) ?></h5>
      </div>
      <div class="card-body">
        <?php
          $used = (int) ($user['storage_used_bytes'] ?? 0);
          $quota = (int) ($user['storage_quota_bytes'] ?? 0);
          $percent = $quota > 0 ? min(100, round(($used / $quota) * 100, 2)) : 0;
        ?>
        <?php if ($isStorageUser): ?>
          <div class="d-flex justify-content-between mb-2">
            <span><?= esc($formatBytes($used)) ?> <?= esc(lang('UI.tf83916135509')) ?></span>
            <span><?= esc($formatBytes($quota)) ?> <?= esc(lang('UI.tb878a6801d9a')) ?></span>
          </div>
          <div class="progress">
            <div class="progress-bar" style="width: <?= esc((string) $percent) ?>%"></div>
          </div>
        <?php else: ?>
          <div class="alert alert-light-info mb-0">
            <?= esc(lang('UI.t6487da0b086f')) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= esc(lang('UI.t9ef7d438ce5b')) ?></h5>
      </div>
      <div class="card-body table-border-style">
        <?php if ($activity === []): ?>
          <?= view('components/ui/empty_state', [
              'icon' => 'ti ti-activity',
              'title' => lang('UI.t0cf9505f9f97'),
              'message' => lang('UI.ta651fbe4a02b'),
          ]) ?>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th><?= esc(lang('UI.t33b93476cf59')) ?></th><th><?= esc(lang('UI.t449995c4fe8f')) ?></th><th><?= esc(lang('UI.t64cff1319d2f')) ?></th><th><?= esc(lang('UI.t920e413c7d41')) ?></th></tr></thead>
              <tbody>
                <?php foreach ($activity as $log): ?>
                  <tr>
                    <td class="text-nowrap"><?= esc($formatDate($log['created_at'] ?? null)) ?></td>
                    <td><?= esc((string) ($log['actor_username'] ?? $log['actor_email'] ?? 'Public / System')) ?></td>
                    <td><?= esc(ucwords(str_replace(['.', '_'], ' ', (string) $log['action']))) ?></td>
                    <td><span class="badge bg-light-<?= ($log['status'] ?? '') === 'failure' ? 'danger text-danger' : 'success text-success' ?>"><?= esc(ucfirst((string) $log['status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
