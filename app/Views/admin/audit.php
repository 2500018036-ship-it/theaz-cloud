<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= view('activity/log_table', [
    'logs' => $logs ?? [],
    'pager' => $pager ?? [],
    'query' => $query ?? [],
    'basePath' => site_url('admin/audit'),
    'canDelete' => (bool) ($canDelete ?? false),
]) ?>
<?= $this->endSection() ?>
