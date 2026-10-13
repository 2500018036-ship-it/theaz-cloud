<?php
$assetBase = base_url('template/dist/assets');
$pageTitle = $title ?? $pageTitle ?? 'Theaz Cloud';
$locale = (string) (session('locale') ?? service('request')->getLocale() ?? 'en');
$locale = in_array($locale, ['en', 'id'], true) ? $locale : 'en';
?>
<!DOCTYPE html>
<html lang="<?= esc($locale) ?>">
<head>
  <title><?= esc($pageTitle) ?> | Theaz Cloud</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="description" content="Theaz Cloud">
  <link rel="icon" href="<?= $assetBase ?>/images/favicon.svg" type="image/x-icon">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" id="main-font-link">
  <link rel="stylesheet" href="<?= $assetBase ?>/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="<?= $assetBase ?>/fonts/feather.css">
  <link rel="stylesheet" href="<?= $assetBase ?>/fonts/fontawesome.css">
  <link rel="stylesheet" href="<?= $assetBase ?>/fonts/material.css">
  <link rel="stylesheet" href="<?= $assetBase ?>/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="<?= $assetBase ?>/css/style-preset.css">
  <link rel="stylesheet" href="<?= base_url('assets/css/drive-ui.css') ?>">
  <?= $this->renderSection('pageStyles') ?>
</head>
