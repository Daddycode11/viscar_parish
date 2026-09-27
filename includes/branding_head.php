<?php
require_once __DIR__ . '/site_settings.php';
$branding = site_settings();
?>
<style>:root { --navy: <?= h($branding['color_navy']) ?>; --gold: <?= h($branding['color_gold']) ?>; --wine: <?= h($branding['color_wine']) ?>; }</style>
<?php if ($branding['favicon']): ?><link rel="icon" type="image/png" href="<?= h(app_url($branding['favicon'])) ?>"><?php endif; ?>
<meta name="application-name" content="<?= h($branding['site_name']) ?>">
