<?php
$title = $title ?? 'Form';
$body = $body ?? '';
$footer = $footer ?? '';
?>
<div class="card">
  <div class="card-header">
    <h5><?= esc($title) ?></h5>
  </div>
  <div class="card-body">
    <?= $body ?>
  </div>
  <?php if ($footer !== ''): ?>
    <div class="card-footer">
      <?= $footer ?>
    </div>
  <?php endif; ?>
</div>
