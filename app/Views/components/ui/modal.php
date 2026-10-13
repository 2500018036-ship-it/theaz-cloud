<?php
$id = $id ?? 'app-modal';
$title = $title ?? 'Details';
$body = $body ?? '';
$footer = $footer ?? '';
?>
<div class="modal fade" id="<?= esc($id) ?>" tabindex="-1" aria-labelledby="<?= esc($id) ?>-label" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="<?= esc($id) ?>-label"><?= esc($title) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?= $body ?>
      </div>
      <?php if ($footer !== ''): ?>
        <div class="modal-footer">
          <?= $footer ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
