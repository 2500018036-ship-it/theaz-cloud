<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$summary = $summary ?? [];
$users = $summary['users'] ?? ['total' => 0, 'active' => 0, 'inactive' => 0, 'admins' => 0, 'super_admins' => 0];
$drive = $summary['drive'] ?? ['files' => 0, 'folders' => 0, 'versions' => 0];
$storage = $summary['storage'] ?? ['used_bytes' => 0, 'quota_bytes' => 0, 'disk_free_bytes' => null, 'disk_total_bytes' => null, 'quota_percentage' => 0];
$invitations = $summary['invitations'] ?? ['active' => 0, 'consumed' => 0, 'revoked' => 0, 'expired' => 0];
$health = $summary['health'] ?? [];
$activity = $summary['activity'] ?? [];
$failures = $summary['failures'] ?? ['count' => 0, 'hours' => 24];
$quotaUsers = $summary['quota_users'] ?? [];

$formatBytes = static function (?int $bytes): string {
    if ($bytes === null) {
        return 'N/A';
    }

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
?>

<div class="row">
  <div class="col-md-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted">Users</h6>
            <h4 class="mb-0"><?= esc((string) $users['total']) ?></h4>
          </div>
          <div class="avtar bg-light-primary text-primary"><i class="ti ti-users f-22"></i></div>
        </div>
        <p class="text-muted mb-0 mt-3"><?= esc((string) $users['active']) ?> active, <?= esc((string) $users['admins']) ?> admins, <?= esc((string) ($users['super_admins'] ?? 0)) ?> super</p>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted">Drive Items</h6>
            <h4 class="mb-0"><?= esc((string) ((int) $drive['files'] + (int) $drive['folders'])) ?></h4>
          </div>
          <div class="avtar bg-light-success text-success"><i class="ti ti-folder f-22"></i></div>
        </div>
        <p class="text-muted mb-0 mt-3"><?= esc((string) $drive['files']) ?> files, <?= esc((string) $drive['folders']) ?> folders</p>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted">Storage Used</h6>
            <h4 class="mb-0"><?= esc($formatBytes((int) $storage['used_bytes'])) ?></h4>
          </div>
          <div class="avtar bg-light-warning text-warning"><i class="ti ti-database f-22"></i></div>
        </div>
        <p class="text-muted mb-0 mt-3"><?= esc((string) $storage['quota_percentage']) ?>% of assigned quota</p>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted">Failures</h6>
            <h4 class="mb-0"><?= esc((string) $failures['count']) ?></h4>
          </div>
          <div class="avtar bg-light-danger text-danger"><i class="ti ti-alert-circle f-22"></i></div>
        </div>
        <p class="text-muted mb-0 mt-3">Last <?= esc((string) $failures['hours']) ?> hours</p>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div>
          <h5 class="mb-1">User Storage Quotas</h5>
          <p class="text-muted mb-0">Upgrade quota per user from the dashboard.</p>
        </div>
        <a href="<?= site_url('admin/users') ?>" class="btn btn-sm btn-light-primary"><i class="ti ti-users me-1"></i>All Users</a>
      </div>
      <div class="card-body table-border-style">
        <?php if ($quotaUsers === []): ?>
          <?= view('components/ui/empty_state', [
              'icon' => 'ti ti-database',
              'title' => 'No users found',
              'message' => 'Storage quota controls will appear after users are created.',
          ]) ?>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Status</th>
                  <th>Usage</th>
                  <th class="text-end">Upgrade Quota</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($quotaUsers as $quotaUser): ?>
                  <?php
                    $used = (int) ($quotaUser['storage_used_bytes'] ?? 0);
                    $quota = (int) ($quotaUser['storage_quota_bytes'] ?? 0);
                    $percentage = $quota > 0 ? min(100, round(($used / $quota) * 100, 2)) : 0;
                    $quotaGb = $quota > 0 ? round($quota / 1073741824, 2) : 0;
                    $role = (string) ($quotaUser['role'] ?? 'user');
                    $roleLabel = ucwords(str_replace('_', ' ', $role));
                  ?>
                  <tr>
                    <td>
                      <a href="<?= site_url('admin/users/' . $quotaUser['id']) ?>" class="fw-semibold"><?= esc((string) $quotaUser['username']) ?></a>
                      <div class="text-muted small"><?= esc((string) $quotaUser['email']) ?></div>
                    </td>
                    <td>
                      <span class="badge bg-light-secondary text-secondary"><?= esc($roleLabel) ?></span>
                      <span class="badge bg-light-<?= ($quotaUser['status'] ?? '') === 'active' ? 'success text-success' : 'warning text-warning' ?>"><?= esc(ucfirst((string) ($quotaUser['status'] ?? 'inactive'))) ?></span>
                    </td>
                    <td style="min-width: 220px">
                      <div class="d-flex justify-content-between small mb-1">
                        <span><?= esc($formatBytes($used)) ?></span>
                        <span><?= esc($formatBytes($quota)) ?></span>
                      </div>
                      <div class="progress" style="height: 6px">
                        <div class="progress-bar" style="width: <?= esc((string) $percentage) ?>%"></div>
                      </div>
                    </td>
                    <td class="text-end">
                      <form action="<?= site_url('admin/users/' . $quotaUser['id'] . '/quota') ?>" method="post" class="d-inline-flex gap-2 justify-content-end align-items-center" data-admin-confirm="Update this user's storage quota?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="dashboard">
                        <input type="number" step="0.01" min="0" name="quota_value" class="form-control form-control-sm" value="<?= esc((string) $quotaGb) ?>" style="width: 110px" aria-label="Quota value">
                        <select name="quota_unit" class="form-select form-select-sm" style="width: 86px" aria-label="Quota unit">
                          <option value="gb">GB</option>
                          <option value="mb">MB</option>
                          <option value="tb">TB</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-light-warning">
                          <i class="ti ti-arrow-up me-1"></i>Upgrade
                        </button>
                      </form>
                    </td>
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

<div class="row">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h5 class="mb-0">Recent Activity</h5>
        <a href="<?= site_url('admin/audit') ?>" class="btn btn-sm btn-light-primary"><i class="ti ti-clipboard-list me-1"></i>Audit Log</a>
      </div>
      <div class="card-body table-border-style">
        <?php if ($activity === []): ?>
          <?= view('components/ui/empty_state', [
              'icon' => 'ti ti-activity',
              'title' => 'No activity yet',
              'message' => 'Activity will appear here as the workspace is used.',
          ]) ?>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Status</th>
                </tr>
              </thead>
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
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">System Health</h5>
      </div>
      <div class="card-body">
        <?php foreach (['database' => 'Database', 'storage' => 'Private Storage'] as $key => $label): ?>
          <?php $entry = $health[$key] ?? ['ok' => false, 'message' => 'Unavailable']; ?>
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <div class="fw-semibold"><?= esc($label) ?></div>
              <div class="text-muted small"><?= esc((string) $entry['message']) ?></div>
            </div>
            <span class="badge bg-light-<?= ! empty($entry['ok']) ? 'success text-success' : 'danger text-danger' ?>"><?= ! empty($entry['ok']) ? 'OK' : 'Issue' ?></span>
          </div>
        <?php endforeach; ?>
        <div class="border-top pt-3">
          <div class="text-muted small">Application</div>
          <div class="fw-semibold"><?= esc((string) ($health['application']['name'] ?? 'Theaz Cloud')) ?></div>
          <div class="text-muted small">CI <?= esc((string) ($health['application']['version'] ?? 'Unknown')) ?>, PHP <?= esc((string) ($health['application']['php'] ?? PHP_VERSION)) ?></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Storage Capacity</h5>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Assigned quota</span>
          <span><?= esc($formatBytes((int) $storage['quota_bytes'])) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Disk free</span>
          <span><?= esc($formatBytes($storage['disk_free_bytes'] ?? null)) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-3">
          <span class="text-muted">Disk total</span>
          <span><?= esc($formatBytes($storage['disk_total_bytes'] ?? null)) ?></span>
        </div>
        <a href="<?= site_url('admin/storage') ?>" class="btn btn-sm btn-light-primary"><i class="ti ti-server me-1"></i>Storage Detail</a>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-md-6 col-xl-3">
    <a href="<?= site_url('admin/users') ?>" class="card text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2"><i class="ti ti-users text-primary"></i><span class="fw-semibold">Manage Users</span></div>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-xl-3">
    <a href="<?= site_url('admin/invitations') ?>" class="card text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2"><i class="ti ti-ticket text-success"></i><span class="fw-semibold">Invitations</span></div>
        <p class="text-muted mb-0 mt-2"><?= esc((string) $invitations['active']) ?> active</p>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-xl-3">
    <a href="<?= site_url('admin/storage') ?>" class="card text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2"><i class="ti ti-server text-warning"></i><span class="fw-semibold">Storage</span></div>
        <p class="text-muted mb-0 mt-2"><?= esc((string) $drive['versions']) ?> retained versions</p>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-xl-3">
    <a href="<?= site_url('admin/audit') ?>" class="card text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2"><i class="ti ti-clipboard-list text-danger"></i><span class="fw-semibold">Audit</span></div>
      </div>
    </a>
  </div>
</div>
<?= $this->endSection() ?>
