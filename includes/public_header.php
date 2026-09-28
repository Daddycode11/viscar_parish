<?php
// Shared white header for public/login.php and public/signup.php (matches index.php)
require_once __DIR__ . '/site_settings.php';
if (!function_exists('ui_icon') && is_file(__DIR__ . '/icons.php')) {
    require_once __DIR__ . '/icons.php';
}

$ss      = site_settings();
$logoSrc = '../' . ltrim($ss['site_logo'] ?? 'assets/img/church-logo.png', '/');
$siteName = $ss['site_name'] ?? 'Apostolic Vicariate of San Jose';

// Set these BEFORE including this file
$headerCtaHref  = $headerCtaHref  ?? 'register.php';
$headerCtaLabel = $headerCtaLabel ?? 'Register';
$headerCtaIcon  = $headerCtaIcon  ?? 'user';
$ctaIconHtml    = function_exists('ui_icon') ? ui_icon($headerCtaIcon) : '';
?>
<style>
/* ── Header (same look as index.php) ───────────────────── */
header.site-header{
  position:sticky !important;top:0 !important;left:auto !important;right:auto !important;
  z-index:500;width:100%;height:72px;
  background:rgba(250,247,242,.95);
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  border-bottom:1px solid rgba(26,21,16,.1);
  padding:0 5vw;display:flex;align-items:center;justify-content:space-between;
  box-shadow:none;color:#1A1510;
}
header.site-header .sh-logo{display:flex;align-items:center;gap:12px;flex-shrink:0;text-decoration:none;color:inherit}
header.site-header .sh-logo img{
  width:46px;height:46px;border-radius:50%;object-fit:contain;background:#fff;
  border:1px solid #C9A84C;box-shadow:0 2px 12px rgba(201,168,76,.25);flex-shrink:0
}
header.site-header .sh-text{display:flex;flex-direction:column;line-height:1.15}
header.site-header .sh-text span:first-child{
  font-family:"Cormorant Garamond",Georgia,serif;font-size:1.05rem;font-weight:600;color:#1A1510;white-space:nowrap
}
header.site-header .sh-text span:last-child{
  font-family:"DM Sans",sans-serif;font-size:.68rem;font-weight:300;letter-spacing:.08em;
  color:rgba(26,21,16,.6);text-transform:uppercase
}
header.site-header nav{display:flex;align-items:center;gap:4px}
header.site-header nav a{
  font-family:"DM Sans",sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.04em;
  padding:7px 14px;border-radius:30px;color:rgba(26,21,16,.6);text-decoration:none;
  transition:.35s cubic-bezier(.4,0,.2,1);white-space:nowrap;background:transparent
}
header.site-header nav a:hover{color:#1A1510;background:rgba(26,21,16,.1)}
header.site-header nav a.nav-btn{background:#1B2A4A;color:#fff;padding:8px 20px}
header.site-header nav a.nav-btn:hover{background:#C9A84C;color:#1A1510}

/* ── Remove leftover space from old fixed-header layout ── */
body{padding-top:0 !important;margin-top:0 !important}
header.site-header + .page,
header.site-header ~ .page{
  padding-top:0 !important;
  margin-top:0 !important;
  min-height:calc(100vh - 72px) !important;
}

@media (max-width:1024px){
  header.site-header .sh-text span:last-child{display:none}
}
@media (max-width:640px){
  header.site-header{height:64px;padding:0 4vw}
  header.site-header .sh-logo{min-width:0;gap:8px}
  header.site-header .sh-logo img{width:38px;height:38px}
  header.site-header .sh-text span:first-child{white-space:normal;font-size:.9rem}
  header.site-header nav a.nav-btn{padding:8px 12px}
  header.site-header ~ .page{min-height:calc(100vh - 64px) !important}
}
</style>

<header class="site-header">
  <a class="sh-logo" href="../index.php">
    <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?= htmlspecialchars($siteName) ?> Logo">
    <div class="sh-text">
      <span><?= htmlspecialchars($siteName) ?></span>
      <span>Parish Service Platform</span>
    </div>
  </a>
  <nav>
    <a href="../index.php">Home</a>
    <a href="<?= htmlspecialchars($headerCtaHref) ?>" class="nav-btn"><?= $ctaIconHtml ?> <?= htmlspecialchars($headerCtaLabel) ?></a>
  </nav>
</header>