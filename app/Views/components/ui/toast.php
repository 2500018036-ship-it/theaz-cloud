<?php
$id = $id ?? 'app-toast';
$title = $title ?? 'Notification';
$message = $message ?? '';
$variant = $variant ?? 'primary';
?>
<?php if ($message !== ''): ?>
  <div class="toast-container position-fixed top-0 end-0 p-3">
    <div id="<?= esc($id) ?>" class="toast text-bg-<?= esc($variant) ?> border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
      <div class="d-flex">
        <div class="toast-body">
          <strong><?= esc($title) ?>:</strong> <?= esc($message) ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="<?= esc(lang('UI.t7d9eb7acb13e'), 'attr') ?>"></button>
      </div>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var toastElement = document.getElementById('<?= esc($id) ?>');
      if (toastElement && window.bootstrap) {
        new bootstrap.Toast(toastElement).show();
      }
    });
  </script>
<?php endif; ?>
