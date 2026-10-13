<?= $this->extend('layouts/admin') ?>

<?php
$storage = $storage ?? [];
$drive = $drive ?? [];
$users = $users ?? [];
$pager = $pager ?? ['page' => 1, 'total' => 0, 'totalPages' => 1];

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
?>

<?= $this->section('content') ?>
<div class="row">
  <div class="col-md-6 col-xl-3">
    <div class="card"><div class="card-body"><h6 class="text-muted">Used Quota</h6><h4><?= esc($formatBytes((int) ($storage['used_bytes'] ?? 0))) ?></h4><p class="mb-0 text-muted"><?= esc((string) ($storage['quota_percentage'] ?? 0)) ?>% assigned</p></div></div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card"><div class="card-body"><h6 class="text-muted">Assigned Quota</h6><h4><?= esc($formatBytes((int) ($storage['quota_bytes'] ?? 0))) ?></h4><p class="mb-0 text-muted"><?= esc($formatBytes((int) ($storage['quota_available'] ?? 0))) ?> available</p></div></div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card"><div class="card-body"><h6 class="text-muted">Disk Free</h6><h4><?= esc($formatBytes($storage['disk_free_bytes'] ?? null)) ?></h4><p class="mb-0 text-muted"><?= esc((string) ($storage['disk_percentage'] ?? 'N/A')) ?>% disk used</p></div></div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card"><div class="card-body"><h6 class="text-muted">Files</h6><h4><?= esc((string) ($drive['files'] ?? 0)) ?></h4><p class="mb-0 text-muted"><?= esc((string) ($drive['versions'] ?? 0)) ?> retained versions</p></div></div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h5 class="mb-1">Private Storage</h5>
    <p class="text-muted mb-0"><?= esc((string) ($storage['storage_root'] ?? 'Unavailable')) ?></p>
  </div>
  <div class="card-body">
    <?php if (($storage['disk_percentage'] ?? 0) !== null && (float) ($storage['disk_percentage'] ?? 0) >= (float) ($storage['warning_threshold'] ?? 85)): ?>
      <div class="alert alert-warning">Filesystem usage is above the configured warning threshold.</div>
    <?php endif; ?>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Writable</div>
          <span class="badge bg-light-<?= ! empty($storage['storage_writable']) ? 'success text-success' : 'danger text-danger' ?>"><?= ! empty($storage['storage_writable']) ? 'Yes' : 'No' ?></span>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Disk total</div>
          <div class="fw-semibold"><?= esc($formatBytes($storage['disk_total_bytes'] ?? null)) ?></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Application-managed file bytes</div>
          <div class="fw-semibold"><?= esc($formatBytes((int) ($storage['app_managed_bytes'] ?? 0))) ?></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Measured at</div>
          <div class="fw-semibold"><?= esc((string) ($storage['measured_at'] ?? '-')) ?></div>
        </div>
      </div>
    </div>
    <p class="text-muted small mb-0 mt-3">Filesystem capacity, application-managed file bytes, allocated user quota, and per-user usage are different measurements. This page reads filesystem capacity on page load and uses database metadata for application usage; it does not scan the full disk or poll continuously.</p>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h5 class="mb-0">User Storage</h5>
  </div>
  <div class="card-body table-border-style">
    <?php if ($users === []): ?>
      <?= view('components/ui/empty_state', [
          'icon' => 'ti ti-server',
          'title' => 'No storage users',
          'message' => 'Users will appear here after accounts are created.',
      ]) ?>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>User</th><th>Used</th><th>Quota</th><th>Usage</th><th class="text-end">Detail</th></tr></thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <?php
                $used = (int) $user['storage_used_bytes'];
                $quota = (int) $user['storage_quota_bytes'];
                $percent = $quota > 0 ? min(100, round(($used / $quota) * 100, 2)) : 0;
              ?>
              <tr>
                <td><a href="<?= site_url('admin/users/' . $user['id']) ?>" class="fw-semibold"><?= esc((string) $user['username']) ?></a><div class="text-muted small"><?= esc((string) $user['email']) ?></div></td>
                <td><?= esc($formatBytes($used)) ?></td>
                <td><?= esc($formatBytes($quota)) ?></td>
                <td><div class="progress" style="height:6px"><div class="progress-bar" style="width: <?= esc((string) $percent) ?>%"></div></div><div class="small text-muted mt-1"><?= esc((string) $percent) ?>%</div></td>
                <td class="text-end"><a href="<?= site_url('admin/users/' . $user['id']) ?>" class="btn btn-sm btn-light-primary"><i class="ti ti-eye me-1"></i>View</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <span class="text-muted small"><?= esc((string) $pager['total']) ?> user(s)</span>
    <?php if ((int) $pager['totalPages'] > 1): ?>
      <ul class="pagination mb-0">
        <?php for ($page = 1; $page <= (int) $pager['totalPages']; $page++): ?>
          <li class="page-item<?= $page === (int) $pager['page'] ? ' active' : '' ?>"><a class="page-link" href="<?= site_url('admin/storage?page=' . $page) ?>"><?= $page ?></a></li>
        <?php endfor; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?= $this->endSection() ?>
