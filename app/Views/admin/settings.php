<?= $this->extend('layouts/admin') ?>

<?php
$settings = $settings ?? [];
$health = $health ?? [];
$canManageSettings = (bool) ($canManageSettings ?? false);
$bytesToGb = static fn ($bytes): string => $bytes === null || $bytes === '' ? '' : (string) round(((int) $bytes) / 1073741824, 2);
?>

<?= $this->section('content') ?>
<?php if (! $canManageSettings): ?>
  <div class="alert alert-info">You can view system settings, but only a super admin or an account with system settings permission can change them.</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8">
    <form action="<?= site_url('admin/settings') ?>" method="post">
      <?= csrf_field() ?>
      <fieldset<?= $canManageSettings ? '' : ' disabled' ?>>

      <div class="card">
        <div class="card-header"><h5 class="mb-0">General Settings</h5></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="app-name">Application name</label>
              <input type="text" class="form-control" id="app-name" name="app_name" value="<?= esc(old('app_name', $settings['app_name'] ?? 'Theaz Cloud')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="timezone">Timezone</label>
              <select class="form-select" id="timezone" name="timezone">
                <?php foreach (['Asia/Jakarta', 'UTC', 'Asia/Singapore', 'Asia/Tokyo', 'America/New_York'] as $zone): ?>
                  <option value="<?= esc($zone) ?>"<?= ($settings['timezone'] ?? 'Asia/Jakarta') === $zone ? ' selected' : '' ?>><?= esc($zone) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="date-format">Date format</label>
              <select class="form-select" id="date-format" name="date_format">
                <?php foreach (['Y-m-d H:i', 'd/m/Y H:i', 'm/d/Y h:i A'] as $format): ?>
                  <option value="<?= esc($format) ?>"<?= ($settings['date_format'] ?? 'Y-m-d H:i') === $format ? ' selected' : '' ?>><?= esc($format) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="admin-contact">Admin contact email</label>
              <input type="email" class="form-control" id="admin-contact" name="admin_contact_email" value="<?= esc(old('admin_contact_email', $settings['admin_contact_email'] ?? '')) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h5 class="mb-0">Registration & Invitation</h5></div>
        <div class="card-body">
          <div class="row g-3">
            <?php foreach ([
                'registration_public_enabled' => 'Public registration',
                'invitation_registration_enabled' => 'Invitation registration',
                'email_verification_required' => 'Require email verification',
            ] as $key => $label): ?>
              <div class="col-md-4">
                <div class="form-check form-switch">
                  <input type="hidden" name="<?= esc($key) ?>" value="0">
                  <input class="form-check-input" type="checkbox" id="<?= esc($key) ?>" name="<?= esc($key) ?>" value="1"<?= ($settings[$key] ?? '0') === '1' ? ' checked' : '' ?>>
                  <label class="form-check-label" for="<?= esc($key) ?>"><?= esc($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="col-md-4">
              <label class="form-label" for="default-invitation-expires-days">Default invitation expiry days</label>
              <input type="number" class="form-control" id="default-invitation-expires-days" name="default_invitation_expires_days" min="1" max="365" value="<?= esc($settings['default_invitation_expires_days'] ?? '7') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="invitation-max-uses-default">Default invitation max uses</label>
              <input type="number" class="form-control" id="invitation-max-uses-default" name="invitation_max_uses_default" min="1" max="1000" value="<?= esc($settings['invitation_max_uses_default'] ?? '1') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="default-user-quota-bytes">Default user quota (bytes)</label>
              <input type="number" class="form-control" id="default-user-quota-bytes" name="default_user_quota_bytes" min="1048576" value="<?= esc($settings['default_user_quota_bytes'] ?? '5368709120') ?>">
              <div class="form-text">Current: <?= esc($bytesToGb($settings['default_user_quota_bytes'] ?? '5368709120')) ?> GB.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="max-admin-quota">Max admin assignable quota (bytes)</label>
              <input type="number" class="form-control" id="max-admin-quota" name="max_admin_assignable_quota_bytes" min="1048576" value="<?= esc($settings['max_admin_assignable_quota_bytes'] ?? '10737418240') ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h5 class="mb-0">Storage Settings</h5></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="max-upload-size">Application upload limit (bytes)</label>
              <input type="number" class="form-control" id="max-upload-size" name="max_upload_size_bytes" min="1024" value="<?= esc($settings['max_upload_size_bytes'] ?? '104857600') ?>">
              <div class="form-text">PHP-FPM and Nginx limits must be updated separately.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="trash-retention-days">Trash retention days</label>
              <input type="number" class="form-control" id="trash-retention-days" name="trash_retention_days" min="0" value="<?= esc($settings['trash_retention_days'] ?? '30') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="version-retention-days">Version retention days</label>
              <input type="number" class="form-control" id="version-retention-days" name="version_retention_days" min="0" value="<?= esc($settings['version_retention_days'] ?? '90') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="storage-warning-threshold">Storage warning threshold (%)</label>
              <input type="number" class="form-control" id="storage-warning-threshold" name="storage_warning_threshold_percent" min="1" max="100" value="<?= esc($settings['storage_warning_threshold_percent'] ?? '85') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="max-preview-text-bytes">Text preview limit (bytes)</label>
              <input type="number" class="form-control" id="max-preview-text-bytes" name="max_preview_text_bytes" min="1024" max="2097152" value="<?= esc($settings['max_preview_text_bytes'] ?? '524288') ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h5 class="mb-0">Security & Notifications</h5></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="password-min-length">Password minimum length</label>
              <input type="number" class="form-control" id="password-min-length" name="password_min_length" min="8" max="128" value="<?= esc($settings['password_min_length'] ?? '10') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="login-max-attempts">Login max attempts</label>
              <input type="number" class="form-control" id="login-max-attempts" name="login_max_attempts" min="1" max="50" value="<?= esc($settings['login_max_attempts'] ?? '5') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="session-lifetime-minutes">Session lifetime minutes</label>
              <input type="number" class="form-control" id="session-lifetime-minutes" name="session_lifetime_minutes" min="15" value="<?= esc($settings['session_lifetime_minutes'] ?? '120') ?>">
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input type="hidden" name="notification_email_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="notification-email-enabled" name="notification_email_enabled" value="1"<?= ($settings['notification_email_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
                <label class="form-check-label" for="notification-email-enabled">Email notification policy</label>
              </div>
            </div>
            <div class="col-md-4">
              <span class="badge <?= ($settings['smtp_configured'] ?? '0') === '1' ? 'bg-light-success text-success' : 'bg-light-secondary text-secondary' ?>">SMTP <?= ($settings['smtp_configured'] ?? '0') === '1' ? 'Configured' : 'Not configured' ?></span>
              <div class="form-text">SMTP secrets are not stored or shown here.</div>
            </div>
          </div>
        </div>
        <div class="card-footer text-end">
          <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Settings</button>
        </div>
      </div>
      </fieldset>
    </form>
  </div>

  <div class="col-xl-4">
    <?php if (($settings['maintenance_mode'] ?? '0') === '1'): ?>
      <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="ti ti-alert-triangle f-20"></i>
        <div>
          <div class="fw-semibold">Maintenance mode is active</div>
          <div class="small">Regular users are blocked until this switch is turned off.</div>
        </div>
      </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Maintenance Settings</h5></div>
      <form action="<?= site_url('admin/settings/maintenance') ?>" method="post" class="card-body" data-admin-confirm="Update maintenance mode?">
        <?= csrf_field() ?>
        <fieldset<?= $canManageSettings ? '' : ' disabled' ?>>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" id="maintenance-mode" name="maintenance_mode" value="1"<?= ($settings['maintenance_mode'] ?? '0') === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="maintenance-mode">Maintenance mode</label>
        </div>
        <div class="mb-3">
          <label class="form-label" for="maintenance-message">Message</label>
          <textarea class="form-control" id="maintenance-message" name="maintenance_message" rows="3" maxlength="500"><?= esc(old('maintenance_message', $settings['maintenance_message'] ?? '')) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label" for="maintenance-scheduled-at">Scheduled at</label>
          <input type="text" class="form-control" id="maintenance-scheduled-at" name="maintenance_scheduled_at" value="<?= esc($settings['maintenance_scheduled_at'] ?? '') ?>" placeholder="Optional">
        </div>
        <button type="submit" class="btn btn-light-warning w-100"><i class="ti ti-tool me-1"></i>Save Maintenance</button>
        </fieldset>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><h5 class="mb-0">System Health</h5></div>
      <div class="card-body">
        <?php foreach ($health as $key => $entry): ?>
          <div class="d-flex align-items-center justify-content-between border-bottom py-2">
            <div>
              <div class="fw-semibold"><?= esc(ucfirst((string) $key)) ?></div>
              <div class="text-muted small"><?= esc((string) ($entry['message'] ?? $entry['version'] ?? 'Available')) ?></div>
            </div>
            <?php if (array_key_exists('ok', $entry)): ?>
              <span class="badge <?= $entry['ok'] ? 'bg-light-success text-success' : 'bg-light-danger text-danger' ?>"><?= $entry['ok'] ? 'OK' : 'Issue' ?></span>
            <?php else: ?>
              <span class="badge bg-light-secondary text-secondary"><?= esc((string) ($entry['php'] ?? 'Info')) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
