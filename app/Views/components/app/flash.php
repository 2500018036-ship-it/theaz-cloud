<?php
$translateFlash = static function ($message): string {
    $message = (string) $message;
    $map = [
        'Language updated.' => 'App.flash.languageUpdated',
        'Bahasa diperbarui.' => 'App.flash.languageUpdated',
        'Folder created.' => 'App.flash.folderCreated',
        'No files were uploaded.' => 'App.flash.noFilesUploaded',
        'New version uploaded.' => 'App.flash.newVersionUploaded',
        'Version restored.' => 'App.flash.versionRestored',
        'Select at least one item.' => 'App.flash.selectAtLeastOneItem',
        'Bulk action completed.' => 'App.flash.bulkActionCompleted',
        'Share link created.' => 'App.flash.shareLinkCreated',
        'Public link revoked.' => 'App.flash.publicLinkRevoked',
        'Access shared.' => 'App.flash.accessShared',
        'Permission updated.' => 'App.flash.permissionUpdated',
        'Access revoked.' => 'App.flash.accessRevoked',
        'Profile updated.' => 'App.flash.profileUpdated',
        'Preferences updated.' => 'App.flash.preferencesUpdated',
        'Password updated.' => 'App.flash.passwordUpdated',
        'Verification email has been queued for the new address.' => 'App.flash.emailQueued',
        'Email address verified.' => 'App.flash.emailVerified',
        'Avatar updated.' => 'App.flash.avatarUpdated',
        'Avatar removed.' => 'App.flash.avatarRemoved',
        'Notification marked as read.' => 'App.flash.notificationRead',
        'All notifications marked as read.' => 'App.flash.allNotificationsRead',
        'System settings updated.' => 'App.flash.settingsUpdated',
        'Maintenance mode updated.' => 'App.flash.maintenanceUpdated',
        'Activity log entry deleted.' => 'App.flash.activityLogDeleted',
        'Activity logs cleared.' => 'App.flash.activityLogsCleared',
        'No activity logs to clear.' => 'App.flash.noActivityLogs',
        'You have been signed out.' => 'App.flash.signedOut',
        'Too many login attempts. Please wait before trying again.' => 'App.flash.loginRateLimited',
        'The provided credentials are invalid.' => 'App.flash.invalidCredentials',
        'This account is not active.' => 'App.flash.inactiveAccount',
        'Please verify your email address before signing in.' => 'App.flash.emailUnverified',
        'Invitation code is invalid, expired, revoked, or already used.' => 'App.flash.invalidInvitation',
        'Registration could not be completed. Please check your details and try again.' => 'App.flash.registrationFailed',
        'Registration successful. We sent a verification email. Please verify your email before signing in.' => 'App.flash.registrationVerifySent',
        'Registration successful. Your verification email is queued; please check your inbox shortly before signing in.' => 'App.flash.registrationVerifyQueued',
        'Account created. You can sign in now.' => 'App.flash.registrationActive',
        'Please wait before requesting another verification email.' => 'App.flash.resendWait',
        'If the account is waiting for verification, we sent a new verification email or queued it for delivery.' => 'App.flash.resendQueued',
        'Stored file is missing from disk.' => 'App.errors.storedFileMissing',
        'Stored file path is invalid.' => 'App.errors.storedFilePathInvalid',
        'Stored file path is outside private storage.' => 'App.errors.storedFilePathOutside',
        'Activity log entry was not found.' => 'App.errors.activityLogNotFound',
        'You do not have permission to perform this administrative action.' => 'App.errors.adminPermissionDenied',
    ];

    if (isset($map[$message])) {
        return lang($map[$message]);
    }

    if (preg_match('/\A(\d+) file\(s\) uploaded\.\z/', $message, $matches) === 1) {
        return lang('App.flash.filesUploaded', [$matches[1]]);
    }

    return $message;
};
?>

<?php if (session('message')): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= esc($translateFlash(session('message'))) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= esc(lang('UI.t7d9eb7acb13e'), 'attr') ?>"></button>
  </div>
<?php endif; ?>

<?php if (session('error')): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= esc($translateFlash(session('error'))) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= esc(lang('UI.t7d9eb7acb13e'), 'attr') ?>"></button>
  </div>
<?php endif; ?>

<?php if (session('errors')): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php foreach ((array) session('errors') as $error): ?>
      <div><?= esc($translateFlash($error)) ?></div>
    <?php endforeach; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= esc(lang('UI.t7d9eb7acb13e'), 'attr') ?>"></button>
  </div>
<?php endif; ?>
