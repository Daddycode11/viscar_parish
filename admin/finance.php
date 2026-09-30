<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Finance — Dynamic version with AJAX payment verification and filters
 */

require_once '../includes/db.php';
require_once '../includes/notifications.php';
$user = currentUser();

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'verify_payment' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE payments SET status='completed', paid_at=COALESCE(paid_at, NOW()), verified_by=?, verified_at=NOW() WHERE id=?");
        $stmt->bind_param('ii', $user['id'], $id);
        $ok = $stmt->execute();

        if ($ok) {
            $r = $conn->prepare("SELECT a.user_id, p.amount FROM payments p JOIN applications a ON p.application_id=a.id WHERE p.id=?");
            $r->bind_param('i', $id);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            if ($row) {
                dispatch_to_user(
                    (int)$row['user_id'],
                    'Payment Verified',
                    'Your payment of PHP ' . number_format((float)$row['amount'], 2) . ' has been verified.',
                    ['in-app','sms','email'],
                    'payment'
                );
            }
        }
        echo json_encode(['success' => $ok, 'message' => $ok ? "Payment #$id verified." : 'Failed.']);
        exit;
    }

    if ($_GET['ajax'] === 'refund' && isset($_POST['id'])) {
        $id     = (int)$_POST['id'];
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $conn->prepare("UPDATE payments SET status='refunded', refund_reason=? WHERE id=?");
        $stmt->bind_param('si', $reason, $id);
        $ok = $stmt->execute();

        if ($ok) {
            $r = $conn->prepare("SELECT a.user_id, p.amount FROM payments p JOIN applications a ON p.application_id=a.id WHERE p.id=?");
            $r->bind_param('i', $id);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            if ($row) {
                dispatch_to_user(
                    (int)$row['user_id'],
                    'Refund Processed',
                    'A refund of PHP ' . number_format((float)$row['amount'], 2) . ' has been processed.' . ($reason ? " Reason: $reason" : ''),
                    ['in-app','sms','email'],
                    'payment'
                );
            }
        }
        echo json_encode(['success' => $ok, 'message' => $ok ? "Refund processed for payment #$id." : 'Failed.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── FILTER PARAMS ─────────────────────────────
$method_filter  = $_GET['method']  ?? '';
$status_filter  = $_GET['status']  ?? '';
$parish_filter  = $_GET['parish']  ?? '';
$date_from      = $_GET['date_from'] ?? date('Y-m-01');
$date_to        = $_GET['date_to']   ?? date('Y-m-t');
$page_num       = max(1,(int)($_GET['page'] ?? 1));
$per_page       = 12;

$parishes_list = [];
$rs = $conn->query("SELECT name FROM parishes ORDER BY name");
while ($r = $rs->fetch_assoc()) $parishes_list[] = $r['name'];

// ── BUILD FILTERED PAYMENTS QUERY ─────────────
$where = []; $params = []; $types = '';
if ($status_filter && in_array($status_filter, ['pending','completed','refunded'])) {
    $where[] = "p.status = ?"; $params[] = $status_filter; $types .= 's';
}
if ($method_filter) {
    $where[] = "p.payment_method = ?"; $params[] = $method_filter; $types .= 's';
}
if ($parish_filter) {
    $where[] = "pa.name = ?"; $params[] = $parish_filter; $types .= 's';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cstmt = $conn->prepare("SELECT COUNT(*) AS t FROM payments p JOIN applications a ON p.application_id=a.id LEFT JOIN parishes pa ON a.parish_id=pa.id $where_sql");
if ($params) $cstmt->bind_param($types, ...$params);
$cstmt->execute();
$total = (int)$cstmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

$pstmt = $conn->prepare("
    SELECT p.id, p.application_id AS app_id, p.amount, p.payment_method, p.status,
           COALESCE(p.paid_at, p.created_at) AS date,
           u.name AS name, s.name AS service, pa.name AS parish
    FROM payments p
    JOIN applications a ON p.application_id = a.id
    JOIN users u ON a.user_id = u.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN parishes pa ON a.parish_id = pa.id
    $where_sql
    ORDER BY date DESC
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
        'id'      => (int)$r['id'],
        'app_id'  => (int)$r['app_id'],
        'name'    => $r['name'],
        'service' => $r['service'] ?? '—',
        'parish'  => $r['parish'] ?? '—',
        'amount'  => (float)$r['amount'],
        'method'  => $r['payment_method'] ?: '—',
        'status'  => $r['status'],
        'date'    => $r['date'],
    ];
}

// Summary stats (whole table, not just current page)
$totals = $conn->query("
    SELECT
      IFNULL(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS total_collected,
      IFNULL(SUM(CASE WHEN status='completed' AND DATE(COALESCE(paid_at, created_at))=CURDATE() THEN amount ELSE 0 END),0) AS today_collected,
      IFNULL(SUM(CASE WHEN status='completed' AND YEAR(COALESCE(paid_at, created_at))=YEAR(CURDATE()) AND MONTH(COALESCE(paid_at, created_at))=MONTH(CURDATE()) THEN amount ELSE 0 END),0) AS monthly_amt,
      SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count
    FROM payments
")->fetch_assoc();
require_once APP_ROOT.'/includes/financial_totals.php';
$lifetimeFinancial=financial_totals('1900-01-01','9999-12-31');
$total_collected = $lifetimeFinancial['verified_revenue'];
$today_collected = financial_totals(date('Y-m-d'),date('Y-m-d'))['verified_revenue'];
$monthly_amt = financial_totals(date('Y-m-01'),date('Y-m-t'))['verified_revenue'];
$pending_count   = (int)$totals['pending_count'];

$rev_by_service = [];
$rs = $conn->query("
    SELECT s.name AS service, IFNULL(SUM(p.amount),0) AS total, COUNT(p.id) AS count
    FROM payments p
    JOIN applications a ON p.application_id = a.id
    JOIN services s ON a.service_id = s.id
    WHERE p.status='completed'
    GROUP BY s.name
    ORDER BY total DESC
    LIMIT 6
");
while ($r = $rs->fetch_assoc()) $rev_by_service[] = $r;
$max_svc = $rev_by_service ? max(array_column($rev_by_service, 'total')) : 1;
$colors = ['#C9A84C','#1B2A4A','#2A7A52','#C97A20','#7A2A3A','#2A52A4'];

$page_id    = 'finance';
$page_title = 'Financial Oversight';
$page_sub   = 'Finance';
include 'includes/layout.php';
?>

<style>
.toast { position:fixed;bottom:28px;right:28px;padding:12px 20px;border-radius:10px;font-size:.82rem;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:600;transform:translateY(80px);opacity:0;transition:.3s cubic-bezier(.4,0,.2,1);max-width:360px; }
.toast.show { transform:none;opacity:1; }
.toast-success { background:var(--green);color:white; }
.toast-error   { background:var(--wine);color:white; }
</style>
<div class="toast" id="toast"></div>

<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Financial Oversight</div>
    <h1 class="sec-title">Finance</h1>
    <p class="sec-sub">Consolidated financial overview and payment management across all parishes.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="reports.php?type=financial" class="btn-sm btn-outline">Export Report</a>
    <a href="reports.php?type=financial&period=monthly" class="btn-sm btn-navy">Monthly Report</a>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))"><div class="stat-card stat-green"><div class="stat-label">Net Revenue</div><div class="stat-value">?<?= number_format($lifetimeFinancial['net_revenue'],2) ?></div></div>
  <div class="stat-card stat-gold">
    <div class="stat-icon">₱</div>
    <div class="stat-label">Total Collected</div>
    <div class="stat-value">₱<?php echo number_format($total_collected); ?></div>
    <div class="stat-delta">All completed payments</div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:calendar]</div>
    <div class="stat-label">This Month</div>
    <div class="stat-value">₱<?php echo number_format($monthly_amt); ?></div>
    <div class="stat-delta"><?php echo date('F Y'); ?></div>
  </div>
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:sun]</div>
    <div class="stat-label">Today</div>
    <div class="stat-value">₱<?php echo number_format($today_collected); ?></div>
    <div class="stat-delta"><?php echo date('M j, Y'); ?></div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:clock]</div>
    <div class="stat-label">Pending</div>
    <div class="stat-value"><?php echo $pending_count; ?></div>
    <div class="stat-delta down">Awaiting verification</div>
  </div>
</div>

<?php require APP_ROOT . '/includes/financial_summary.php'; ?>
<div class="grid-2">
  <!-- Revenue by Service Chart -->
  <div class="card">
    <div class="card-head"><h3>Revenue by Service</h3><a href="reports.php?type=service_demand" class="card-tag" style="color:var(--gold)">View Report →</a></div>
    <div class="card-body">
      <div class="bar-list">
        <?php foreach($rev_by_service as $i => $r): $pct = round(($r['total']/$max_svc)*100); ?>
        <div class="bar-item">
          <div class="bar-top">
            <span class="bar-label"><?php echo htmlspecialchars($r['service']); ?> <span style="font-size:.7rem;color:var(--ink-30)">(<?php echo $r['count']; ?>)</span></span>
            <span class="bar-val">₱<?php echo number_format($r['total']); ?></span>
          </div>
          <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $colors[$i%6]; ?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php
  $trendEnd=new DateTimeImmutable($financialTo);
  $trendStart=$trendEnd->modify('first day of this month')->modify('-5 months');
  $monthly_trend=[];
  for($month=$trendStart;$month<=$trendEnd;$month=$month->modify('+1 month')) {
      $monthEnd=min($month->format('Y-m-t'),$financialTo);
      $monthly_trend[$month->format('M Y')]=financial_totals($month->format('Y-m-01'),$monthEnd)['verified_revenue'];
  }
  $max_m=max(1,...array_values($monthly_trend));
  ?>
  <!-- Monthly trend uses the same verified-income definition as the summary. -->
  <div class="card">
    <div class="card-head"><h3>Monthly Revenue Trend</h3><span class="card-tag"><?= h($trendStart->format('M Y').' – '.$trendEnd->format('M Y')) ?></span></div>
    <div class="card-body">
      <div style="display:flex;align-items:flex-end;gap:8px;height:130px;margin-bottom:14px">
        <?php foreach($monthly_trend as $m => $v): $h = round(($v/$max_m)*100); ?>
        <div style="flex:1;min-width:0;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:5px">
          <span style="font-size:.66rem;color:var(--ink-60);font-weight:500">₱<?php echo number_format($v); ?></span>
          <div style="width:100%;background:var(--navy);border-radius:5px 5px 0 0;height:<?php echo $h; ?>%;transition:height .6s ease;min-height:6px" title="<?php echo $m; ?>: ₱<?php echo number_format($v); ?>"></div>
          <span style="font-size:.64rem;color:var(--ink-30);text-transform:uppercase"><?php echo $m; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="border-top:1px solid var(--ink-10);padding-top:12px;display:flex;justify-content:space-between">
        <span style="font-size:.75rem;color:var(--ink-60)">6-month total: <strong>₱<?php echo number_format(array_sum($monthly_trend)); ?></strong></span>
        <span style="font-size:.75rem;color:var(--ink-60)">Verified income</span>
      </div>
    </div>
  </div>
</div>

<!-- Payments Table with Filters -->
<div class="card">
  <div class="card-head">
    <h3>Payment Transactions</h3>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="card-tag"><?php echo $total; ?> transactions</span>
      <a href="reports.php?type=financial" class="act-btn act-navy" style="font-size:.72rem">Export [icon:download]</a>
    </div>
  </div>

  <!-- Filters -->
  <div style="padding:12px 22px;border-bottom:1px solid var(--ink-10);background:#FAFAF8">
    <form method="GET" action="finance.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:6px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:var(--white);outline:none;cursor:pointer">
        <option value="">All Status</option>
        <option value="completed" <?php echo $status_filter==='completed'?'selected':''; ?>>Completed</option>
        <option value="pending"   <?php echo $status_filter==='pending'?'selected':''; ?>>Pending</option>
        <option value="refunded"  <?php echo $status_filter==='refunded'?'selected':''; ?>>Refunded</option>
      </select>
      <select name="method" onchange="this.form.submit()" style="font-size:.8rem;padding:6px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:var(--white);outline:none;cursor:pointer">
        <option value="">All Methods</option>
        <option value="GCash" <?php echo $method_filter==='GCash'?'selected':''; ?>>GCash</option>
        <option value="Cash"  <?php echo $method_filter==='Cash'?'selected':''; ?>>Cash</option>
      </select>
      <select name="parish" onchange="this.form.submit()" style="font-size:.8rem;padding:6px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:var(--white);outline:none;cursor:pointer">
        <option value="">All Parishes</option>
        <?php foreach($parishes_list as $pl): ?>
        <option value="<?php echo $pl; ?>" <?php echo $parish_filter===$pl?'selected':''; ?>><?php echo $pl; ?></option>
        <?php endforeach; ?>
      </select>
      <?php if($status_filter||$method_filter||$parish_filter): ?>
      <a href="finance.php" style="font-size:.75rem;color:var(--wine);padding:4px 10px;border:1px solid var(--wine-dim);border-radius:20px">[icon:close] Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Ref #</th><th>Parishioner</th><th>Service</th><th>Parish</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if(empty($paged)): ?>
          <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No transactions match the current filter.</td></tr>
          <?php endif; ?>
          <?php foreach($paged as $p):
            $pst = $p['status'];
            $ppill = match($pst) { 'completed'=>'pill-green','refunded'=>'pill-navy', default=>'pill-amber' };
          ?>
          <tr id="pay-row-<?php echo $p['id']; ?>">
            <td style="font-family:monospace;font-size:.72rem;color:var(--ink-30)">#<?php echo $p['id']; ?></td>
            <td style="font-weight:500"><?php echo htmlspecialchars($p['name']); ?></td>
            <td><?php echo htmlspecialchars($p['service']); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($p['parish']); ?></td>
            <td style="font-weight:700;color:var(--navy)">₱<?php echo number_format($p['amount'],2); ?></td>
            <td>
              <?php if($p['method']==='GCash'): ?>
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem"><span style="color:#007BFF">●</span> GCash</span>
              <?php elseif($p['method']==='Cash'): ?>
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem"><span style="color:var(--green)">●</span> Cash</span>
              <?php else: ?><span style="font-size:.75rem;color:var(--ink-30)">—</span><?php endif; ?>
            </td>
            <td><span class="pill <?php echo $ppill; ?>" id="pay-status-<?php echo $p['id']; ?>"><?php echo ucfirst($pst); ?></span></td>
            <td style="font-size:.75rem;color:var(--ink-30)"><?php echo date('M j, Y', strtotime($p['date'])); ?></td>
            <td>
              <?php if($pst === 'pending'): ?>
              <?php elseif($pst === 'completed'): ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:rgba(201,168,76,.05)">
            <td colspan="4" style="padding:12px 14px;font-size:.78rem;font-weight:600;color:var(--ink-60);text-align:right">
              Showing <?php echo count($paged); ?> transactions:
            </td>
            <td style="padding:12px 14px;font-weight:700;color:var(--navy)">
              ₱<?php echo number_format(array_sum(array_map(fn($p)=>$p['status']==='completed'?$p['amount']:0,$paged)),2); ?>
            </td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10)">
    <span style="font-size:.78rem;color:var(--ink-30)">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?></span>
    <div style="display:flex;gap:4px">
      <?php $bq = http_build_query(['status'=>$status_filter,'method'=>$method_filter,'parish'=>$parish_filter]);
      if($page_num > 1): ?><a href="?<?php echo $bq; ?>&page=<?php echo $page_num-1; ?>" class="act-btn act-navy">← Prev</a><?php endif;
      for($p = max(1,$page_num-2); $p <= min($total_pages,$page_num+2); $p++):
        $as = $p===$page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?><a href="?<?php echo $bq; ?>&page=<?php echo $p; ?>" class="act-btn" style="<?php echo $as; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $p; ?></a><?php endfor;
      if($page_num < $total_pages): ?><a href="?<?php echo $bq; ?>&page=<?php echo $page_num+1; ?>" class="act-btn act-navy">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = `toast toast-${type} show`;
    setTimeout(() => t.classList.remove('show'), 3800);
}

function verifyPayment(id) {
    const fd = new FormData(); fd.append('id', id);
    fetch('finance.php?ajax=verify_payment', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById(`pay-status-${id}`);
                if (badge) { badge.className = 'pill pill-green'; badge.textContent = 'Completed'; }
                const row = document.getElementById(`pay-row-${id}`);
                if (row) { const lastTd = row.cells[row.cells.length-1]; lastTd.innerHTML = '<span style="font-size:.72rem;color:var(--ink-30)">Verified [icon:check]</span>'; }
                showToast(`[icon:check] ${data.message}`, 'success');
            } else showToast(`[icon:close] ${data.message}`, 'error');
        });
}


</script>

<?php include 'includes/layout_footer.php'; ?>
