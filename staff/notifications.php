<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Notification Center — Apostolic Vicariate of San Jose
 * Inbox with mark-as-read, filtering, pagination, and AJAX actions.
 */
$page_id = 'notifications'; $page_title = 'Notifications'; $page_sub = 'Center';
require_once __DIR__ . '/includes/layout.php';

// ── Helper ──────────────────────────────────────
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

$uid = (int)$user['id'];

// ── AJAX: Mark single as read ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_read' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $nid = (int)$_GET['id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $nid, $uid);
    $ok = $stmt->execute();
    echo json_encode(['success' => $ok]);
    exit;
}

// ── AJAX: Mark all as read ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_all_read') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $stmt->bind_param('i', $uid);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    echo json_encode(['success' => $ok, 'count' => $affected]);
    exit;
}

// ── AJAX: Delete notification ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $nid = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $nid, $uid);
    $ok = $stmt->execute();
    echo json_encode(['success' => $ok]);
    exit;
}

// ── Filter & Pagination ─────────────────────────
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$valid_types = ['application','payment','announcement','message','system','schedule'];
$where = "WHERE user_id = ?";
$params = [$uid];
$types  = 'i';

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif (in_array($filter, $valid_types)) {
    $where .= " AND type = ?";
    $params[] = $filter;
    $types .= 's';
}

// Count total
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications $where");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total / $limit));

// Count unread
$stmt2 = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
$stmt2->bind_param('i', $uid);
$stmt2->execute();
$unread_count = (int)$stmt2->get_result()->fetch_assoc()['c'];

// Fetch notifications
$sql = "SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$fetch_types  = $types . 'ii';
$fetch_params = array_merge($params, [$limit, $offset]);
$stmt3 = $conn->prepare($sql);
$stmt3->bind_param($fetch_types, ...$fetch_params);
$stmt3->execute();
$notifications = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);

// Type config
$type_icons = [
    'application'  => ['icon' => '[icon:clipboard]', 'color' => 'var(--navy)',  'bg' => 'rgba(27,42,74,.1)',       'pill' => 'pill-navy'],
    'payment'      => ['icon' => '[icon:wallet]',   'color' => 'var(--green)', 'bg' => 'var(--green-dim)',        'pill' => 'pill-green'],
    'announcement' => ['icon' => '[icon:announcement]',  'color' => 'var(--gold)',  'bg' => 'var(--gold-dim)',         'pill' => 'pill-gold'],
    'message'      => ['icon' => '[icon:mail]',    'color' => 'var(--blue)',  'bg' => 'var(--blue-dim)',         'pill' => 'pill-navy'],
    'system'       => ['icon' => '[icon:settings]',    'color' => 'var(--amber)', 'bg' => 'var(--amber-dim)',        'pill' => 'pill-amber'],
    'schedule'     => ['icon' => '[icon:calendar]',  'color' => 'var(--wine)',  'bg' => 'var(--wine-dim)',         'pill' => 'pill-wine'],
];

$filter_tabs = [
    'all'          => 'All',
    'unread'       => 'Unread',
    'application'  => 'Application',
    'payment'      => 'Payment',
    'message'      => 'Message',
    'system'       => 'System',
];
?>

<style>
.notif-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px}
.notif-tab{padding:7px 16px;border-radius:30px;font-size:.75rem;font-weight:500;border:1px solid var(--ink-10);background:var(--white);color:var(--ink-60);cursor:pointer;transition:var(--ease)}
.notif-tab:hover{border-color:var(--navy);color:var(--navy)}
.notif-tab.active{background:var(--navy);color:var(--white);border-color:var(--navy)}
.notif-item{display:flex;gap:14px;padding:16px 22px;border-bottom:1px solid var(--ink-10);transition:background var(--ease);position:relative}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:rgba(201,168,76,.03)}
.notif-item.unread{background:rgba(27,42,74,.03)}
.notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--gold)}
.notif-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;font-size:1.1rem;flex-shrink:0}
.notif-body{flex:1;min-width:0}
.notif-title{font-size:.85rem;color:var(--ink);line-height:1.4;margin-bottom:3px}
.notif-title.bold{font-weight:600}
.notif-msg{font-size:.78rem;color:var(--ink-60);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.notif-meta{display:flex;align-items:center;gap:10px;margin-top:6px}
.notif-time{font-size:.7rem;color:var(--ink-30)}
.notif-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;margin-left:8px}
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:20px 0}
.page-btn{padding:7px 14px;border-radius:8px;font-size:.78rem;font-weight:500;border:1px solid var(--ink-10);background:var(--white);color:var(--ink-60);cursor:pointer;transition:var(--ease)}
.page-btn:hover{border-color:var(--navy);color:var(--navy)}
.page-btn.active{background:var(--navy);color:var(--white);border-color:var(--navy)}
.page-btn:disabled{opacity:.4;cursor:not-allowed}
.empty-state{text-align:center;padding:60px 20px;color:var(--ink-30)}
.empty-state .empty-icon{font-size:2.5rem;margin-bottom:12px}
.empty-state p{font-size:.85rem;margin-bottom:16px}
</style>

<!-- Toast & Loading -->
<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Notification Center</div>
    <h1 class="sec-title">Notifications</h1>
    <p class="sec-sub">Stay updated with your latest alerts and messages.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <?php if ($unread_count > 0): ?>
    <span class="pill pill-amber" id="unreadBadge"><?php echo $unread_count; ?> unread</span>
    <button onclick="markAllRead()" class="btn-sm btn-navy" id="markAllBtn">Mark All as Read</button>
    <?php else: ?>
    <span class="pill pill-green">All caught up</span>
    <?php endif; ?>
  </div>
</div>

<!-- FILTER TABS -->
<div class="notif-tabs">
  <?php foreach ($filter_tabs as $key => $label): ?>
  <a href="?filter=<?php echo $key; ?>" class="notif-tab <?php echo $filter === $key ? 'active' : ''; ?>">
    <?php echo $label; ?>
    <?php if ($key === 'unread' && $unread_count > 0): ?>
      <span style="background:var(--wine);color:white;font-size:.6rem;padding:1px 6px;border-radius:10px;margin-left:3px"><?php echo $unread_count; ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- NOTIFICATION LIST -->
<div class="card">
  <div class="card-head">
    <h3>
      <?php echo $filter_tabs[$filter] ?? 'All'; ?> Notifications
      <span style="font-size:.75rem;font-weight:400;color:var(--ink-30);margin-left:8px">(<?php echo $total; ?>)</span>
    </h3>
    <span class="card-tag">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($notifications)): ?>
    <div class="empty-state">
      <div class="empty-icon">[icon:bell]</div>
      <p>No notifications found<?php echo $filter !== 'all' ? ' for this filter' : ''; ?>.</p>
      <?php if ($filter !== 'all'): ?>
      <a href="?filter=all" class="btn-sm btn-outline">View All Notifications</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <?php foreach ($notifications as $n):
        $tc = $type_icons[$n['type']] ?? $type_icons['system'];
        $is_unread = !$n['is_read'];
    ?>
    <div class="notif-item <?php echo $is_unread ? 'unread' : ''; ?>" id="notif-<?php echo $n['id']; ?>">
      <div class="notif-icon" style="background:<?php echo $tc['bg']; ?>;color:<?php echo $tc['color']; ?>">
        <?php echo $tc['icon']; ?>
      </div>
      <div class="notif-body">
        <div class="notif-title <?php echo $is_unread ? 'bold' : ''; ?>">
          <?php echo htmlspecialchars($n['title']); ?>
        </div>
        <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
        <div class="notif-meta">
          <span class="pill <?php echo $tc['pill']; ?>" style="font-size:.6rem;padding:2px 8px"><?php echo ucfirst($n['type']); ?></span>
          <span class="notif-time"><?php echo timeAgo($n['created_at']); ?></span>
        </div>
      </div>
      <div class="notif-actions">
        <?php if (!empty($n['link'])): ?>
        <a href="<?php echo htmlspecialchars($n['link']); ?>" class="act-btn act-navy" title="View">View &rarr;</a>
        <?php endif; ?>
        <?php if ($is_unread): ?>
        <button onclick="markRead(<?php echo $n['id']; ?>)" class="act-btn act-green" title="Mark as Read">[icon:check]</button>
        <?php endif; ?>
        <button onclick="deleteNotif(<?php echo $n['id']; ?>)" class="act-btn act-wine" title="Delete">[icon:close]</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- PAGINATION -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
  <?php if ($page > 1): ?>
  <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>" class="page-btn">&laquo; Prev</a>
  <?php endif; ?>

  <?php
  $start = max(1, $page - 2);
  $end   = min($total_pages, $page + 2);
  if ($start > 1) echo '<span class="page-btn" style="border:none;background:none;cursor:default">&hellip;</span>';
  for ($p = $start; $p <= $end; $p++): ?>
  <a href="?filter=<?php echo $filter; ?>&page=<?php echo $p; ?>" class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
  <?php endfor;
  if ($end < $total_pages) echo '<span class="page-btn" style="border:none;background:none;cursor:default">&hellip;</span>';
  ?>

  <?php if ($page < $total_pages): ?>
  <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>" class="page-btn">Next &raquo;</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
function markRead(id) {
    const el = document.getElementById('notif-' + id);
    fetch('notifications.php?ajax=mark_read&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (el) {
                    el.classList.remove('unread');
                    const title = el.querySelector('.notif-title');
                    if (title) title.classList.remove('bold');
                    const btn = el.querySelector('.act-green');
                    if (btn) btn.remove();
                }
                updateUnreadBadge(-1);
                showToast('Notification marked as read.', 'success');
            } else {
                showToast('Failed to update.', 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
}

function markAllRead() {
    const btn = document.getElementById('markAllBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Marking...'; }
    fetch('notifications.php?ajax=mark_all_read')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notif-item.unread').forEach(el => {
                    el.classList.remove('unread');
                    const title = el.querySelector('.notif-title');
                    if (title) title.classList.remove('bold');
                    const readBtn = el.querySelector('.act-green');
                    if (readBtn) readBtn.remove();
                });
                updateUnreadBadge(-9999);
                showToast('All notifications marked as read.', 'success');
                if (btn) { btn.textContent = 'All Read'; }
            } else {
                showToast('Failed to update.', 'error');
                if (btn) { btn.disabled = false; btn.textContent = 'Mark All as Read'; }
            }
        })
        .catch(() => {
            showToast('Network error.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Mark All as Read'; }
        });
}

function deleteNotif(id) {
    if (!confirm('Delete this notification?')) return;
    const el = document.getElementById('notif-' + id);
    fetch('notifications.php?ajax=delete&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (el) {
                    const wasUnread = el.classList.contains('unread');
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(20px)';
                    el.style.transition = 'all .3s ease';
                    setTimeout(() => {
                        el.remove();
                        if (wasUnread) updateUnreadBadge(-1);
                    }, 300);
                }
                showToast('Notification deleted.', 'success');
            } else {
                showToast('Failed to delete.', 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
}

function updateUnreadBadge(change) {
    const badge = document.getElementById('unreadBadge');
    if (!badge) return;
    let count = parseInt(badge.textContent) || 0;
    count = Math.max(0, count + change);
    if (count > 0) {
        badge.textContent = count + ' unread';
    } else {
        badge.className = 'pill pill-green';
        badge.textContent = 'All caught up';
    }
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
