<?php
$icon = $icon ?? 'ti ti-inbox';
$title = $title ?? 'No data';
$message = $message ?? 'Development placeholder';
$compact = (bool) ($compact ?? false);
?>
<div class="text-center<?= $compact ? ' py-2' : ' py-5' ?>">
  <div class="avtar avtar-s bg-light-secondary text-secondary mx-auto mb-3">
    <i class="<?= esc($icon) ?> f-20"></i>
  </div>
  <h6 class="mb-1"><?= esc($title) ?></h6>
  <p class="text-muted mb-0"><?= esc($message) ?></p>
</div>
