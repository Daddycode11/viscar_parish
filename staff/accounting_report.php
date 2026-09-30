<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/accounting.php';
try { $rows=accounting_rows($user,$_GET); }
catch (DomainException $error) { fail_request($error->getMessage(),422); }
$totals=[];
foreach($rows as $row) { $totals[$row['document_type']]=($totals[$row['document_type']]??0)+money_cents($row['amount']); }
if (($_GET['export']??'')==='csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="accounting-'.date('Ymd').'.csv"');
    header('Cache-Control: no-store');
    $out=fopen('php://output','w');
    report_csv($out,['Type','Number','Date','Payee / payer','Amount','Description','Reference','Status','Parish']);
    foreach($rows as $row) { report_csv($out,[ACCOUNTING_REPORT_TYPES[$row['document_type']],$row['document_number'],$row['document_date'],$row['party_name'],$row['amount'],$row['description'],$row['reference'],$row['status'],$row['parish_id']]); }
    foreach($totals as $type=>$cents) { report_csv($out,['TOTAL '.ACCOUNTING_REPORT_TYPES[$type],'','','',number_format($cents/100,2,'.','')]); }
    exit;
}
$page_id='export';$page_title=t('Export Reports');require __DIR__.'/includes/layout.php';
?>
<div class="sec-head"><div><div class="sec-tag">Reports &amp; Export</div><h1 class="sec-title">Reports</h1><p class="sec-sub">Financial reports for your assigned parish.</p></div></div>
<style>@media print{.sidebar,.topbar,form,button,.accounting-tabs{display:none!important}.main{margin:0!important}table{font-size:10pt}tr{break-inside:avoid}thead{display:table-header-group}}</style><h1><?= h($staff_parish_name) ?> ? <?= h(ACCOUNTING_REPORT_TYPES[$_GET['document_type']??'']??'Accounting Report') ?></h1><p>Period: <?= h($_GET['date_from']??date('Y-m-01')) ?> to <?= h($_GET['date_to']??date('Y-m-d')) ?> ? Generated <?= h(date('Y-m-d H:i')) ?></p><button onclick="print()">Print / Export PDF</button><form method="get" class="filter-bar card-body">
<label><?= h(t('From')) ?><input type="date" name="date_from" value="<?= h($_GET['date_from']??date('Y-m-01')) ?>"></label>
<label><?= h(t('To')) ?><input type="date" name="date_to" value="<?= h($_GET['date_to']??date('Y-m-d')) ?>"></label>
<label><?= h(t('Parish')) ?><select name="parish_id"><option value="<?= (int)$user['parish_id'] ?>"><?= h($staff_parish_name) ?></option></select></label>
<select name="document_type"><option value=""><?= h(t('All')) ?></option><?php foreach(ACCOUNTING_REPORT_TYPES as $key=>$label): ?><option value="<?= $key ?>" <?= ($_GET['document_type']??'')===$key?'selected':'' ?>><?= h(t($label)) ?></option><?php endforeach; ?></select>
<select name="status"><option value=""><?= h(t('All')) ?></option><?php foreach(['draft','completed','refunded'] as $status): ?><option value="<?= $status ?>" <?= ($_GET['status']??'')===$status?'selected':'' ?>><?= h(t(ucfirst($status))) ?></option><?php endforeach; ?></select>
<button><?= h(t('Filter')) ?></button><button name="export" value="csv"><?= h(t('Export CSV')) ?></button></form>
<?php require APP_ROOT . '/includes/financial_summary.php'; ?><section class="card"><div class="card-body tbl-wrap"><table><tr><th><?= h(t('Reference')) ?></th><th><?= h(t('Date')) ?></th><th><?= h(t('Payee / payer')) ?></th><th><?= h(t('Amount')) ?></th><th><?= h(t('Status')) ?></th></tr>
<?php foreach($rows as $row): ?><tr><td><?= h($row['document_number']) ?></td><td><?= h($row['document_date']) ?></td><td><?= h($row['party_name']) ?></td><td><?= h($row['amount']) ?></td><td><?= h(t(ucfirst($row['status']))) ?></td></tr><?php endforeach; ?></table>
<?php foreach($totals as $type=>$cents): ?><p><?= h(t('Total').' — '.t(ACCOUNTING_REPORT_TYPES[$type])) ?>: <?= number_format($cents/100,2) ?></p><?php endforeach; ?></div></section>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
