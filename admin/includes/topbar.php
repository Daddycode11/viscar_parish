<?php
require_once __DIR__.'/../../includes/icons.php';
/**
 * admin/includes/topbar.php
 * Set $page_title and $page_sub before including.
 */
$page_title = $page_title ?? 'Dashboard';
$page_sub   = $page_sub   ?? 'Overview';
?>
<div class="topbar">
  <button class="sb-toggle" id="sbToggle" aria-label="Toggle sidebar">
    <?= ui_icon('menu') ?>
  </button>

  <div>
    <div class="topbar-page"><?= htmlspecialchars($page_title) ?></div>
    <div class="topbar-crumb">Admin <span>›</span> <span><?= htmlspecialchars($page_sub) ?></span></div>
  </div>

  <div class="topbar-right">
    <div class="t-search">
      <span style="font-size:.8rem;color:var(--ink-30)"><?= ui_icon('search') ?></span>
      <input type="text" placeholder="Search…" aria-label="Search">
    </div>
    <a href="notifications.php" class="t-icon-btn" title="Notifications">
      <?= ui_icon('bell') ?><span class="dot"></span>
    </a>
    <a href="announcements.php" class="t-icon-btn" title="Announcements"><?= ui_icon('mail') ?></a>
  </div>
</div>