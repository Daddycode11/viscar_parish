<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

// NOTE: this line sends normal page loads to accounting_report.php, so the UI below
// is only shown if you remove it. Kept as-is to avoid changing your routing.
if (!isset($_GET['ajax']) || isset($_GET['document_type'])) { require __DIR__ . '/accounting_report.php'; exit; }

$page_id = 'export'; $page_title = 'Export Reports'; $page_sub = 'Finance';
require_once __DIR__ . '/includes/layout.php';

// Bookkeeper only
if ($user['role'] !== 'bookkeeper') {
    if (!headers_sent()) {
        header('Location: dashboard.php');
    } else {
        echo '<script>location.href="dashboard.php"</script>';
    }
    exit;
}

if (!isset($_GET['ajax'])) {
    echo '<p><a class="btn-sm btn-navy" href="accounting_report.php">' . h(t('Accounting')) . ' &rsaquo; ' . h(t('Export Reports')) . '</a></p>';
}

// ── Helpers ────────────────────────────────────────────────────
function export_is_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

/** Transaction list shared by preview, CSV and print. */
function export_transactions($conn, $scopeParish, $start, $end, $statusSql, $limit = 0) {
    $scope = (int)$scopeParish;
    $sql = "
        SELECT p.id, p.receipt_number, u.name AS parishioner, COALESCE(s.name,'—') AS service_name,
               COALESCE(pa.name,'—') AS parish_name,
               p.amount, p.payment_method AS method, p.status, p.created_at
        FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scope})) p
        JOIN (SELECT * FROM applications WHERE parish_id = {$scope}) a ON p.application_id=a.id
        JOIN (SELECT * FROM users WHERE parish_id = {$scope} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scope})) u ON a.user_id=u.id
        LEFT JOIN services s ON a.service_id=s.id
        LEFT JOIN parishes pa ON a.parish_id=pa.id
        WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
        {$statusSql}
        ORDER BY p.created_at DESC";
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Summary cards shared by preview and print. */
function export_summary($conn, $scopeParish, $start, $end, $user) {
    $scope = (int)$scopeParish;
    $stmt = $conn->prepare("
        SELECT
            COUNT(p.id) AS total_transactions,
            COALESCE(AVG(CASE WHEN p.status IN ('completed','refunded') THEN p.amount END),0) AS avg_transaction
        FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scope})) p
        JOIN (SELECT * FROM applications WHERE parish_id = {$scope}) a ON p.application_id=a.id
        WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
    ");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();

    require_once APP_ROOT . '/includes/financial_totals.php';
    try {
        $financial = financial_totals($start, $end, (int)$user['parish_id']);
    } catch (DomainException $e) {
        fail_request($e->getMessage(), 422);
    }
    $summary['total_revenue'] = $financial['verified_revenue'];
    $summary['total_refunds'] = $financial['refunds'];
    $summary['net_revenue']   = $financial['net_revenue'];
    return $summary;
}

// ── AJAX Handlers ──────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $start = $_POST['start_date'] ?? date('Y-m-d');
    $end   = $_POST['end_date']   ?? date('Y-m-d');
    if (!export_is_date($start) || !export_is_date($end)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid date range']);
        exit;
    }
    if ($start > $end) { list($start, $end) = array($end, $start); } // swap if reversed

    $status    = $_POST['status'] ?? 'all';
    $statusSql = in_array($status, ['completed','pending','refunded'], true) ? " AND p.status = '{$status}'" : '';
    $scope     = (int)$scopeParish;

    // ── Preview ─────────────────────────────────────────────
    if ($_GET['ajax'] === 'preview') {
        $summary = export_summary($conn, $scopeParish, $start, $end, $user);

        // Net revenue by service (completed only = verified minus refunded, matches net_revenue)
        $stmt = $conn->prepare("
            SELECT COALESCE(s.name,'Unknown') AS service_name,
                   COUNT(p.id) AS tx_count,
                   SUM(CASE WHEN p.status='completed' THEN p.amount ELSE 0 END) AS total
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scope})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scope}) a ON p.application_id=a.id
            LEFT JOIN services s ON a.service_id=s.id
            WHERE p.status IN ('completed','refunded') AND DATE(COALESCE(p.verified_at,p.paid_at)) BETWEEN ? AND ?
            GROUP BY s.id ORDER BY total DESC
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $by_service = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Net revenue by method
        $stmt = $conn->prepare("
            SELECT p.payment_method AS method,
                   COUNT(p.id) AS tx_count,
                   SUM(CASE WHEN p.status='completed' THEN p.amount ELSE 0 END) AS total
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scope})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scope}) a ON p.application_id=a.id
            WHERE p.status IN ('completed','refunded') AND DATE(COALESCE(p.verified_at,p.paid_at)) BETWEEN ? AND ?
            GROUP BY p.payment_method ORDER BY total DESC
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $by_method = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $transactions = export_transactions($conn, $scopeParish, $start, $end, $statusSql, 500);

        echo json_encode(['ok'=>true,'summary'=>$summary,'by_service'=>$by_service,'by_method'=>$by_method,'transactions'=>$transactions]);
        exit;
    }

    // ── CSV Export ───────────────────────────────────────────
    if ($_GET['ajax'] === 'export_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="financial_report_'.$start.'_to_'.$end.'.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Receipt #','Parishioner','Service','Parish','Amount','Method','Status','Date']);
        foreach (export_transactions($conn, $scopeParish, $start, $end, $statusSql) as $row) {
            fputcsv($out, [
                $row['receipt_number'] ?? '—',
                $row['parishioner'],
                $row['service_name'],
                $row['parish_name'],
                number_format($row['amount'], 2),
                ucfirst($row['method']),
                ucfirst($row['status']),
                $row['created_at']
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Print Report ────────────────────────────────────────
    if ($_GET['ajax'] === 'print_report') {
        $summary = export_summary($conn, $scopeParish, $start, $end, $user);
        $rows    = export_transactions($conn, $scopeParish, $start, $end, $statusSql);
        $statusLabel = $statusSql ? ucfirst($status) : 'All statuses';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Financial Report</title>';
        $html .= '<style>body{font-family:"DM Sans",Arial,sans-serif;padding:40px;color:#1A1510;max-width:1000px;margin:0 auto}';
        $html .= 'h1{font-family:"Cormorant Garamond",Georgia,serif;color:#43658b;font-size:1.8rem;margin-bottom:4px}';
        $html .= '.sub{color:#888;font-size:.85rem;margin-bottom:24px}';
        $html .= '.summary{display:flex;gap:20px;margin-bottom:28px;flex-wrap:wrap}';
        $html .= '.s-box{flex:1;min-width:150px;background:#f8f7f4;border:1px solid #e0ddd6;border-radius:8px;padding:16px;text-align:center}';
        $html .= '.s-box .val{font-size:1.4rem;font-weight:600;color:#43658b}.s-box .lbl{font-size:.72rem;color:#888;text-transform:uppercase;letter-spacing:.08em;margin-top:4px}';
        $html .= 'table{width:100%;border-collapse:collapse;margin-top:16px}';
        $html .= 'th{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#888;padding:8px 10px;text-align:left;border-bottom:2px solid #43658b}';
        $html .= 'td{padding:8px 10px;font-size:.82rem;border-bottom:1px solid #eee}';
        $html .= '.completed{color:#43658b}.pending{color:#C97A20}.refunded{color:#7A2A3A}';
        $html .= '@media print{body{padding:20px}}</style></head><body>';
        $html .= '<h1>Apostolic Vicariate of San Jose</h1>';
        $html .= '<div class="sub">Financial Report: ' . htmlspecialchars($start) . ' to ' . htmlspecialchars($end)
               . ' | ' . htmlspecialchars($statusLabel) . ' | Generated: ' . date('M d, Y g:i A') . '</div>';

        $html .= '<div class="summary">';
        $html .= '<div class="s-box"><div class="val">P ' . number_format($summary['total_revenue'], 2) . '</div><div class="lbl">Total Revenue</div></div>';
        $html .= '<div class="s-box"><div class="val">' . (int)$summary['total_transactions'] . '</div><div class="lbl">Transactions</div></div>';
        $html .= '<div class="s-box"><div class="val">P ' . number_format($summary['avg_transaction'], 2) . '</div><div class="lbl">Avg Transaction</div></div>';
        $html .= '<div class="s-box"><div class="val">P ' . number_format($summary['total_refunds'], 2) . '</div><div class="lbl">Refunds</div></div>';
        $html .= '</div>';

        $html .= '<table><thead><tr><th>Receipt #</th><th>Parishioner</th><th>Service</th><th>Parish</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $cls = $r['status'] === 'completed' ? 'completed' : ($r['status'] === 'refunded' ? 'refunded' : 'pending');
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($r['receipt_number'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['parishioner']) . '</td>';
            $html .= '<td>' . htmlspecialchars($r['service_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($r['parish_name']) . '</td>';
            $html .= '<td>P ' . number_format($r['amount'], 2) . '</td>';
            $html .= '<td>' . htmlspecialchars(ucfirst($r['method'])) . '</td>';
            $html .= '<td class="'.$cls.'">' . htmlspecialchars(ucfirst($r['status'])) . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($r['created_at'])) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<script>window.onload=function(){window.print();}</script>';
        $html .= '</body></html>';

        header('Content-Type: text/html');
        echo str_replace('<body>', '<body>' . navigation_controls('staff/export.php'), $html);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Invalid action']);
    exit;
}
?>

<!-- Header -->
<div class="sec-head">
  <div>
    <div class="sec-tag">Finance</div>
    <h2 class="sec-title">Export Reports</h2>
    <p class="sec-sub">Generate and export financial reports</p>
  </div>
</div>

<!-- Report Type Cards -->
<div class="stats-grid report-types" role="group" aria-label="Report type">
  <button type="button" class="stat-card stat-navy report-type active" data-type="daily" aria-pressed="true">
    <div class="stat-icon">[icon:calendar]</div><div class="stat-label">Daily Report</div><div class="stat-value">Today</div>
  </button>
  <button type="button" class="stat-card stat-gold report-type" data-type="weekly" aria-pressed="false">
    <div class="stat-icon">[icon:calendar]</div><div class="stat-label">Weekly Report</div><div class="stat-value">This Week</div>
  </button>
  <button type="button" class="stat-card stat-green report-type" data-type="monthly" aria-pressed="false">
    <div class="stat-icon">[icon:chart]</div><div class="stat-label">Monthly Report</div><div class="stat-value">This Month</div>
  </button>
  <button type="button" class="stat-card stat-wine report-type" data-type="annual" aria-pressed="false">
    <div class="stat-icon">[icon:chart]</div><div class="stat-label">Annual Report</div><div class="stat-value">This Year</div>
  </button>
  <button type="button" class="stat-card stat-amber report-type" data-type="custom" aria-pressed="false">
    <div class="stat-icon">[icon:edit]</div><div class="stat-label">Custom Period</div><div class="stat-value">Pick Dates</div>
  </button>
</div>

<style>
.report-types{grid-template-columns:repeat(5,1fr);margin-bottom:24px}
.report-type{border:2px solid transparent;text-align:left;font:inherit;width:100%;cursor:pointer;
  transition:border-color var(--ease),transform var(--ease),box-shadow var(--ease)}
.report-type .stat-value{font-size:1rem}
.report-type:hover{transform:translateY(-2px)}
.report-type:focus-visible{outline:2px solid var(--gold);outline-offset:2px}
.report-type.active{border-color:var(--gold);transform:translateY(-3px);box-shadow:var(--sh-md)}
.export-actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
.export-actions .btn-sm{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;cursor:pointer}
.export-actions .btn-sm:disabled{opacity:.55;cursor:not-allowed}
.filter-error{color:var(--wine,#7A2A3A);font-size:.78rem;margin-top:10px;display:none}
@media(max-width:900px){.report-types{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.report-types{grid-template-columns:repeat(2,1fr)}.export-actions .btn-sm{flex:1 1 100%;justify-content:center}}
@media(max-width:480px){.report-types{grid-template-columns:1fr}}
</style>

<!-- Date Range, Filters & Actions -->
<div class="card">
  <div class="card-head">
    <h3>Date Range &amp; Export</h3>
    <span class="card-tag" id="rangeLabel">Daily</span>
  </div>
  <div class="card-body">
    <div class="form-grid" style="align-items:flex-end">
      <div class="form-group" style="margin-bottom:0">
        <label for="startDate">Start Date</label>
        <input type="date" id="startDate" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label for="endDate">End Date</label>
        <input type="date" id="endDate" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label for="statusFilter">Status</label>
        <select id="statusFilter">
          <option value="all">All statuses</option>
          <option value="completed">Completed</option>
          <option value="pending">Pending</option>
          <option value="refunded">Refunded</option>
        </select>
      </div>
    </div>
    <div class="filter-error" id="filterError"></div>
    <div class="export-actions">
      <button type="button" class="btn-sm btn-navy"  id="btnPreview">[icon:search] Generate Preview</button>
      <button type="button" class="btn-sm btn-gold"  id="btnPrint">[icon:print] Print Report</button>
      <button type="button" class="btn-sm btn-green" id="btnCsv">[icon:save] Download CSV</button>
    </div>
  </div>
</div>

<!-- Report Preview -->
<div id="reportPreview" style="display:none">
  <div class="stats-grid" id="summaryCards"></div>

  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h3>Net Revenue by Service</h3><span class="card-tag">Breakdown</span></div>
      <div class="card-body">
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Service</th><th>Transactions</th><th>Net Total</th></tr></thead>
            <tbody id="byServiceBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Net Revenue by Payment Method</h3><span class="card-tag">Breakdown</span></div>
      <div class="card-body">
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Method</th><th>Transactions</th><th>Net Total</th></tr></thead>
            <tbody id="byMethodBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Transaction List</h3><span class="card-tag" id="txCount">0 records</span></div>
    <div class="card-body">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Receipt #</th><th>Parishioner</th><th>Service</th><th>Parish</th>
              <th>Amount</th><th>Method</th><th>Status</th><th>Date</th>
            </tr>
          </thead>
          <tbody id="txBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Empty state -->
<div id="emptyState" class="card">
  <div class="card-body" style="text-align:center;padding:50px 20px;color:var(--ink-30)">
    <div style="font-size:2.5rem;margin-bottom:12px">[icon:chart]</div>
    <p style="font-size:.85rem;margin-bottom:8px">Select a report type and click <strong>Generate Preview</strong> to view the report.</p>
    <p style="font-size:.78rem">You can then print or export to CSV.</p>
  </div>
</div>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<script>
var startEl    = document.getElementById('startDate');
var endEl      = document.getElementById('endDate');
var statusEl   = document.getElementById('statusFilter');
var rangeLabel = document.getElementById('rangeLabel');
var errEl      = document.getElementById('filterError');
var LABELS = {daily:'Daily', weekly:'Weekly', monthly:'Monthly', annual:'Annual', custom:'Custom'};

function fmt(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
function pad(n) { return n < 10 ? '0'+n : ''+n; }
function num(v) { return parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function cap(s) { s = String(s||''); return s.charAt(0).toUpperCase() + s.slice(1); }
function esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s == null ? '' : s));
  return d.innerHTML;
}
function pillClass(status) {
  switch (status) {
    case 'completed': return 'pill-green';
    case 'pending':   return 'pill-amber';
    case 'refunded':  return 'pill-wine';
    default:          return 'pill-navy';
  }
}

function setActive(type) {
  document.querySelectorAll('.report-type').forEach(function(c){
    var on = c.getAttribute('data-type') === type;
    c.classList.toggle('active', on);
    c.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  rangeLabel.textContent = LABELS[type];
}

function rangeFor(type) {
  var t = new Date(), s = fmt(t), e = fmt(t);
  if (type === 'weekly') {
    var d = t.getDay(), mon = new Date(t);
    mon.setDate(t.getDate() - (d === 0 ? 6 : d - 1));
    s = fmt(mon);
  } else if (type === 'monthly') {
    s = t.getFullYear() + '-' + pad(t.getMonth()+1) + '-01';
  } else if (type === 'annual') {
    s = t.getFullYear() + '-01-01';
  }
  return [s, e];
}

function selectReportType(el) {
  var type = el.getAttribute('data-type');
  setActive(type);
  if (type === 'custom') { startEl.focus(); return; }
  var r = rangeFor(type);
  startEl.value = r[0];
  endEl.value = r[1];
  loadPreview(); // presets load instantly
}

function validRange() {
  var msg = '';
  if (!startEl.value || !endEl.value) msg = 'Please choose both a start and end date.';
  else if (startEl.value > endEl.value) msg = 'Start date must be on or before the end date.';
  errEl.textContent = msg;
  errEl.style.display = msg ? 'block' : 'none';
  return !msg;
}

function setBusy(b) {
  ['btnPreview','btnPrint','btnCsv'].forEach(function(id){ document.getElementById(id).disabled = b; });
}

function formData() {
  var fd = new FormData();
  fd.append('start_date', startEl.value);
  fd.append('end_date', endEl.value);
  fd.append('status', statusEl.value);
  return fd;
}

function postToTab(action) {
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = 'export.php?ajax=' + action;
  form.target = '_blank';
  [['start_date', startEl.value], ['end_date', endEl.value], ['status', statusEl.value]].forEach(function(p){
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = p[0]; i.value = p[1];
    form.appendChild(i);
  });
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
}

function exportCSV()   { if (!validRange()) return; postToTab('export_csv');   showToast('CSV download started','success'); }
function printReport() { if (!validRange()) return; postToTab('print_report'); }

function loadPreview() {
  if (!validRange()) return;
  setLoading(true); setBusy(true);

  fetch('export.php?ajax=preview', {method:'POST', body: formData()})
    .then(function(r){ return r.json(); })
    .then(function(d){
      setLoading(false); setBusy(false);
      if (!d.ok) { showToast(d.error || 'Error loading report', 'error'); return; }

      document.getElementById('emptyState').style.display = 'none';
      document.getElementById('reportPreview').style.display = 'block';

      var s = d.summary;
      document.getElementById('summaryCards').innerHTML =
        '<div class="stat-card stat-green"><div class="stat-icon">₱</div><div class="stat-label">Total Revenue</div><div class="stat-value">'+num(s.total_revenue)+'</div></div>' +
        '<div class="stat-card stat-navy"><div class="stat-icon">[icon:wallet]</div><div class="stat-label">Total Transactions</div><div class="stat-value">'+parseInt(s.total_transactions,10)+'</div></div>' +
        '<div class="stat-card stat-gold"><div class="stat-icon">[icon:chart]</div><div class="stat-label">Avg Transaction</div><div class="stat-value">'+num(s.avg_transaction)+'</div></div>' +
        '<div class="stat-card stat-wine"><div class="stat-icon">[icon:refresh]</div><div class="stat-label">Refunds</div><div class="stat-value">'+num(s.total_refunds)+'</div></div>';

      var svcHtml = '';
      d.by_service.forEach(function(r){
        svcHtml += '<tr><td>'+esc(r.service_name)+'</td><td>'+r.tx_count+'</td><td>P '+num(r.total)+'</td></tr>';
      });
      document.getElementById('byServiceBody').innerHTML = svcHtml || '<tr><td colspan="3" style="text-align:center;color:var(--ink-30)">No data</td></tr>';

      var mthHtml = '';
      d.by_method.forEach(function(r){
        mthHtml += '<tr><td>'+esc(cap(r.method))+'</td><td>'+r.tx_count+'</td><td>P '+num(r.total)+'</td></tr>';
      });
      document.getElementById('byMethodBody').innerHTML = mthHtml || '<tr><td colspan="3" style="text-align:center;color:var(--ink-30)">No data</td></tr>';

      var txHtml = '';
      d.transactions.forEach(function(r){
        txHtml += '<tr>' +
          '<td>'+ esc(r.receipt_number || '—') +'</td>' +
          '<td>'+ esc(r.parishioner) +'</td>' +
          '<td>'+ esc(r.service_name) +'</td>' +
          '<td>'+ esc(r.parish_name) +'</td>' +
          '<td>P '+ num(r.amount) +'</td>' +
          '<td>'+ esc(cap(r.method)) +'</td>' +
          '<td><span class="pill '+pillClass(r.status)+'">'+ esc(cap(r.status)) +'</span></td>' +
          '<td>'+ new Date(String(r.created_at).replace(' ','T')).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) +'</td>' +
          '</tr>';
      });
      document.getElementById('txBody').innerHTML = txHtml || '<tr><td colspan="8" style="text-align:center;color:var(--ink-30)">No transactions found</td></tr>';
      document.getElementById('txCount').textContent = d.transactions.length + ' records';

      showToast('Report generated successfully','success');
    })
    .catch(function(){
      setLoading(false); setBusy(false);
      showToast('Failed to load report','error');
    });
}

// ── Events ──
document.querySelectorAll('.report-type').forEach(function(c){
  c.addEventListener('click', function(){ selectReportType(c); });
});
[startEl, endEl].forEach(function(i){
  i.addEventListener('change', function(){ setActive('custom'); validRange(); });
});
document.getElementById('btnPreview').addEventListener('click', loadPreview);
document.getElementById('btnPrint').addEventListener('click', printReport);
document.getElementById('btnCsv').addEventListener('click', exportCSV);
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>