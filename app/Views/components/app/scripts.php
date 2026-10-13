<?php $assetBase = base_url('template/dist/assets'); ?>
<script src="<?= $assetBase ?>/js/plugins/popper.min.js"></script>
<script src="<?= $assetBase ?>/js/plugins/simplebar.min.js"></script>
<script src="<?= $assetBase ?>/js/plugins/bootstrap.min.js"></script>
<script src="<?= $assetBase ?>/js/fonts/custom-font.js"></script>
<script>window.__THEAZ_ASSET_BASE__ = <?= json_encode($assetBase, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= $assetBase ?>/js/pcoded.js"></script>
<script src="<?= $assetBase ?>/js/plugins/feather.min.js"></script>
<script>layout_change('light');</script>
<script>change_box_container('false');</script>
<script>layout_rtl_change('false');</script>
<script>preset_change('preset-1');</script>
<script>font_change('Public-Sans');</script>
<?= $this->renderSection('pageScripts') ?>
<script src="<?= base_url('assets/js/drive-ui.js') ?>"></script>
<script src="<?= base_url('assets/js/drive-upload.js') ?>"></script>
<script src="<?= base_url('assets/js/admin-ui.js') ?>"></script>
