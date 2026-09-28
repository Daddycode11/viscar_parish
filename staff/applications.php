<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Applications — Full CRUD
 * Features: list, filter, search, approve/reject with modal, view detail, pagination
 */

$page_id    = 'applications';
$page_title = 'Applications';
$page_sub   = 'Service Applications';
include 'includes/layout.php';

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Approve
    if ($_GET['ajax'] === 'approve' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE applications SET status='approved' WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? "Application #$id approved." : 'Failed.']);
        exit;
    }

    // Reject
    if ($_GET['ajax'] === 'reject' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE applications SET status='rejected' WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? "Application #$id rejected." : 'Failed.']);
        exit;
    }

    // Get single application detail
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT a.*, u.name AS parishioner_name, u.email AS parishioner_email, u.phone AS parishioner_phone,
                                       s.name AS service_name, s.fee AS service_fee, p.name AS parish_name
                                FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
                                JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                                LEFT JOIN services s ON a.service_id = s.id
                                LEFT JOIN parishes p ON a.parish_id = p.id
                                WHERE a.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        if (!$app) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

        // Get payment info
        $pstmt = $conn->prepare("SELECT * FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE application_id = ? ORDER BY created_at DESC LIMIT 1");
        $pstmt->bind_param('i', $id);
        $pstmt->execute();
        $payment = $pstmt->get_result()->fetch_assoc();
        $app['payment'] = $payment;

        echo json_encode(['success' => true, 'data' => $app]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
$status_filter  = $_GET['status']  ?? '';
$service_filter = $_GET['service'] ?? '';
$search         = trim($_GET['q'] ?? '');
$page_num       = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 12;

// Build WHERE
$where = [];
$params = [];
$types  = '';

if ($status_filter && in_array($status_filter, ['pending','approved','rejected','cancelled'])) {
    $where[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($service_filter) {
    $where[] = "a.service_id = ?";
    $params[] = (int)$service_filter;
    $types .= 'i';
}
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR a.id LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Counts
$count_sql = "SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id $where_sql";
$stmt = $conn->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Fetch
$sql = "SELECT a.id, a.source, a.created_by, a.service_id, a.schedule, a.status, a.payment_status, a.created_at, a.parish_id,
               u.name AS parishioner_name, u.email AS parishioner_email,
               s.name AS service_name, p.name AS parish_name
        FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
        JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN parishes p ON a.parish_id = p.id
        $where_sql
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?";
$bind_types = $types . 'ii';
$bind_params = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
if ($bind_params) $stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Status counts (unfiltered)
$cnt_all = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications")->fetch_assoc()['t'];
$cnt_pending = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE status='pending'")->fetch_assoc()['t'];
$cnt_approved = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE status='approved'")->fetch_assoc()['t'];
$cnt_rejected = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE status='rejected'")->fetch_assoc()['t'];

// Services list for filter
$services = $conn->query("SELECT id, name FROM services WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$pillMap = ['approved'=>'pill-green','rejected'=>'pill-wine','pending'=>'pill-amber'];
?>

<style>
.app-row-actions { opacity: 0; transition: opacity .2s; }
tbody tr:hover .app-row-actions { opacity: 1; }
</style>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Reject Modal -->
<div class="modal-wrap" id="rejectModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Reject Application</h2>
    <p>Are you sure you want to reject this application? This action will notify the parishioner.</p>
    <div class="form-group">
      <label>Reason (optional note)</label>
      <textarea id="rejectReason" rows="3" placeholder="e.g. Missing baptismal certificate..." style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none"></textarea>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('rejectModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="submitReject()" class="btn-sm btn-wine">Confirm Rejection</button>
    </div>
  </div>
</div>

<!-- View Detail Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:680px;width:100%">
    <div id="viewContent" style="min-height:200px">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Service Applications</div>
    <h1 class="sec-title">Applications</h1>
    <p class="sec-sub">Review and manage all sacramental service applications.</p>
  </div>
</div>

<!-- STATUS CARDS -->
<div class="stats-grid" style="margin-bottom:20px">
  <?php
  $scards = [
    ['All',      $cnt_all,      'stat-navy',  '[icon:clipboard]', ''],
    ['Pending',  $cnt_pending,  'stat-amber', '[icon:clock]',   'pending'],
    ['Approved', $cnt_approved, 'stat-green', '[icon:check]',  'approved'],
    ['Rejected', $cnt_rejected, 'stat-wine',  '[icon:close]',  'rejected'],
  ];
  foreach ($scards as [$label,$val,$cls,$icon,$sf]):
    $active = ($status_filter === $sf && $sf !== '') || ($sf === '' && $status_filter === '');
    $href = 'applications.php' . ($sf ? '?status='.$sf : '');
  ?>
  <a href="<?php echo $href; ?>" style="text-decoration:none">
    <div class="stat-card <?php echo $cls; ?>" style="cursor:pointer;padding:16px 20px<?php echo ($status_filter === $sf) ? ';box-shadow:0 0 0 3px var(--gold)' : ''; ?>">
      <div class="stat-icon"><?php echo $icon; ?></div>
      <div class="stat-label"><?php echo $label; ?></div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $val; ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- SEARCH + FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="applications.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email, ID..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <?php foreach (['pending','approved','rejected','cancelled'] as $s): ?>
        <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="service" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Services</option>
        <?php foreach ($services as $sv): ?>
        <option value="<?php echo $sv['id']; ?>" <?php echo $service_filter==(string)$sv['id']?'selected':''; ?>><?php echo htmlspecialchars($sv['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($status_filter || $service_filter || $search): ?>
      <a href="applications.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto;white-space:nowrap"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- APPLICATIONS TABLE -->
<div class="card">
  <div class="card-head">
    <h3>All Applications</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Parishioner</th><th>Service</th><th>Parish</th><th>Schedule</th><th>Status</th><th>Payment</th><th>Filed</th><th style="width:150px">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($apps)): ?>
          <tr><td colspan="9" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No applications found.</td></tr>
          <?php endif; ?>
          <?php foreach ($apps as $a):
            $pill = $pillMap[$a['status']] ?? 'pill-amber';
            $ppill = $a['payment_status']==='paid' ? 'pill-green' : ($a['payment_status']==='refunded' ? 'pill-wine' : 'pill-amber');
            $sched = $a['schedule'] ? date('M j, Y g:i A', strtotime($a['schedule'])) : '—';
          ?>
          <tr id="app-row-<?php echo $a['id']; ?>">
            <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $a['id']; ?></td>
            <td>
              <div style="font-weight:500"><?php echo htmlspecialchars($a['parishioner_name']); ?></div>
              <?php if ($a['source'] === 'walk_in'): ?><small class="pill pill-navy"><?= h(t('Walk-in application')) ?></small><?php endif; ?>
              <div style="font-size:.72rem;color:var(--ink-30)"><?php echo htmlspecialchars($a['parishioner_email']); ?></div>
            </td>
            <td><?php echo htmlspecialchars($a['service_name'] ?? 'Service #'.$a['service_id']); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($a['parish_name'] ?? '—'); ?></td>
            <td style="font-size:.75rem;color:var(--ink-60)"><?php echo $sched; ?></td>
            <td><span class="pill <?php echo $pill; ?>" id="status-<?php echo $a['id']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
            <td><span class="pill <?php echo $ppill; ?>"><?php echo ucfirst($a['payment_status']); ?></span></td>
            <td style="font-size:.73rem;color:var(--ink-30)"><?php echo date('M j, Y', strtotime($a['created_at'])); ?></td>
            <td>
              <div class="app-row-actions" style="display:flex;gap:4px;flex-wrap:wrap">
                <button onclick="requestApplicationDocs(<?php echo $a['id']; ?>)" class="act-btn act-gold" title="Request Documents">Request Docs</button>
                <?php if(in_array($a['status'],['pending','approved'],true)): ?><a class="act-btn act-gold" title="Correct application answers" href="application_details.php?id=<?= (int)$a['id'] ?>&amp;edit=1#corrections">[icon:edit]</a><?php endif; ?>
                <button onclick="viewApp(<?php echo $a['id']; ?>)" class="act-btn act-navy" title="View">[icon:eye]</button>
                <?php if ($a['status'] === 'pending'): ?>
                <button onclick="approveApp(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars(addslashes($a['parishioner_name'])); ?>')" class="act-btn act-green" title="Approve">[icon:check]</button>
                <button onclick="openRejectModal(<?php echo $a['id']; ?>)" class="act-btn act-wine" title="Reject">[icon:close]</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10);flex-wrap:wrap;gap:10px">
    <span style="font-size:.78rem;color:var(--ink-30)">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?></span>
    <div style="display:flex;gap:4px">
      <?php
      $base_q = http_build_query(array_filter(['status'=>$status_filter,'service'=>$service_filter,'q'=>$search]));
      if ($page_num > 1): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num-1; ?>" class="act-btn act-navy">&larr; Prev</a>
      <?php endif;
      for ($p = max(1,$page_num-2); $p <= min($total_pages,$page_num+2); $p++):
        $act_s = $p===$page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $p; ?>" class="act-btn" style="<?php echo $act_s; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $p; ?></a>
      <?php endfor;
      if ($page_num < $total_pages): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num+1; ?>" class="act-btn act-navy">Next &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function requestApplicationDocs(id) {
 const docs=prompt('Required documents and instructions:'); if(!docs || !docs.trim())return;
 const data=new FormData();data.append('id',id);data.append('docs',docs);data.append('message',docs);
 fetch('applications.php?ajax=request_docs',{method:'POST',body:data}).then(r=>r.json()).then(r=>showToast(r.message,r.success?'success':'error')).catch(()=>showToast('Unable to request documents.','error'));
}

let pendingActionId = null;

function approveApp(id, name) {
    if (!confirm('Approve application #' + id + ' for ' + name + '?')) return;
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('applications.php?ajax=approve', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const b = document.getElementById('status-' + id);
                if (b) { b.className = 'pill pill-green'; b.textContent = 'Approved'; }
                const row = document.getElementById('app-row-' + id);
                if (row) { const acts = row.querySelector('.app-row-actions'); if (acts) acts.innerHTML = '<span style="font-size:.72rem;color:var(--ink-30)">Processed</span>'; }
                showToast('[icon:check] ' + data.message, 'success');
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function openRejectModal(id) {
    pendingActionId = id;
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
}

function submitReject() {
    closeModal('rejectModal');
    setLoading(true);
    const fd = new FormData(); fd.append('id', pendingActionId); fd.append('reason',document.getElementById('rejectReason').value.trim());
    fetch('applications.php?ajax=reject', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const b = document.getElementById('status-' + pendingActionId);
                if (b) { b.className = 'pill pill-wine'; b.textContent = 'Rejected'; }
                const row = document.getElementById('app-row-' + pendingActionId);
                if (row) { const acts = row.querySelector('.app-row-actions'); if (acts) acts.innerHTML = '<span style="font-size:.72rem;color:var(--ink-30)">Rejected</span>'; }
                showToast('[icon:check] ' + data.message, 'success');
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function viewApp(id) { location.href='application_details.php?id='+encodeURIComponent(id); }
</script>

<?php include 'includes/layout_footer.php'; ?>
