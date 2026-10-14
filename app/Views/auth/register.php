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
            <h3 class="mb-0"><b><?= esc(lang('UI.t5e2b8e96503d')) ?></b></h3>
            <a href="<?= site_url('login') ?>" class="link-primary"><?= esc(lang('UI.te77fea936d3e')) ?></a>
          </div>

          <?= view('components/app/flash') ?>

          <form action="<?= site_url('register') ?>" method="post" novalidate>
            <?= csrf_field() ?>
            <div class="form-group mb-3">
              <label class="form-label" for="invitation_code"><?= esc(lang('UI.ta2301312904b')) ?></label>
              <input type="text" class="form-control" id="invitation_code" name="invitation_code" value="<?= esc(old('invitation_code')) ?>" placeholder="<?= esc(lang('UI.ta2301312904b'), 'attr') ?>" autocomplete="one-time-code" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="username"><?= esc(lang('UI.te3b89e9d33f8')) ?></label>
              <input type="text" class="form-control" id="username" name="username" value="<?= esc(old('username')) ?>" placeholder="<?= esc(lang('UI.te3b89e9d33f8'), 'attr') ?>" autocomplete="username" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="email"><?= esc(lang('UI.t09bf25ef3083')) ?></label>
              <input type="email" class="form-control" id="email" name="email" value="<?= esc(old('email')) ?>" placeholder="<?= esc(lang('UI.t09bf25ef3083'), 'attr') ?>" autocomplete="email" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="password"><?= esc(lang('UI.te7cf3ef4f17c')) ?></label>
              <input type="password" class="form-control" id="password" name="password" placeholder="<?= esc(lang('UI.te7cf3ef4f17c'), 'attr') ?>" autocomplete="new-password" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="password_confirm"><?= esc(lang('UI.tc292210c4416')) ?></label>
              <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="<?= esc(lang('UI.tc292210c4416'), 'attr') ?>" autocomplete="new-password" required>
            </div>
            <div class="d-grid mt-3">
              <button type="submit" class="btn btn-primary"><i class="ti ti-user-plus me-1"></i><?= esc(lang('UI.t0dffe234b447')) ?></button>
            </div>
          </form>
        </div>
      </div>
      <div class="auth-footer row">
        <div class="col my-1">
          <p class="m-0"><?= esc(lang('UI.tfccf4d78c1b6')) ?></p>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
