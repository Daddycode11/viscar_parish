<?php
require_once __DIR__.'/../../includes/icons.php';
/**
 * admin/includes/sidebar.php
 * Include on every admin page.
 * Requires: $user (from currentUser()), $conn (from db.php)
 */
$cur = basename($_SERVER['PHP_SELF']);

// Live badge counts
$pending_apps = (int)($conn->query("SELECT COUNT(*) as t FROM applications WHERE status='pending'")->fetch_assoc()['t'] ?? 0);
$unread_notif = 3; // replace with real query when notification table exists
?>
<aside class="sidebar" id="sidebar">

  <!-- Brand -->
  <div class="sb-brand">
    <img src="../assets/img/church-logo.png" alt="Logo" onerror="this.classList.add('err')">
    <div class="sb-brand-fb">AV</div>
    <div class="sb-brand-text">
      <strong>Apostolic Vicariate</strong>
      <small>Admin Panel</small>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="sb-nav" role="navigation">

    <div class="sb-group">
      <div class="sb-group-label">Overview</div>
      <a href="dashboard.php"  class="sb-link <?= $cur==='dashboard.php'  ? 'active':'' ?>"><span class="si"><?= ui_icon('dashboard') ?></span> Dashboard</a>
      <a href="analytics.php"  class="sb-link <?= $cur==='analytics.php'  ? 'active':'' ?>"><span class="si"><?= ui_icon('chart') ?></span> Analytics</a>
    </div>

    <div class="sb-group">
      <div class="sb-group-label">Management</div>
      <a href="parishes.php"     class="sb-link <?= $cur==='parishes.php'     ? 'active':'' ?>">
        <span class="si"><?= ui_icon('church') ?></span> Parish Management
      </a>
      <a href="users.php"        class="sb-link <?= $cur==='users.php'        ? 'active':'' ?>">
        <span class="si"><?= ui_icon('user') ?></span> User Management
      </a>
      <a href="applications.php" class="sb-link <?= $cur==='applications.php' ? 'active':'' ?>">
        <span class="si"><?= ui_icon('clipboard') ?></span> Applications
        <?php if($pending_apps > 0): ?>
          <span class="sb-badge sb-badge-red"><?= $pending_apps ?></span>
        <?php endif; ?>
      </a>
    </div>

    <div class="sb-group">
      <div class="sb-group-label">Finance</div>
      <a href="finance.php"  class="sb-link <?= $cur==='finance.php'  ? 'active':'' ?>"><span class="si"><?= ui_icon('wallet') ?></span> Financial Oversight</a>
      <a href="reports.php"  class="sb-link <?= $cur==='reports.php'  ? 'active':'' ?>"><span class="si"><?= ui_icon('chart') ?></span> Reports &amp; Export</a>
    </div>

    <div class="sb-group">
      <div class="sb-group-label">Communication</div>
      <a href="announcements.php"  class="sb-link <?= $cur==='announcements.php'  ? 'active':'' ?>"><span class="si"><?= ui_icon('announcement') ?></span> Announcements</a>
      <a href="notifications.php"  class="sb-link <?= $cur==='notifications.php'  ? 'active':'' ?>">
        <span class="si"><?= ui_icon('bell') ?></span> Notifications
        <?php if($unread_notif > 0): ?>
          <span class="sb-badge sb-badge-grn"><?= $unread_notif ?></span>
        <?php endif; ?>
      </a>
    </div>

    <div class="sb-group">
      <div class="sb-group-label">System</div>
      <a href="backup.php"   class="sb-link <?= $cur==='backup.php'   ? 'active':'' ?>"><span class="si"><?= ui_icon('save') ?></span> Backup &amp; Restore</a>
      <a href="settings.php" class="sb-link <?= $cur==='settings.php' ? 'active':'' ?>"><span class="si"><?= ui_icon('settings') ?></span> Settings</a>
    </div>

  </nav>

  <!-- Footer / User -->
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= profile_avatar($user) ?></div>
      <div class="sb-user-info">
        <strong><?= htmlspecialchars($user['name']) ?></strong>
        <small>Administrator</small>
      </div>
    </div>
    <a href="../public/logout.php" class="sb-logout"><?= ui_icon('logout') ?> &nbsp;Sign Out</a>
  </div>

</aside>