<?php
$id = $id ?? 'app-dropdown';
$label = $label ?? 'Actions';
$icon = $icon ?? 'ti ti-dots';
$items = $items ?? [];
?>
<div class="dropdown">
  <button class="btn btn-light-secondary dropdown-toggle" type="button" id="<?= esc($id) ?>" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="<?= esc($icon) ?> me-1"></i>
    <?= esc($label) ?>
  </button>
  <ul class="dropdown-menu" aria-labelledby="<?= esc($id) ?>">
    <?php foreach ($items as $item): ?>
      <li>
        <a class="dropdown-item<?= ! empty($item['disabled']) ? ' disabled' : '' ?>" href="<?= esc($item['url'] ?? '#!') ?>">
          <?php if (! empty($item['icon'])): ?>
            <i class="<?= esc($item['icon']) ?>"></i>
          <?php endif; ?>
          <span><?= esc($item['label'] ?? '') ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
