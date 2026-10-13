<?php
$currentPage = max(1, (int) ($currentPage ?? 1));
$totalPages = max(1, (int) ($totalPages ?? 1));
$baseUrl = $baseUrl ?? current_url();
?>
<nav aria-label="Page navigation">
  <ul class="pagination mb-0">
    <li class="page-item<?= $currentPage <= 1 ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= esc($currentPage <= 1 ? '#!' : $baseUrl . '?page=' . ($currentPage - 1)) ?>">Previous</a>
    </li>
    <?php for ($page = 1; $page <= $totalPages; $page++): ?>
      <li class="page-item<?= $page === $currentPage ? ' active' : '' ?>">
        <a class="page-link" href="<?= esc($baseUrl . '?page=' . $page) ?>"><?= $page ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item<?= $currentPage >= $totalPages ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= esc($currentPage >= $totalPages ? '#!' : $baseUrl . '?page=' . ($currentPage + 1)) ?>">Next</a>
    </li>
  </ul>
</nav>
