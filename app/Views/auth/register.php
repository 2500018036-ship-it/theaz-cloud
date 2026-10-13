<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-main">
  <div class="auth-wrapper v3">
    <div class="auth-form">
      <div class="auth-header">
        <a href="<?= site_url('/') ?>"><img src="<?= base_url('template/dist/assets/images/logo-dark.svg') ?>" alt="Theaz Cloud"></a>
      </div>
      <div class="card my-5">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-end mb-4">
            <h3 class="mb-0"><b>Sign up</b></h3>
            <a href="<?= site_url('login') ?>" class="link-primary">Already have an account?</a>
          </div>

          <?= view('components/app/flash') ?>

          <form action="<?= site_url('register') ?>" method="post" novalidate>
            <?= csrf_field() ?>
            <div class="form-group mb-3">
              <label class="form-label" for="invitation_code">Invitation Code</label>
              <input type="text" class="form-control" id="invitation_code" name="invitation_code" value="<?= esc(old('invitation_code')) ?>" placeholder="Invitation Code" autocomplete="one-time-code" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="username">Username</label>
              <input type="text" class="form-control" id="username" name="username" value="<?= esc(old('username')) ?>" placeholder="Username" autocomplete="username" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="email">Email Address</label>
              <input type="email" class="form-control" id="email" name="email" value="<?= esc(old('email')) ?>" placeholder="Email Address" autocomplete="email" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="password">Password</label>
              <input type="password" class="form-control" id="password" name="password" placeholder="Password" autocomplete="new-password" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="password_confirm">Confirm Password</label>
              <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Confirm Password" autocomplete="new-password" required>
            </div>
            <div class="d-grid mt-3">
              <button type="submit" class="btn btn-primary"><i class="ti ti-user-plus me-1"></i>Create Account</button>
            </div>
          </form>
        </div>
      </div>
      <div class="auth-footer row">
        <div class="col my-1">
          <p class="m-0">Invite-only registration</p>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
