<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

require_once '../includes/db.php';
$user = currentUser();
$flash = '';
$notificationUser=(int)$user['id'];

// ── ACTIONS ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['_action'] ?? '';
    if ($act === 'mark_read' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id={$notificationUser}");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $flash = 'success:Notification marked as read.';
    }
    if ($act === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read=1 WHERE is_read=0 AND user_id={$notificationUser}");
        $flash = 'success:All notifications marked as read.';
    }
    if ($act === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id=? AND user_id={$notificationUser}");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $flash = 'success:Notification deleted.';
    }
}

// ── FILTERS ───────────────────────────────────
$type_filter   = $_GET['type']   ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');
$page_num      = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;

$where  = ["n.user_id={$notificationUser}"];
$params = [];
$types  = '';
if ($type_filter) {
    $where[]  = "n.type = ?";
    $params[] = $type_filter; $types .= 's';
}
if ($status_filter === 'unread') {
    $where[] = "n.is_read = 0";
} elseif ($status_filter === 'read') {
    $where[] = "n.is_read = 1";
}
if ($search !== '') {
    $where[]  = "(n.title LIKE ? OR n.message LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$cstmt = $conn->prepare("SELECT COUNT(*) AS t FROM notifications n LEFT JOIN users u ON n.user_id = u.id $where_sql");
if ($params) $cstmt->bind_param($types, ...$params);
$cstmt->execute();
$total = (int)$cstmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Page
$pstmt = $conn->prepare("
    SELECT n.id, n.title, n.message, n.type, n.is_read, n.link, n.created_at,
           u.name AS user_name, u.role AS user_role
    FROM notifications n
    LEFT JOIN users u ON n.user_id = u.id
    $where_sql
    ORDER BY n.created_at DESC
    LIMIT ? OFFSET ?
");
$pparams = array_merge($params, [$per_page, $offset]);
$ptypes  = $types . 'ii';
$pstmt->bind_param($ptypes, ...$pparams);
$pstmt->execute();
$notifications = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stat counts
$total_count    = (int)$conn->query("SELECT COUNT(*) AS t FROM notifications WHERE user_id={$notificationUser}")->fetch_assoc()['t'];
$unread_count   = (int)$conn->query("SELECT COUNT(*) AS t FROM notifications WHERE is_read=0 AND user_id={$notificationUser}")->fetch_assoc()['t'];
$today_count    = (int)$conn->query("SELECT COUNT(*) AS t FROM notifications WHERE user_id={$notificationUser} AND DATE(created_at)=CURDATE()")->fetch_assoc()['t'];

$type_pill = [
    'application'  => 'pill-amber',
    'payment'      => 'pill-green',
    'announcement' => 'pill-navy',
    'message'      => 'pill-gold',
    'system'       => 'pill-navy',
    'schedule'     => 'pill-amber',
    'confirmation' => 'pill-green',
];

$page_id    = 'notifications';
$page_title = 'Notifications';
$page_sub   = 'System';
include 'includes/layout.php';
?>

<?php if ($flash): [$ftype, $fmsg] = explode(':', $flash, 2); ?>
<div class="notice notice-<?php echo $ftype === 'success' ? 'green' : 'amber'; ?>" style="margin-bottom:20px">
  <span><?php echo $ftype === 'success' ? '[icon:check]' : '[icon:alert]'; ?></span>
  <span><?php echo htmlspecialchars($fmsg); ?></span>
</div>
<?php endif; ?>

<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">System</div>
    <h1 class="sec-title">Notifications</h1>
    <p class="sec-sub">All system and user notifications across the platform.</p>
  </div>
  <?php if ($unread_count > 0): ?>
  <form method="POST" action="notifications.php" style="display:inline">
    <input type="hidden" name="_action" value="mark_all_read">
    <button type="submit" class="btn-sm btn-navy">Mark all as read</button>
  </form>
  <?php endif; ?>
</div>

<!-- STAT CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card stat-navy" style="padding:16px 20px">
    <div class="stat-icon">[icon:bell]</div>
    <div class="stat-label">Total</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $total_count; ?></div>
  </div>
  <div class="stat-card stat-wine" style="padding:16px 20px">
    <div class="stat-icon">●</div>
    <div class="stat-label">Unread</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $unread_count; ?></div>
  </div>
  <div class="stat-card stat-green" style="padding:16px 20px">
    <div class="stat-icon">[icon:calendar]</div>
    <div class="stat-label">Today</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $today_count; ?></div>
  </div>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="notifications.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:200px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search title, message, recipient..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <select name="type" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Types</option>
        <?php foreach (['application','payment','announcement','message','system','schedule','confirmation'] as $t): ?>
        <option value="<?php echo $t; ?>" <?php echo $type_filter === $t ? 'selected' : ''; ?>><?php echo ucfirst($t); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <option value="unread" <?php echo $status_filter === 'unread' ? 'selected' : ''; ?>>Unread</option>
        <option value="read"   <?php echo $status_filter === 'read'   ? 'selected' : ''; ?>>Read</option>
      </select>
      <?php if ($type_filter || $status_filter || $search): ?>
      <a href="notifications.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- LIST -->
<div class="card">
  <div class="card-head">
    <h3>All Notifications</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($notifications)): ?>
    <p style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No notifications match your filters.</p>
    <?php endif; ?>

    <?php foreach ($notifications as $n):
      $pill = $type_pill[$n['type']] ?? 'pill-navy';
      $unread = !$n['is_read'];
    ?>
    <div style="display:flex;gap:14px;padding:16px 22px;border-bottom:1px solid var(--ink-10);<?php echo $unread ? 'background:rgba(201,168,76,.04);' : ''; ?>align-items:flex-start">
      <div style="width:38px;height:38px;border-radius:10px;background:<?php echo $unread ? 'var(--gold-dim)' : 'var(--ink-10)'; ?>;display:grid;place-items:center;flex-shrink:0">[icon:bell]</div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:wrap">
          <span style="font-weight:<?php echo $unread ? '600' : '500'; ?>;font-size:.9rem;color:var(--ink)"><?php echo htmlspecialchars($n['title']); ?></span>
          <span class="pill <?php echo $pill; ?>" style="font-size:.62rem"><?php echo htmlspecialchars($n['type']); ?></span>
          <?php if ($unread): ?><span class="pill pill-wine" style="font-size:.62rem">Unread</span><?php endif; ?>
        </div>
        <div style="font-size:.8rem;color:var(--ink-60);line-height:1.5;margin-bottom:4px"><?php echo htmlspecialchars($n['message']); ?></div>
        <div style="font-size:.7rem;color:var(--ink-30)">
          To: <strong><?php echo htmlspecialchars($n['user_name'] ?? '—'); ?></strong>
          <?php if (!empty($n['user_role'])): ?> &middot; <?php echo ucfirst($n['user_role']); ?><?php endif; ?>
          &middot; <?php echo date('M j, Y g:i A', strtotime($n['created_at'])); ?>
        </div>
      </div>
      <div style="display:flex;gap:4px;flex-shrink:0">
        <?php if ($unread): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="_action" value="mark_read">
          <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
          <button type="submit" class="act-btn act-green" title="Mark as read">[icon:check]</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this notification?')">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
          <button type="submit" class="act-btn act-wine" title="Delete">[icon:close]</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10);flex-wrap:wrap;gap:10px">
    <span style="font-size:.78rem;color:var(--ink-30)">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?></span>
    <div style="display:flex;gap:4px">
      <?php $bq = http_build_query(array_filter(['type'=>$type_filter,'status'=>$status_filter,'q'=>$search]));
      if ($page_num > 1): ?>
      <a href="?<?php echo $bq; ?>&page=<?php echo $page_num-1; ?>" class="act-btn act-navy">&larr; Prev</a>
      <?php endif;
      for ($pp = max(1,$page_num-2); $pp <= min($total_pages,$page_num+2); $pp++):
        $as = $pp === $page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?>
      <a href="?<?php echo $bq; ?>&page=<?php echo $pp; ?>" class="act-btn" style="<?php echo $as; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $pp; ?></a>
      <?php endfor;
      if ($page_num < $total_pages): ?>
      <a href="?<?php echo $bq; ?>&page=<?php echo $page_num+1; ?>" class="act-btn act-navy">Next &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include 'includes/layout_footer.php'; ?>
