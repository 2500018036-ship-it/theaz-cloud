<?php
$currentLocale = (string) (session('locale') ?? service('request')->getLocale() ?? 'en');
$currentLocale = in_array($currentLocale, ['en', 'id'], true) ? $currentLocale : 'en';
$label = $currentLocale === 'id' ? 'ID' : 'EN';
$compact = (bool) ($compact ?? false);
$request = service('request');
$returnTo = '/' . ltrim(uri_string(), '/');
$query = $request->getUri()->getQuery();
if ($query !== '') {
    $returnTo .= '?' . $query;
}
?>
<<?= $compact ? 'div' : 'li' ?> class="dropdown <?= $compact ? 'd-inline-block' : 'pc-h-item' ?>">
  <a class="<?= $compact ? 'btn btn-sm btn-light-secondary dropdown-toggle' : 'pc-head-link dropdown-toggle arrow-none me-0' ?>" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false" title="<?= esc(lang('App.settings.language')) ?>">
    <i class="ti ti-language"></i>
    <span class="<?= $compact ? '' : 'ms-1' ?>"><?= esc($label) ?></span>
  </a>
  <div class="dropdown-menu dropdown-menu-end">
    <?php foreach (['en' => lang('App.settings.english'), 'id' => lang('App.settings.indonesian')] as $locale => $name): ?>
      <form action="<?= site_url('language') ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="locale" value="<?= esc($locale) ?>">
        <input type="hidden" name="return_to" value="<?= esc($returnTo) ?>">
        <button type="submit" class="dropdown-item d-flex align-items-center justify-content-between<?= $currentLocale === $locale ? ' active' : '' ?>">
          <span><?= esc($name) ?></span>
          <?php if ($currentLocale === $locale): ?>
            <i class="ti ti-check ms-3"></i>
          <?php endif; ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
</<?= $compact ? 'div' : 'li' ?>>
