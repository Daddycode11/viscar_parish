<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Payments — Bookkeeper payment management
 * Features: list, verify, refund, search, filter by status/method, view detail, pagination
 */

$page_id    = 'payments';
$page_title = 'Payments';
$page_sub   = 'Payment Management';
include 'includes/layout.php';

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Verify payment
    if ($_GET['ajax'] === 'verify' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE payments SET status='completed', paid_at=NOW() WHERE id=? AND status='pending'");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        echo json_encode(['success' => $affected, 'message' => $affected ? "Payment #$id verified." : 'Payment not found or already processed.']);
        exit;
    }

    // Refund payment
    if ($_GET['ajax'] === 'refund' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $conn->prepare("UPDATE payments SET status='refunded' WHERE id=? AND status='completed'");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        if ($affected) {
            // Also update application payment_status
            $conn->query("UPDATE applications SET payment_status='refunded' WHERE id=(SELECT application_id FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE id=$id)");
        }
        echo json_encode(['success' => $affected, 'message' => $affected ? "Payment #$id refunded." : 'Payment not found or not eligible for refund.']);
        exit;
    }

    // Get payment detail
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT p.*, a.service_id, a.schedule, a.status AS app_status, a.payment_status AS app_payment_status,
                                       u.name AS parishioner_name, u.email AS parishioner_email, u.phone AS parishioner_phone,
                                       s.name AS service_name, s.fee AS service_fee,
                                       par.name AS parish_name
                                FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
                                JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
                                JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                                LEFT JOIN services s ON a.service_id = s.id
                                LEFT JOIN parishes par ON a.parish_id = par.id
                                WHERE p.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        if (!$pay) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }
        echo json_encode(['success' => true, 'data' => $pay]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
try {
$status_filter = input_text($_GET,'status');
$method_filter = input_text($_GET,'method');
$search        = input_text($_GET,'q');
$page_num      = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;

$where = [];
$params = [];
$types  = '';

if ($status_filter && in_array($status_filter, ['pending','completed','refunded','failed'])) {
    $where[] = "p.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($method_filter) {
    $where[] = "p.payment_method = ?";
    $params[] = $method_filter;
    $types .= 's';
}
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR p.reference_number LIKE ? OR p.id LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$csql = "SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
         JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
         JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
         $where_sql";
$stmt = $conn->prepare($csql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Fetch
$sql = "SELECT p.*,
               u.name AS parishioner_name, u.email AS parishioner_email,
               s.name AS service_name, a.service_id
        FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
        JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
        JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        $where_sql
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$btypes = $types . 'ii';
$bparams = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
if ($bparams) $stmt->bind_param($btypes, ...$bparams);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Status counts
$cnt_all       = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments")->fetch_assoc()['t'];
$cnt_pending   = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='pending'")->fetch_assoc()['t'];
$cnt_completed = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='completed'")->fetch_assoc()['t'];
$cnt_refunded  = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='refunded'")->fetch_assoc()['t'];

// Revenue totals
$total_revenue = $conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='completed'")->fetch_assoc()['t'];
$today_revenue = $conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='completed' AND DATE(paid_at)=CURDATE()")->fetch_assoc()['t'];

// Payment methods for filter
$methods_r = $conn->query("SELECT DISTINCT payment_method FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE payment_method IS NOT NULL AND payment_method != '' ORDER BY payment_method");
$methods = [];
if ($methods_r) while ($row = $methods_r->fetch_assoc()) $methods[] = $row['payment_method'];

$pillMap = ['completed'=>'pill-green','pending'=>'pill-amber','refunded'=>'pill-wine','failed'=>'pill-wine'];
} catch(Throwable $error) {
    $reference=bin2hex(random_bytes(6));
    error_log('Payments page ['.$reference.']: '.get_class($error).' code '.$error->getCode().' '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine());
    http_response_code(500);
    echo '<section class="card"><div class="card-body" role="alert"><h2>Payment records could not be loaded</h2><p>Please retry. If this continues, give the administrator reference '.h($reference).'. Your payment records have not been changed.</p><a class="btn-sm btn-navy" href="payments.php">Retry payments</a></div></section>';
    require __DIR__.'/includes/layout_footer.php';exit;
}
?>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Refund Modal -->
<div class="modal-wrap" id="refundModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Refund Payment</h2>
    <p>Are you sure you want to refund this payment? The application payment status will also be updated.</p>
    <div class="form-group">
      <label>Reason (optional)</label>
      <textarea id="refundReason" rows="3" placeholder="Reason for refund..." style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none"></textarea>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('refundModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="confirmRefund()" class="btn-sm btn-wine">Confirm Refund</button>
    </div>
  </div>
</div>

<!-- View Detail Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:650px;width:100%">
    <div id="viewContent" style="min-height:200px">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Financial Management</div>
    <h1 class="sec-title">Payments</h1>
    <p class="sec-sub">Verify, track, and manage all parish payment transactions.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="finance.php" class="btn-sm btn-outline">[icon:chart] Financial Reports</a>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stats-grid" style="margin-bottom:20px">
  <a href="payments.php" style="text-decoration:none">
    <div class="stat-card stat-navy" style="padding:16px 20px<?php echo !$status_filter?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:clipboard]</div>
      <div class="stat-label">Total Payments</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_all; ?></div>
    </div>
  </a>
  <a href="payments.php?status=pending" style="text-decoration:none">
    <div class="stat-card stat-amber" style="padding:16px 20px<?php echo $status_filter==='pending'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:clock]</div>
      <div class="stat-label">Pending</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_pending; ?></div>
      <div class="stat-delta down">Need verification</div>
    </div>
  </a>
  <a href="payments.php?status=completed" style="text-decoration:none">
    <div class="stat-card stat-green" style="padding:16px 20px<?php echo $status_filter==='completed'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:check]</div>
      <div class="stat-label">Verified</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_completed; ?></div>
      <div class="stat-delta">₱<?php echo number_format($total_revenue); ?> total</div>
    </div>
  </a>
  <a href="payments.php?status=refunded" style="text-decoration:none">
    <div class="stat-card stat-wine" style="padding:16px 20px<?php echo $status_filter==='refunded'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:refresh]</div>
      <div class="stat-label">Refunded</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_refunded; ?></div>
    </div>
  </a>
</div>

<!-- TODAY REVENUE BANNER -->
<?php if ((float)$today_revenue > 0): ?>
<div class="notice notice-green" style="margin-bottom:18px">
  <span>[icon:sun]</span>
  <span>Today's verified revenue: <strong>₱<?php echo number_format($today_revenue, 2); ?></strong> (<?php echo date('F j, Y'); ?>)</span>
</div>
<?php endif; ?>

<?php if ((int)$cnt_pending > 0): ?>
<div class="notice notice-amber" style="margin-bottom:18px">
  <span>[icon:alert]</span>
  <span><strong><?php echo $cnt_pending; ?> payment<?php echo $cnt_pending > 1 ? 's' : ''; ?></strong> awaiting verification. Review and confirm transactions promptly.</span>
</div>
<?php endif; ?>

<!-- SEARCH + FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="payments.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email, reference #..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <?php foreach (['pending','completed','refunded','failed'] as $s): ?>
        <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="method" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Methods</option>
        <?php foreach ($methods as $m): ?>
        <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $method_filter===$m?'selected':''; ?>><?php echo htmlspecialchars($m); ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($status_filter || $method_filter || $search): ?>
      <a href="payments.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- PAYMENTS TABLE -->
<div class="card">
  <div class="card-head">
    <h3>All Payments</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Parishioner</th><th>Service</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th><th>Date</th><th style="width:140px">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr><td colspan="9" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No payments found.</td></tr>
          <?php endif; ?>
          <?php foreach ($payments as $p):
            $pill = $pillMap[$p['status']] ?? 'pill-amber';
          ?>
          <tr id="pay-row-<?php echo $p['id']; ?>">
            <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $p['id']; ?></td>
            <td>
              <div style="font-weight:500"><?php echo htmlspecialchars($p['parishioner_name']); ?></div>
              <div style="font-size:.72rem;color:var(--ink-30)"><?php echo htmlspecialchars($p['parishioner_email']); ?></div>
            </td>
            <td><?php echo htmlspecialchars($p['service_name'] ?? 'Service #'.$p['service_id']); ?></td>
            <td><span class="pill pill-navy"><?php echo htmlspecialchars(($p['manual_method_name']??'') ?: $p['payment_method'] ?: '—'); ?></span></td>
            <td style="font-size:.75rem;color:var(--ink-60);font-family:monospace"><?php echo htmlspecialchars($p['reference_number'] ?: '—'); ?></td>
            <td style="font-weight:600;color:var(--green)">₱<?php echo number_format($p['amount'], 2); ?></td>
            <td><span class="pill <?php echo $pill; ?>" id="pstatus-<?php echo $p['id']; ?>"><?php echo ucfirst($p['status']); ?></span></td>
            <td style="font-size:.73rem;color:var(--ink-30)"><?php echo date('M j, Y', strtotime($p['paid_at'] ?? $p['created_at'])); ?></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <?php if(!empty($p['proof_file'])): ?><a class="act-btn act-navy" target="_blank" rel="noopener" href="../public/payment_file.php?payment=<?= (int)$p['id'] ?>">View proof</a><a class="act-btn" href="../public/payment_file.php?payment=<?= (int)$p['id'] ?>&amp;download=1">Download</a><?php endif; ?>
                <button onclick="viewPayment(<?php echo $p['id']; ?>)" class="act-btn act-navy" title="View">[icon:eye]</button>
                <?php if ($p['status'] === 'pending'): ?>
                <button onclick="verifyPayment(<?php echo $p['id']; ?>)" class="act-btn act-green" title="Verify">[icon:check] Verify</button>
                <button type="button" class="act-btn act-wine" onclick="markFailed(<?= (int)$p['id'] ?>)">Failed / Did not pay</button>
                <?php elseif ($p['status'] === 'completed'): ?>
                <a href="requests.php?type=refund&amp;application_id=<?= (int)$p['application_id'] ?>" class="act-btn act-wine"><?= h(t('Refunds')) ?></a>
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
      $base_q = http_build_query(array_filter(['status'=>$status_filter,'method'=>$method_filter,'q'=>$search]));
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
let refundId = null;
async function markFailed(id){const reason=prompt('Why did this payment fail?');if(!reason||!reason.trim())return;const data=new FormData();data.append('id',id);data.append('reason',reason);try{const response=await fetch('payments.php?ajax=fail_payment',{method:'POST',body:data});const result=await response.json();showToast(result.message,result.success?'success':'error');if(result.success)location.reload();}catch(e){showToast('Unable to save. Please retry.','error');}}

function verifyPayment(id) {
    if (!confirm('Verify payment #' + id + ' as completed?')) return;
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('payments.php?ajax=verify', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const b = document.getElementById('pstatus-' + id);
                if (b) { b.className = 'pill pill-green'; b.textContent = 'Completed'; }
                showToast('[icon:check] ' + data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function openRefund(id) {
    refundId = id;
    document.getElementById('refundReason').value = '';
    openModal('refundModal');
}

function confirmRefund() {
    closeModal('refundModal');
    setLoading(true);
    const fd = new FormData();
    fd.append('id', refundId);
    fd.append('reason', document.getElementById('refundReason').value.trim());
    fetch('payments.php?ajax=refund', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const b = document.getElementById('pstatus-' + refundId);
                if (b) { b.className = 'pill pill-wine'; b.textContent = 'Refunded'; }
                showToast('[icon:check] ' + data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function viewPayment(id) {
    document.getElementById('viewContent').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px"><div class="spinner" style="border-top-color:var(--navy)"></div></div>';
    openModal('viewModal');
    fetch('payments.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('viewContent').innerHTML = '<p style="color:var(--wine);padding:20px">' + data.message + '</p>'; return; }
            const p = data.data;
            const safe=value=>{const el=document.createElement('span');el.textContent=value??'';return el.innerHTML;};for(const key of ['parishioner_name','parishioner_email','parishioner_phone','parish_name','reference_number','failure_reason','service_name','manual_method_name'])p[key]=safe(p[key]);
            const sc = {completed:'green',pending:'amber',refunded:'wine'}[p.status] || 'amber';
            document.getElementById('viewContent').innerHTML = `
              <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px">
                <div>
                  <h2 style="font-family:var(--fh);font-size:1.3rem">Payment #${p.id}</h2>
                  <p style="font-size:.8rem;color:var(--ink-60);margin-top:2px">Application #${p.application_id} &middot; ${p.service_name || 'Service #'+p.service_id}</p>
                </div>
                <span class="pill pill-${sc}" style="font-size:.78rem;padding:5px 14px">${p.status.charAt(0).toUpperCase()+p.status.slice(1)}</span>
              </div>
              <div style="background:var(--green-dim);border-radius:10px;padding:18px;text-align:center;margin-bottom:18px">
                <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:var(--green);margin-bottom:4px">Amount</div>
                <div style="font-family:var(--fh);font-size:2.2rem;font-weight:600;color:var(--green)">\u20B1${Number(p.amount).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
                ${[['Parishioner',p.parishioner_name],['Email',p.parishioner_email],['Phone',p.parishioner_phone||'\u2014'],['Parish',p.parish_name||'\u2014'],['Payment Method',p.manual_method_name||p.payment_method||'\u2014'],['Reference #',p.reference_number||'\u2014'],['Failure reason',p.failure_reason||'\u2014'],['Failed at',ViscarTime.datetime(p.failed_at)||'\u2014'],['Service Fee',p.service_fee?'\u20B1'+Number(p.service_fee).toLocaleString():'\u2014'],['Schedule',p.schedule?new Date(p.schedule).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true}):'\u2014'],['Paid At',p.paid_at?new Date(p.paid_at).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true}):'\u2014'],['Created',new Date(p.created_at).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true})]].map(([l,v])=>`
                  <div style="background:#F8F6F2;border-radius:8px;padding:10px 12px">
                    <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:3px">${l}</div>
                    <div style="font-size:.83rem;font-weight:500;color:var(--ink)">${v}</div>
                  </div>`).join('')}
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--ink-10)">
                ${p.status === 'pending' ? `<button onclick="closeModal('viewModal');verifyPayment(${p.id})" class="btn-sm btn-green">[icon:check] Verify</button>` : ''}
                ${p.status === 'completed' ? `<button onclick="location.href='requests.php?type=refund&amp;application_id=${Number(p.application_id)}'" class="btn-sm btn-wine">[icon:refresh] Refund</button>` : ''}
                <button onclick="closeModal('viewModal')" class="btn-sm btn-outline">Close</button>
              </div>`;
        });
}
</script>

<?php include 'includes/layout_footer.php'; ?>
