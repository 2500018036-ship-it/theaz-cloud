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
            <h3 class="mb-0"><b><?= esc(lang('UI.t9d6322c1f4d9')) ?></b></h3>
            <a href="<?= site_url('register') ?>" class="link-primary"><?= esc(lang('UI.t43cf5bc15fe0')) ?></a>
          </div>

          <?= view('components/app/flash') ?>

          <form action="<?= site_url('login') ?>" method="post" novalidate>
            <?= csrf_field() ?>
            <div class="form-group mb-3">
              <label class="form-label" for="identifier"><?= esc(lang('UI.ta8e8987f236c')) ?></label>
              <input type="text" class="form-control" id="identifier" name="identifier" value="<?= esc(old('identifier')) ?>" placeholder="<?= esc(lang('UI.ta8e8987f236c'), 'attr') ?>" autocomplete="username" required>
            </div>
            <div class="form-group mb-3">
              <label class="form-label" for="password"><?= esc(lang('UI.te7cf3ef4f17c')) ?></label>
              <input type="password" class="form-control" id="password" name="password" placeholder="<?= esc(lang('UI.te7cf3ef4f17c'), 'attr') ?>" autocomplete="current-password" required>
            </div>
            <div class="d-grid mt-4">
              <button type="submit" class="btn btn-primary"><i class="ti ti-login me-1"></i><?= esc(lang('UI.t9d6322c1f4d9')) ?></button>
            </div>
          </form>
          <details class="mt-3">
            <summary><?= esc(lang('UI.t900852de7cb8')) ?></summary>
            <form action="<?= site_url('email/verification/resend') ?>" method="post" class="mt-2">
              <?= csrf_field() ?>
              <label class="form-label"><?= esc(lang('UI.ta8e8987f236c')) ?></label>
              <input class="form-control mb-2" name="identifier" type="text" maxlength="254" required autocomplete="username">
              <button type="submit" class="btn btn-light-primary"><?= esc(lang('UI.t3e01e3010ce7')) ?></button>
            </form>
          </details>

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
