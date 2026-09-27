<?php
// Presentation only: shared by standalone forms and role layouts.
require_once __DIR__.'/icons.php';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/password-visibility.css'), ENT_QUOTES, 'UTF-8') ?>">
<script defer src="<?= htmlspecialchars(app_url('assets/js/password-visibility.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<template id="password-visibility-icon"><?= ui_icon('eye') ?></template>
