<?php
$assetBase = base_url('template/dist/assets');
$variant = $variant ?? 'user';
$activeMenu = $activeMenu ?? 'dashboard';
$isAdmin = $variant === 'admin';
$brandUrl = $isAdmin ? site_url('admin/dashboard') : site_url('dashboard');
$canManageAdmins = false;
$canSystemSettings = false;
if ($isAdmin && session('user_id')) {
    try {
        $authz = new \App\Libraries\AdminAuthorization();
        $canManageAdmins = $authz->hasPermission((int) session('user_id'), 'admins.manage');
        $canSystemSettings = $authz->hasPermission((int) session('user_id'), 'system_settings.view');
    } catch (\Throwable) {
        $canManageAdmins = false;
        $canSystemSettings = false;
    }
}

$items = $isAdmin
    ? [
        ['caption' => lang('App.menu.administration'), 'icon' => 'ti ti-shield-lock'],
        ['key' => 'admin.dashboard', 'label' => lang('App.menu.dashboard'), 'icon' => 'ti ti-dashboard', 'url' => site_url('admin/dashboard')],
        ...($canManageAdmins ? [['key' => 'admin.management', 'label' => lang('App.menu.adminManagement'), 'icon' => 'ti ti-user-shield', 'url' => site_url('admin/management')]] : []),
        ['key' => 'admin.users', 'label' => lang('App.menu.users'), 'icon' => 'ti ti-users', 'url' => site_url('admin/users')],
        ['key' => 'admin.invitations', 'label' => lang('App.menu.invitations'), 'icon' => 'ti ti-ticket', 'url' => site_url('admin/invitations')],
        ['caption' => lang('App.menu.operations'), 'icon' => 'ti ti-database'],
        ['key' => 'admin.storage', 'label' => lang('App.menu.storage'), 'icon' => 'ti ti-server', 'url' => site_url('admin/storage')],
        ['key' => 'admin.audit', 'label' => lang('App.menu.auditLog'), 'icon' => 'ti ti-clipboard-list', 'url' => site_url('admin/audit')],
        ['key' => 'admin.announcements', 'label' => lang('App.menu.announcements'), 'icon' => 'ti ti-speakerphone', 'url' => site_url('admin/announcements')],
        ...($canSystemSettings ? [['key' => 'admin.settings', 'label' => lang('App.menu.systemSettings'), 'icon' => 'ti ti-settings', 'url' => site_url('admin/settings')]] : []),
    ]
    : [
        ['caption' => lang('App.menu.workspace'), 'icon' => 'ti ti-cloud'],
        ['key' => 'dashboard', 'label' => lang('App.menu.dashboard'), 'icon' => 'ti ti-dashboard', 'url' => site_url('dashboard')],
        ['key' => 'drive', 'label' => lang('App.menu.myDrive'), 'icon' => 'ti ti-folder', 'url' => site_url('drive')],
        ['key' => 'shared-with-me', 'label' => lang('App.menu.sharedWithMe'), 'icon' => 'ti ti-users', 'url' => site_url('drive/shared-with-me')],
        ['key' => 'shared-by-me', 'label' => lang('App.menu.sharedByMe'), 'icon' => 'ti ti-user-share', 'url' => site_url('drive/shared-by-me')],
        ['key' => 'recent', 'label' => lang('App.menu.recentFiles'), 'icon' => 'ti ti-clock', 'url' => site_url('drive/recent')],
        ['key' => 'starred', 'label' => lang('App.menu.starred'), 'icon' => 'ti ti-star', 'url' => site_url('drive/starred')],
        ['key' => 'trash', 'label' => lang('App.menu.trash'), 'icon' => 'ti ti-trash', 'url' => site_url('drive/trash')],
        ['key' => 'activity', 'label' => lang('App.menu.activity'), 'icon' => 'ti ti-activity', 'url' => site_url('drive/activity')],
        ['caption' => lang('App.menu.account'), 'icon' => 'ti ti-user-cog'],
        ['key' => 'notifications', 'label' => lang('App.menu.notifications'), 'icon' => 'ti ti-bell', 'url' => site_url('notifications')],
        ['key' => 'settings', 'label' => lang('App.menu.settings'), 'icon' => 'ti ti-settings', 'url' => site_url('settings')],
    ];
?>
<nav class="pc-sidebar">
  <div class="navbar-wrapper">
    <div class="m-header">
      <a href="<?= $brandUrl ?>" class="b-brand text-primary">
        <img src="<?= $assetBase ?>/images/logo-dark.svg" class="img-fluid logo-lg" alt="Theaz Cloud">
      </a>
    </div>
    <div class="navbar-content">
      <ul class="pc-navbar">
        <?php foreach ($items as $item): ?>
          <?php if (isset($item['caption'])): ?>
            <li class="pc-item pc-caption">
              <label><?= esc($item['caption']) ?></label>
              <i class="<?= esc($item['icon']) ?>"></i>
            </li>
          <?php else: ?>
            <?php
              $isActive = $activeMenu === $item['key'];
              $isDisabled = (bool) ($item['disabled'] ?? false);
            ?>
            <li class="pc-item<?= $isActive ? ' active' : '' ?>">
              <a href="<?= esc($item['url']) ?>" class="pc-link<?= $isDisabled ? ' disabled' : '' ?>"<?= $isDisabled ? ' aria-disabled="true"' : '' ?>>
                <span class="pc-micon"><i class="<?= esc($item['icon']) ?>"></i></span>
                <span class="pc-mtext"><?= esc($item['label']) ?></span>
                <?php if ($isDisabled): ?>
                  <span class="badge bg-light-secondary text-secondary ms-auto">Soon</span>
                <?php endif; ?>
              </a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</nav>
