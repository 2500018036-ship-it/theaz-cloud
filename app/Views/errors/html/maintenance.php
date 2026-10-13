<?= $this->extend('layouts/error') ?>

<?= $this->section('content') ?>
<div class="text-center">
  <div class="mb-3">
    <i class="ti ti-tools f-46 text-warning"></i>
  </div>
  <h3 class="mb-2">Maintenance Mode</h3>
  <p class="text-muted mb-4"><?= esc($message ?? 'The service is temporarily unavailable for maintenance.') ?></p>
  <div class="alert alert-light-warning text-start">
    If this appeared unexpectedly, maintenance mode can be turned off from <strong>Admin &gt; System Settings</strong> by a super admin.
  </div>
  <form action="<?= site_url('logout') ?>" method="post" class="d-inline">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-light-secondary"><i class="ti ti-logout me-1"></i>Logout</button>
  </form>
</div>
<?= $this->endSection() ?>
