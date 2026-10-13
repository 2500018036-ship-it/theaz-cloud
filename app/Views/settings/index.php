<?= $this->extend($settingsLayout ?? 'layouts/user') ?>

<?php
$user = $user ?? [];
$profile = $profile ?? [];
$notificationSummary = $notificationSummary ?? ['unread' => 0];
$defaultAvatar = base_url('template/dist/assets/images/user/avatar-2.jpg');
$isAdminAccount = (bool) ($isAdminAccount ?? false);

$checked = static fn (array $data, string $key): string => (int) ($data[$key] ?? 0) === 1 ? ' checked' : '';
?>

<?= $this->section('content') ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <img src="<?= site_url('settings/avatar') ?>" onerror="this.src='<?= esc($defaultAvatar, 'attr') ?>'" alt="Avatar" class="rounded-circle mb-3" width="96" height="96" style="object-fit:cover">
        <h5 class="mb-1"><?= esc((string) (($profile['display_name'] ?? '') !== '' ? $profile['display_name'] : ($user['username'] ?? 'Account'))) ?></h5>
        <p class="text-muted mb-3"><?= esc((string) ($user['email'] ?? '')) ?></p>
        <form action="<?= site_url('settings/avatar') ?>" method="post" enctype="multipart/form-data" class="vstack gap-2">
          <?= csrf_field() ?>
          <input type="file" name="avatar" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif" required>
          <button type="submit" class="btn btn-light-primary"><i class="ti ti-upload me-1"></i>Update Avatar</button>
        </form>
        <?php if (! empty($profile['avatar_path'])): ?>
          <form action="<?= site_url('settings/avatar/delete') ?>" method="post" class="mt-2">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-light-danger"><i class="ti ti-trash me-1"></i>Remove Avatar</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <span class="text-muted">Unread notifications</span>
          <span class="badge bg-light-primary text-primary"><?= esc((string) ($notificationSummary['unread'] ?? 0)) ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Profile</h5>
      </div>
      <form action="<?= site_url('settings/profile') ?>" method="post" class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="display-name">Display name</label>
            <input type="text" class="form-control" id="display-name" name="display_name" maxlength="120" value="<?= esc(old('display_name', $profile['display_name'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" value="<?= esc((string) ($user['username'] ?? '')) ?>" disabled>
          </div>
          <div class="col-12">
            <label class="form-label" for="bio">Bio</label>
            <textarea class="form-control" id="bio" name="bio" rows="3" maxlength="1000"><?= esc(old('bio', $profile['bio'] ?? '')) ?></textarea>
          </div>
        </div>
        <div class="text-end mt-3">
          <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Profile</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Email</h5>
      </div>
      <form action="<?= site_url('settings/email') ?>" method="post" class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3 align-items-end">
          <div class="col-md-7">
            <label class="form-label" for="email">Current email</label>
            <input type="email" class="form-control" value="<?= esc((string) ($user['email'] ?? '')) ?>" disabled>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="new-email">New email</label>
            <input type="email" class="form-control" id="new-email" name="email" value="<?= esc(old('email')) ?>" required>
          </div>
        </div>
        <?php if (! empty($profile['pending_email'])): ?>
          <div class="alert alert-light-warning mt-3 mb-0">
            Pending verification: <?= esc((string) $profile['pending_email']) ?>
          </div>
        <?php endif; ?>
        <div class="text-end mt-3">
          <button type="submit" class="btn btn-light-primary"><i class="ti ti-mail-forward me-1"></i>Queue Verification Email</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Password</h5>
      </div>
      <form action="<?= site_url('settings/password') ?>" method="post" class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label" for="current-password">Current password</label>
            <input type="password" class="form-control" id="current-password" name="current_password" autocomplete="current-password" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="new-password">New password</label>
            <input type="password" class="form-control" id="new-password" name="new_password" autocomplete="new-password" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="confirm-password">Confirm password</label>
            <input type="password" class="form-control" id="confirm-password" name="new_password_confirm" autocomplete="new-password" required>
          </div>
        </div>
        <div class="text-end mt-3">
          <button type="submit" class="btn btn-light-primary"><i class="ti ti-lock-check me-1"></i>Update Password</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><?= $isAdminAccount ? 'Account Preferences' : 'Drive Preferences' ?></h5>
      </div>
      <form action="<?= site_url('settings/preferences') ?>" method="post" class="card-body">
        <?= csrf_field() ?>
        <?php if ($isAdminAccount): ?>
          <input type="hidden" name="default_drive_view" value="<?= esc((string) ($profile['default_drive_view'] ?? 'list')) ?>">
          <input type="hidden" name="default_sort" value="<?= esc((string) ($profile['default_sort'] ?? 'name')) ?>">
          <input type="hidden" name="default_sort_dir" value="<?= esc((string) ($profile['default_sort_dir'] ?? 'asc')) ?>">
        <?php endif; ?>
        <div class="row g-3">
          <div class="<?= $isAdminAccount ? 'col-md-12' : 'col-md-3' ?>">
            <label class="form-label" for="locale"><?= esc(lang('App.settings.language')) ?></label>
            <select class="form-select" id="locale" name="locale">
              <option value="en"<?= ($profile['locale'] ?? 'en') === 'en' ? ' selected' : '' ?>><?= esc(lang('App.settings.english')) ?></option>
              <option value="id"<?= ($profile['locale'] ?? 'en') === 'id' ? ' selected' : '' ?>><?= esc(lang('App.settings.indonesian')) ?></option>
            </select>
          </div>
          <?php if (! $isAdminAccount): ?>
            <div class="col-md-3">
              <label class="form-label" for="default-drive-view">Default view</label>
              <select class="form-select" id="default-drive-view" name="default_drive_view">
                <option value="list"<?= ($profile['default_drive_view'] ?? 'list') === 'list' ? ' selected' : '' ?>>List</option>
                <option value="grid"<?= ($profile['default_drive_view'] ?? 'list') === 'grid' ? ' selected' : '' ?>>Grid</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="default-sort">Default sort</label>
              <select class="form-select" id="default-sort" name="default_sort">
                <?php foreach (['name' => 'Name', 'updated' => 'Updated', 'size' => 'Size', 'type' => 'Type'] as $value => $label): ?>
                  <option value="<?= esc($value) ?>"<?= ($profile['default_sort'] ?? 'name') === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="default-sort-dir">Direction</label>
              <select class="form-select" id="default-sort-dir" name="default_sort_dir">
                <option value="asc"<?= ($profile['default_sort_dir'] ?? 'asc') === 'asc' ? ' selected' : '' ?>>Ascending</option>
                <option value="desc"<?= ($profile['default_sort_dir'] ?? 'asc') === 'desc' ? ' selected' : '' ?>>Descending</option>
              </select>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($isAdminAccount): ?>
          <div class="alert alert-light-info mt-3 mb-0">
            Administrator accounts do not use personal My Drive preferences.
          </div>
        <?php endif; ?>

        <hr>
        <div class="row g-3">
          <?php foreach ([
              'notify_in_app_announcements' => 'In-app announcements',
              'notify_in_app_sharing' => 'In-app sharing updates',
              'notify_in_app_security' => 'In-app security alerts',
              'email_announcements' => 'Email announcements',
              'email_sharing' => 'Email sharing updates',
              'email_updates' => 'Email account updates',
          ] as $key => $label): ?>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="<?= esc($key) ?>" name="<?= esc($key) ?>" value="1"<?= $checked($profile, $key) ?>>
                <label class="form-check-label" for="<?= esc($key) ?>"><?= esc($label) ?></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="text-end mt-3">
          <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Preferences</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
