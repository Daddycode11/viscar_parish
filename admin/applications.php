<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';
if(!isset($_GET['ajax'])) require_sensitive_verification($user);

/**
 * Applications — Full dynamic version
 * Features: live search, status filter, approve/reject, pagination, view modal
 */

require_once '../includes/db.php';
require_once '../includes/notifications.php';
$user = currentUser();

// ── AJAX HANDLERS (JSON responses) ────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'get_app' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("
            SELECT a.id, a.status, a.rejection_reason, a.schedule, a.uploaded_files,
                   a.created_at AS filed,
                   u.name AS parishioner_name, u.email AS parishioner_email, u.phone AS parishioner_phone,
                   s.name AS service_name, s.fee AS service_fee,
                   p.name AS parish_name,
                   pay.status AS payment_status, pay.payment_method, pay.amount
            FROM applications a
            JOIN users u    ON a.user_id    = u.id
            LEFT JOIN services s   ON a.service_id = s.id
            LEFT JOIN parishes p   ON a.parish_id  = p.id
            LEFT JOIN payments pay ON pay.application_id = a.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        if (!$r) { echo json_encode(['success' => false, 'message' => 'Application not found.']); exit; }

        require_once APP_ROOT.'/includes/application_details.php';
        $full=sqlrow('SELECT * FROM applications WHERE id=?',[$id]);$labels=application_detail_data($full)['documents'];
        $docs=array_map(fn($row)=>['label'=>($labels[$row['requirement_key']]??'Document').' ? '.$row['original_name'],'url'=>app_url('public/document_view.php?'.attachment_query($id,$row))],application_attachments($full));

        echo json_encode(['success' => true, 'data' => [
            'id'              => (int)$r['id'],
            'name'            => $r['parishioner_name'],
            'email'           => $r['parishioner_email'],
            'phone'           => $r['parishioner_phone'] ?? '—',
            'service'         => $r['service_name'] ?? '—',
            'parish'          => $r['parish_name'] ?? '—',
            'schedule'        => $r['schedule'] ?? '—',
            'status'          => $r['status'],
            'payment_status'  => $r['payment_status'] ?? 'unpaid',
            'payment_method'  => $r['payment_method'] ?? '—',
            'amount'          => (float)($r['amount'] ?? $r['service_fee'] ?? 0),
            'filed'           => $r['filed'],
            'notes'           => '',
            'docs'            => $docs,
            'rejection_reason'=> $r['rejection_reason'] ?? '',
        ]]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── FILTER PARAMS ─────────────────────────────
$status_filter  = $_GET['status']  ?? '';
$service_filter = $_GET['service'] ?? '';
$parish_filter  = $_GET['parish']  ?? '';
$search         = trim($_GET['q']  ?? '');
$page_num       = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 12;

// ── REFERENCE LISTS FROM DB ───────────────────
$services_list = [];
$rs = $conn->query("SELECT DISTINCT name FROM services ORDER BY name");
while ($row = $rs->fetch_assoc()) $services_list[] = $row['name'];

$parishes_list = [];
$rs = $conn->query("SELECT name FROM parishes ORDER BY name");
while ($row = $rs->fetch_assoc()) $parishes_list[] = $row['name'];

// ── BUILD FILTERED QUERY ──────────────────────
$where  = [];
$params = [];
$types  = '';

if ($status_filter && in_array($status_filter, ['pending','approved','rejected','cancelled'])) {
    $where[]  = "a.status = ?";
    $params[] = $status_filter; $types .= 's';
}
if ($service_filter) {
    $where[]  = "s.name = ?";
    $params[] = $service_filter; $types .= 's';
}
if ($parish_filter) {
    $where[]  = "p.name = ?";
    $params[] = $parish_filter; $types .= 's';
}
if ($search !== '') {
    $where[]  = "(u.name LIKE ? OR u.email LIKE ? OR s.name LIKE ? OR a.id LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$cstmt = $conn->prepare("SELECT COUNT(*) AS t FROM applications a JOIN users u ON a.user_id=u.id LEFT JOIN services s ON a.service_id=s.id LEFT JOIN parishes p ON a.parish_id=p.id $where_sql");
if ($params) $cstmt->bind_param($types, ...$params);
$cstmt->execute();
$total = (int)$cstmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Page
$pstmt = $conn->prepare("
    SELECT a.id, a.status, a.payment_status, a.schedule, a.created_at,
           u.name AS name, u.email AS email,
           s.name AS service, s.fee AS amount,
           p.name AS parish
    FROM applications a
    JOIN users u ON a.user_id = u.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN parishes p ON a.parish_id = p.id
    $where_sql
    ORDER BY a.created_at DESC
    LIMIT ? OFFSET ?
");
$pparams = array_merge($params, [$per_page, $offset]);
$ptypes  = $types . 'ii';
$pstmt->bind_param($ptypes, ...$pparams);
$pstmt->execute();
$paged = [];
$rs = $pstmt->get_result();
while ($r = $rs->fetch_assoc()) {
    $paged[] = [
        'id'       => (int)$r['id'],
        'name'     => $r['name'],
        'email'    => $r['email'],
        'service'  => $r['service'] ?? '—',
        'parish'   => $r['parish'] ?? '—',
        'schedule' => $r['schedule'] ?: $r['created_at'],
        'status'   => $r['status'],
        'payment'  => ($r['payment_status'] === 'paid') ? 'paid' : 'unpaid',
        'filed'    => $r['created_at'],
        'amount'   => (float)($r['amount'] ?? 0),
    ];
}

// ── STATUS COUNTS ─────────────────────────────
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'total'=>0];
$cs = $conn->query("SELECT status, COUNT(*) AS c FROM applications GROUP BY status");
while ($r = $cs->fetch_assoc()) {
    if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
    $counts['total'] += (int)$r['c'];
}

$pillMap = ['approved'=>'pill-green','rejected'=>'pill-wine','pending'=>'pill-amber','completed'=>'pill-navy'];

$page_id    = 'applications';
$page_title = 'Applications';
$page_sub   = 'Service Applications';
include 'includes/layout.php';
?>

<style>
/* Application-specific styles */
.app-row-actions { opacity: 0; transition: opacity .2s; }
tbody tr:hover .app-row-actions { opacity: 1; }
.search-highlight { background: rgba(201,168,76,.25); border-radius: 2px; padding: 0 1px; }
.loading-overlay { position:fixed;inset:0;background:rgba(13,24,40,.35);z-index:500;display:none;place-items:center;backdrop-filter:blur(2px); }
.loading-overlay.show { display:grid; }
.spinner { width:44px;height:44px;border:3px solid rgba(255,255,255,.2);border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.toast { position:fixed;bottom:28px;right:28px;padding:12px 20px;border-radius:10px;font-size:.82rem;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:600;transform:translateY(80px);opacity:0;transition:.3s cubic-bezier(.4,0,.2,1);max-width:360px; }
.toast.show { transform:none;opacity:1; }
.toast-success { background:var(--green);color:white; }
.toast-error   { background:var(--wine);color:white; }
.toast-info    { background:var(--navy);color:white; }
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner"></div>
</div>

<!-- Toast notification -->
<div class="toast" id="toast"></div>

<!-- Reject Modal -->
<div class="modal-wrap" id="rejectModal">
  <div class="modal">
    <h2 style="color:var(--wine)">[icon:close] Reject Application</h2>
    <p>Provide a reason. This will be notified to the parishioner via in-app, email, or SMS.</p>
    <div class="form-group">
      <label>Rejection Reason *</label>
      <textarea id="rejectReason" rows="4" placeholder="e.g. Missing PSA baptismal certificate. Please resubmit with complete requirements." style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;transition:border-color var(--ease)"></textarea>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('rejectModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="submitReject()" class="btn-sm btn-wine">Confirm Rejection</button>
    </div>
  </div>
</div>

<!-- Request Docs Modal -->
<div class="modal-wrap" id="docsModal">
  <div class="modal">
    <h2>[icon:attachment] Request Additional Documents</h2>
    <p>Specify which documents are needed. The parishioner will be notified to resubmit.</p>
    <div class="form-group">
      <label>Message to Parishioner *</label>
      <textarea id="docsMessage" rows="4" placeholder="e.g. Please submit: 1) Original PSA Birth Certificate 2) Parental consent form signed" style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none"></textarea>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('docsModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="submitDocsRequest()" class="btn-sm btn-navy">Send Request</button>
    </div>
  </div>
</div>

<!-- Application Detail Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:680px;width:100%">
    <div id="viewModalContent" style="min-height:200px">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Service Applications</div>
    <h1 class="sec-title">Applications</h1>
    <p class="sec-sub">Monitor and process all sacramental service applications across all parishes.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="reports.php?type=applications" class="btn-sm btn-outline">Export Report</a>
  </div>
</div>

<!-- STATUS CARDS -->
<div class="stats-grid" style="margin-bottom:20px">
  <?php
  $scards = [
    ['Pending',  $counts['pending'],  'stat-amber','[icon:clock]','pending'],
    ['Approved', $counts['approved'], 'stat-green', '[icon:check]','approved'],
    ['Rejected', $counts['rejected'], 'stat-wine',  '[icon:close]','rejected'],
    ['Total',    $counts['total'],    'stat-navy',  '[icon:clipboard]',''],
  ];
  foreach($scards as [$label,$val,$cls,$icon,$sf]):
    $active = $status_filter === $sf && $sf !== '';
    $href = 'applications.php' . ($sf ? '?status='.$sf : '');
  ?>
  <a href="<?php echo $href; ?>" style="text-decoration:none">
    <div class="stat-card <?php echo $cls; ?>" style="cursor:pointer<?php echo $active ? ';box-shadow:0 0 0 3px var(--gold);' : ''; ?>">
      <div class="stat-icon"><?php echo $icon; ?></div>
      <div class="stat-label"><?php echo $label; ?></div>
      <div class="stat-value"><?php echo $val; ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- SEARCH + FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="applications.php" id="filterForm" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <!-- Live Search -->
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px;transition:border-color var(--ease)" id="searchWrap">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" id="searchInput" value="<?php echo htmlspecialchars($search); ?>"
          placeholder="Search name, email, service, ID…"
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%"
          oninput="liveSearch(this.value)">
        <?php if($search): ?>
        <a href="applications.php?<?php echo http_build_query(['status'=>$status_filter,'service'=>$service_filter,'parish'=>$parish_filter]); ?>" style="color:var(--ink-30);font-size:.8rem">[icon:close]</a>
        <?php endif; ?>
      </div>

      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <?php foreach(['pending','approved','rejected','cancelled'] as $s): ?>
        <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>

      <select name="service" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Services</option>
        <?php foreach($services_list as $sv): ?>
        <option value="<?php echo $sv; ?>" <?php echo $service_filter===$sv?'selected':''; ?>><?php echo $sv; ?></option>
        <?php endforeach; ?>
      </select>

      <select name="parish" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Parishes</option>
        <?php foreach($parishes_list as $pl): ?>
        <option value="<?php echo $pl; ?>" <?php echo $parish_filter===$pl?'selected':''; ?>><?php echo $pl; ?></option>
        <?php endforeach; ?>
      </select>

      <?php if($status_filter || $service_filter || $parish_filter || $search): ?>
      <a href="applications.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear All</a>
      <?php endif; ?>

      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto;white-space:nowrap" id="resultCount"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- APPLICATIONS TABLE -->
<div class="card">
  <div class="card-head">
    <h3>All Applications</h3>
    <span class="card-tag"><?php echo $total; ?> of <?php echo $counts['total']; ?></span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table id="appTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Parishioner</th>
            <th>Service</th>
            <th>Parish</th>
            <th>Schedule</th>
            <th>Filed</th>
            <th>Status</th>
            <th>Payment</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody id="appTableBody">
          <?php if(empty($paged)): ?>
          <tr id="emptyRow"><td colspan="9" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No applications match your search or filters.</td></tr>
          <?php endif; ?>
          <?php foreach($paged as $a):
            $pill  = $pillMap[$a['status']] ?? 'pill-amber';
            $ppill = $a['payment']==='paid' ? 'pill-green' : 'pill-wine';
          ?>
          <tr id="app-row-<?php echo $a['id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($a['name'])); ?>" data-service="<?php echo htmlspecialchars(strtolower($a['service'])); ?>" data-id="<?php echo $a['id']; ?>">
            <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $a['id']; ?></td>
            <td>
              <div style="font-weight:500"><?php echo htmlspecialchars($a['name']); ?></div>
              <div style="font-size:.72rem;color:var(--ink-30)"><?php echo htmlspecialchars($a['email']); ?></div>
            </td>
            <td><?php echo htmlspecialchars($a['service']); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($a['parish']); ?></td>
            <td style="font-size:.75rem;color:var(--ink-60)"><?php echo date('M j, Y', strtotime($a['schedule'])); ?></td>
            <td style="font-size:.73rem;color:var(--ink-30)"><?php echo date('M j', strtotime($a['filed'])); ?></td>
            <td>
              <span class="pill <?php echo $pill; ?>" id="status-badge-<?php echo $a['id']; ?>">
                <?php echo ucfirst($a['status']); ?>
              </span>
            </td>
            <td>
              <span class="pill <?php echo $ppill; ?>"><?php echo ucfirst($a['payment']); ?></span>
            </td>
            <td>
              <div class="app-row-actions" style="display:flex;gap:4px;flex-wrap:wrap">
                <button onclick="viewApp(<?php echo $a['id']; ?>)" class="act-btn act-navy" title="View details">[icon:eye]</button>
                
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10);flex-wrap:wrap;gap:10px">
    <span style="font-size:.78rem;color:var(--ink-30)">
      Page <?php echo $page_num; ?> of <?php echo $total_pages; ?> · Showing <?php echo $offset+1; ?>–<?php echo min($offset+$per_page,$total); ?> of <?php echo $total; ?>
    </span>
    <div style="display:flex;gap:4px">
      <?php
      $base_q = http_build_query(['status'=>$status_filter,'service'=>$service_filter,'parish'=>$parish_filter,'q'=>$search]);
      if($page_num > 1): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num-1; ?>" class="act-btn act-navy">← Prev</a>
      <?php endif;
      for($p = max(1,$page_num-2); $p <= min($total_pages,$page_num+2); $p++):
        $active_s = $p===$page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $p; ?>" class="act-btn" style="<?php echo $active_s; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $p; ?></a>
      <?php endfor;
      if($page_num < $total_pages): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num+1; ?>" class="act-btn act-navy">Next →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function escapeHtml(v){const e=document.createElement("span");e.textContent=String(v);return e.innerHTML.replace(/"/g,"&quot;");}
let pendingActionId   = null;
let pendingActionName = null;

// ── TOAST ────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = `toast toast-${type} show`;
    setTimeout(() => t.classList.remove('show'), 3800);
}

// ── LOADING ───────────────────────────────────
function setLoading(on) {
    document.getElementById('loadingOverlay').classList.toggle('show', on);
}

// ── APPROVE ───────────────────────────────────
function approveApp(id, name) {
    if (!confirm(`Approve application #${id} for ${name}?`)) return;
    setLoading(true);
    const fd = new FormData();
    fd.append('id', id);
    fetch('applications.php?ajax=approve', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
            if (data.success) {
                // Update badge in place
                const badge = document.getElementById(`status-badge-${id}`);
                if (badge) {
                    badge.className = 'pill pill-green';
                    badge.textContent = 'Approved';
                }
                // Hide action buttons
                const row = document.getElementById(`app-row-${id}`);
                if (row) {
                    const actions = row.querySelector('.app-row-actions');
                    if (actions) actions.innerHTML = '<span style="font-size:.72rem;color:var(--ink-30)">Processed</span>';
                }
                showToast(`[icon:check] ${data.message}`, 'success');
            } else {
                showToast(`[icon:close] ${data.message}`, 'error');
            }
        })
        .catch(() => { setLoading(false); showToast('Network error. Please try again.', 'error'); });
}

// ── REJECT MODAL ──────────────────────────────
function openRejectModal(id, name) {
    pendingActionId   = id;
    pendingActionName = name;
    document.getElementById('rejectReason').value = '';
    document.querySelector('#rejectModal h2').textContent = `[icon:close] Reject Application #${id}`;
    openModal('rejectModal');
}

function submitReject() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { document.getElementById('rejectReason').style.borderColor = 'var(--wine)'; return; }
    closeModal('rejectModal');
    setLoading(true);
    const fd = new FormData();
    fd.append('id', pendingActionId);
    fd.append('reason', reason);
    fetch('applications.php?ajax=reject', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
            if (data.success) {
                const badge = document.getElementById(`status-badge-${pendingActionId}`);
                if (badge) { badge.className = 'pill pill-wine'; badge.textContent = 'Rejected'; }
                const row = document.getElementById(`app-row-${pendingActionId}`);
                if (row) {
                    const actions = row.querySelector('.app-row-actions');
                    if (actions) actions.innerHTML = '<span style="font-size:.72rem;color:var(--ink-30)">Rejected</span>';
                }
                showToast(`[icon:check] ${data.message}`, 'success');
            } else {
                showToast(`[icon:close] ${data.message}`, 'error');
            }
        })
        .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── DOCS REQUEST ──────────────────────────────
function openDocsModal(id) {
    pendingActionId = id;
    document.getElementById('docsMessage').value = '';
    document.querySelector('#docsModal h2').textContent = `[icon:attachment] Request Docs for #${id}`;
    openModal('docsModal');
}

function submitDocsRequest() {
    const msg = document.getElementById('docsMessage').value.trim();
    if (!msg) { document.getElementById('docsMessage').style.borderColor = 'var(--wine)'; return; }
    closeModal('docsModal');
    setLoading(true);
    const fd = new FormData();
    fd.append('id', pendingActionId);
    fd.append('message', msg);
    fetch('applications.php?ajax=request_docs', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
            showToast(data.success ? `[icon:check] ${data.message}` : `[icon:close] ${data.message}`, data.success ? 'success' : 'error');
        })
        .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── VIEW APPLICATION DETAIL ────────────────────
function viewApp(id) {
    document.getElementById('viewModalContent').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px"><div class="spinner" style="border-top-color:var(--navy)"></div></div>';
    openModal('viewModal');
    fetch(`applications.php?ajax=get_app&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('viewModalContent').innerHTML = `<p style="color:var(--wine);padding:20px">${data.message}</p>`;
                return;
            }
            const a = data.data;
            const statusColor = {approved:'green',rejected:'wine',pending:'amber'}[a.status] || 'amber';
            const payColor = a.payment_status === 'paid' ? 'green' : 'wine';
            const docList = a.docs.length
                ? a.docs.map(d => `<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#F8F6F2;border-radius:6px;margin-bottom:6px">
                    <span>[icon:file]</span>
                    <span style="font-size:.8rem;flex:1">${escapeHtml(d.label)}</span>
                    <a href="${escapeHtml(d.url)}" class="act-btn act-navy" style="font-size:.68rem">[icon:download]</a>
                  </div>`).join('')
                : '<p style="font-size:.8rem;color:var(--ink-30);font-style:italic">No documents uploaded.</p>';

            document.getElementById('viewModalContent').innerHTML = `
              <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px">
                <div>
                  <h2 style="font-family:var(--fh);font-size:1.3rem">${a.service} Application</h2>
                  <p style="font-size:.8rem;color:var(--ink-60);margin-top:2px">Filed ${a.filed} · ${a.parish}</p>
                </div>
                <span class="pill pill-${statusColor}" style="font-size:.78rem;padding:5px 14px">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
                ${[['Full Name',a.name],['Email',a.email],['Phone',a.phone||'—'],['Amount','₱'+a.amount],['Payment Method',a.payment_method],['Payment Status',a.payment_status]].map(([l,v])=>`
                  <div style="background:#F8F6F2;border-radius:8px;padding:10px 12px">
                    <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:3px">${l}</div>
                    <div style="font-size:.83rem;font-weight:500;color:var(--ink)">${v}</div>
                  </div>`).join('')}
              </div>
              <div style="background:#F8F6F2;border-radius:8px;padding:12px;margin-bottom:14px">
                <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:4px">Schedule</div>
                <div style="font-size:.88rem;font-weight:500">${a.schedule}</div>
              </div>
              ${a.notes ? `<div style="background:#F8F6F2;border-radius:8px;padding:12px;margin-bottom:14px">
                <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:4px">Notes</div>
                <div style="font-size:.82rem;color:var(--ink);line-height:1.5">${a.notes}</div>
              </div>` : ''}
              ${a.rejection_reason ? `<div class="notice notice-wine" style="margin-bottom:14px"><span>[icon:close]</span><span><strong>Rejection Reason:</strong> ${a.rejection_reason}</span></div>` : ''}
              <div style="margin-bottom:16px">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:8px">Documents (${a.docs.length})</div>
                ${docList}
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--ink-10)">
                ${a.status === 'pending' ? `
                  
                ` : ''}
                <button onclick="closeModal('viewModal')" class="btn-sm btn-outline">Close</button>
              </div>
            `;
        });
}

// ── LIVE SEARCH (client-side) ─────────────────
function liveSearch(query) {
    const rows = document.querySelectorAll('#appTableBody tr[id^="app-row-"]');
    const q = query.toLowerCase().trim();
    let visible = 0;

    rows.forEach(row => {
        const name    = row.dataset.name    || '';
        const service = row.dataset.service || '';
        const id      = row.dataset.id      || '';
        const match   = !q || name.includes(q) || service.includes(q) || id.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('resultCount').textContent = visible + ' results';

    const empty = document.getElementById('emptyRow');
    if (!empty && visible === 0) {
        const tbody = document.getElementById('appTableBody');
        const tr = document.createElement('tr');
        tr.id = 'dynamicEmpty';
        tr.innerHTML = '<td colspan="9" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No matches found for "' + q + '"</td>';
        tbody.appendChild(tr);
    } else if (visible > 0) {
        const de = document.getElementById('dynamicEmpty');
        if (de) de.remove();
    }
}

// Auto-focus search
document.getElementById('searchInput').addEventListener('focus', () => {
    document.getElementById('searchWrap').style.borderColor = 'var(--gold)';
    document.getElementById('searchWrap').style.boxShadow = '0 0 0 3px var(--gold-dim)';
});
document.getElementById('searchInput').addEventListener('blur', () => {
    document.getElementById('searchWrap').style.borderColor = 'var(--ink-10)';
    document.getElementById('searchWrap').style.boxShadow = 'none';
});
</script>

<?php include 'includes/layout_footer.php'; ?>
