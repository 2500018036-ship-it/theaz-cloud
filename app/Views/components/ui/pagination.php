<?php
$currentPage = max(1, (int) ($currentPage ?? 1));
$totalPages = max(1, (int) ($totalPages ?? 1));
$baseUrl = $baseUrl ?? current_url();
?>
<nav aria-label="<?= esc(lang('UI.t024148d6099b'), 'attr') ?>">
  <ul class="pagination mb-0">
    <li class="page-item<?= $currentPage <= 1 ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= esc($currentPage <= 1 ? '#!' : $baseUrl . '?page=' . ($currentPage - 1)) ?>"><?= esc(lang('UI.ta57b08a480b8')) ?></a>
    </li>
    <?php for ($page = 1; $page <= $totalPages; $page++): ?>
      <li class="page-item<?= $page === $currentPage ? ' active' : '' ?>">
        <a class="page-link" href="<?= esc($baseUrl . '?page=' . $page) ?>"><?= $page ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item<?= $currentPage >= $totalPages ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= esc($currentPage >= $totalPages ? '#!' : $baseUrl . '?page=' . ($currentPage + 1)) ?>"><?= esc(lang('UI.t1ff57a29d7c9')) ?></a>
    </li>
  </ul>
</nav>
