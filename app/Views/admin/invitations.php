<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$invitations = $invitations ?? [];
$pager = $pager ?? ['page' => 1, 'total' => 0, 'totalPages' => 1];
$query = $query ?? ['q' => '', 'status' => ''];

$formatDate = static function ($value, string $fallback = '-') {
    if ($value === null || $value === '') {
        return $fallback;
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? $fallback : date('Y-m-d H:i', $timestamp);
};

$datetimeInput = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'active' => 'bg-light-success text-success',
        'consumed' => 'bg-light-primary text-primary',
        'revoked' => 'bg-light-danger text-danger',
        'expired' => 'bg-light-warning text-warning',
        default => 'bg-light-secondary text-secondary',
    };
};

$formatBytes = static function (?int $bytes): string {
    if ($bytes === null || $bytes <= 0) {
        return 'Default';
    }

    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    return $bytes . ' B';
};

$urlWith = static function (array $overrides = []) use ($query): string {
    $params = array_merge($query, $overrides);
    $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

    return site_url('admin/invitations') . ($params === [] ? '' : '?' . http_build_query($params));
};
?>

<?php if (session('invitation_code')): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <div class="d-flex align-items-center gap-2">
      <i class="ti ti-ticket f-20"></i>
      <div>
        <div class="fw-semibold"><?= esc(lang('UI.t5f9521c3cf28')) ?></div>
        <code class="fs-6"><?= esc(session('invitation_code')) ?></code>
      </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= esc(lang('UI.t7d9eb7acb13e'), 'attr') ?>"></button>
  </div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header">
        <h5><?= esc(lang('UI.t9d7975e01b1a')) ?></h5>
      </div>
      <div class="card-body">
        <form action="<?= site_url('admin/invitations') ?>" method="post" novalidate>
          <?= csrf_field() ?>
          <div class="form-group mb-3">
            <label class="form-label" for="label"><?= esc(lang('UI.t0e66373f45dc')) ?></label>
            <input type="text" class="form-control" id="label" name="label" value="<?= esc(old('label')) ?>" maxlength="120" placeholder="<?= esc(lang('UI.te51f3f133229'), 'attr') ?>">
          </div>
          <div class="form-group mb-3">
            <label class="form-label" for="max_uses"><?= esc(lang('UI.t8d0c3c50ee59')) ?></label>
            <input type="number" class="form-control" id="max_uses" name="max_uses" value="<?= esc(old('max_uses', '1')) ?>" min="1" max="1000" required>
          </div>
          <div class="form-group mb-4">
            <label class="form-label" for="expires_at"><?= esc(lang('UI.t884da2adb047')) ?></label>
            <input type="datetime-local" class="form-control" id="expires_at" name="expires_at" value="<?= esc(old('expires_at')) ?>">
          </div>
          <div class="row g-2 mb-4">
            <div class="col-7">
              <label class="form-label" for="quota_value"><?= esc(lang('UI.tce13a24cac0d')) ?></label>
              <input type="number" class="form-control" id="quota_value" name="quota_value" min="0" step="0.01" value="<?= esc(old('quota_value')) ?>" placeholder="<?= esc(lang('UI.tb0459211f9f7'), 'attr') ?>">
            </div>
            <div class="col-5">
              <label class="form-label" for="quota_unit"><?= esc(lang('UI.t4e545960f1bf')) ?></label>
              <select class="form-select" id="quota_unit" name="quota_unit">
                <option value="gb">GB</option>
                <option value="mb">MB</option>
                <option value="tb">TB</option>
              </select>
            </div>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-primary">
              <i class="ti ti-plus me-1"></i><?= esc(lang('UI.t4759498ac2a7')) ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header">
        <div class="row g-3 align-items-end">
          <div class="col-lg-4">
            <h5 class="mb-1"><?= esc(lang('UI.tcc6ea86a51d3')) ?></h5>
            <p class="text-muted mb-0"><?= esc(lang('UI.t52d71df8b1b5')) ?></p>
          </div>
          <div class="col-lg-8">
            <form action="<?= site_url('admin/invitations') ?>" method="get" class="row g-2 justify-content-lg-end">
              <div class="col-md-5">
                <input type="search" name="q" class="form-control form-control-sm" value="<?= esc($query['q'] ?? '') ?>" placeholder="<?= esc(lang('UI.t41fb804d2795'), 'attr') ?>">
              </div>
              <div class="col-md-4">
                <select name="status" class="form-select form-select-sm">
                  <option value=""><?= esc(lang('UI.t32b9b2c4de19')) ?></option>
                  <?php foreach (['active', 'consumed', 'revoked', 'expired'] as $statusOption): ?>
                    <option value="<?= esc($statusOption) ?>"<?= ($query['status'] ?? '') === $statusOption ? ' selected' : '' ?>><?= esc(ucfirst($statusOption)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i><?= esc(lang('UI.t31e392d1c037')) ?></button>
                <a href="<?= site_url('admin/invitations') ?>" class="btn btn-sm btn-light-secondary"><i class="ti ti-x me-1"></i><?= esc(lang('UI.t83b12c2216ef')) ?></a>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div class="card-body table-border-style">
        <?php if ($invitations === []): ?>
          <?= view('components/ui/empty_state', [
              'icon' => 'ti ti-ticket',
              'title' => lang('UI.t60243e9ada8e'),
              'message' => lang('UI.t7aa769efa437'),
          ]) ?>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th><?= esc(lang('UI.t0e66373f45dc')) ?></th>
                  <th><?= esc(lang('UI.t920e413c7d41')) ?></th>
                  <th><?= esc(lang('UI.t8d59829c1e15')) ?></th>
                  <th><?= esc(lang('UI.t6c105cae1a20')) ?></th>
                  <th><?= esc(lang('UI.tf6725f3af08a')) ?></th>
                  <th><?= esc(lang('UI.td70b9e24bca2')) ?></th>
                  <th class="text-end"><?= esc(lang('UI.t64cff1319d2f')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($invitations as $invitation): ?>
                  <?php $status = (string) ($invitation['status'] ?? 'active'); ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= esc($invitation['label'] ?: 'Unlabeled invitation') ?></div>
                      <div class="text-muted small"><?= esc($invitation['created_by_email'] ?: 'System') ?></div>
                    </td>
                    <td><span class="badge <?= esc($statusClass($status)) ?>"><?= esc(ucfirst($status)) ?></span></td>
                    <td><?= esc((string) ($invitation['use_count'] ?? 0)) ?> / <?= esc((string) ($invitation['max_uses'] ?? 1)) ?></td>
                    <td><?= esc($formatBytes(isset($invitation['storage_quota_bytes']) ? (int) $invitation['storage_quota_bytes'] : null)) ?></td>
                    <td><?= esc($formatDate($invitation['expires_at'] ?? null, 'Never')) ?></td>
                    <td><?= esc($formatDate($invitation['created_at'] ?? null)) ?></td>
                    <td class="text-end">
                      <div class="d-flex flex-wrap gap-2 justify-content-end">
                        <?php if (in_array($status, ['active', 'expired'], true)): ?>
                          <button type="button" class="btn btn-sm btn-light-primary" data-bs-toggle="collapse" data-bs-target="#invitation-edit-<?= esc((string) $invitation['id']) ?>"><i class="ti ti-pencil me-1"></i><?= esc(lang('UI.t464c4ffd019e')) ?></button>
                        <?php endif; ?>
                        <?php if ($status === 'active'): ?>
                          <form action="<?= site_url('admin/invitations/' . $invitation['id'] . '/revoke') ?>" method="post" class="d-inline" data-admin-confirm="Revoke this invitation?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-light-danger">
                              <i class="ti ti-ban me-1"></i><?= esc(lang('UI.t87e6d00bbf53')) ?>
                            </button>
                          </form>
                        <?php else: ?>
                          <span class="text-muted small"><?= esc($status === 'revoked' ? 'Revoked by ' . ($invitation['revoked_by_email'] ?: 'admin') : 'No action') ?></span>
                        <?php endif; ?>
                        <?php if (in_array($status, ['expired', 'revoked'], true)): ?>
                          <form action="<?= site_url('admin/invitations/' . $invitation['id'] . '/delete') ?>" method="post" data-admin-confirm="Delete this invitation?">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-light-danger" type="submit"><i class="ti ti-trash me-1"></i><?= esc(lang('UI.te2d0a54968ea')) ?></button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                  <?php if (in_array($status, ['active', 'expired'], true)): ?>
                    <tr class="collapse" id="invitation-edit-<?= esc((string) $invitation['id']) ?>">
                      <td colspan="7">
                        <form action="<?= site_url('admin/invitations/' . $invitation['id'] . '/update') ?>" method="post" class="row g-2 align-items-end">
                          <?= csrf_field() ?>
                          <div class="col-md-4">
                            <label class="form-label small mb-1"><?= esc(lang('UI.t0e66373f45dc')) ?></label>
                            <input type="text" name="label" class="form-control form-control-sm" value="<?= esc((string) ($invitation['label'] ?? '')) ?>" maxlength="120">
                          </div>
                          <div class="col-md-3">
                            <label class="form-label small mb-1"><?= esc(lang('UI.t8d0c3c50ee59')) ?></label>
                            <input type="number" name="max_uses" class="form-control form-control-sm" min="<?= esc((string) ($invitation['use_count'] ?? 0)) ?>" max="1000" value="<?= esc((string) ($invitation['max_uses'] ?? 1)) ?>">
                          </div>
                          <div class="col-md-3">
                            <label class="form-label small mb-1"><?= esc(lang('UI.t884da2adb047')) ?></label>
                            <input type="datetime-local" name="expires_at" class="form-control form-control-sm" value="<?= esc($datetimeInput($invitation['expires_at'] ?? null)) ?>">
                          </div>
                          <div class="col-md-1">
                            <label class="form-label small mb-1"><?= esc(lang('UI.t6c105cae1a20')) ?></label>
                            <input type="number" name="quota_value" class="form-control form-control-sm" min="0" step="0.01" value="<?= ! empty($invitation['storage_quota_bytes']) ? esc((string) round(((int) $invitation['storage_quota_bytes']) / 1073741824, 2)) : '' ?>">
                          </div>
                          <div class="col-md-1">
                            <label class="form-label small mb-1"><?= esc(lang('UI.t4e545960f1bf')) ?></label>
                            <select name="quota_unit" class="form-select form-select-sm">
                              <option value="gb">GB</option>
                              <option value="mb">MB</option>
                              <option value="tb">TB</option>
                            </select>
                          </div>
                          <div class="col-md-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy me-1"></i><?= esc(lang('UI.t1509f561f241')) ?></button>
                          </div>
                        </form>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span class="text-muted small"><?= esc((string) $pager['total']) ?> <?= esc(lang('UI.tb812af91e9d6')) ?></span>
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
  </div>
</div>
<?= $this->endSection() ?>
