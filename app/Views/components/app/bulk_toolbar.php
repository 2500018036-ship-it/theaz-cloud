<form id="<?= esc($id, 'attr') ?>" data-selection-form<?= ! empty($downloadAction) ? ' data-download-action="' . esc($downloadAction, 'attr') . '"' : '' ?> action="<?= esc($action) ?>" method="post" class="d-flex flex-wrap gap-2 align-items-center mb-3" data-confirm="Apply this action to the selected items?">
  <?= csrf_field() ?>
  <?php foreach (($hidden ?? []) as $name => $value): ?><input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc((string) $value, 'attr') ?>"><?php endforeach; ?>
  <label class="form-check d-flex gap-2 align-items-center mb-0">
    <input class="form-check-input mt-0" type="checkbox" data-select-all="<?= esc($id, 'attr') ?>">
    <span><?= esc(lang('App.actions.selectAll')) ?></span>
  </label>
  <button type="button" class="btn btn-sm btn-light-secondary" data-select-clear="<?= esc($id, 'attr') ?>"><?= esc(lang('App.actions.clearSelection')) ?></button>
  <span class="small text-muted" data-selected-count>0</span>
  <select name="bulk_action" class="form-select form-select-sm w-auto" aria-label="<?= esc(lang('App.actions.bulk'), 'attr') ?>">
    <?php foreach ($options as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" data-selection-submit class="btn btn-sm btn-outline-danger" disabled><?= esc(lang('App.actions.apply')) ?></button>
</form>
