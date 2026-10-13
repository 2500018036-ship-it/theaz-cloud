<?= view('components/app/head', ['title' => $title ?? 'Error']) ?>
<body>
  <?= view('components/app/loader') ?>
  <div class="auth-main">
    <div class="auth-wrapper v3">
      <div class="auth-form">
        <div class="auth-header">
          <a href="<?= site_url('/') ?>"><img src="<?= base_url('template/dist/assets/images/logo-dark.svg') ?>" alt="Theaz Cloud"></a>
        </div>
        <div class="card my-5">
          <div class="card-body text-center">
            <?= $this->renderSection('content') ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?= view('components/app/scripts') ?>
</body>
</html>
