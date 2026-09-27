<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

if (!isset($_GET['ajax']) || isset($_GET['document_type'])) { require __DIR__ . '/accounting_report.php'; exit; }
$page_id = 'export'; $page_title = 'Export Reports'; $page_sub = 'Finance';
require_once __DIR__ . '/includes/layout.php';
if (!isset($_GET['ajax'])) echo '<p><a class="btn-sm btn-navy" href="accounting_report.php">' . h(t('Accounting')) . ' ? ' . h(t('Export Reports')) . '</a></p>';

// Bookkeeper only
if ($user['role'] !== 'bookkeeper') {
    header('Location: dashboard.php');
    exit;
}

// ── AJAX Handlers ──────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $start = $_POST['start_date'] ?? date('Y-m-d');
    $end   = $_POST['end_date']   ?? date('Y-m-d');

    // ── Preview ─────────────────────────────────────────────
    if ($_GET['ajax'] === 'preview') {
        // Summary
        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN p.status IN ('completed','refunded') THEN p.amount ELSE 0 END),0) AS total_revenue,
                COUNT(p.id) AS total_transactions,
                COALESCE(AVG(CASE WHEN p.status IN ('completed','refunded') THEN p.amount END),0) AS avg_transaction,
                COALESCE(SUM(CASE WHEN p.status='refunded' THEN p.amount ELSE 0 END),0) AS total_refunds
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        require_once APP_ROOT.'/includes/financial_totals.php';
        try{$financial=financial_totals($start,$end,(int)$user['parish_id']);}catch(DomainException $e){fail_request($e->getMessage(),422);}
        $summary['total_revenue']=$financial['verified_revenue'];$summary['total_refunds']=$financial['refunds'];$summary['net_revenue']=$financial['net_revenue'];

        // Revenue by service
        $stmt = $conn->prepare("
            SELECT COALESCE(s.name,'Unknown') AS service_name,
                   COUNT(p.id) AS tx_count,
                   SUM(p.amount) AS total
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            LEFT JOIN services s ON a.service_id=s.id
            WHERE p.status IN ('completed','refunded') AND DATE(COALESCE(p.verified_at,p.paid_at)) BETWEEN ? AND ?
            GROUP BY s.id ORDER BY total DESC
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $by_service = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Revenue by method
        $stmt = $conn->prepare("
            SELECT p.payment_method AS method,
                   COUNT(p.id) AS tx_count,
                   SUM(p.amount) AS total
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            WHERE p.status IN ('completed','refunded') AND DATE(COALESCE(p.verified_at,p.paid_at)) BETWEEN ? AND ?
            GROUP BY p.payment_method ORDER BY total DESC
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $by_method = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Transaction list
        $stmt = $conn->prepare("
            SELECT p.id, p.receipt_number, u.name AS parishioner, COALESCE(s.name,'—') AS service_name,
                   p.amount, p.payment_method AS method, p.status, p.created_at,
                   COALESCE(pa.name,'—') AS parish_name
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id=u.id
            LEFT JOIN services s ON a.service_id=s.id
            LEFT JOIN parishes pa ON a.parish_id=pa.id
            WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
            ORDER BY p.created_at DESC
            LIMIT 500
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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

        $stmt = $conn->prepare("
            SELECT p.receipt_number, u.name AS parishioner, COALESCE(s.name,'—') AS service_name,
                   COALESCE(pa.name,'—') AS parish_name,
                   p.amount, p.payment_method AS method, p.status, p.created_at
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id=u.id
            LEFT JOIN services s ON a.service_id=s.id
            LEFT JOIN parishes pa ON a.parish_id=pa.id
            WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
            ORDER BY p.created_at DESC
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            fputcsv($out, [
                $row['receipt_number'] ?? '—',
                $row['parishioner'],
                $row['service_name'],
                $row['parish_name'],
                number_format($row['amount'],2),
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
        // Summary
        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN p.status IN ('completed','refunded') THEN p.amount ELSE 0 END),0) AS total_revenue,
                COUNT(p.id) AS total_transactions,
                COALESCE(AVG(CASE WHEN p.status IN ('completed','refunded') THEN p.amount END),0) AS avg_transaction,
                COALESCE(SUM(CASE WHEN p.status='refunded' THEN p.amount ELSE 0 END),0) AS total_refunds
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        require_once APP_ROOT.'/includes/financial_totals.php';
        try{$financial=financial_totals($start,$end,(int)$user['parish_id']);}catch(DomainException $e){fail_request($e->getMessage(),422);}
        $summary['total_revenue']=$financial['verified_revenue'];$summary['total_refunds']=$financial['refunds'];$summary['net_revenue']=$financial['net_revenue'];

        // Transactions
        $stmt = $conn->prepare("
            SELECT p.receipt_number, u.name AS parishioner, COALESCE(s.name,'—') AS service_name,
                   COALESCE(pa.name,'—') AS parish_name,
                   p.amount, p.payment_method AS method, p.status, p.created_at
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id=a.id
            JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id=u.id
            LEFT JOIN services s ON a.service_id=s.id
            LEFT JOIN parishes pa ON a.parish_id=pa.id
            WHERE DATE(CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ?
            ORDER BY p.created_at DESC
        ");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Financial Report</title>';
        $html .= '<style>body{font-family:"DM Sans",Arial,sans-serif;padding:40px;color:#1A1510;max-width:1000px;margin:0 auto}';
        $html .= 'h1{font-family:"Cormorant Garamond",Georgia,serif;color:#1B2A4A;font-size:1.8rem;margin-bottom:4px}';
        $html .= '.sub{color:#888;font-size:.85rem;margin-bottom:24px}';
        $html .= '.summary{display:flex;gap:20px;margin-bottom:28px;flex-wrap:wrap}';
        $html .= '.s-box{flex:1;min-width:150px;background:#f8f7f4;border:1px solid #e0ddd6;border-radius:8px;padding:16px;text-align:center}';
        $html .= '.s-box .val{font-size:1.4rem;font-weight:600;color:#1B2A4A}.s-box .lbl{font-size:.72rem;color:#888;text-transform:uppercase;letter-spacing:.08em;margin-top:4px}';
        $html .= 'table{width:100%;border-collapse:collapse;margin-top:16px}';
        $html .= 'th{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#888;padding:8px 10px;text-align:left;border-bottom:2px solid #1B2A4A}';
        $html .= 'td{padding:8px 10px;font-size:.82rem;border-bottom:1px solid #eee}';
        $html .= '.completed{color:#2A7A52}.pending{color:#C97A20}.refunded{color:#7A2A3A}';
        $html .= '@media print{body{padding:20px}}</style></head><body>';
        $html .= '<h1>Apostolic Vicariate of San Jose</h1>';
        $html .= '<div class="sub">Financial Report: ' . htmlspecialchars($start) . ' to ' . htmlspecialchars($end) . ' | Generated: ' . date('M d, Y g:i A') . '</div>';

        $html .= '<div class="summary">';
        $html .= '<div class="s-box"><div class="val">P ' . number_format($summary['total_revenue'],2) . '</div><div class="lbl">Total Revenue</div></div>';
        $html .= '<div class="s-box"><div class="val">' . (int)$summary['total_transactions'] . '</div><div class="lbl">Transactions</div></div>';
        $html .= '<div class="s-box"><div class="val">P ' . number_format($summary['avg_transaction'],2) . '</div><div class="lbl">Avg Transaction</div></div>';
        $html .= '<div class="s-box"><div class="val">P ' . number_format($summary['total_refunds'],2) . '</div><div class="lbl">Refunds</div></div>';
        $html .= '</div>';

        $html .= '<table><thead><tr><th>Receipt #</th><th>Parishioner</th><th>Service</th><th>Parish</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $cls = $r['status'] === 'completed' ? 'completed' : ($r['status'] === 'refunded' ? 'refunded' : 'pending');
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($r['receipt_number'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['parishioner']) . '</td>';
            $html .= '<td>' . htmlspecialchars($r['service_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($r['parish_name']) . '</td>';
            $html .= '<td>P ' . number_format($r['amount'],2) . '</td>';
            $html .= '<td>' . ucfirst($r['method']) . '</td>';
            $html .= '<td class="'.$cls.'">' . ucfirst($r['status']) . '</td>';
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

<!-- Report Type Selection -->
<div class="sec-head">
  <div>
    <div class="sec-tag">Finance</div>
    <h2 class="sec-title">Export Reports</h2>
    <p class="sec-sub">Generate and export financial reports</p>
  </div>
</div>

<!-- Report Type Cards -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:24px">
  <div class="stat-card stat-navy report-type active" data-type="daily" onclick="selectReportType(this)" style="cursor:pointer">
    <div class="stat-icon">[icon:calendar]</div>
    <div class="stat-label">Daily Report</div>
    <div class="stat-value" style="font-size:1rem">Today</div>
  </div>
  <div class="stat-card stat-gold report-type" data-type="weekly" onclick="selectReportType(this)" style="cursor:pointer">
    <div class="stat-icon">[icon:calendar]</div>
    <div class="stat-label">Weekly Report</div>
    <div class="stat-value" style="font-size:1rem">This Week</div>
  </div>
  <div class="stat-card stat-green report-type" data-type="monthly" onclick="selectReportType(this)" style="cursor:pointer">
    <div class="stat-icon">[icon:chart]</div>
    <div class="stat-label">Monthly Report</div>
    <div class="stat-value" style="font-size:1rem">This Month</div>
  </div>
  <div class="stat-card stat-wine report-type" data-type="annual" onclick="selectReportType(this)" style="cursor:pointer">
    <div class="stat-icon">[icon:chart]</div>
    <div class="stat-label">Annual Report</div>
    <div class="stat-value" style="font-size:1rem">This Year</div>
  </div>
  <div class="stat-card stat-amber report-type" data-type="custom" onclick="selectReportType(this)" style="cursor:pointer">
    <div class="stat-icon">[icon:edit]</div>
    <div class="stat-label">Custom Period</div>
    <div class="stat-value" style="font-size:1rem">Pick Dates</div>
  </div>
</div>

<style>
.report-type{border:2px solid transparent;transition:border-color var(--ease),transform var(--ease)}
.report-type.active{border-color:var(--gold);transform:translateY(-3px);box-shadow:var(--sh-md)}
@media(max-width:768px){.stats-grid[style*="grid-template-columns:repeat(5"]{grid-template-columns:repeat(2,1fr)!important}}
@media(max-width:480px){.stats-grid[style*="grid-template-columns:repeat(5"]{grid-template-columns:1fr!important}}
</style>

<!-- Date Range & Actions -->
<div class="card">
  <div class="card-head">
    <h3>Date Range & Export</h3>
    <span class="card-tag" id="rangeLabel">Daily</span>
  </div>
  <div class="card-body">
    <div class="form-grid" style="align-items:flex-end">
      <div class="form-group" style="margin-bottom:0">
        <label>Start Date</label>
        <input type="date" id="startDate" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label>End Date</label>
        <input type="date" id="endDate" value="<?php echo date('Y-m-d'); ?>">
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
      <button class="btn-sm btn-navy" onclick="loadPreview()">[icon:search] Generate Preview</button>
      <button class="btn-sm btn-gold" onclick="printReport()">[icon:print] Print Report</button>
      <button class="btn-sm btn-green" onclick="exportCSV()">[icon:save] Download CSV</button>
    </div>
  </div>
</div>

<!-- Report Preview -->
<div id="reportPreview" style="display:none">

  <!-- Summary Cards -->
  <div class="stats-grid" id="summaryCards"></div>

  <!-- Revenue by Service -->
  <div class="grid-2">
    <div class="card">
      <div class="card-head">
        <h3>Revenue by Service</h3>
        <span class="card-tag">Breakdown</span>
      </div>
      <div class="card-body">
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Service</th><th>Transactions</th><th>Total</th></tr></thead>
            <tbody id="byServiceBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Revenue by Method -->
    <div class="card">
      <div class="card-head">
        <h3>Revenue by Payment Method</h3>
        <span class="card-tag">Breakdown</span>
      </div>
      <div class="card-body">
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Method</th><th>Transactions</th><th>Total</th></tr></thead>
            <tbody id="byMethodBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Transaction List -->
  <div class="card">
    <div class="card-head">
      <h3>Transaction List</h3>
      <span class="card-tag" id="txCount">0 records</span>
    </div>
    <div class="card-body">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Receipt #</th>
              <th>Parishioner</th>
              <th>Service</th>
              <th>Parish</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody id="txBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Empty state before generating -->
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
var startEl = document.getElementById('startDate');
var endEl   = document.getElementById('endDate');
var rangeLabel = document.getElementById('rangeLabel');

function selectReportType(el) {
  document.querySelectorAll('.report-type').forEach(function(c){ c.classList.remove('active'); });
  el.classList.add('active');
  var type = el.getAttribute('data-type');
  var today = new Date();
  var s, e;

  switch(type) {
    case 'daily':
      s = e = fmt(today);
      rangeLabel.textContent = 'Daily';
      break;
    case 'weekly':
      var day = today.getDay();
      var mon = new Date(today); mon.setDate(today.getDate() - (day === 0 ? 6 : day - 1));
      s = fmt(mon); e = fmt(today);
      rangeLabel.textContent = 'Weekly';
      break;
    case 'monthly':
      s = today.getFullYear() + '-' + pad(today.getMonth()+1) + '-01';
      e = fmt(today);
      rangeLabel.textContent = 'Monthly';
      break;
    case 'annual':
      s = today.getFullYear() + '-01-01';
      e = fmt(today);
      rangeLabel.textContent = 'Annual';
      break;
    case 'custom':
      rangeLabel.textContent = 'Custom';
      return; // let user pick
  }
  startEl.value = s;
  endEl.value = e;
}

function fmt(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
function pad(n) { return n < 10 ? '0'+n : ''+n; }

function num(v) { return parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function pillClass(status) {
  switch(status) {
    case 'completed': return 'pill-green';
    case 'pending':   return 'pill-amber';
    case 'refunded':  return 'pill-wine';
    default:          return 'pill-navy';
  }
}

function loadPreview() {
  setLoading(true);
  var fd = new FormData();
  fd.append('start_date', startEl.value);
  fd.append('end_date', endEl.value);

  fetch('export.php?ajax=preview', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      setLoading(false);
      if (!d.ok) { showToast(d.error||'Error loading report','error'); return; }

      document.getElementById('emptyState').style.display = 'none';
      document.getElementById('reportPreview').style.display = 'block';

      // Summary cards
      var s = d.summary;
      document.getElementById('summaryCards').innerHTML =
        '<div class="stat-card stat-green"><div class="stat-icon">₱</div><div class="stat-label">Total Revenue</div><div class="stat-value">'+num(s.total_revenue)+'</div></div>' +
        '<div class="stat-card stat-navy"><div class="stat-icon">[icon:wallet]</div><div class="stat-label">Total Transactions</div><div class="stat-value">'+(parseInt(s.total_transactions))+'</div></div>' +
        '<div class="stat-card stat-gold"><div class="stat-icon">[icon:chart]</div><div class="stat-label">Avg Transaction</div><div class="stat-value">'+num(s.avg_transaction)+'</div></div>' +
        '<div class="stat-card stat-wine"><div class="stat-icon">[icon:refresh]</div><div class="stat-label">Refunds</div><div class="stat-value">'+num(s.total_refunds)+'</div></div>';

      // By service
      var svcHtml = '';
      d.by_service.forEach(function(r){
        svcHtml += '<tr><td>'+esc(r.service_name)+'</td><td>'+r.tx_count+'</td><td>P '+num(r.total)+'</td></tr>';
      });
      document.getElementById('byServiceBody').innerHTML = svcHtml || '<tr><td colspan="3" style="text-align:center;color:var(--ink-30)">No data</td></tr>';

      // By method
      var mthHtml = '';
      d.by_method.forEach(function(r){
        mthHtml += '<tr><td>'+esc(r.method).charAt(0).toUpperCase()+esc(r.method).slice(1)+'</td><td>'+r.tx_count+'</td><td>P '+num(r.total)+'</td></tr>';
      });
      document.getElementById('byMethodBody').innerHTML = mthHtml || '<tr><td colspan="3" style="text-align:center;color:var(--ink-30)">No data</td></tr>';

      // Transactions
      var txHtml = '';
      d.transactions.forEach(function(r){
        txHtml += '<tr>';
        txHtml += '<td>'+ esc(r.receipt_number||'—') +'</td>';
        txHtml += '<td>'+ esc(r.parishioner) +'</td>';
        txHtml += '<td>'+ esc(r.service_name) +'</td>';
        txHtml += '<td>'+ esc(r.parish_name) +'</td>';
        txHtml += '<td>P '+ num(r.amount) +'</td>';
        txHtml += '<td>'+ esc(r.method).charAt(0).toUpperCase()+esc(r.method).slice(1) +'</td>';
        txHtml += '<td><span class="pill '+pillClass(r.status)+'">'+ r.status.charAt(0).toUpperCase()+r.status.slice(1) +'</span></td>';
        txHtml += '<td>'+ new Date(r.created_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) +'</td>';
        txHtml += '</tr>';
      });
      document.getElementById('txBody').innerHTML = txHtml || '<tr><td colspan="8" style="text-align:center;color:var(--ink-30)">No transactions found</td></tr>';
      document.getElementById('txCount').textContent = d.transactions.length + ' records';

      showToast('Report generated successfully','success');
    })
    .catch(function(err){
      setLoading(false);
      showToast('Failed to load report','error');
    });
}

function exportCSV() {
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = 'export.php?ajax=export_csv';
  form.target = '_blank';
  var s = document.createElement('input'); s.name='start_date'; s.value=startEl.value; form.appendChild(s);
  var e = document.createElement('input'); e.name='end_date'; e.value=endEl.value; form.appendChild(e);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
  showToast('CSV download started','success');
}

function printReport() {
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = 'export.php?ajax=print_report';
  form.target = '_blank';
  var s = document.createElement('input'); s.name='start_date'; s.value=startEl.value; form.appendChild(s);
  var e = document.createElement('input'); e.name='end_date'; e.value=endEl.value; form.appendChild(e);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
}

function esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s||''));
  return d.innerHTML;
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
