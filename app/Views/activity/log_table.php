<?php
$logs = $logs ?? [];
$pager = $pager ?? ['page' => 1, 'perPage' => 25, 'total' => 0, 'totalPages' => 1];
$query = $query ?? ['q' => '', 'action' => '', 'status' => ''];
$basePath = $basePath ?? current_url();
$canDelete = (bool) ($canDelete ?? false);

$formatDate = static function ($value): string {
    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '-' : date('Y-m-d H:i:s', $timestamp);
};

$labelAction = static function (string $action): string {
    return ucwords(str_replace(['.', '_'], ' ', $action));
};

$metadataText = static function (array $metadata): string {
    if ($metadata === []) {
        return '-';
    }

    $pairs = [];
    foreach ($metadata as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }

        $pairs[] = $key . ': ' . (string) $value;
    }

    return implode(', ', $pairs);
};

$urlWith = static function (array $overrides = []) use ($basePath, $query): string {
    $params = array_merge($query, $overrides);
    $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

    return $basePath . ($params === [] ? '' : '?' . http_build_query($params));
};
?>

<div class="card">
  <div class="card-header">
    <div class="row g-3 align-items-end">
      <div class="col-lg-4">
        <h5 class="mb-1">Activity Log</h5>
        <p class="text-muted mb-0">Important events are paginated and filtered without exposing secrets.</p>
      </div>
      <div class="col-lg-8">
        <form action="<?= esc($basePath) ?>" method="get" class="row g-2 justify-content-lg-end">
          <div class="col-md-4">
            <input type="search" name="q" class="form-control form-control-sm" value="<?= esc($query['q'] ?? '') ?>" placeholder="Search activity">
          </div>
          <div class="col-md-3">
            <input type="text" name="action" class="form-control form-control-sm" value="<?= esc($query['action'] ?? '') ?>" placeholder="Action">
          </div>
          <div class="col-md-3">
            <select name="status" class="form-select form-select-sm">
              <option value="">All status</option>
              <option value="success"<?= ($query['status'] ?? '') === 'success' ? ' selected' : '' ?>>Success</option>
              <option value="failure"<?= ($query['status'] ?? '') === 'failure' ? ' selected' : '' ?>>Failure</option>
            </select>
          </div>
          <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Apply</button>
            <a href="<?= esc($basePath) ?>" class="btn btn-sm btn-light-secondary"><i class="ti ti-x me-1"></i>Reset Filters</a>
          </div>
        </form>
      </div>
    </div>
    <?php if ($canDelete && (int) ($pager['total'] ?? 0) > 0): ?>
      <div class="mt-3 d-flex justify-content-end">
        <form action="<?= site_url('admin/audit/clear') ?>" method="post" data-admin-confirm="Clear all activity log entries? This cannot be undone.">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-light-danger">
            <i class="ti ti-trash-x me-1"></i>Clear Logs
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-body table-border-style">
    <?php if ($logs === []): ?>
      <?= view('components/ui/empty_state', [
          'icon' => 'ti ti-activity',
          'title' => 'No activity found',
          'message' => 'Events will appear here as people use the workspace.',
      ]) ?>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Time</th>
              <th>Actor</th>
              <th>Action</th>
              <th>Target</th>
              <th>Status</th>
              <th>Details</th>
              <?php if ($canDelete): ?>
                <th class="text-end">Action</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <?php
                $actor = $log['actor_username'] ?? $log['actor_email'] ?? 'Public / System';
                $target = $log['target_name'] ?? ($log['target_id'] !== null ? '#' . $log['target_id'] : '-');
                $status = (string) ($log['status'] ?? 'success');
              ?>
              <tr>
                <td class="text-nowrap"><?= esc($formatDate($log['created_at'] ?? null)) ?></td>
                <td>
                  <div class="fw-semibold"><?= esc((string) $actor) ?></div>
                  <?php if (! empty($log['subject_username']) || ! empty($log['subject_email'])): ?>
                    <div class="text-muted small">Subject: <?= esc((string) ($log['subject_username'] ?? $log['subject_email'])) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= esc($labelAction((string) $log['action'])) ?></td>
                <td class="text-break"><?= esc((string) $target) ?></td>
                <td>
                  <span class="badge bg-light-<?= $status === 'failure' ? 'danger text-danger' : 'success text-success' ?>">
                    <?= esc(ucfirst($status)) ?>
                  </span>
                </td>
                <td class="text-muted small text-break"><?= esc($metadataText($log['metadata'] ?? [])) ?></td>
                <?php if ($canDelete): ?>
                  <td class="text-end">
                    <form action="<?= site_url('admin/audit/' . (int) $log['id'] . '/delete') ?>" method="post" class="d-inline" data-admin-confirm="Delete this activity log entry?">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-sm btn-light-danger">
                        <i class="ti ti-trash me-1"></i>Delete
                      </button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <span class="text-muted small"><?= esc((string) $pager['total']) ?> event(s)</span>
    <?php if ((int) $pager['totalPages'] > 1): ?>
      <ul class="pagination mb-0">
        <?php for ($page = 1; $page <= (int) $pager['totalPages']; $page++): ?>
          <li class="page-item<?= $page === (int) $pager['page'] ? ' active' : '' ?>">
            <a class="page-link" href="<?= esc($urlWith(['page' => $page])) ?>"><?= $page ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
