<?= view('components/app/head', ['title' => $title ?? 'Authentication']) ?>
<body>
<div id="cloud-shell">
  <?= view('components/app/loader') ?>
  <div class="position-fixed top-0 end-0 p-3" style="z-index: 1030">
    <?= view('components/app/language_switcher', ['compact' => true]) ?>
  </div>

  <?= $this->renderSection('content') ?>

</div>
  <?= view('components/app/scripts') ?>
</body>
</html>
