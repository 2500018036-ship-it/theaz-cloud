<?= $this->extend('layouts/admin') ?>

<?php
$users = $users ?? [];
$pager = $pager ?? ['page' => 1, 'total' => 0, 'totalPages' => 1];
$query = $query ?? ['q' => '', 'role' => '', 'status' => ''];
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

$urlWith = static function (array $overrides = []) use ($query): string {
    $params = array_merge($query, $overrides);
    $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

    return site_url('admin/users') . ($params === [] ? '' : '?' . http_build_query($params));
};
?>

<?= $this->section('content') ?>
<div class="card">
  <div class="card-header">
    <div class="row g-3 align-items-end">
      <div class="col-lg-4">
        <h5 class="mb-1">Users</h5>
        <p class="text-muted mb-0">Manage status, role, and quota from backend-validated actions.</p>
      </div>
      <div class="col-lg-8">
        <form action="<?= site_url('admin/users') ?>" method="get" class="row g-2 justify-content-lg-end">
          <div class="col-md-4">
            <input type="search" name="q" class="form-control form-control-sm" value="<?= esc($query['q'] ?? '') ?>" placeholder="Search username or email">
          </div>
          <div class="col-md-3">
            <select name="role" class="form-select form-select-sm">
              <option value="">All roles</option>
              <option value="super_admin"<?= ($query['role'] ?? '') === 'super_admin' ? ' selected' : '' ?>>Super Admin</option>
              <option value="admin"<?= ($query['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Admin</option>
              <option value="user"<?= ($query['role'] ?? '') === 'user' ? ' selected' : '' ?>>User</option>
            </select>
          </div>
          <div class="col-md-3">
            <select name="status" class="form-select form-select-sm">
              <option value="">All status</option>
              <option value="active"<?= ($query['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
              <option value="pending_verification"<?= ($query['status'] ?? '') === 'pending_verification' ? ' selected' : '' ?>>Pending verification</option>
              <option value="inactive"<?= ($query['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
              <option value="suspended"<?= ($query['status'] ?? '') === 'suspended' ? ' selected' : '' ?>>Suspended</option>
            </select>
          </div>
          <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Apply</button>
            <a href="<?= site_url('admin/users') ?>" class="btn btn-sm btn-light-secondary"><i class="ti ti-x me-1"></i>Clear</a>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="card-body table-border-style">
    <?php if ($users === []): ?>
      <?= view('components/ui/empty_state', [
          'icon' => 'ti ti-users',
          'title' => 'No users found',
          'message' => 'Try changing the filter or search term.',
      ]) ?>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>User</th>
              <th>Status</th>
              <th>Role</th>
              <th>Storage</th>
              <th>Last Login</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <?php
                $status = (string) $user['status'];
                $role = (string) $user['role'];
                $used = (int) $user['storage_used_bytes'];
                $quota = (int) $user['storage_quota_bytes'];
                $percentage = $quota > 0 ? min(100, round(($used / $quota) * 100, 2)) : 0;
                $roleLabel = ucwords(str_replace('_', ' ', $role));
                $roleBadge = match ($role) {
                    'super_admin' => 'danger text-danger',
                    'admin' => 'primary text-primary',
                    default => 'secondary text-secondary',
                };
                $canChangeStatus = $role !== 'super_admin' || $isSuperAdmin;
                $canChangeRole = $isSuperAdmin && (int) $user['id'] !== (int) session('user_id');
              ?>
              <tr>
                <td>
                  <a href="<?= site_url('admin/users/' . $user['id']) ?>" class="fw-semibold"><?= esc((string) $user['username']) ?></a>
                  <div class="text-muted small"><?= esc((string) $user['email']) ?></div>
                </td>
                <td><span class="badge bg-light-<?= esc($statusBadge($status)) ?>"><?= esc($statusLabel($status)) ?></span></td>
                <td><span class="badge bg-light-<?= esc($roleBadge) ?>"><?= esc($roleLabel) ?></span></td>
                <td>
                  <div class="small mb-1"><?= esc($formatBytes($used)) ?> / <?= esc($formatBytes($quota)) ?></div>
                  <div class="progress" style="height: 6px">
                    <div class="progress-bar" style="width: <?= esc((string) $percentage) ?>%"></div>
                  </div>
                </td>
                <td class="text-nowrap"><?= esc($formatDate($user['last_login_at'] ?? null)) ?></td>
                <td class="text-end">
                  <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <?php if ($canChangeStatus): ?>
                      <form action="<?= site_url('admin/users/' . $user['id'] . '/status') ?>" method="post" class="d-inline-flex gap-1" data-admin-confirm="Change this user's status?">
                        <?= csrf_field() ?>
                        <select name="status" class="form-select form-select-sm" aria-label="Status">
                          <?php foreach (['active', 'pending_verification', 'inactive', 'suspended'] as $option): ?>
                            <option value="<?= esc($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= esc($statusLabel($option)) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-light-primary"><i class="ti ti-device-floppy me-1"></i>Save</button>
                      </form>
                    <?php else: ?>
                      <span class="text-muted small">Protected</span>
                    <?php endif; ?>
                    <?php if ($canChangeRole): ?>
                      <form action="<?= site_url('admin/users/' . $user['id'] . '/role') ?>" method="post" class="d-inline-flex gap-1" data-admin-confirm="Change this user's role?">
                        <?= csrf_field() ?>
                        <select name="role" class="form-select form-select-sm" aria-label="Role">
                          <option value="user"<?= $role === 'user' ? ' selected' : '' ?>>User</option>
                          <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>>Admin</option>
                          <option value="super_admin"<?= $role === 'super_admin' ? ' selected' : '' ?>>Super Admin</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-light-warning"><i class="ti ti-user-shield me-1"></i>Role</button>
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
  <div class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <span class="text-muted small"><?= esc((string) $pager['total']) ?> user(s)</span>
    <?php if ((int) $pager['totalPages'] > 1): ?>
      <ul class="pagination mb-0">
        <?php for ($page = 1; $page <= (int) $pager['totalPages']; $page++): ?>
          <li class="page-item<?= $page === (int) $pager['page'] ? ' active' : '' ?>">
            <a class="page-link" href="<?= esc($urlWith(['page' => $page])) ?>"><?= $page ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?= $this->endSection() ?>
