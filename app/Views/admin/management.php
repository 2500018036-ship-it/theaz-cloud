<?= $this->extend('layouts/admin') ?>

<?php
$admins = $admins ?? ['items' => [], 'pager' => ['total' => 0]];
$activity = $activity ?? [];
$permissionLabels = $permissionLabels ?? [];
$permissionKeys = $permissionKeys ?? [];
$createPermissions = old('permissions', ['admin.dashboard', 'users.view', 'storage.view', 'activity_logs.view']);
$createPermissions = is_array($createPermissions) ? $createPermissions : [$createPermissions];

$formatDate = static function ($value): string {
    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp);
};

$formatBytes = static function (?int $bytes): string {
    if ($bytes === null) {
        return 'Default';
    }

    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    return $bytes . ' B';
};

$statusLabel = static fn (string $status): string => ucwords(str_replace('_', ' ', $status));
$statusBadge = static fn (string $status): string => match ($status) {
    'active' => 'success text-success',
    'pending_verification' => 'info text-info',
    'suspended' => 'danger text-danger',
    default => 'warning text-warning',
};
?>

<?= $this->section('content') ?>
<?php if (session('admin_invitation_link')): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <div class="fw-semibold">Admin activation link for <?= esc((string) session('admin_invitation_email')) ?></div>
    <code class="text-break"><?= esc((string) session('admin_invitation_link')) ?></code>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Create Admin</h5>
      </div>
      <form action="<?= site_url('admin/management/admins') ?>" method="post" class="card-body" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="admin-email">Email</label>
          <input type="email" class="form-control" id="admin-email" name="email" value="<?= esc(old('email')) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-username">Username</label>
          <input type="text" class="form-control" id="admin-username" name="username" value="<?= esc(old('username')) ?>" minlength="3" maxlength="80" autocomplete="off" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-password">Password</label>
          <input type="password" class="form-control" id="admin-password" name="password" autocomplete="new-password" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-password-confirm">Confirm Password</label>
          <input type="password" class="form-control" id="admin-password-confirm" name="password_confirm" autocomplete="new-password" required>
        </div>
        <div class="alert alert-light-info py-2">
          Admin accounts manage the system only and do not receive personal cloud storage.
        </div>
        <div class="mb-3">
          <label class="form-label">Permissions</label>
          <div class="vstack gap-2">
            <?php foreach ($permissionKeys as $permission): ?>
              <?php $checked = in_array($permission, $createPermissions, true); ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="create-permission-<?= esc(str_replace('.', '-', $permission)) ?>" name="permissions[]" value="<?= esc($permission) ?>"<?= $checked ? ' checked' : '' ?>>
                <label class="form-check-label" for="create-permission-<?= esc(str_replace('.', '-', $permission)) ?>"><?= esc($permissionLabels[$permission] ?? $permission) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-user-plus me-1"></i>Create Admin</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Recent Admin Activity</h5>
      </div>
      <div class="card-body p-0">
        <?php if ($activity === []): ?>
          <div class="p-3">
            <?= view('components/ui/empty_state', [
                'icon' => 'ti ti-activity',
                'title' => 'No activity',
                'message' => 'Administrative activity will appear here.',
                'compact' => true,
            ]) ?>
          </div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($activity as $log): ?>
              <div class="list-group-item">
                <div class="fw-semibold"><?= esc((string) $log['action']) ?></div>
                <div class="text-muted small"><?= esc($formatDate($log['created_at'] ?? null)) ?> by <?= esc((string) ($log['actor_username'] ?? $log['actor_email'] ?? 'System')) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Administrators</h5>
      </div>
      <div class="card-body table-border-style">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr><th>Account</th><th>Role</th><th>Status</th><th>Last Login</th><th class="text-end">Controls</th></tr></thead>
            <tbody>
              <?php foreach ($admins['items'] as $admin): ?>
                <?php
                  $adminId = (int) $admin['id'];
                  $adminRole = (string) $admin['role'];
                  $adminStatus = (string) $admin['status'];
                  $isSelf = $adminId === (int) session('user_id');
                  $roleLabel = ucwords(str_replace('_', ' ', $adminRole));
                  $adminPermissions = array_values($admin['permission_list'] ?? []);
                ?>
                <tr>
                  <td>
                    <a href="<?= site_url('admin/users/' . $adminId) ?>" class="fw-semibold"><?= esc($admin['username']) ?></a>
                    <div class="text-muted small"><?= esc($admin['email']) ?></div>
                    <div class="text-muted small">
                      <?= $adminRole === 'super_admin'
                          ? 'All permissions'
                          : esc((string) count($adminPermissions)) . ' permission(s)' ?>
                    </div>
                  </td>
                  <td><span class="badge bg-light-primary text-primary"><?= esc($roleLabel) ?></span></td>
                  <td><span class="badge bg-light-<?= esc($statusBadge($adminStatus)) ?>"><?= esc($statusLabel($adminStatus)) ?></span></td>
                  <td><?= esc($formatDate($admin['last_login_at'] ?? null)) ?></td>
                  <td class="text-end">
                    <div class="d-inline-flex flex-wrap gap-2 justify-content-end">
                      <?php if (! $isSelf): ?>
                        <form action="<?= site_url('admin/users/' . $adminId . '/status') ?>" method="post" class="d-inline-flex gap-1" data-admin-confirm="Change this administrator status?">
                          <?= csrf_field() ?>
                          <input type="hidden" name="return_to" value="management">
                          <select name="status" class="form-select form-select-sm" aria-label="Admin status">
                            <?php foreach (['active', 'pending_verification', 'inactive', 'suspended'] as $option): ?>
                              <option value="<?= esc($option) ?>"<?= $adminStatus === $option ? ' selected' : '' ?>><?= esc($statusLabel($option)) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button type="submit" class="btn btn-sm btn-light-primary"><i class="ti ti-device-floppy me-1"></i>Status</button>
                        </form>
                        <?php if ($adminRole === 'admin'): ?>
                          <button type="button" class="btn btn-sm btn-light-primary" data-bs-toggle="collapse" data-bs-target="#admin-permissions-<?= esc((string) $adminId) ?>"><i class="ti ti-key me-1"></i>Permissions</button>
                          <form action="<?= site_url('admin/users/' . $adminId . '/role') ?>" method="post" data-admin-confirm="Revoke administrative access for this account?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="management">
                            <input type="hidden" name="role" value="user">
                            <button type="submit" class="btn btn-sm btn-light-danger"><i class="ti ti-user-minus me-1"></i>Revoke Admin</button>
                          </form>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="badge bg-light-secondary text-secondary align-self-center">Current session</span>
                      <?php endif; ?>
                      <a href="<?= site_url('admin/users/' . $adminId) ?>" class="btn btn-sm btn-light-secondary"><i class="ti ti-external-link me-1"></i>Open</a>
                    </div>
                  </td>
                </tr>
                <?php if ($adminRole === 'admin'): ?>
                  <tr class="collapse" id="admin-permissions-<?= esc((string) $adminId) ?>">
                    <td colspan="5">
                      <form action="<?= site_url('admin/management/admins/' . $adminId . '/permissions') ?>" method="post" class="border rounded p-3">
                        <?= csrf_field() ?>
                        <div class="row g-2">
                          <?php foreach ($permissionKeys as $permission): ?>
                            <div class="col-md-6">
                              <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="permission-<?= esc((string) $adminId) ?>-<?= esc(str_replace('.', '-', $permission)) ?>" name="permissions[]" value="<?= esc($permission) ?>"<?= in_array($permission, $adminPermissions, true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="permission-<?= esc((string) $adminId) ?>-<?= esc(str_replace('.', '-', $permission)) ?>"><?= esc($permissionLabels[$permission] ?? $permission) ?></label>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <div class="text-end mt-3">
                          <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Permissions</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer text-muted small"><?= esc((string) $admins['pager']['total']) ?> administrator(s)</div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
