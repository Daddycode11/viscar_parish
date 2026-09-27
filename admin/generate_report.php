<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($report_label ?? 'Report'); ?> — Apostolic Vicariate of San Jose</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500&display=swap');

  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DM Sans', sans-serif; font-size: 11pt; color: #1A1510; background: white; }

  .print-page { padding: 25mm 20mm; max-width: 210mm; margin: 0 auto; }

  /* Header */
  .report-header { display: flex; align-items: flex-start; justify-content: space-between; padding-bottom: 16px; border-bottom: 2px solid #1B2A4A; margin-bottom: 20px; }
  .report-logo-area { display: flex; align-items: center; gap: 12px; }
  .report-logo-box { width: 48px; height: 48px; border-radius: 8px; background: #1B2A4A; display: flex; align-items: center; justify-content: center; color: #C9A84C; font-family: 'Cormorant Garamond', serif; font-size: 18pt; font-weight: 600; }
  .report-org-name { font-family: 'Cormorant Garamond', serif; font-size: 14pt; font-weight: 600; color: #1B2A4A; }
  .report-org-sub { font-size: 8pt; color: #999; text-transform: uppercase; letter-spacing: 0.08em; margin-top: 2px; }
  .report-meta { text-align: right; font-size: 9pt; color: #666; }
  .report-meta strong { display: block; font-size: 14pt; font-family: 'Cormorant Garamond', serif; color: #1B2A4A; font-weight: 600; margin-bottom: 4px; }

  /* Summary boxes */
  .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
  .summary-box { border: 1px solid #E8E4DE; border-radius: 6px; padding: 12px; }
  .summary-box .sb-label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 4px; }
  .summary-box .sb-val { font-family: 'Cormorant Garamond', serif; font-size: 16pt; font-weight: 600; color: #1B2A4A; }
  .summary-box.highlight { background: #1B2A4A; border-color: #1B2A4A; }
  .summary-box.highlight .sb-label { color: rgba(255,255,255,.6); }
  .summary-box.highlight .sb-val { color: #C9A84C; }

  /* Filter info */
  .filter-strip { background: #F8F6F2; border-radius: 6px; padding: 8px 14px; margin-bottom: 20px; font-size: 9pt; color: #666; display: flex; gap: 20px; flex-wrap: wrap; }
  .filter-strip span strong { color: #1A1510; }

  /* Table */
  table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 16px; }
  thead th { background: #1B2A4A; color: white; padding: 8px 10px; text-align: left; font-size: 8pt; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; }
  tbody td { padding: 7px 10px; border-bottom: 1px solid #F0EDE8; vertical-align: middle; }
  tbody tr:nth-child(even) td { background: #FAFAF9; }
  tbody tr:hover td { background: #FFF8E8; }
  tfoot td { padding: 8px 10px; font-weight: 600; background: #F8F6F2; border-top: 2px solid #1B2A4A; }

  /* Status pills */
  .status-pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 8pt; font-weight: 500; }
  .pill-green  { background: rgba(42,122,82,.12);  color: #2A7A52; }
  .pill-wine   { background: rgba(122,42,58,.12);  color: #7A2A3A; }
  .pill-amber  { background: rgba(201,122,32,.12); color: #C97A20; }
  .pill-navy   { background: rgba(27,42,74,.1);    color: #1B2A4A; }

  /* Bar viz */
  .mini-bar { display: flex; align-items: center; gap: 6px; }
  .mini-bar-track { height: 5px; background: #E8E4DE; border-radius: 3px; overflow: hidden; flex: 1; min-width: 50px; }
  .mini-bar-fill  { height: 100%; background: #C9A84C; border-radius: 3px; }

  /* Footer */
  .report-footer { margin-top: 24px; padding-top: 14px; border-top: 1px solid #E8E4DE; display: flex; justify-content: space-between; font-size: 8.5pt; color: #999; }

  /* Signature area */
  .signature-area { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-top: 30px; padding-top: 20px; }
  .sig-box { border-top: 1px solid #1B2A4A; padding-top: 6px; text-align: center; font-size: 8.5pt; color: #666; }
  .sig-box strong { display: block; font-weight: 500; color: #1A1510; }

  /* Print controls (screen only) */
  .print-controls { position: fixed; top: 20px; right: 20px; display: flex; gap: 8px; }
  .print-btn { padding: 8px 18px; border-radius: 20px; font-size: 12px; font-weight: 500; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
  .print-btn.primary { background: #1B2A4A; color: white; }
  .print-btn.secondary { background: white; color: #1B2A4A; border: 1px solid #ddd; }

  @media print {
    .print-controls { display: none; }
    body { font-size: 10pt; }
    @page { margin: 15mm; size: A4 portrait; }
  }
</style>
</head>
<body>

<!-- Print Controls (hidden on print) -->
<div class="print-controls">
  <button onclick="window.print()" class="print-btn primary">[icon:print] Print / Save PDF</button>
  <?= navigation_controls('admin/reports.php') ?>
</div>

<div class="print-page">

  <!-- Header -->
  <div class="report-header">
    <div class="report-logo-area">
      <div class="report-logo-box">AV</div>
      <div>
        <div class="report-org-name">Apostolic Vicariate of San Jose</div>
        <div class="report-org-sub">Parish Service Platform · Official Report</div>
      </div>
    </div>
    <div class="report-meta">
      <strong><?php echo htmlspecialchars($report_label ?? 'Report'); ?></strong>
      Generated: <?php echo date('F j, Y g:i A'); ?><br>
      Prepared by: <?php echo htmlspecialchars($user['name'] ?? 'Administrator'); ?><br>
      Report ID: RPT-<?php echo strtoupper(substr(md5(microtime()),0,8)); ?>
    </div>
  </div>

  <!-- Filter strip -->
  <div class="filter-strip">
    <span><strong>Period:</strong> <?php echo date('M j, Y', strtotime($date_from)); ?> – <?php echo date('M j, Y', strtotime($date_to)); ?></span>
    <span><strong>Parish:</strong> <?php echo $parish_id ? htmlspecialchars($parishes_list[array_search($parish_id, array_column($parishes_list,'id'))]['name'] ?? 'All') : 'All Parishes'; ?></span>
    <span><strong>Total Records:</strong> <?php echo count($report_rows); ?></span>
  </div>

  <!-- Summary boxes (financial only) -->
  <?php if($report_type === 'financial'): ?>
  <div class="summary-row">
    <div class="summary-box highlight">
      <div class="sb-label">Total Collected</div>
      <div class="sb-val">₱<?php echo number_format($paid_total); ?></div>
    </div>
    <div class="summary-box">
      <div class="sb-label">Transactions</div>
      <div class="sb-val"><?php echo count($report_rows); ?></div>
    </div>
    <div class="summary-box">
      <div class="sb-label">Pending Amount</div>
      <div class="sb-val">₱<?php echo number_format($unpaid_total ?? 0); ?></div>
    </div>
    <div class="summary-box">
      <div class="sb-label">Avg per Transaction</div>
      <div class="sb-val">₱<?php echo count($report_rows)?number_format($total_all/count($report_rows),0):'0'; ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Data table -->
  <table>
    <thead>
      <tr>
        <?php foreach($report_columns as $col): ?>
        <th><?php echo htmlspecialchars($col); ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach($report_rows as $row): ?>
      <tr>
        <?php if($report_type === 'financial'):
          $pill = $row['status']==='completed' ? 'pill-green' : 'pill-amber';
        ?>
          <td style="font-family:monospace;font-size:8pt;color:#999"><?php echo htmlspecialchars($row['ref']); ?></td>
          <td><?php echo date('M j, Y', strtotime($row['date'])); ?></td>
          <td><strong><?php echo htmlspecialchars($row['parishioner']); ?></strong></td>
          <td><?php echo htmlspecialchars($row['service']); ?></td>
          <td style="font-size:9pt;color:#666"><?php echo htmlspecialchars($row['parish']); ?></td>
          <td><strong>₱<?php echo number_format($row['amount'],2); ?></strong></td>
          <td><?php echo htmlspecialchars($row['method']); ?></td>
          <td><span class="status-pill <?php echo $pill; ?>"><?php echo ucfirst($row['status']); ?></span></td>

        <?php elseif($report_type === 'applications'):
          $sp = ['approved'=>'pill-green','rejected'=>'pill-wine','pending'=>'pill-amber'];
          $spill = $sp[$row['status']] ?? 'pill-amber';
          $ppill = $row['payment']==='paid' ? 'pill-green' : 'pill-wine';
        ?>
          <td style="color:#999;font-size:8pt">#<?php echo $row['id']; ?></td>
          <td><?php echo date('M j', strtotime($row['date'])); ?></td>
          <td><strong><?php echo htmlspecialchars($row['parishioner']); ?></strong></td>
          <td style="font-size:8.5pt;color:#666"><?php echo htmlspecialchars($row['email']); ?></td>
          <td><?php echo htmlspecialchars($row['service']); ?></td>
          <td style="font-size:8.5pt;color:#666"><?php echo htmlspecialchars($row['parish']); ?></td>
          <td><?php echo date('M j, Y', strtotime($row['schedule'])); ?></td>
          <td><span class="status-pill <?php echo $spill; ?>"><?php echo ucfirst($row['status']); ?></span></td>
          <td><span class="status-pill <?php echo $ppill; ?>"><?php echo ucfirst($row['payment']); ?></span></td>

        <?php elseif($report_type === 'parish_comparison'):
          $max_rev = max(array_column($report_rows,'revenue'));
          $pct = round(($row['revenue']/$max_rev)*100);
        ?>
          <td><strong><?php echo htmlspecialchars($row['parish']); ?></strong></td>
          <td style="text-align:center"><?php echo $row['apps']; ?></td>
          <td style="text-align:center"><span class="status-pill pill-green"><?php echo $row['approved']; ?></span></td>
          <td style="text-align:center"><span class="status-pill pill-wine"><?php echo $row['rejected']; ?></span></td>
          <td style="text-align:center"><span class="status-pill pill-amber"><?php echo $row['pending']; ?></span></td>
          <td>
            <div class="mini-bar">
              <strong>₱<?php echo number_format($row['revenue']); ?></strong>
              <div class="mini-bar-track"><div class="mini-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
            </div>
          </td>
          <td style="text-align:center"><?php echo $row['users']; ?></td>
          <td style="text-align:center"><?php echo $row['services']; ?></td>

        <?php elseif($report_type === 'service_demand'): ?>
          <td><strong><?php echo htmlspecialchars($row['service']); ?></strong></td>
          <td style="text-align:center;font-weight:600"><?php echo $row['count']; ?></td>
          <td style="font-weight:600">₱<?php echo number_format($row['revenue']); ?></td>
          <td>₱<?php echo number_format($row['avg_fee']); ?></td>
          <td>
            <div class="mini-bar">
              <?php echo $row['completion_rate']; ?>%
              <div class="mini-bar-track"><div class="mini-bar-fill" style="width:<?php echo $row['completion_rate']; ?>%;background:#2A7A52"></div></div>
            </div>
          </td>

        <?php elseif($report_type === 'user_activity'):
          $rPill = match($row['role']) {'Secretary'=>'pill-amber','Bookkeeper'=>'pill-navy',default=>'pill-green'};
        ?>
          <td><strong><?php echo htmlspecialchars($row['user']); ?></strong></td>
          <td><span class="status-pill <?php echo $rPill; ?>"><?php echo $row['role']; ?></span></td>
          <td style="font-size:8.5pt;color:#666"><?php echo htmlspecialchars($row['parish']); ?></td>
          <td style="text-align:center"><?php echo $row['logins']; ?></td>
          <td style="text-align:center;font-weight:600"><?php echo $row['apps_processed']; ?></td>
          <td><?php echo date('M j, Y', strtotime($row['last_active'])); ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if($report_type === 'financial'): ?>
    <tfoot>
      <tr>
        <td colspan="5" style="text-align:right">TOTAL COLLECTED (<?php echo $comp_count; ?> transactions):</td>
        <td><strong>₱<?php echo number_format($paid_total,2); ?></strong></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <!-- Signatures -->
  <div class="signature-area">
    <div class="sig-box"><strong>Prepared by</strong><?php echo htmlspecialchars($user['name']); ?>, Admin</div>
    <div class="sig-box"><strong>Reviewed by</strong>Parish Finance Officer</div>
    <div class="sig-box"><strong>Approved by</strong>Vicar Apostolic</div>
  </div>

  <!-- Footer -->
  <div class="report-footer">
    <span>Apostolic Vicariate of San Jose · Parish Service Platform</span>
    <span>Page 1 of 1 · Generated <?php echo date('Y-m-d H:i:s'); ?></span>
    <span>CONFIDENTIAL — For internal use only</span>
  </div>

</div>
<script>
  // Auto-trigger print dialog
  window.onload = () => { setTimeout(() => window.print(), 600); };
</script>
</body>
</html>