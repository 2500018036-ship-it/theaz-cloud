<?= $this->extend('layouts/user') ?>

<?= $this->section('content') ?>
<?= view('activity/log_table', [
    'logs' => $logs ?? [],
    'pager' => $pager ?? [],
    'query' => $query ?? [],
    'basePath' => site_url('drive/activity'),
]) ?>
<?= $this->endSection() ?>
