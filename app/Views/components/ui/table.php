<?php
$title = $title ?? null;
$columns = $columns ?? [];
$rows = $rows ?? [];
$emptyTitle = $emptyTitle ?? 'No records';
$emptyMessage = $emptyMessage ?? 'Development placeholder';
?>
<div class="card">
  <?php if ($title): ?>
    <div class="card-header">
      <h5><?= esc($title) ?></h5>
    </div>
  <?php endif; ?>
  <div class="card-body table-border-style">
    <?php if ($rows === []): ?>
      <?= view('components/ui/empty_state', [
          'icon' => 'ti ti-table',
          'title' => $emptyTitle,
          'message' => $emptyMessage,
      ]) ?>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <?php foreach ($columns as $column): ?>
                <th><?= esc($column) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <?php foreach ($row as $cell): ?>
                  <td><?= esc($cell) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
