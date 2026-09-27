<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff FAQ Management — Full CRUD
 * Features: list, create, edit, delete, toggle status, filter by category, accordion
 */

$page_id    = 'faq';
$page_title = 'FAQ Management';
$page_sub   = 'Communication';
require_once __DIR__ . '/includes/layout.php';

$parish_id = (int)($user['parish_id'] ?? 0);

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Create
    if ($_GET['ajax'] === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $question   = trim($_POST['question'] ?? '');
        $answer     = trim($_POST['answer'] ?? '');
        $category   = trim($_POST['category'] ?? 'General');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $created_by = (int)$user['id'];

        if (!$question || !$answer) {
            echo json_encode(['success'=>false,'message'=>'Question and answer are required.']);
            exit;
        }
        if (!$category) $category = 'General';

        $stmt = $conn->prepare("INSERT INTO faqs (parish_id, question, answer, category, sort_order, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('isssisi', $parish_id, $question, $answer, $category, $sort_order, $status, $created_by);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'FAQ created successfully.' : 'Failed to create FAQ.', 'id'=>$conn->insert_id]);
        exit;
    }

    // Update
    if ($_GET['ajax'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id         = (int)($_POST['id'] ?? 0);
        $question   = trim($_POST['question'] ?? '');
        $answer     = trim($_POST['answer'] ?? '');
        $category   = trim($_POST['category'] ?? 'General');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$id || !$question || !$answer) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields.']);
            exit;
        }
        if (!$category) $category = 'General';

        $stmt = $conn->prepare("UPDATE faqs SET question=?, answer=?, category=?, sort_order=?, status=? WHERE id=? AND parish_id=?");
        $stmt->bind_param('sssisii', $question, $answer, $category, $sort_order, $status, $id, $parish_id);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'FAQ updated successfully.' : 'Failed to update FAQ.']);
        exit;
    }

    // Delete
    if ($_GET['ajax'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM faqs WHERE id=? AND parish_id=?");
        $stmt->bind_param('ii', $id, $parish_id);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'FAQ deleted.' : 'Failed to delete FAQ.']);
        exit;
    }

    // Toggle status
    if ($_GET['ajax'] === 'toggle' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE faqs SET status = IF(status='active','inactive','active') WHERE id=? AND parish_id=?");
        $stmt->bind_param('ii', $id, $parish_id);
        $ok = $stmt->execute();

        $new_status = '';
        if ($ok) {
            $r = $conn->prepare("SELECT status FROM faqs WHERE id=?");
            $r->bind_param('i', $id);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            $new_status = $row['status'] ?? '';
        }
        echo json_encode(['success'=>$ok, 'message'=>$ok ? 'Status toggled.' : 'Failed.', 'new_status'=>$new_status]);
        exit;
    }

    // Get single
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT f.*, u.name AS author_name FROM faqs f LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON f.created_by = u.id WHERE f.id=? AND f.parish_id=?");
        $stmt->bind_param('ii', $id, $parish_id);
        $stmt->execute();
        $faq = $stmt->get_result()->fetch_assoc();
        echo json_encode($faq ? ['success'=>true,'data'=>$faq] : ['success'=>false,'message'=>'Not found.']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
$cat_filter = trim($_GET['category'] ?? '');

$where  = ["f.parish_id = ?"];
$params = [$parish_id];
$types  = 'i';

if ($cat_filter) {
    $where[]  = "f.category = ?";
    $params[] = $cat_filter;
    $types   .= 's';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Counts
$stmt = $conn->prepare("SELECT COUNT(*) as t FROM faqs f WHERE f.parish_id=?");
$stmt->bind_param('i', $parish_id); $stmt->execute();
$cnt_all = (int)$stmt->get_result()->fetch_assoc()['t'];

$stmt = $conn->prepare("SELECT COUNT(*) as t FROM faqs f WHERE f.parish_id=? AND f.status='active'");
$stmt->bind_param('i', $parish_id); $stmt->execute();
$cnt_active = (int)$stmt->get_result()->fetch_assoc()['t'];

$stmt = $conn->prepare("SELECT COUNT(*) as t FROM faqs f WHERE f.parish_id=? AND f.status='inactive'");
$stmt->bind_param('i', $parish_id); $stmt->execute();
$cnt_inactive = (int)$stmt->get_result()->fetch_assoc()['t'];

$stmt = $conn->prepare("SELECT COUNT(DISTINCT category) as t FROM faqs f WHERE f.parish_id=?");
$stmt->bind_param('i', $parish_id); $stmt->execute();
$cnt_categories = (int)$stmt->get_result()->fetch_assoc()['t'];

// Distinct categories for filter and datalist
$stmt = $conn->prepare("SELECT DISTINCT category FROM faqs WHERE parish_id=? ORDER BY category");
$stmt->bind_param('i', $parish_id); $stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch FAQs
$sql = "SELECT f.*, u.name AS author_name FROM faqs f LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON f.created_by = u.id $where_sql ORDER BY f.sort_order ASC, f.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$faqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<style>
.faq-item { border: 1px solid var(--ink-10); border-radius: var(--r); margin-bottom: 10px; overflow: hidden; }
.faq-question { padding: 16px 20px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: 500; transition: background var(--ease); }
.faq-question:hover { background: rgba(201,168,76,.04); }
.faq-answer { padding: 0 20px; max-height: 0; overflow: hidden; transition: max-height .3s ease, padding .3s ease; }
.faq-item.open .faq-answer { max-height: 500px; padding: 0 20px 16px; }
.faq-item.open .faq-question { background: var(--gold-dim); }
.faq-arrow { transition: transform .3s ease; }
.faq-item.open .faq-arrow { transform: rotate(180deg); }
</style>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Create/Edit Modal -->
<div class="modal-wrap" id="faqModal">
  <div class="modal" style="max-width:600px">
    <h2 id="faqModalTitle">New FAQ</h2>
    <p id="faqModalSub">Add a frequently asked question and its answer.</p>
    <input type="hidden" id="faqId" value="">
    <div class="form-grid">
      <div class="form-group form-full">
        <label>Question *</label>
        <input type="text" id="faqQuestion" placeholder="e.g. What are the office hours?">
      </div>
      <div class="form-group form-full">
        <label>Answer *</label>
        <textarea id="faqAnswer" rows="5" placeholder="Write the answer here..." style="resize:vertical"></textarea>
      </div>
      <div class="form-group">
        <label>Category</label>
        <input type="text" id="faqCategory" list="catList" placeholder="e.g. General">
        <datalist id="catList">
          <?php foreach ($categories as $c): ?>
          <option value="<?php echo htmlspecialchars($c['category']); ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" id="faqSortOrder" value="0" min="0">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="faqStatus">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('faqModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="saveFaq()" class="btn-sm btn-navy" id="faqSaveBtn">Save FAQ</button>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-wrap" id="deleteModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Delete FAQ</h2>
    <p>Are you sure you want to permanently delete this FAQ? This cannot be undone.</p>
    <div class="modal-actions">
      <button onclick="closeModal('deleteModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="confirmDelete()" class="btn-sm btn-wine">Delete</button>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Communication</div>
    <h1 class="sec-title">FAQ Management</h1>
    <p class="sec-sub">Create and manage frequently asked questions for parishioners.</p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="openCreate()" class="btn-sm btn-navy">+ New FAQ</button>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <div class="stat-card stat-navy" style="padding:16px 20px">
    <div class="stat-icon">[icon:help]</div>
    <div class="stat-label">Total FAQs</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_all; ?></div>
  </div>
  <div class="stat-card stat-green" style="padding:16px 20px">
    <div class="stat-icon">[icon:check]</div>
    <div class="stat-label">Active</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_active; ?></div>
  </div>
  <div class="stat-card stat-wine" style="padding:16px 20px">
    <div class="stat-icon">[icon:ban]</div>
    <div class="stat-label">Inactive</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_inactive; ?></div>
  </div>
  <div class="stat-card stat-gold" style="padding:16px 20px">
    <div class="stat-icon">[icon:folder]</div>
    <div class="stat-label">Categories</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_categories; ?></div>
  </div>
</div>

<!-- CATEGORY FILTER -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="faq.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <label style="font-size:.78rem;font-weight:500;color:var(--ink-60);text-transform:uppercase;letter-spacing:.06em">Filter by Category</label>
      <select name="category" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?php echo htmlspecialchars($c['category']); ?>" <?php echo $cat_filter === $c['category'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['category']); ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($cat_filter): ?>
      <a href="faq.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo count($faqs); ?> results</span>
    </form>
  </div>
</div>

<!-- FAQ LIST -->
<div class="card">
  <div class="card-head">
    <h3>All FAQs</h3>
    <span class="card-tag"><?php echo count($faqs); ?> total</span>
  </div>
  <div class="card-body">
    <?php if (empty($faqs)): ?>
    <div class="empty-state">
      <div class="empty-icon">[icon:help]</div>
      <p>No FAQs found. Click "New FAQ" to create one.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($faqs as $faq):
      $is_active = $faq['status'] === 'active';
    ?>
    <div class="faq-item" id="faq-item-<?php echo $faq['id']; ?>">
      <div class="faq-question" onclick="toggleAccordion(this)">
        <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
          <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($faq['question']); ?></span>
          <span class="pill pill-amber" style="flex-shrink:0"><?php echo htmlspecialchars($faq['category']); ?></span>
          <span class="pill <?php echo $is_active ? 'pill-green' : 'pill-wine'; ?>" style="flex-shrink:0" id="faq-status-<?php echo $faq['id']; ?>"><?php echo ucfirst($faq['status']); ?></span>
          <span style="font-size:.7rem;color:var(--ink-30);flex-shrink:0;min-width:30px;text-align:center" title="Sort order">#<?php echo $faq['sort_order']; ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;margin-left:10px">
          <button onclick="event.stopPropagation();openEdit(<?php echo $faq['id']; ?>)" class="act-btn act-navy" title="Edit">[icon:edit]</button>
          <button onclick="event.stopPropagation();toggleStatus(<?php echo $faq['id']; ?>)" class="act-btn <?php echo $is_active ? 'act-wine' : 'act-green'; ?>" title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>">
            <?php echo $is_active ? '◼' : '▶'; ?>
          </button>
          <button onclick="event.stopPropagation();deleteFaq(<?php echo $faq['id']; ?>)" class="act-btn act-wine" title="Delete">[icon:close]</button>
          <span class="faq-arrow" style="font-size:.7rem;color:var(--ink-30);margin-left:4px">▼</span>
        </div>
      </div>
      <div class="faq-answer">
        <div style="padding-top:12px;border-top:1px solid var(--ink-10);font-size:.85rem;line-height:1.7;color:var(--ink-60);white-space:pre-wrap"><?php echo htmlspecialchars($faq['answer']); ?></div>
        <div style="font-size:.7rem;color:var(--ink-30);margin-top:10px">
          By <?php echo htmlspecialchars($faq['author_name'] ?? 'Unknown'); ?> &middot;
          <?php echo date('M j, Y \a\t g:i A', strtotime($faq['created_at'])); ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
let deleteId = null;

function toggleAccordion(el) {
    el.closest('.faq-item').classList.toggle('open');
}

function openCreate() {
    document.getElementById('faqModalTitle').textContent = 'New FAQ';
    document.getElementById('faqModalSub').textContent = 'Add a frequently asked question and its answer.';
    document.getElementById('faqSaveBtn').textContent = 'Save FAQ';
    document.getElementById('faqId').value = '';
    document.getElementById('faqQuestion').value = '';
    document.getElementById('faqAnswer').value = '';
    document.getElementById('faqCategory').value = '';
    document.getElementById('faqSortOrder').value = '0';
    document.getElementById('faqStatus').value = 'active';
    openModal('faqModal');
}

function openEdit(id) {
    setLoading(true);
    fetch('faq.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const f = data.data;
            document.getElementById('faqModalTitle').textContent = 'Edit FAQ';
            document.getElementById('faqModalSub').textContent = 'Update the FAQ details.';
            document.getElementById('faqSaveBtn').textContent = 'Save Changes';
            document.getElementById('faqId').value = f.id;
            document.getElementById('faqQuestion').value = f.question;
            document.getElementById('faqAnswer').value = f.answer;
            document.getElementById('faqCategory').value = f.category;
            document.getElementById('faqSortOrder').value = f.sort_order;
            document.getElementById('faqStatus').value = f.status;
            openModal('faqModal');
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function saveFaq() {
    const id        = document.getElementById('faqId').value;
    const question  = document.getElementById('faqQuestion').value.trim();
    const answer    = document.getElementById('faqAnswer').value.trim();
    const category  = document.getElementById('faqCategory').value.trim() || 'General';
    const sortOrder = document.getElementById('faqSortOrder').value;
    const status    = document.getElementById('faqStatus').value;

    if (!question || !answer) { showToast('Question and answer are required.', 'error'); return; }

    closeModal('faqModal');
    setLoading(true);

    const fd = new FormData();
    fd.append('question', question);
    fd.append('answer', answer);
    fd.append('category', category);
    fd.append('sort_order', sortOrder);
    fd.append('status', status);

    let action = 'create';
    if (id) {
        action = 'update';
        fd.append('id', id);
    }

    fetch('faq.php?ajax=' + action, { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) setTimeout(() => location.reload(), 800);
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function toggleStatus(id) {
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('faq.php?ajax=toggle', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast('[icon:check] Status updated.', 'success');
                setTimeout(() => location.reload(), 800);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function deleteFaq(id) {
    deleteId = id;
    openModal('deleteModal');
}

function confirmDelete() {
    closeModal('deleteModal');
    setLoading(true);
    const fd = new FormData(); fd.append('id', deleteId);
    fetch('faq.php?ajax=delete', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const item = document.getElementById('faq-item-' + deleteId);
                if (item) { item.style.opacity = '0.3'; setTimeout(() => item.remove(), 500); }
                showToast('[icon:check] ' + data.message, 'success');
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
