<?= view('components/app/head', ['title' => $pageTitle ?? $title ?? 'Theaz Cloud']) ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
<div id="cloud-shell">
  <?= view('components/app/loader') ?>
  <?= view('components/app/sidebar', [
      'variant' => 'user',
      'activeMenu' => $activeMenu ?? 'dashboard',
  ]) ?>
  <?= view('components/app/header', [
      'variant' => 'user',
      'currentUser' => $currentUser ?? [],
  ]) ?>

  <div class="pc-container">
    <div class="pc-content">
      <?= view('components/app/breadcrumb', [
          'pageTitle' => $pageTitle ?? $title ?? 'Dashboard',
          'breadcrumbs' => $breadcrumbs ?? [],
      ]) ?>
      <?= view('components/app/flash') ?>
      <?= $this->renderSection('content') ?>
    </div>
  </div>

  <?= view('components/app/footer') ?>
  <?= view('components/ui/toast', $toast ?? ['message' => '']) ?>
</div>
  <?= view('components/app/scripts') ?>
</body>
</html>
