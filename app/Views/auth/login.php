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
            <h3 class="mb-0"><b>Login</b></h3>
            <a href="<?= site_url('register') ?>" class="link-primary">Have an invitation?</a>
          </div>

          <?= view('components/app/flash') ?>

          <form action="<?= site_url('login') ?>" method="post" novalidate>
            <?= csrf_field() ?>
            <div class="form-group mb-3">
              <label class="form-label" for="identifier">Email or Username</label>
              <input type="text" class="form-control" id="identifier" name="identifier" value="<?= esc(old('identifier')) ?>" placeholder="Email or Username" autocomplete="username" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="password">Password</label>
              <input type="password" class="form-control" id="password" name="password" placeholder="Password" autocomplete="current-password" required>
            </div>
            <div class="d-grid mt-4">
              <button type="submit" class="btn btn-primary"><i class="ti ti-login me-1"></i>Login</button>
            </div>
          </form>

        </div>
      </div>
      <div class="auth-footer row">
        <div class="col my-1">
          <p class="m-0">Theaz Cloud</p>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
