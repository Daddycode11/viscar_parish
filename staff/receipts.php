<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Official Receipts — Bookkeeper Role
 * Features: generate receipts for completed payments, search/filter, print, pagination
 */

$page_id    = 'receipts';
$page_title = 'Official Receipts';
$page_sub   = 'Receipts';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../includes/notifications.php';

// Bookkeeper only
if ($user['role'] !== 'bookkeeper') {
    header('Location: dashboard.php');
    exit;
}

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Get pending payments (completed, no receipt)
    if ($_GET['ajax'] === 'pending_payments') {
        $stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_method, p.reference_number, p.paid_at,
                                       u.name AS parishioner_name, s.name AS service_name, pa.name AS parish_name
                                FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
                                JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
                                JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                                LEFT JOIN services s ON a.service_id = s.id
                                LEFT JOIN parishes pa ON a.parish_id = pa.id
                                WHERE p.status = 'completed' AND p.receipt_number IS NULL
                                ORDER BY p.paid_at DESC");
        $stmt->execute();
        $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'data' => $payments]);
        exit;
    }

    // Generate receipt
    if ($_GET['ajax'] === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');

        if (!$payment_id) {
            echo json_encode(['success' => false, 'message' => 'Payment ID is required.']);
            exit;
        }

        // Verify payment exists and has no receipt
        $stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_method, p.reference_number,
                                       u.id AS user_id, u.name AS parishioner_name,
                                       s.name AS service_name, pa.name AS parish_name
                                FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
                                JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
                                JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                                LEFT JOIN services s ON a.service_id = s.id
                                LEFT JOIN parishes pa ON a.parish_id = pa.id
                                WHERE p.id = ? AND p.status = 'completed' AND p.receipt_number IS NULL");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();

        if (!$pay) {
            echo json_encode(['success' => false, 'message' => 'Payment not found, not completed, or already has a receipt.']);
            exit;
        }

        // Generate receipt number: OR-YYYYMMDD-padded_id
        // First insert to get the ID, then update the receipt_number
        $ins = $conn->prepare("INSERT INTO receipts (payment_id, receipt_number, amount, parishioner_name, service_name, parish_name, issued_by, issued_at, notes, created_at) VALUES (?, '', ?, ?, ?, ?, ?, NOW(), ?, NOW())");
        $ins->bind_param('idssssis', $payment_id, $pay['amount'], $pay['parishioner_name'], $pay['service_name'], $pay['parish_name'], $user['id'], $notes);
        if (!$ins->execute()) {
            echo json_encode(['success' => false, 'message' => 'Failed to create receipt: ' . $conn->error]);
            exit;
        }

        $receipt_id = $conn->insert_id;
        $receipt_number = 'OR-' . date('Ymd') . '-' . str_pad($receipt_id, 4, '0', STR_PAD_LEFT);

        // Update receipt with generated number
        $upd = $conn->prepare("UPDATE receipts SET receipt_number = ? WHERE id = ?");
        $upd->bind_param('si', $receipt_number, $receipt_id);
        $upd->execute();

        // Update payment with receipt number
        $upd2 = $conn->prepare("UPDATE payments SET receipt_number = ? WHERE id = ?");
        $upd2->bind_param('si', $receipt_number, $payment_id);
        $upd2->execute();

        // Audit log
        auditLog($user['id'], 'generate_receipt', 'receipt', $receipt_id, "Receipt $receipt_number for payment #$payment_id, amount: " . number_format($pay['amount'], 2));

        // Payment confirmation to the parishioner — in-app + SMS + Email
        $confirm_title   = "Payment Confirmed — Receipt $receipt_number";
        $confirm_message = "Hi {$pay['parishioner_name']}, we received your payment of PHP "
                         . number_format((float)$pay['amount'], 2) . " for "
                         . ($pay['service_name'] ?? 'parish service')
                         . ". Your official receipt number is $receipt_number. Thank you.";
        $channels = ['in-app'];
        if (role_can_channel('bookkeeper', 'sms'))   $channels[] = 'sms';
        if (role_can_channel('bookkeeper', 'email')) $channels[] = 'email';
        $dispatch = dispatch_to_user((int)$pay['user_id'], $confirm_title, $confirm_message, $channels, 'payment');

        echo json_encode([
            'success' => true,
            'message' => "Receipt $receipt_number generated. Confirmation: SMS {$dispatch['sms_sent']}/{$dispatch['sms_failed']}, Email {$dispatch['email_sent']}/{$dispatch['email_failed']}.",
            'receipt_number' => $receipt_number,
            'id' => $receipt_id,
        ]);
        exit;
    }

    // Get receipt detail
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT r.*, u.name AS issued_by_name, p.payment_method, p.reference_number
                                FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) r
                                LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON r.issued_by = u.id
                                LEFT JOIN (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p ON r.payment_id = p.id
                                WHERE r.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Receipt not found.']); exit; }
        echo json_encode(['success' => true, 'data' => $rec]);
        exit;
    }

    // Print receipt
    if ($_GET['ajax'] === 'print' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT r.*, u.name AS issued_by_name, p.payment_method, p.reference_number
                                FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) r
                                LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON r.issued_by = u.id
                                LEFT JOIN (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p ON r.payment_id = p.id
                                WHERE r.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Receipt not found.']); exit; }

        $booking=$conn->execute_query('SELECT a.* FROM applications a JOIN payments p ON p.application_id=a.id WHERE p.id=?',[$rec['payment_id']])->fetch_assoc();
        $qr_data=getVerificationURL($booking['id'],$booking['parish_id'],$booking['qr_code']);
        $qr_url = getQRImageURL($qr_data, 80);

        $html = generateReceiptHTML($rec, $rec['parish_name'] ?? '', $qr_url);
        echo json_encode(['success' => true, 'html' => $html]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to'] ?? '';
$page_num  = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

$where  = [];
$params = [];
$types  = '';

if ($search) {
    $where[] = "(r.receipt_number LIKE ? OR r.parishioner_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}
if ($date_from) {
    $where[] = "DATE(r.issued_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where[] = "DATE(r.issued_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$csql = "SELECT COUNT(*) as t FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) r $where_sql";
$stmt = $conn->prepare($csql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Fetch receipts
$sql = "SELECT r.id, r.receipt_number, r.parishioner_name, r.service_name, r.amount, r.issued_at, r.parish_name
        FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) r
        $where_sql
        ORDER BY r.issued_at DESC
        LIMIT ? OFFSET ?";
$btypes = $types . 'ii';
$bparams = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
if ($bparams) $stmt->bind_param($btypes, ...$bparams);
$stmt->execute();
$receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stat counts
$cnt_total    = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) receipts")->fetch_assoc()['t'];
$cnt_today    = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) receipts WHERE DATE(issued_at) = CURDATE()")->fetch_assoc()['t'];
$sum_total    = (float)$conn->query("SELECT IFNULL(SUM(amount), 0) as t FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) receipts")->fetch_assoc()['t'];
$sum_month    = (float)$conn->query("SELECT IFNULL(SUM(amount), 0) as t FROM (SELECT * FROM receipts WHERE payment_id IN (SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id = {$scopeParish})) receipts WHERE MONTH(issued_at) = MONTH(CURDATE()) AND YEAR(issued_at) = YEAR(CURDATE())")->fetch_assoc()['t'];

// Count pending payments (for badge)
$cnt_pending = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='completed' AND receipt_number IS NULL")->fetch_assoc()['t'];
?>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Generate Receipt Modal -->
<div class="modal-wrap" id="generateModal">
  <div class="modal" style="max-width:600px">
    <h2>Generate Official Receipt</h2>
    <p>Select a completed payment to issue an official receipt.</p>
    <div id="pendingPaymentsList" style="margin-bottom:16px">
      <div style="text-align:center;padding:30px;color:var(--ink-30)">Loading payments...</div>
    </div>
    <div id="generateFormWrap" style="display:none">
      <div class="notice notice-navy" style="margin-bottom:14px">
        <span>[icon:wallet]</span>
        <div id="selectedPaymentInfo"></div>
      </div>
      <input type="hidden" id="gen_payment_id">
      <div class="form-group">
        <label>Notes (optional)</label>
        <textarea id="gen_notes" rows="2" placeholder="Additional notes for this receipt..." style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;background:#FAFAF8"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="resetGenerateModal()" class="btn-sm btn-outline">Back</button>
        <button type="button" onclick="confirmGenerate()" class="btn-sm btn-green">[icon:file] Generate Receipt</button>
      </div>
    </div>
    <div id="generateModalFooter">
      <div class="modal-actions">
        <button type="button" onclick="closeModal('generateModal')" class="btn-sm btn-outline">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- View Receipt Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:600px;width:100%">
    <div id="viewContent" style="min-height:200px;display:flex;align-items:center;justify-content:center">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Financial Management</div>
    <h1 class="sec-title">Official Receipts</h1>
    <p class="sec-sub">Generate and manage official receipts for completed payments.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button onclick="openGenerateModal()" class="btn-sm btn-navy" style="position:relative">
      [icon:plus] Generate Receipt
      <?php if ($cnt_pending > 0): ?>
      <span style="position:absolute;top:-6px;right:-6px;background:var(--wine);color:white;font-size:.6rem;padding:2px 6px;border-radius:10px;min-width:18px;text-align:center"><?php echo $cnt_pending; ?></span>
      <?php endif; ?>
    </button>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stats-grid">
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:clipboard]</div>
    <div class="stat-label">Total Receipts</div>
    <div class="stat-value"><?php echo $cnt_total; ?></div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon">[icon:calendar]</div>
    <div class="stat-label">Today's Receipts</div>
    <div class="stat-value"><?php echo $cnt_today; ?></div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">₱</div>
    <div class="stat-label">Total Amount Receipted</div>
    <div class="stat-value" style="font-size:1.5rem">₱<?php echo number_format($sum_total, 2); ?></div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:chart]</div>
    <div class="stat-label">This Month Revenue</div>
    <div class="stat-value" style="font-size:1.5rem">₱<?php echo number_format($sum_month, 2); ?></div>
  </div>
</div>

<?php if ($cnt_pending > 0): ?>
<div class="notice notice-amber" style="margin-bottom:18px">
  <span>[icon:alert]</span>
  <span><strong><?php echo $cnt_pending; ?> completed payment<?php echo $cnt_pending > 1 ? 's' : ''; ?></strong> awaiting receipt generation.</span>
</div>
<?php endif; ?>

<!-- SEARCH & FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="receipts.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by receipt # or parishioner name..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <input type="date" name="from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From"
        style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none" onchange="this.form.submit()">
      <input type="date" name="to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To"
        style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none" onchange="this.form.submit()">
      <?php if ($search || $date_from || $date_to): ?>
      <a href="receipts.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- RECEIPTS TABLE -->
<div class="card">
  <div class="card-head">
    <h3>All Receipts</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Receipt #</th>
            <th>Parishioner</th>
            <th>Service</th>
            <th>Amount</th>
            <th>Issued At</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($receipts)): ?>
          <tr><td colspan="6" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No receipts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($receipts as $r): ?>
          <tr>
            <td><span style="font-family:monospace;font-size:.78rem;font-weight:600;color:var(--navy)"><?php echo htmlspecialchars($r['receipt_number']); ?></span></td>
            <td><div style="font-weight:500"><?php echo htmlspecialchars($r['parishioner_name']); ?></div></td>
            <td style="font-size:.82rem;color:var(--ink-60)"><?php echo htmlspecialchars($r['service_name'] ?? '—'); ?></td>
            <td style="font-weight:600;color:var(--green)">₱<?php echo number_format($r['amount'], 2); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo date('M j, Y g:i A', strtotime($r['issued_at'])); ?></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <button onclick="viewReceipt(<?php echo $r['id']; ?>)" class="act-btn act-navy" title="View">[icon:eye]</button>
                <button onclick="printReceipt(<?php echo $r['id']; ?>)" class="act-btn act-green" title="Print">[icon:print] Print</button>
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
      $base_q = http_build_query(array_filter(['q'=>$search,'from'=>$date_from,'to'=>$date_to]));
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
function openGenerateModal() {
    document.getElementById('generateFormWrap').style.display = 'none';
    document.getElementById('generateModalFooter').style.display = '';
    document.getElementById('pendingPaymentsList').innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-30)">Loading payments...</div>';
    openModal('generateModal');

    fetch('receipts.php?ajax=pending_payments')
        .then(r => r.json()).then(data => {
            if (!data.success || !data.data.length) {
                document.getElementById('pendingPaymentsList').innerHTML = '<div class="empty-state" style="text-align:center;padding:30px;color:var(--ink-30)"><div style="font-size:2rem;margin-bottom:8px">[icon:check]</div><p>All completed payments already have receipts.</p></div>';
                return;
            }
            let html = '<div style="max-height:320px;overflow-y:auto">';
            data.data.forEach(p => {
                html += `<div onclick="selectPayment(${p.id}, this)" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border:1.5px solid var(--ink-10);border-radius:10px;margin-bottom:8px;cursor:pointer;transition:.2s" onmouseover="this.style.borderColor='var(--gold)';this.style.background='var(--gold-dim)'" onmouseout="this.style.borderColor='var(--ink-10)';this.style.background='none'" data-id="${p.id}">
                  <div>
                    <div style="font-weight:500;font-size:.88rem">${p.parishioner_name}</div>
                    <div style="font-size:.72rem;color:var(--ink-60)">${p.service_name || 'N/A'} &middot; Payment #${p.id} &middot; ${p.payment_method || 'N/A'}</div>
                    ${p.reference_number ? '<div style="font-size:.68rem;color:var(--ink-30);font-family:monospace">Ref: ' + p.reference_number + '</div>' : ''}
                  </div>
                  <div style="text-align:right">
                    <div style="font-weight:600;color:var(--green);font-size:1rem">\u20B1${Number(p.amount).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
                    <div style="font-size:.68rem;color:var(--ink-30)">${p.paid_at ? new Date(p.paid_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : ''}</div>
                  </div>
                </div>`;
            });
            html += '</div>';
            document.getElementById('pendingPaymentsList').innerHTML = html;
        }).catch(() => {
            document.getElementById('pendingPaymentsList').innerHTML = '<div style="text-align:center;padding:30px;color:var(--wine)">Failed to load payments.</div>';
        });
}

function selectPayment(id, el) {
    document.getElementById('gen_payment_id').value = id;
    const name = el.querySelector('div > div:first-child').textContent;
    const detail = el.querySelector('div > div:nth-child(2)').textContent;
    const amount = el.querySelector('div:last-child > div:first-child').textContent;
    document.getElementById('selectedPaymentInfo').innerHTML = `<strong>${name}</strong><br><span style="font-size:.8rem">${detail}</span><br><span style="font-size:.9rem;font-weight:600">${amount}</span>`;
    document.getElementById('gen_notes').value = '';
    document.getElementById('pendingPaymentsList').style.display = 'none';
    document.getElementById('generateFormWrap').style.display = '';
    document.getElementById('generateModalFooter').style.display = 'none';
}

function resetGenerateModal() {
    document.getElementById('pendingPaymentsList').style.display = '';
    document.getElementById('generateFormWrap').style.display = 'none';
    document.getElementById('generateModalFooter').style.display = '';
}

function confirmGenerate() {
    const paymentId = document.getElementById('gen_payment_id').value;
    if (!paymentId) { showToast('Please select a payment.', 'error'); return; }

    setLoading(true);
    const fd = new FormData();
    fd.append('payment_id', paymentId);
    fd.append('notes', document.getElementById('gen_notes').value);

    fetch('receipts.php?ajax=generate', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast(data.message, 'success');
                closeModal('generateModal');
                setTimeout(() => location.reload(), 1200);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function viewReceipt(id) {
    document.getElementById('viewContent').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px"><div class="spinner" style="border-top-color:var(--navy)"></div></div>';
    openModal('viewModal');
    fetch('receipts.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('viewContent').innerHTML = '<p style="color:var(--wine);padding:20px">' + data.message + '</p>'; return; }
            const d = data.data;
            document.getElementById('viewContent').innerHTML = `
              <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px">
                <div>
                  <h2 style="font-family:var(--fh);font-size:1.3rem">Receipt ${d.receipt_number}</h2>
                  <p style="font-size:.8rem;color:var(--ink-60);margin-top:2px">Payment #${d.payment_id}</p>
                </div>
                <span class="pill pill-green" style="font-size:.78rem;padding:5px 14px">Issued</span>
              </div>
              <div style="background:var(--green-dim);border-radius:10px;padding:18px;text-align:center;margin-bottom:18px">
                <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:var(--green);margin-bottom:4px">Amount</div>
                <div style="font-family:var(--fh);font-size:2.2rem;font-weight:600;color:var(--green)">\u20B1${Number(d.amount).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
                ${[
                  ['Receipt Number', d.receipt_number],
                  ['Parishioner', d.parishioner_name],
                  ['Service', d.service_name || '\u2014'],
                  ['Parish', d.parish_name || '\u2014'],
                  ['Payment Method', d.payment_method || '\u2014'],
                  ['Reference #', d.reference_number || '\u2014'],
                  ['Issued By', d.issued_by_name || '\u2014'],
                  ['Issued At', d.issued_at ? new Date(d.issued_at).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}) : '\u2014'],
                  ['Notes', d.notes || '\u2014']
                ].map(([l,v])=>`
                  <div style="background:#F8F6F2;border-radius:8px;padding:10px 12px${l==='Notes'?';grid-column:1/-1':''}">
                    <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:3px">${l}</div>
                    <div style="font-size:.83rem;font-weight:500;color:var(--ink)">${v}</div>
                  </div>`).join('')}
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--ink-10)">
                <button onclick="closeModal('viewModal');printReceipt(${d.id})" class="btn-sm btn-green">[icon:print] Print</button>
                <button onclick="closeModal('viewModal')" class="btn-sm btn-outline">Close</button>
              </div>`;
        });
}

function printReceipt(id) {
    setLoading(true);
    fetch('receipts.php?ajax=print&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const w = window.open('', '_blank');
            w.document.write(data.html);
            w.document.close();
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
