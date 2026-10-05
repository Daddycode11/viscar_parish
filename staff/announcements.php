<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Announcements — Full CRUD
 * Features: list, create, edit, delete, toggle status, search, filter
 */

$page_id    = 'announcements';
$page_title = 'Announcements';
$page_sub   = 'Parish Announcements';
include 'includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';

// Secretary only — bookkeepers don't author parish announcements
if ($user['role'] !== 'secretary') {
    header('Location: dashboard.php');
    exit;
}

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Create
    if ($_GET['ajax'] === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/../includes/announcement_delivery.php';
        try {
            $result = publish_announcement($user, $_POST);
            echo json_encode(['success'=>true] + $result);
        } catch (Throwable $error) {
            http_response_code(422);
            echo json_encode(['success'=>false,'message'=>$error instanceof DomainException ? $error->getMessage() : 'Unable to publish announcement.']);
        }
        exit;
    }

    // Send confirmation (schedule / event / request) to a single parishioner
    if ($_GET['ajax'] === 'send_confirmation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $message    = trim($_POST['message'] ?? '');
        $send_sms   = !empty($_POST['send_sms']);
        $send_email = !empty($_POST['send_email']);

        if (!$user_id || !$title || !$message) {
            echo json_encode(['success'=>false,'message'=>'Recipient, title and message are required.']);
            exit;
        }

        // Verify the target parishioner belongs to the secretary's parish
        $stmt = $conn->prepare("SELECT id FROM (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) users WHERE id=? AND parish_id=? AND role='parishioner' AND status='active'");
        $stmt->bind_param('ii', $user_id, $user['parish_id']);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success'=>false,'message'=>'Recipient not found in your parish.']);
            exit;
        }

        $channels = ['in-app'];
        if ($send_sms)   $channels[] = 'sms';
        if ($send_email) $channels[] = 'email';
        $r = dispatch_to_user($user_id, $title, $message, $channels, 'system');
        echo json_encode([
            'success' => true,
            'message' => delivery_feedback($r),
            'notification_message' => delivery_feedback($r),
            'delivery' => $r,
        ]);
        exit;
    }

    // Update
    if ($_GET['ajax'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $status  = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$id || !$title || !$content) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE announcements SET title=?, content=?, status=? WHERE id=?");
        $stmt->bind_param('sssi', $title, $content, $status, $id);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'Announcement updated.' : 'Failed.']);
        exit;
    }

    // Delete
    if ($_GET['ajax'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'Announcement deleted.' : 'Failed.']);
        exit;
    }

    // Toggle status
    if ($_GET['ajax'] === 'toggle_status' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE announcements SET status = IF(status='active','inactive','active') WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();

        // Get new status
        $new_status = '';
        if ($ok) {
            $r = $conn->query("SELECT status FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) announcements WHERE id=$id");
            $new_status = $r ? $r->fetch_assoc()['status'] : '';
        }
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'Status toggled.' : 'Failed.', 'new_status'=>$new_status]);
        exit;
    }

    // Get single
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT a.*, u.name AS author_name FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) a LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.sent_by = u.id WHERE a.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ann = $stmt->get_result()->fetch_assoc();
        echo json_encode($ann ? ['success'=>true,'data'=>$ann] : ['success'=>false,'message'=>'Not found.']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';
$page_num      = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;

$where = [];
$params = [];
$types  = '';

if ($status_filter && in_array($status_filter, ['active','inactive'])) {
    $where[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($search) {
    $where[] = "(a.title LIKE ? OR a.content LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$csql = "SELECT COUNT(*) as t FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) a $where_sql";
$stmt = $conn->prepare($csql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Fetch
$sql = "SELECT a.*, u.name AS author_name
        FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) a
        LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.sent_by = u.id
        $where_sql
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?";
$btypes = $types . 'ii';
$bparams = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
if ($bparams) $stmt->bind_param($btypes, ...$bparams);
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Counts
$cnt_all = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) announcements")->fetch_assoc()['t'];
$cnt_active = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) announcements WHERE status='active'")->fetch_assoc()['t'];
$cnt_inactive = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM announcements WHERE parish_id = {$scopeParish}) announcements WHERE status='inactive'")->fetch_assoc()['t'];
?>
<?php if (!isset($_GET['ajax'])) require APP_ROOT . '/includes/announcement_status.php'; ?>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Create/Edit Modal -->
<div class="modal-wrap" id="annModal">
  <div class="modal" style="max-width:600px">
    <h2 id="annModalTitle">New Announcement</h2>
    <p id="annModalSub">Compose and publish an announcement to parishioners.</p>
    <input type="hidden" id="annId" value="">
    <div class="form-group">
      <label>Title *</label>
      <input type="text" id="annTitle" placeholder="e.g. Holy Week Schedule 2026">
    </div>
    <div class="form-group">
      <label>Content *</label>
      <textarea id="annContent" rows="6" placeholder="Write your announcement here..." style="resize:vertical"></textarea>
    </div>
    <div class="form-group" id="annChannelsGroup">
      <label>Also send via</label>
      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:.85rem">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" id="annSendSms"> SMS
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" id="annSendEmail"> Email
        </label>
        <span style="font-size:.72rem;color:var(--ink-30)">In-app notification is always sent.</span>
      </div>
    </div>
    <div class="form-group" id="annStatusGroup" style="display:none">
      <label>Status</label>
      <select id="annStatus">
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('annModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="saveAnnouncement()" class="btn-sm btn-navy" id="annSaveBtn">Publish</button>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-wrap" id="deleteModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Delete Announcement</h2>
    <p>Are you sure you want to permanently delete this announcement? This cannot be undone.</p>
    <div class="modal-actions">
      <button onclick="closeModal('deleteModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="confirmDelete()" class="btn-sm btn-wine">Delete</button>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:650px;width:100%">
    <div id="viewContent" style="min-height:200px;display:flex;align-items:center;justify-content:center">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Communication</div>
    <h1 class="sec-title">Announcements</h1>
    <p class="sec-sub">Create and manage parish announcements for parishioners.</p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="openCreate()" class="btn-sm btn-navy">+ New Announcement</button>
  </div>
</div>

<!-- STATUS CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <a href="announcements.php" style="text-decoration:none">
    <div class="stat-card stat-navy" style="padding:16px 20px<?php echo !$status_filter?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:announcement]</div>
      <div class="stat-label">Total</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_all; ?></div>
    </div>
  </a>
  <a href="announcements.php?status=active" style="text-decoration:none">
    <div class="stat-card stat-green" style="padding:16px 20px<?php echo $status_filter==='active'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:check]</div>
      <div class="stat-label">Active</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_active; ?></div>
    </div>
  </a>
  <a href="announcements.php?status=inactive" style="text-decoration:none">
    <div class="stat-card stat-wine" style="padding:16px 20px<?php echo $status_filter==='inactive'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:ban]</div>
      <div class="stat-label">Inactive</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_inactive; ?></div>
    </div>
  </a>
</div>

<!-- SEARCH + FILTER -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="announcements.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search title or content..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
        <option value="inactive" <?php echo $status_filter==='inactive'?'selected':''; ?>>Inactive</option>
      </select>
      <?php if ($status_filter || $search): ?>
      <a href="announcements.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- ANNOUNCEMENTS LIST -->
<div class="card">
  <div class="card-head">
    <h3>All Announcements</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($announcements)): ?>
    <p style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No announcements found.</p>
    <?php endif; ?>

    <?php foreach ($announcements as $ann):
      $is_active = $ann['status'] === 'active';
    ?>
    <div id="ann-row-<?php echo $ann['id']; ?>" style="display:flex;gap:16px;padding:18px 22px;border-bottom:1px solid var(--ink-10);transition:background .2s;align-items:flex-start" onmouseover="this.style.background='rgba(201,168,76,.03)'" onmouseout="this.style.background='transparent'">
      <!-- Icon -->
      <div style="width:42px;height:42px;border-radius:10px;background:<?php echo $is_active ? 'var(--green-dim)' : 'var(--wine-dim)'; ?>;display:grid;place-items:center;flex-shrink:0;font-size:1.1rem">
        [icon:announcement]
      </div>

      <!-- Content -->
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
          <span style="font-weight:500;font-size:.88rem;color:var(--ink)"><?php echo htmlspecialchars($ann['title']); ?></span>
          <span class="pill <?php echo $is_active ? 'pill-green' : 'pill-wine'; ?>" id="ann-status-<?php echo $ann['id']; ?>"><?php echo ucfirst($ann['status']); ?></span>
        </div>
        <div style="font-size:.8rem;color:var(--ink-60);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
          <?php echo htmlspecialchars($ann['content']); ?>
        </div>
        <div style="font-size:.7rem;color:var(--ink-30);margin-top:6px">
          By <?php echo htmlspecialchars($ann['author_name'] ?? 'Unknown'); ?> &middot;
          <?php echo date('M j, Y \a\t g:i A', strtotime($ann['sent_at'] ?? $ann['created_at'])); ?>
        </div>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:4px;flex-shrink:0;align-items:center">
        <button onclick="viewAnn(<?php echo $ann['id']; ?>)" class="act-btn act-navy" title="View">[icon:eye]</button>
        <button onclick="openEdit(<?php echo $ann['id']; ?>)" class="act-btn act-gold" title="Edit">[icon:edit]</button>
        <button onclick="toggleStatus(<?php echo $ann['id']; ?>)" class="act-btn <?php echo $is_active ? 'act-wine' : 'act-green'; ?>" title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>" id="ann-toggle-<?php echo $ann['id']; ?>">
          <?php echo $is_active ? '◼' : '▶'; ?>
        </button>
        <button onclick="deleteAnn(<?php echo $ann['id']; ?>)" class="act-btn act-wine" title="Delete">[icon:close]</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10);flex-wrap:wrap;gap:10px">
    <span style="font-size:.78rem;color:var(--ink-30)">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?></span>
    <div style="display:flex;gap:4px">
      <?php
      $base_q = http_build_query(array_filter(['status'=>$status_filter,'q'=>$search]));
      if ($page_num > 1): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num-1; ?>" class="act-btn act-navy">&larr; Prev</a>
      <?php endif;
      for ($pp = max(1,$page_num-2); $pp <= min($total_pages,$page_num+2); $pp++):
        $as = $pp===$page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $pp; ?>" class="act-btn" style="<?php echo $as; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $pp; ?></a>
      <?php endfor;
      if ($page_num < $total_pages): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num+1; ?>" class="act-btn act-navy">Next &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
let deleteId = null;
let announcementRequestKey = <?= json_encode(bin2hex(random_bytes(32))) ?>;

function openCreate() {
    document.getElementById('annModalTitle').textContent = 'New Announcement';
    announcementRequestKey = Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('annModalSub').textContent = 'Compose and publish an announcement to parishioners.';
    document.getElementById('annSaveBtn').textContent = 'Publish';
    document.getElementById('annId').value = '';
    document.getElementById('annTitle').value = '';
    document.getElementById('annContent').value = '';
    document.getElementById('annStatus').value = 'active';
    document.getElementById('annStatusGroup').style.display = 'none';
    document.getElementById('annChannelsGroup').style.display = '';
    document.getElementById('annSendSms').checked = false;
    document.getElementById('annSendEmail').checked = false;
    openModal('annModal');
}

function openEdit(id) {
    setLoading(true);
    fetch('announcements.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const a = data.data;
            document.getElementById('annModalTitle').textContent = 'Edit Announcement';
            document.getElementById('annModalSub').textContent = 'Update the announcement details.';
            document.getElementById('annSaveBtn').textContent = 'Save Changes';
            document.getElementById('annId').value = a.id;
            document.getElementById('annTitle').value = a.title;
            document.getElementById('annContent').value = a.content;
            document.getElementById('annStatus').value = a.status;
            document.getElementById('annStatusGroup').style.display = 'block';
            document.getElementById('annChannelsGroup').style.display = 'none';
            openModal('annModal');
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function saveAnnouncement() {
    const id = document.getElementById('annId').value;
    const title = document.getElementById('annTitle').value.trim();
    const content = document.getElementById('annContent').value.trim();
    if (!title || !content) { showToast('Title and content are required.', 'error'); return; }

    const saveButton = document.getElementById('annSaveBtn');
    if (saveButton.disabled) return;
    saveButton.disabled = true;
    setLoading(true);
    const fd = new FormData();
    fd.append('title', title);
    fd.append('content', content);

    fd.append('request_key', announcementRequestKey);
    let action = 'create';
    if (id) {
        action = 'update';
        fd.append('id', id);
        fd.append('status', document.getElementById('annStatus').value);
    } else {
        if (document.getElementById('annSendSms').checked)   fd.append('send_sms', '1');
        if (document.getElementById('annSendEmail').checked) fd.append('send_email', '1');
    }

    fetch('announcements.php?ajax=' + action, { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) { closeModal('annModal'); setTimeout(() => location.reload(), 800); }
        }).catch(() => { showToast('Connection interrupted. Check the announcement list before retrying.', 'error'); })
        .finally(() => { setLoading(false); saveButton.disabled = false; });
}

function viewAnn(id) {
    document.getElementById('viewContent').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px"><div class="spinner" style="border-top-color:var(--navy)"></div></div>';
    openModal('viewModal');
    fetch('announcements.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('viewContent').innerHTML = '<p style="color:var(--wine);padding:20px">' + data.message + '</p>'; return; }
            const a = data.data;
            const sc = a.status === 'active' ? 'green' : 'wine';
            document.getElementById('viewContent').innerHTML = `
              <div style="margin-bottom:20px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                  <h2 style="font-family:var(--fh);font-size:1.3rem;flex:1">${a.title}</h2>
                  <span class="pill pill-${sc}" style="font-size:.78rem;padding:5px 14px">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span>
                </div>
                <div style="font-size:.75rem;color:var(--ink-30)">
                  By ${a.author_name || 'Unknown'} &middot; ${new Date(a.sent_at || a.created_at).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true})}
                </div>
              </div>
              <div style="background:#F8F6F2;border-radius:10px;padding:20px;font-size:.88rem;line-height:1.8;color:var(--ink);white-space:pre-wrap;margin-bottom:20px">${a.content}</div>
              <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--ink-10)">
                <button onclick="closeModal('viewModal');openEdit(${a.id})" class="btn-sm btn-gold">[icon:edit] Edit</button>
                <button onclick="closeModal('viewModal')" class="btn-sm btn-outline">Close</button>
              </div>`;
        });
}

function toggleStatus(id) {
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('announcements.php?ajax=toggle_status', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast('[icon:check] Status updated.', 'success');
                setTimeout(() => location.reload(), 800);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function deleteAnn(id) {
    deleteId = id;
    openModal('deleteModal');
}

function confirmDelete() {
    closeModal('deleteModal');
    setLoading(true);
    const fd = new FormData(); fd.append('id', deleteId);
    fetch('announcements.php?ajax=delete', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const row = document.getElementById('ann-row-' + deleteId);
                if (row) { row.style.opacity = '0.3'; setTimeout(() => row.remove(), 500); }
                showToast('[icon:check] ' + data.message, 'success');
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}
</script>

<?php include 'includes/layout_footer.php'; ?>
