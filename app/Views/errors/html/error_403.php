<?= $this->extend('layouts/error') ?>

<?= $this->section('content') ?>
<div class="avtar avtar-xl bg-light-danger text-danger mx-auto mb-4">
  <i class="ti ti-lock f-28"></i>
</div>
<h3 class="mb-2"><b>403 Forbidden</b></h3>
<p class="text-muted mb-4">You do not have permission to access this page.</p>
<a href="<?= site_url('/') ?>" class="btn btn-primary"><i class="ti ti-home me-1"></i>Back to Home</a>
<?= $this->endSection() ?>
