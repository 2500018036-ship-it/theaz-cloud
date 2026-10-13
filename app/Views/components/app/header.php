<?php
$assetBase = base_url('template/dist/assets');
$currentUser = $currentUser ?? [];
$displayName = $currentUser['username'] ?? session('username') ?? 'Account';
$displayRole = $currentUser['role'] ?? session('role') ?? 'user';
$variant = $variant ?? 'user';
$isAdminHeader = $variant === 'admin';
$canSystemSettings = false;
$defaultAvatar = base_url('template/dist/assets/images/user/avatar-2.jpg');
$notificationSummary = ['unread' => 0, 'items' => []];
$searchAction = $isAdminHeader ? site_url('admin/users') : site_url('drive');
$searchPlaceholder = $isAdminHeader ? lang('App.header.searchUsers') : lang('App.header.search');

if (session('is_logged_in') && session('user_id')) {
  try {
    $notificationSummary = (new \App\Libraries\NotificationService())->summary((int) session('user_id'));
  } catch (\Throwable) {
    $notificationSummary = ['unread' => 0, 'items' => []];
  }

  if ($isAdminHeader) {
    try {
      $canSystemSettings = (new \App\Libraries\AdminAuthorization())->hasPermission((int) session('user_id'), 'system_settings.view');
    } catch (\Throwable) {
      $canSystemSettings = false;
    }
  }
}
?>
<header class="pc-header">
  <div class="header-wrapper">
    <div class="me-auto pc-mob-drp">
      <ul class="list-unstyled">
        <li class="pc-h-item pc-sidebar-collapse">
          <a href="#" class="pc-head-link ms-0" id="sidebar-hide">
            <i class="ti ti-menu-2"></i>
          </a>
        </li>
        <li class="pc-h-item pc-sidebar-popup">
          <a href="#" class="pc-head-link ms-0" id="mobile-collapse">
            <i class="ti ti-menu-2"></i>
          </a>
        </li>
        <li class="dropdown pc-h-item d-inline-flex d-md-none">
          <a class="pc-head-link dropdown-toggle arrow-none m-0" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
            <i class="ti ti-search"></i>
          </a>
          <div class="dropdown-menu pc-h-dropdown drp-search">
            <form class="px-3" action="<?= esc($searchAction) ?>" method="get">
              <div class="form-group mb-0 d-flex align-items-center">
                <i data-feather="search"></i>
                <input type="search" name="q" class="form-control border-0 shadow-none" placeholder="<?= esc($searchPlaceholder) ?>" value="<?= esc(request()->getGet('q') ?? '') ?>">
              </div>
            </form>
          </div>
        </li>
        <li class="pc-h-item d-none d-md-inline-flex">
          <form class="header-search" action="<?= esc($searchAction) ?>" method="get">
            <i data-feather="search" class="icon-search"></i>
            <input type="search" name="q" class="form-control" placeholder="<?= esc($searchPlaceholder) ?>" value="<?= esc(request()->getGet('q') ?? '') ?>">
          </form>
        </li>
      </ul>
    </div>
    <div class="ms-auto">
      <ul class="list-unstyled">
        <?= view('components/app/language_switcher') ?>
        <li class="dropdown pc-h-item">
          <a class="pc-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
            <i class="ti ti-bell"></i>
            <?php if ((int) $notificationSummary['unread'] > 0): ?>
              <span class="badge bg-danger rounded-pill position-absolute translate-middle"><?= esc((string) min(99, (int) $notificationSummary['unread'])) ?></span>
            <?php endif; ?>
          </a>
          <div class="dropdown-menu dropdown-notification dropdown-menu-end pc-h-dropdown">
            <div class="dropdown-header d-flex align-items-center justify-content-between">
              <h5 class="m-0"><?= esc(lang('App.header.notifications')) ?></h5>
              <a href="<?= site_url('notifications') ?>" class="link-primary small"><?= esc(lang('App.header.viewAll')) ?></a>
            </div>
            <div class="dropdown-divider"></div>
            <?php if (($notificationSummary['items'] ?? []) === []): ?>
              <div class="p-3">
                <?= view('components/ui/empty_state', [
                  'icon' => 'ti ti-bell-off',
                  'title' => lang('App.header.noNotifications'),
                  'message' => lang('App.header.caughtUp'),
                  'compact' => true,
                ]) ?>
              </div>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($notificationSummary['items'] as $notice): ?>
                  <a class="list-group-item list-group-item-action" href="<?= esc((string) ($notice['action_url'] ?: site_url('notifications'))) ?>">
                    <div class="d-flex justify-content-between gap-2">
                      <span class="fw-semibold text-truncate"><?= esc($notice['title']) ?></span>
                      <?php if (empty($notice['read_at'])): ?>
                        <span class="badge bg-light-primary text-primary"><?= esc(lang('App.header.new')) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="text-muted small text-truncate"><?= esc((string) ($notice['body'] ?? '')) ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </li>
        <li class="dropdown pc-h-item header-user-profile">
          <a class="pc-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" data-bs-auto-close="outside" aria-expanded="false">
            <img src="<?= site_url('settings/avatar') ?>" onerror="this.src='<?= esc($defaultAvatar, 'attr') ?>'" alt="user-image" class="user-avtar rounded-circle" style="object-fit:cover">
            <span><?= esc($displayName) ?></span>
          </a>
          <div class="dropdown-menu dropdown-user-profile dropdown-menu-end pc-h-dropdown">
            <div class="dropdown-header">
              <div class="d-flex mb-1">
                <div class="flex-shrink-0">
                  <img src="<?= site_url('settings/avatar') ?>" onerror="this.src='<?= esc($defaultAvatar, 'attr') ?>'" alt="user-image" class="user-avtar wid-35 rounded-circle" style="object-fit:cover">
                </div>
                <div class="flex-grow-1 ms-3">
                  <h6 class="mb-1"><?= esc($displayName) ?></h6>
                  <span><?= esc(ucfirst((string) $displayRole)) ?></span>
                </div>
              </div>
            </div>
            <?php if ($isAdminHeader && $canSystemSettings): ?>
              <a href="<?= site_url('admin/settings') ?>" class="dropdown-item">
                <i class="ti ti-adjustments-cog"></i>
                <span><?= esc(lang('App.header.systemSettings')) ?></span>
              </a>
            <?php endif; ?>
            <a href="<?= site_url('settings') ?>" class="dropdown-item">
              <i class="ti ti-user-cog"></i>
              <span><?= esc(lang('App.header.myAccountSettings')) ?></span>
            </a>
            <a href="<?= site_url('notifications') ?>" class="dropdown-item">
              <i class="ti ti-bell"></i>
              <span><?= esc(lang('App.header.notifications')) ?></span>
            </a>
            <form action="<?= site_url('logout') ?>" method="post">
              <?= csrf_field() ?>
              <button type="submit" class="dropdown-item">
                <i class="ti ti-power"></i>
                <span><?= esc(lang('App.header.logout')) ?></span>
              </button>
            </form>
          </div>
        </li>
      </ul>
    </div>
  </div>
</header>
