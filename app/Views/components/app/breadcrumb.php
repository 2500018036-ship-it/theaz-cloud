<?php
$pageTitle = $pageTitle ?? $title ?? 'Dashboard';
$breadcrumbs = $breadcrumbs ?? [];
?>
<div class="page-header">
  <div class="page-block">
    <div class="row align-items-center">
      <div class="col-md-12">
        <div class="page-header-title">
          <h5 class="m-b-10"><?= esc($pageTitle) ?></h5>
        </div>
        <ul class="breadcrumb">
          <li class="breadcrumb-item"><a href="<?= site_url('/') ?>"><?= esc(lang('UI.t3a78695388b3')) ?></a></li>
          <?php foreach ($breadcrumbs as $breadcrumb): ?>
            <?php if (! empty($breadcrumb['url'])): ?>
              <li class="breadcrumb-item"><a href="<?= esc($breadcrumb['url']) ?>"><?= esc($breadcrumb['label']) ?></a></li>
            <?php else: ?>
              <li class="breadcrumb-item" aria-current="page"><?= esc($breadcrumb['label']) ?></li>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($breadcrumbs === []): ?>
            <li class="breadcrumb-item" aria-current="page"><?= esc($pageTitle) ?></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
