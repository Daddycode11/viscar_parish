<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/accounting.php';

try { $rows = accounting_rows($user, $_GET); }
catch (DomainException $error) { fail_request($error->getMessage(), 422); }

$totals = [];
foreach ($rows as $row) {
    $totals[$row['document_type']] = ($totals[$row['document_type']] ?? 0) + money_cents($row['amount']);
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="accounting-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    report_csv($out, ['Type','Number','Date','Payee / payer','Amount','Description','Reference','Status','Parish','Type of service']);
    foreach ($rows as $row) {
        report_csv($out, [
            ACCOUNTING_REPORT_TYPES[$row['document_type']],
            $row['document_number'],
            $row['document_date'],
            $row['party_name'],
            $row['amount'],
            $row['description'],
            $row['reference'],
            $row['status'],
            $row['parish_id'],
            $row['service_name'] ?? 'Not applicable'
        ]);
    }
    foreach ($totals as $type => $cents) {
        report_csv($out, ['TOTAL ' . ACCOUNTING_REPORT_TYPES[$type], '', '', '', number_format($cents / 100, 2, '.', '')]);
    }
    exit;
}

// Safe string reads for filters (ignores array-style params like ?status[]=x)
$q = function ($key, $default = '') {
    return (isset($_GET[$key]) && is_string($_GET[$key])) ? $_GET[$key] : $default;
};
$dateFrom = $q('date_from', date('Y-m-01'));
$dateTo   = $q('date_to', date('Y-m-d'));
$docType  = $q('document_type');
$status   = $q('status');
$title    = ACCOUNTING_REPORT_TYPES[$docType] ?? 'Accounting Report';

$page_id = 'export'; $page_title = t('Export Reports');
require __DIR__ . '/includes/layout.php';
?>
<div class="sec-head">
  <div>
    <div class="sec-tag">Reports &amp; Export</div>
    <h1 class="sec-title">Reports</h1>
    <p class="sec-sub">Financial reports for your assigned parish.</p>
  </div>
</div>

<style>
.report-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px;margin:8px 0 18px}
.report-title{font-family:"Cormorant Garamond",Georgia,serif;font-size:1.9rem;margin:0 0 4px}
.report-meta{margin:0;color:var(--ink-60,#6b665e);font-size:.88rem}

.report-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px}
.report-filters label{display:flex;flex-direction:column;gap:6px;font-size:.72rem;color:var(--ink-60,#6b665e);text-transform:uppercase;letter-spacing:.08em}
.report-filters select,.report-filters input[type=date]{min-width:150px}
.report-actions{display:flex;gap:10px;margin-left:auto;flex-wrap:wrap}

.report-page .btn-sm{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border:0;border-radius:999px;
  font:600 .8rem/1 "DM Sans",Arial,sans-serif;letter-spacing:.04em;cursor:pointer;text-decoration:none;
  transition:transform .15s ease,box-shadow .15s ease,opacity .15s ease}
.report-page .btn-sm:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.report-page .btn-sm:focus-visible{outline:2px solid var(--gold,#C9A24B);outline-offset:2px}
.report-page .btn-sm:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}

.report-table{width:100%;border-collapse:collapse}
.report-table th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-60,#6b665e);padding:10px;border-bottom:2px solid var(--navy,#43658b)}
.report-table td{padding:10px;font-size:.88rem;border-bottom:1px solid rgba(0,0,0,.07)}
.report-table .num{text-align:right;white-space:nowrap}
.report-empty{text-align:center;color:var(--ink-30,#999);padding:28px 10px}
.report-totals{margin-top:16px;display:grid;gap:6px}
.report-totals p{margin:0;display:flex;justify-content:space-between;gap:16px;font-weight:600;border-top:1px solid rgba(0,0,0,.08);padding-top:6px}

@media(max-width:640px){
  .report-actions{margin-left:0;width:100%}
  .report-actions .btn-sm,.report-head .btn-sm{flex:1 1 100%}
  .report-filters label,.report-filters select,.report-filters input[type=date]{width:100%}
}
@media print{
  .sidebar,.topbar,form,button,.accounting-tabs,.report-actions,.report-head .btn-sm{display:none!important}
  .main{margin:0!important}
  table{font-size:10pt}tr{break-inside:avoid}thead{display:table-header-group}
}
</style>

<div class="report-page">
  <div class="report-head">
    <div>
      <h2 class="report-title"><?= h($staff_parish_name) ?> &mdash; <?= h(t($title)) ?></h2>
      <p class="report-meta">Period: <?= h($dateFrom) ?> to <?= h($dateTo) ?> &middot; Generated <?= h(date('Y-m-d g:i A')) ?></p>
    </div>
    <button type="button" class="btn-sm btn-gold" onclick="window.print()">Print / Export PDF</button>
  </div>

  <form method="get" class="filter-bar card-body report-filters">
    <label><?= h(t('From')) ?>
      <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
    </label>
    <label><?= h(t('To')) ?>
      <input type="date" name="date_to" value="<?= h($dateTo) ?>">
    </label>
    <label><?= h(t('Parish')) ?>
      <select name="parish_id"><option value="<?= (int)$user['parish_id'] ?>"><?= h($staff_parish_name) ?></option></select>
    </label>
    <label>Type
      <select name="document_type">
        <option value=""><?= h(t('All')) ?></option>
        <?php foreach (ACCOUNTING_REPORT_TYPES as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $docType === $key ? 'selected' : '' ?>><?= h(t($label)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= h(t('Status')) ?>
      <select name="status">
        <option value=""><?= h(t('All')) ?></option>
        <?php foreach (['draft', 'completed', 'refunded'] as $st): ?>
          <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= h(t(ucfirst($st))) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="report-actions">
      <button type="submit" class="btn-sm btn-navy"><?= h(t('Filter')) ?></button>
      <button type="submit" class="btn-sm btn-green" name="export" value="csv"><?= h(t('Export CSV')) ?></button>
    </div>
  </form>

  <?php $hideFinancialFilter = true; require APP_ROOT . '/includes/financial_summary.php'; ?>

  <section class="card">
    <div class="card-body tbl-wrap">
      <table class="report-table">
        <thead>
          <tr>
            <th><?= h(t('Reference')) ?></th>
            <th><?= h(t('Date')) ?></th>
            <th><?= h(t('Payee / payer')) ?></th>
            <th>Type of service</th>
            <th class="num"><?= h(t('Amount')) ?></th>
            <th><?= h(t('Status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="report-empty">No records found for this period.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= h($row['document_number']) ?></td>
              <td><?= h($row['document_date']) ?></td>
              <td><?= h($row['party_name']) ?></td>
              <td><?= h($row['service_name'] ?? 'Not applicable') ?></td>
              <td class="num"><?= h($row['amount']) ?></td>
              <td><?= h(t(ucfirst($row['status']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totals): ?>
        <div class="report-totals">
          <?php foreach ($totals as $type => $cents): ?>
            <p><span><?= h(t('Total') . ' — ' . t(ACCOUNTING_REPORT_TYPES[$type])) ?></span><span><?= number_format($cents / 100, 2) ?></span></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>