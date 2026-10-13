<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php $invitation = $invitation ?? []; ?>
<div class="card my-5">
  <div class="card-body">
    <div class="text-center mb-4">
      <h3 class="mb-1">Activate Admin Account</h3>
      <p class="text-muted mb-0"><?= esc((string) ($invitation['recipient_email'] ?? '')) ?></p>
    </div>
    <form action="<?= site_url('admin-invitations/activate/' . rawurlencode((string) ($token ?? ''))) ?>" method="post">
      <?= csrf_field() ?>
      <div class="form-group mb-3">
        <label class="form-label" for="username">Username</label>
        <input type="text" class="form-control" id="username" name="username" value="<?= esc(old('username')) ?>" minlength="3" maxlength="80" required>
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" class="form-control" id="password" name="password" minlength="10" required>
      </div>
      <div class="form-group mb-4">
        <label class="form-label" for="password-confirm">Confirm Password</label>
        <input type="password" class="form-control" id="password-confirm" name="password_confirm" minlength="10" required>
      </div>
      <button type="submit" class="btn btn-primary w-100"><i class="ti ti-shield-check me-1"></i>Activate Account</button>
    </form>
  </div>
</div>
<?= $this->endSection() ?>
