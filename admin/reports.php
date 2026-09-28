<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Reports & Export — Apostolic Vicariate of San Jose
 * Full dynamic version with live data preview, filtering, and export
 */

// ── UNCOMMENT FOR REAL DB ──────────────────────
// require_once '../includes/auth.php';
// require_once '../includes/db.php';
// checkRole('admin');
// $user = currentUser();
// ───────────────────────────────────────────────

$user = currentUser();

// ── FILTER PARAMETERS ─────────────────────────
$report_type  = $_GET['type']       ?? 'financial';
$period       = $_GET['period']     ?? 'monthly';
$date_from    = $_GET['date_from']  ?? date('Y-m-01');
$date_to      = $_GET['date_to']    ?? date('Y-m-d');
$parish_id    = $_GET['parish_id']  ?? '';
$format       = $_GET['format']     ?? 'preview';
$page_num     = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 15;

// ── SET DATE RANGE BY PERIOD ──────────────────
if (!isset($_GET['date_from'])) {
    switch ($period) {
        case 'daily':
            $date_from = date('Y-m-d');
            $date_to   = date('Y-m-d');
            break;
        case 'weekly':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to   = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'monthly':
            $date_from = date('Y-m-01');
            $date_to   = date('Y-m-t');
            break;
        case 'quarterly':
            $qm = ceil(date('n') / 3) * 3 - 2;
            $date_from = date("Y-$qm-01");
            $date_to   = date('Y-m-t', strtotime(date("Y-" . ($qm+2) . "-01")));
            break;
        case 'annual':
            $date_from = date('Y-01-01');
            $date_to   = date('Y-12-31');
            break;
    }
}

// ── PARISHES LIST ─────────────────────────────
$parishes_list=$conn->query('SELECT id,name FROM parishes ORDER BY name')->fetch_all(MYSQLI_ASSOC);
require_once __DIR__.'/../includes/report_data.php';
try{report_range($date_from,$date_to);}catch(DomainException $e){fail_request($e->getMessage(),422);}

// ── LOAD REPORT DATA ──────────────────────────
require_once APP_ROOT.'/includes/accounting.php';
$auditReport=isset(ACCOUNTING_REPORT_TYPES[$report_type]);
if ($auditReport) {
    $report_label=ACCOUNTING_REPORT_TYPES[$report_type].' Audit Report';
    $report_columns=['Type','Number','Date','Payee / payer','Amount','Particulars','Reference','Status','Parish'];
    $report_rows=[];
    foreach($parishes_list as $reportParish) {
        if($parish_id && (int)$parish_id!==(int)$reportParish['id'])continue;
        foreach(accounting_rows($user,['document_type'=>$report_type,'date_from'=>$date_from,'date_to'=>$date_to,'parish_id'=>$reportParish['id']]) as $row) {
            $report_rows[]=[ACCOUNTING_REPORT_TYPES[$row['document_type']],$row['document_number'],$row['document_date'],$row['party_name'],$row['amount'],$row['description'],$row['reference'],$row['status'],$reportParish['name']];
        }
    }
} else switch ($report_type) {
    case 'applications':
        $report_rows    = gen_applications_data($date_from, $date_to, $parish_id);
        $report_label   = 'Applications Report';
        $report_columns = ['App #','Date Filed','Parishioner','Email','Service','Parish','Schedule','Status','Payment'];
        break;
    case 'parish_comparison':
        $report_rows    = gen_parish_comparison($date_from, $date_to);
        $report_label   = 'Parish Comparison Report';
        $report_columns = ['Parish','Total Apps','Approved','Rejected','Pending','Revenue','Users','Services'];
        break;
    case 'service_demand':
        $report_rows    = gen_service_demand($date_from, $date_to, $parish_id);
        $report_label   = 'Service Demand Analysis';
        $report_columns = ['Service','Applications','Revenue','Avg Fee','Completion Rate'];
        break;
    case 'user_activity':
        $report_rows    = gen_user_activity($date_from, $date_to);
        $report_label   = 'User Activity Report';
        $report_columns = ['Staff Name','Role','Parish','Logins','Apps Processed','Last Active'];
        break;
    default: // financial
        $report_type    = 'financial';
        $report_rows    = gen_financial_data($date_from, $date_to, $parish_id);
        $report_label   = 'Financial Summary Report';
        $report_columns = ['Ref #','Date','Parishioner','Service','Parish','Amount','Method','Status'];
        break;
}

// ── SUMMARY STATS ─────────────────────────────
$total_rows = count($report_rows);
$total_pages = max(1, ceil($total_rows / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;
$paged_rows = array_slice($report_rows, $offset, $per_page);

// Financial summary totals
$report_total_amount = 0;
$report_paid_count   = 0;
if ($report_type === 'financial') {
    foreach ($report_rows as $r) {
        if (in_array($r['status'],['completed','refunded'],true)) { $report_total_amount += $r['amount']; $report_paid_count++; }
    }
}

// ── EXPORT HANDLER ────────────────────────────
if (isset($_GET['export']) && in_array($_GET['export'], ['csv','excel','pdf'])) {
    $export_fmt = $_GET['export'];
    $filename = strtolower(str_replace(' ','_',$report_label)) . '_' . date('Ymd');

    if ($export_fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        // Header row
        report_csv($out, array_merge(['Report: ' . $report_label], []));
        report_csv($out, ['Period: ' . date('M j, Y', strtotime($date_from)) . ' – ' . date('M j, Y', strtotime($date_to))]);
        report_csv($out, []);
        report_csv($out, $report_columns);
        // Data rows
        foreach ($report_rows as $row) {
            if($auditReport) { report_csv($out,$row);continue; }
            switch ($report_type) {
                case 'financial':
                    report_csv($out, [$row['ref'],$row['date'],$row['parishioner'],$row['service'],$row['parish'],'₱'.$row['amount'],$row['method'],ucfirst($row['status'])]);
                    break;
                case 'applications':
                    report_csv($out, ['#'.$row['id'],$row['date'],$row['parishioner'],$row['email'],$row['service'],$row['parish'],$row['schedule'],ucfirst($row['status']),ucfirst($row['payment'])]);
                    break;
                case 'parish_comparison':
                    report_csv($out, [$row['parish'],$row['apps'],$row['approved'],$row['rejected'],$row['pending'],'₱'.$row['revenue'],$row['users'],$row['services']]);
                    break;
                case 'service_demand':
                    report_csv($out, [$row['service'],$row['count'],'₱'.$row['revenue'],'₱'.$row['avg_fee'],$row['completion_rate'].'%']);
                    break;
                case 'user_activity':
                    report_csv($out, [$row['user'],$row['role'],$row['parish'],$row['logins'],$row['apps_processed'],$row['last_active']]);
                    break;
            }
        }
        if ($report_type === 'financial') {
            report_csv($out, []);
            report_csv($out, ['','','','','TOTAL COLLECTED','₱'.$report_total_amount,'','']);
        }
        fclose($out);
        exit;
    }

    // ── PDF EXPORT — redirect to printable HTML (browser handles "Save as PDF") ──
    if ($export_fmt === 'pdf') {
        $params = $_GET;
        unset($params['export']);
        $params['format'] = 'print';
        header('Location: reports.php?' . http_build_query($params));
        exit;
    }

    // ── EXCEL EXPORT ──────────────────────────
    if ($export_fmt === 'excel') {
        if (file_exists('../vendor/autoload.php') && class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            require_once '../vendor/autoload.php';
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($report_label, 0, 31));
            // Title
            $sheet->setCellValue('A1', $report_label);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->setCellValue('A2', 'Period: ' . date('M j, Y', strtotime($date_from)) . ' – ' . date('M j, Y', strtotime($date_to)));
            $row_i = 4;
            // Column headers
            foreach ($report_columns as $ci => $col) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1) . $row_i;
                $sheet->setCellValue($cell, $col);
                $sheet->getStyle($cell)->getFont()->setBold(true);
                $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1B2A4A');
                $sheet->getStyle($cell)->getFont()->getColor()->setRGB('FFFFFF');
            }
            $row_i++;
            foreach ($report_rows as $rr) {
                $vals = match($report_type) {
                    'financial'         => [$rr['ref'],$rr['date'],$rr['parishioner'],$rr['service'],$rr['parish'],$rr['amount'],$rr['method'],ucfirst($rr['status'])],
                    'applications'      => ['#'.$rr['id'],$rr['date'],$rr['parishioner'],$rr['email'],$rr['service'],$rr['parish'],$rr['schedule'],ucfirst($rr['status']),ucfirst($rr['payment'])],
                    'parish_comparison' => [$rr['parish'],$rr['apps'],$rr['approved'],$rr['rejected'],$rr['pending'],$rr['revenue'],$rr['users'],$rr['services']],
                    'service_demand'    => [$rr['service'],$rr['count'],$rr['revenue'],$rr['avg_fee'],$rr['completion_rate'].'%'],
                    'user_activity'     => [$rr['user'],$rr['role'],$rr['parish'],$rr['logins'],$rr['apps_processed'],$rr['last_active']],
                    default             => $auditReport ? $rr : []
                };
                foreach ($vals as $ci => $v) {
                    $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1) . $row_i, $v);
                }
                $row_i++;
            }
            foreach (range('A', 'I') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        }
        // Fallback: CSV with .xlsx extension (opens in Excel fine)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        report_csv($out, $report_columns);
        foreach ($report_rows as $rr) {
            report_csv($out, match($report_type) {
                'financial'         => [$rr['ref'],$rr['date'],$rr['parishioner'],$rr['service'],$rr['parish'],'₱'.$rr['amount'],$rr['method'],ucfirst($rr['status'])],
                'applications'      => ['#'.$rr['id'],$rr['date'],$rr['parishioner'],$rr['email'],$rr['service'],$rr['parish'],$rr['schedule'],ucfirst($rr['status']),ucfirst($rr['payment'])],
                'parish_comparison' => [$rr['parish'],$rr['apps'],$rr['approved'],$rr['rejected'],$rr['pending'],'₱'.$rr['revenue'],$rr['users'],$rr['services']],
                'service_demand'    => [$rr['service'],$rr['count'],'₱'.$rr['revenue'],'₱'.$rr['avg_fee'],$rr['completion_rate'].'%'],
                'user_activity'     => [$rr['user'],$rr['role'],$rr['parish'],$rr['logins'],$rr['apps_processed'],$rr['last_active']],
                default => $auditReport ? $rr : []
            });
        }
        fclose($out);
        exit;
    }
}

// ── PRINTABLE VERSION ─────────────────────────
if (isset($_GET['format']) && $_GET['format'] === 'print') {
    // Variables expected by generate_report.php
    $paid_total   = $report_total_amount;
    $unpaid_total = 0;
    $total_all    = 0;
    if ($report_type === 'financial') {
        foreach ($report_rows as $r) {
            $total_all += (float)$r['amount'];
            if ($r['status'] === 'pending') $unpaid_total += (float)$r['amount'];
        }
    }
    $comp_count = $report_paid_count;
    include 'generate_report.php';
    exit;
}

// ── BUILD EXPORT QUERY STRING ─────────────────
$base_params = [
    'type'      => $report_type,
    'period'    => $period,
    'date_from' => $date_from,
    'date_to'   => $date_to,
    'parish_id' => $parish_id,
];
$export_csv_url   = 'reports.php?' . http_build_query(array_merge($base_params, ['export'=>'csv']));
$export_excel_url = 'reports.php?' . http_build_query(array_merge($base_params, ['export'=>'excel']));
$export_pdf_url   = 'reports.php?' . http_build_query(array_merge($base_params, ['format'=>'print']));

$page_id    = 'reports';
$page_title = 'Reports & Export';
$page_sub   = 'Reports';
include 'includes/layout.php';
?>

<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Reports & Export</div>
    <h1 class="sec-title">Reports</h1>
    <p class="sec-sub">Generate, preview, and export consolidated reports across all parishes.</p>
  </div>
  <!-- Export buttons shown when data is loaded -->
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?php echo htmlspecialchars($export_pdf_url); ?>" target="_blank" class="btn-sm btn-wine" style="display:flex;align-items:center;gap:6px">
      <span>[icon:file]</span> Print / PDF
    </a>
    <a href="<?php echo htmlspecialchars($export_excel_url); ?>" class="btn-sm btn-green" style="display:flex;align-items:center;gap:6px">
      <span>[icon:book]</span> Excel
    </a>
    <a href="<?php echo htmlspecialchars($export_csv_url); ?>" class="btn-sm btn-navy" style="display:flex;align-items:center;gap:6px">
      <span>[icon:chart]</span> CSV
    </a>
  </div>
</div>

<!-- ═══ REPORT FILTER FORM ══════════════════════════════ -->
<div class="card" style="margin-bottom:22px">
  <div class="card-head">
    <h3>Report Configuration</h3>
    <span class="card-tag">Adjust filters to generate your report</span>
  </div>
  <div class="card-body">
    <form method="GET" action="reports.php" id="reportForm">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;align-items:end">

        <div class="form-group" style="margin:0">
          <label>Report Type</label>
          <select name="type" id="reportType" onchange="updatePeriodVisibility()">
            <option value="financial"         <?php echo $report_type==='financial'?'selected':''; ?>>Financial Summary</option>
            <option value="applications"      <?php echo $report_type==='applications'?'selected':''; ?>>Applications Report</option>
            <option value="parish_comparison" <?php echo $report_type==='parish_comparison'?'selected':''; ?>>Parish Comparison</option>
            <option value="service_demand"    <?php echo $report_type==='service_demand'?'selected':''; ?>>Service Demand</option>
            <option value="user_activity"     <?php echo $report_type==='user_activity'?'selected':''; ?>>User Activity</option>
            <?php foreach(ACCOUNTING_REPORT_TYPES as $key=>$label): ?><option value="<?= h($key) ?>" <?= $report_type===$key?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin:0">
          <label>Period</label>
          <select name="period" id="periodSelect" onchange="applyPreset(this.value)">
            <option value="daily"    <?php echo $period==='daily'?'selected':''; ?>>Today</option>
            <option value="weekly"   <?php echo $period==='weekly'?'selected':''; ?>>This Week</option>
            <option value="monthly"  <?php echo $period==='monthly'?'selected':''; ?>>This Month</option>
            <option value="quarterly"<?php echo $period==='quarterly'?'selected':''; ?>>This Quarter</option>
            <option value="annual"   <?php echo $period==='annual'?'selected':''; ?>>This Year</option>
            <option value="custom"   <?php echo $period==='custom'?'selected':''; ?>>Custom Range</option>
          </select>
        </div>

        <div class="form-group" style="margin:0">
          <label>From Date</label>
          <input type="date" name="date_from" id="dateFrom" value="<?php echo $date_from; ?>">
        </div>

        <div class="form-group" style="margin:0">
          <label>To Date</label>
          <input type="date" name="date_to" id="dateTo" value="<?php echo $date_to; ?>">
        </div>

        <div class="form-group" style="margin:0" id="parishField">
          <label>Parish</label>
          <select name="parish_id">
            <option value="">All Parishes</option>
            <?php foreach($parishes_list as $pl): ?>
            <option value="<?php echo $pl['id']; ?>" <?php echo $parish_id==$pl['id']?'selected':''; ?>>
              <?php echo htmlspecialchars($pl['name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:flex;gap:8px;align-items:flex-end">
          <button type="submit" class="btn-sm btn-navy" style="flex:1;padding:9px">Generate</button>
          <a href="reports.php" class="btn-sm btn-outline" style="padding:9px 14px" title="Reset filters">[icon:refresh]</a>
        </div>

      </div>
    </form>
  </div>
</div>

<!-- ═══ REPORT SUMMARY STATS ════════════════════════════ -->
<?php if($report_type === 'financial'): ?>
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:22px">
  <?php
  $total_all   = array_sum(array_column($report_rows,'amount'));
  $paid_total  = array_sum(array_map(fn($r)=>in_array($r['status'],['completed','refunded'],true)?$r['amount']:0, $report_rows));
  $unpaid_total= $total_all - $paid_total;
  $comp_count  = count(array_filter($report_rows, fn($r)=>in_array($r['status'],['completed','refunded'],true)));
  ?>
  <div class="stat-card stat-gold">
    <div class="stat-icon">₱</div>
    <div class="stat-label">Total Collected</div>
    <div class="stat-value">₱<?php echo number_format($paid_total); ?></div>
    <div class="stat-delta"><?php echo $comp_count; ?> verified transactions</div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:clock]</div>
    <div class="stat-label">Pending Amount</div>
    <div class="stat-value">₱<?php echo number_format($unpaid_total); ?></div>
    <div class="stat-delta down"><?php echo count($report_rows)-$comp_count; ?> unpaid</div>
  </div>
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:chart]</div>
    <div class="stat-label">Transactions</div>
    <div class="stat-value"><?php echo count($report_rows); ?></div>
    <div class="stat-delta">In selected period</div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:chart]</div>
    <div class="stat-label">Avg per Transaction</div>
    <div class="stat-value">₱<?php echo count($report_rows)?number_format($total_all/count($report_rows),0):'0'; ?></div>
    <div class="stat-delta">Based on all records</div>
  </div>
</div>
<?php elseif($report_type === 'applications'): ?>
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:22px">
  <?php
  $app_approved = count(array_filter($report_rows, fn($r)=>$r['status']==='approved'));
  $app_pending  = count(array_filter($report_rows, fn($r)=>$r['status']==='pending'));
  $app_rejected = count(array_filter($report_rows, fn($r)=>$r['status']==='rejected'));
  $app_paid     = count(array_filter($report_rows, fn($r)=>$r['payment']==='paid'));
  ?>
  <div class="stat-card stat-navy"><div class="stat-icon">[icon:clipboard]</div><div class="stat-label">Total</div><div class="stat-value"><?php echo count($report_rows); ?></div></div>
  <div class="stat-card stat-green"><div class="stat-icon">[icon:check]</div><div class="stat-label">Approved</div><div class="stat-value"><?php echo $app_approved; ?></div></div>
  <div class="stat-card stat-amber"><div class="stat-icon">[icon:clock]</div><div class="stat-label">Pending</div><div class="stat-value"><?php echo $app_pending; ?></div></div>
  <div class="stat-card stat-wine"><div class="stat-icon">[icon:close]</div><div class="stat-label">Rejected</div><div class="stat-value"><?php echo $app_rejected; ?></div></div>
</div>
<?php elseif($report_type === 'parish_comparison'): ?>
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:22px">
  <?php
  $tot_apps = array_sum(array_column($report_rows,'apps'));
  $tot_rev  = array_sum(array_column($report_rows,'revenue'));
  $top_p    = $report_rows[0]['parish'] ?? '—';
  ?>
  <div class="stat-card stat-navy"><div class="stat-icon">[icon:church]</div><div class="stat-label">Parishes</div><div class="stat-value"><?php echo count($report_rows); ?></div></div>
  <div class="stat-card stat-amber"><div class="stat-icon">[icon:clipboard]</div><div class="stat-label">Total Apps</div><div class="stat-value"><?php echo $tot_apps; ?></div></div>
  <div class="stat-card stat-gold"><div class="stat-icon">₱</div><div class="stat-label">Total Revenue</div><div class="stat-value">₱<?php echo number_format($tot_rev); ?></div></div>
  <div class="stat-card stat-green"><div class="stat-icon">[icon:chart]</div><div class="stat-label">Top Parish</div><div class="stat-value" style="font-size:1rem;line-height:1.2"><?php echo htmlspecialchars(explode(' ',$top_p)[0]); ?></div></div>
</div>
<?php endif; ?>

<!-- ═══ REPORT PREVIEW TABLE ════════════════════════════ -->
<div class="card">
  <div class="card-head">
    <h3>
      <?php echo htmlspecialchars($report_label); ?>
      <span style="font-size:.75rem;font-weight:400;color:var(--ink-30);margin-left:8px">
        <?php echo date('M j, Y', strtotime($date_from)); ?> – <?php echo date('M j, Y', strtotime($date_to)); ?>
        <?php if($parish_id): foreach($parishes_list as $pl) if($pl['id']==$parish_id) echo ' · '.htmlspecialchars($pl['name']); endif; ?>
      </span>
    </h3>
    <div style="display:flex;align-items:center;gap:10px">
      <span class="card-tag"><?php echo $total_rows; ?> records</span>
      <div style="display:flex;gap:6px">
        <a href="<?php echo htmlspecialchars($export_csv_url); ?>" class="act-btn act-navy" title="Export CSV">CSV [icon:download]</a>
        <a href="<?php echo htmlspecialchars($export_excel_url); ?>" class="act-btn act-green" title="Export Excel">XLS [icon:download]</a>
        <a href="<?php echo htmlspecialchars($export_pdf_url); ?>" target="_blank" class="act-btn act-wine" title="Print/PDF">PDF [icon:download]</a>
      </div>
    </div>
  </div>

  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <?php foreach($report_columns as $col): ?>
            <th><?php echo htmlspecialchars($col); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($paged_rows)): ?>
          <tr><td colspan="<?php echo count($report_columns); ?>" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">
            No records found for the selected period and filters.
          </td></tr>
          <?php endif; ?>

          <?php foreach($paged_rows as $row): ?>
          <tr>
            <?php if($report_type === 'financial'):
              $sc = ['completed'=>'pill-green','pending'=>'pill-amber'];
              $pill = $sc[$row['status']] ?? 'pill-amber';
            ?>
              <td style="font-family:monospace;font-size:.75rem;color:var(--ink-30)"><?php echo htmlspecialchars($row['ref']); ?></td>
              <td style="font-size:.78rem;color:var(--ink-60)"><?php echo date('M j, Y', strtotime($row['date'])); ?></td>
              <td style="font-weight:500"><?php echo htmlspecialchars($row['parishioner']); ?></td>
              <td><?php echo htmlspecialchars($row['service']); ?></td>
              <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($row['parish']); ?></td>
              <td style="font-weight:600;color:var(--navy)">₱<?php echo number_format($row['amount'],2); ?></td>
              <td style="font-size:.78rem"><?php echo htmlspecialchars($row['method']); ?></td>
              <td><span class="pill <?php echo $pill; ?>"><?php echo ucfirst($row['status']); ?></span></td>

            <?php elseif($report_type === 'applications'):
              $sp = ['approved'=>'pill-green','rejected'=>'pill-wine','pending'=>'pill-amber'];
              $spill = $sp[$row['status']] ?? 'pill-amber';
              $ppill = $row['payment']==='paid' ? 'pill-green' : 'pill-wine';
            ?>
              <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $row['id']; ?></td>
              <td style="font-size:.78rem;color:var(--ink-60)"><?php echo date('M j, Y', strtotime($row['date'])); ?></td>
              <td style="font-weight:500"><?php echo htmlspecialchars($row['parishioner']); ?></td>
              <td style="font-size:.75rem;color:var(--ink-60)"><?php echo htmlspecialchars($row['email']); ?></td>
              <td><?php echo htmlspecialchars($row['service']); ?></td>
              <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($row['parish']); ?></td>
              <td style="font-size:.75rem;color:var(--ink-60)"><?php echo date('M j, Y', strtotime($row['schedule'])); ?></td>
              <td><span class="pill <?php echo $spill; ?>"><?php echo ucfirst($row['status']); ?></span></td>
              <td><span class="pill <?php echo $ppill; ?>"><?php echo ucfirst($row['payment']); ?></span></td>

            <?php elseif($report_type === 'parish_comparison'):
              $max_rev = max(1, ...array_column($report_rows,'revenue'));
              $pct = round(($row['revenue']/$max_rev)*100);
            ?>
              <td style="font-weight:500"><?php echo htmlspecialchars($row['parish']); ?></td>
              <td style="text-align:center"><?php echo $row['apps']; ?></td>
              <td style="text-align:center"><span class="pill pill-green"><?php echo $row['approved']; ?></span></td>
              <td style="text-align:center"><span class="pill pill-wine"><?php echo $row['rejected']; ?></span></td>
              <td style="text-align:center"><span class="pill pill-amber"><?php echo $row['pending']; ?></span></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-weight:600;color:var(--navy);min-width:70px">₱<?php echo number_format($row['revenue']); ?></span>
                  <div class="bar-track" style="flex:1;min-width:60px"><div class="bar-fill" style="width:<?php echo $pct; ?>%;background:var(--gold)"></div></div>
                </div>
              </td>
              <td style="text-align:center"><?php echo $row['users']; ?></td>
              <td style="text-align:center"><?php echo $row['services']; ?></td>

            <?php elseif($report_type === 'service_demand'): ?>
              <td style="font-weight:500"><?php echo htmlspecialchars($row['service']); ?></td>
              <td style="text-align:center;font-weight:600;color:var(--navy)"><?php echo $row['count']; ?></td>
              <td style="font-weight:600;color:var(--green)">₱<?php echo number_format($row['revenue']); ?></td>
              <td style="color:var(--ink-60)">₱<?php echo number_format($row['avg_fee']); ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:6px">
                  <div class="bar-track" style="width:70px"><div class="bar-fill" style="width:<?php echo $row['completion_rate']; ?>%;background:var(--green)"></div></div>
                  <span style="font-size:.78rem;color:var(--ink-60)"><?php echo $row['completion_rate']; ?>%</span>
                </div>
              </td>

            <?php elseif($report_type === 'user_activity'):
              $rPill = match($row['role']) { 'Secretary'=>'pill-gold','Bookkeeper'=>'pill-amber', default=>'pill-navy' };
            ?>
              <td style="font-weight:500"><?php echo htmlspecialchars($row['user']); ?></td>
              <td><span class="pill <?php echo $rPill; ?>"><?php echo $row['role']; ?></span></td>
              <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($row['parish']); ?></td>
              <td style="text-align:center;font-weight:600"><?php echo $row['logins']; ?></td>
              <td style="text-align:center;font-weight:600;color:var(--navy)"><?php echo $row['apps_processed']; ?></td>
              <td style="font-size:.75rem;color:var(--ink-30)"><?php echo date('M j, Y', strtotime($row['last_active'])); ?></td>
            <?php elseif($auditReport): foreach($row as $value): ?><td><?= h($value) ?></td><?php endforeach; ?>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>

        <?php if($report_type === 'financial' && count($paged_rows) > 0): ?>
        <tfoot>
          <tr style="background:rgba(201,168,76,.06)">
            <td colspan="5" style="padding:12px 14px;font-size:.78rem;font-weight:600;color:var(--ink-60);text-align:right">
              Page <?php echo $page_num; ?> Total Collected:
            </td>
            <td style="padding:12px 14px;font-weight:700;color:var(--navy);font-size:.88rem">
              ₱<?php echo number_format(array_sum(array_map(fn($r)=>in_array($r['status'],['completed','refunded'],true)?$r['amount']:0, $paged_rows)),2); ?>
            </td>
            <td colspan="2"></td>
          </tr>
          <?php if($page_num === $total_pages): ?>
          <tr style="background:rgba(27,42,74,.06)">
            <td colspan="5" style="padding:12px 14px;font-size:.82rem;font-weight:700;color:var(--navy);text-align:right">
              GRAND TOTAL COLLECTED:
            </td>
            <td style="padding:12px 14px;font-weight:700;color:var(--navy);font-size:1rem;font-family:var(--fh)">
              ₱<?php echo number_format($paid_total,2); ?>
            </td>
            <td colspan="2"></td>
          </tr>
          <?php endif; ?>
        </tfoot>
        <?php endif; ?>

      </table>
    </div>
  </div>

  <!-- ── PAGINATION ──────────────────────────── -->
  <?php if($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10);flex-wrap:wrap;gap:10px">
    <span style="font-size:.78rem;color:var(--ink-30)">
      Showing <?php echo $offset+1; ?>–<?php echo min($offset+$per_page,$total_rows); ?> of <?php echo $total_rows; ?> records
    </span>
    <div style="display:flex;gap:4px;align-items:center">
      <?php
      $pg_params = array_merge($base_params, []);
      // Prev
      if($page_num > 1):
        $pg_params['page'] = $page_num - 1;
      ?>
      <a href="reports.php?<?php echo http_build_query($pg_params); ?>" class="act-btn act-navy">← Prev</a>
      <?php endif; ?>
      <?php
      // Page numbers
      $start = max(1, $page_num - 2);
      $end   = min($total_pages, $page_num + 2);
      for($pn = $start; $pn <= $end; $pn++):
        $pg_params['page'] = $pn;
        $active_style = $pn === $page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?>
      <a href="reports.php?<?php echo http_build_query($pg_params); ?>" class="act-btn" style="<?php echo $active_style; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $pn; ?></a>
      <?php endfor; ?>
      <?php if($page_num < $total_pages):
        $pg_params['page'] = $page_num + 1;
      ?>
      <a href="reports.php?<?php echo http_build_query($pg_params); ?>" class="act-btn act-navy">Next →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ RECENT EXPORTS LOG ══════════════════════════════ -->
<div class="grid-2" style="margin-top:4px">
  <div class="card">
    <div class="card-head"><h3>Export History</h3><span class="card-tag">Session</span></div>
    <div class="card-body" style="padding:0" id="exportHistory">
      <div style="padding:30px;text-align:center;color:var(--ink-30);font-size:.82rem;font-style:italic">
        No exports this session. Use the buttons above to export.
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Saved Reports</h3></div>
    <div class="card-body" style="padding:0">
      <?php
      $saved = [
          ['name'=>'Monthly Financial — All Parishes','format'=>'PDF','date'=>'2026-02-25','size'=>'2.4 MB','url'=>'#'],
          ['name'=>'Applications Report — Jan 2026','format'=>'Excel','date'=>'2026-02-20','size'=>'1.1 MB','url'=>'#'],
          ['name'=>'Revenue by Service — Q1 2026','format'=>'PDF','date'=>'2026-02-15','size'=>'980 KB','url'=>'#'],
          ['name'=>'Parish Comparison — 2025 Annual','format'=>'PDF','date'=>'2026-01-05','size'=>'3.2 MB','url'=>'#'],
      ];
      foreach($saved as $s):
        $fIcon = $s['format']==='PDF' ? '[icon:file]' : ($s['format']==='Excel' ? '[icon:book]' : '[icon:chart]');
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--ink-10)">
        <span style="font-size:1.4rem"><?php echo $fIcon; ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-size:.8rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($s['name']); ?></div>
          <div style="font-size:.68rem;color:var(--ink-30)"><?php echo $s['format']; ?> · <?php echo $s['size']; ?> · <?php echo date('M j, Y', strtotime($s['date'])); ?></div>
        </div>
        <a href="<?php echo $s['url']; ?>" class="act-btn act-navy" style="font-size:.7rem;flex-shrink:0">[icon:download]</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
// ── PERIOD PRESETS ────────────────────────────
function applyPreset(period) {
    const now = new Date();
    let from, to;
    const pad = n => String(n).padStart(2,'0');
    const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

    switch(period) {
        case 'daily':
            from = to = fmt(now); break;
        case 'weekly':
            const day = now.getDay();
            const diff = (day === 0) ? -6 : 1 - day;
            const mon = new Date(now); mon.setDate(now.getDate() + diff);
            const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
            from = fmt(mon); to = fmt(sun); break;
        case 'monthly':
            from = `${now.getFullYear()}-${pad(now.getMonth()+1)}-01`;
            const last = new Date(now.getFullYear(), now.getMonth()+1, 0);
            to = fmt(last); break;
        case 'quarterly':
            const qm = Math.floor(now.getMonth() / 3) * 3;
            from = `${now.getFullYear()}-${pad(qm+1)}-01`;
            const qend = new Date(now.getFullYear(), qm+3, 0);
            to = fmt(qend); break;
        case 'annual':
            from = `${now.getFullYear()}-01-01`;
            to   = `${now.getFullYear()}-12-31`; break;
        case 'custom':
            return; // let user pick manually
    }
    document.getElementById('dateFrom').value = from;
    document.getElementById('dateTo').value = to;
}

// ── PARISH FIELD VISIBILITY ───────────────────
function updatePeriodVisibility() {
    const type = document.getElementById('reportType').value;
    const pf = document.getElementById('parishField');
    pf.style.display = (type === 'parish_comparison' || type === 'user_activity') ? 'none' : '';
}
updatePeriodVisibility();

// ── TRACK EXPORTS IN SESSION ──────────────────
document.querySelectorAll('a[href*="export="], a[href*="format=print"]').forEach(link => {
    link.addEventListener('click', () => {
        const fmt = link.href.includes('export=csv') ? 'CSV' :
                    link.href.includes('export=excel') ? 'Excel' : 'PDF';
        const history = document.getElementById('exportHistory');
        const now = new Date();
        const time = now.toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});
        const existing = history.querySelector('.export-empty');
        if (existing) existing.remove();
        const div = document.createElement('div');
        div.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 20px;border-bottom:1px solid var(--ink-10)';
        div.innerHTML = `
            <span style="font-size:1rem">${fmt==='PDF'?'[icon:file]':fmt==='Excel'?'[icon:book]':'[icon:chart]'}</span>
            <div style="flex:1">
                <div style="font-size:.8rem;font-weight:500"><?php echo htmlspecialchars($report_label); ?></div>
                <div style="font-size:.68rem;color:var(--ink-30)">${fmt} · Exported at ${time}</div>
            </div>
            <span class="pill pill-green" style="font-size:.62rem">Done</span>
        `;
        history.prepend(div);
    });
});
</script>

<?php include 'includes/layout_footer.php'; ?>
