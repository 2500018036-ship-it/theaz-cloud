<?= $this->extend('layouts/user') ?>

<?= $this->section('content') ?>
<?php
$driveOverview = $driveOverview ?? [
    'storage' => ['used' => 0, 'quota' => 1, 'percentage' => 0],
    'recent' => [],
    'starred' => 0,
    'trash' => 0,
];

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' B';
};

$recentRows = array_map(static function (array $item) use ($formatBytes): array {
    $timestamp = empty($item['updated_at']) ? false : strtotime((string) $item['updated_at']);

    return [
        $item['name'],
        ucfirst((string) $item['type']),
        $formatBytes((int) $item['size_bytes']),
        $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp),
    ];
}, $driveOverview['recent']);
?>
<div class="row">
  <div class="col-md-6 col-xl-4">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted"><?= esc(lang('UI.t2f89b69f7a4c')) ?></h6>
            <h4 class="mb-0"><?= esc($formatBytes((int) $driveOverview['storage']['used'])) ?></h4>
          </div>
          <div class="avtar bg-light-primary text-primary">
            <i class="ti ti-cloud f-22"></i>
          </div>
        </div>
        <div class="progress mt-3" style="height: 6px">
          <div class="progress-bar bg-primary" style="width: <?= esc((string) $driveOverview['storage']['percentage']) ?>%"></div>
        </div>
        <p class="text-muted mb-0 mt-2"><?= esc($formatBytes((int) $driveOverview['storage']['quota'])) ?> <?= esc(lang('UI.tb878a6801d9a')) ?></p>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted"><?= esc(lang('UI.t7b156e50d043')) ?></h6>
            <h4 class="mb-0"><?= esc((string) count($driveOverview['recent'])) ?></h4>
          </div>
          <div class="avtar bg-light-success text-success">
            <i class="ti ti-clock f-22"></i>
          </div>
        </div>
        <p class="text-muted mb-0 mt-3"><?= esc((string) $driveOverview['starred']) ?> <?= esc(lang('UI.tee0c88a77bce')) ?> <?= esc((string) $driveOverview['trash']) ?> <?= esc(lang('UI.t425f479206ad')) ?></p>
      </div>
    </div>
  </div>
  <div class="col-md-12 col-xl-4">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6 class="mb-2 f-w-400 text-muted"><?= esc(lang('UI.t2cc2b6f7f200')) ?></h6>
            <h4 class="mb-0"><?= esc(lang('UI.t5fa7aac5375c')) ?></h4>
          </div>
          <div class="avtar bg-light-warning text-warning">
            <i class="ti ti-tools f-22"></i>
          </div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <a href="<?= site_url('drive') ?>" class="btn btn-primary"><i class="ti ti-upload"></i> <?= esc(lang('UI.t865e89de78d9')) ?></a>
          <a href="<?= site_url('drive') ?>" class="btn btn-light-secondary"><i class="ti ti-folder-plus"></i> <?= esc(lang('UI.t74ccd4330384')) ?></a>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-xl-8">
    <?= view('components/ui/table', [
        'title' => lang('UI.t7b156e50d043'),
        'columns' => ['Name', 'Type', 'Size', 'Updated'],
        'rows' => $recentRows,
        'emptyTitle' => 'No files to show',
        'emptyMessage' => 'Upload files from My Drive.',
    ]) ?>
  </div>
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header">
        <h5><?= esc(lang('UI.t87bb59ba2f92')) ?></h5>
      </div>
      <div class="card-body">
        <div class="d-grid gap-2">
          <a href="<?= site_url('drive') ?>" class="btn btn-light-primary"><i class="ti ti-folder"></i> <?= esc(lang('UI.t428d9cf8159f')) ?></a>
          <a href="<?= site_url('drive/recent') ?>" class="btn btn-light-secondary"><i class="ti ti-clock"></i> <?= esc(lang('UI.t7b156e50d043')) ?></a>
          <a href="<?= site_url('drive/starred') ?>" class="btn btn-light-warning"><i class="ti ti-star"></i> <?= esc(lang('UI.t07a2d579c3f0')) ?></a>
          <a href="<?= site_url('drive/trash') ?>" class="btn btn-light-danger"><i class="ti ti-trash"></i> <?= esc(lang('UI.tc560122ac470')) ?></a>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
