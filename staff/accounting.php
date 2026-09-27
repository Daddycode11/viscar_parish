<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/accounting.php';
$type = input_text($_GET, 'type') ?: 'receipt';
if (!isset(ACCOUNTING_TYPES[$type])) { fail_request('Invalid document type.', 422); }
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $GLOBALS['new_uploads']=[];
    try {
        revision_transaction(function () use ($user) {
            if (($_POST['_action'] ?? '')==='replenish') { replenish_petty_cash($user); }
            elseif (($_POST['_action'] ?? '') === 'complete') {
                complete_accounting_document($user, (int) ($_POST['id'] ?? 0),$_POST);
            } else { create_accounting_document($user, $_POST); }
        });
        header('Location: accounting.php?type=' . $type . '&saved=1', true, 303); exit;
    } catch (Throwable $error) {
        foreach($GLOBALS['new_uploads'] as $file)if(is_file($file))unlink($file);
        http_response_code(422);
        error_log('Accounting save: '.get_class($error).' '.$error->getMessage());
        $notice = $error instanceof DomainException ? $error->getMessage() : 'Unable to save document.';
    }
}
if(isset($_GET['attachment'])) {
    $d=sqlrow('SELECT attachment FROM accounting_documents WHERE id=? AND parish_id=?',[(int)$_GET['attachment'],$user['parish_id']]);
    $name=$d['attachment']??'';
    if(!preg_match('/^[a-f0-9]{40}\.(pdf|png|jpe?g)$/',$name)||!is_file(private_path($name)))fail_request('Attachment not found.',404);
    header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file(private_path($name)));header('Content-Disposition: inline; filename="receipt.'.pathinfo($name,PATHINFO_EXTENSION).'"');header('X-Content-Type-Options: nosniff');readfile(private_path($name));exit;
}
if (isset($_GET['print'])) {
    $row = sqlrow('SELECT d.*,p.name parish_name,u.name creator,v.name approver FROM accounting_documents d JOIN parishes p ON p.id=d.parish_id JOIN users u ON u.id=d.created_by LEFT JOIN users v ON v.id=d.approved_by WHERE d.id=? AND d.parish_id=?', [(int)$_GET['print'],$user['parish_id']]);
    if (!$row) { fail_request('Document not found.',404); }
    header('Cache-Control: no-store');
    require __DIR__ . '/../includes/accounting_print.php'; exit;
}
$page_id = 'receipts'; $page_title = t('Accounting');
require __DIR__ . '/includes/layout.php';
?>
<h1><?= h(t('Accounting')) ?></h1><nav class="accounting-tabs" aria-label="Accounting document types">
<?php foreach (ACCOUNTING_TYPES as $key=>$label): ?><a class="btn-sm" href="accounting.php?type=<?= $key ?>" <?= $key===$type?'aria-current="page"':'' ?>><?= h(t($label)) ?></a> <?php endforeach; ?>
</nav><p role="status"><?= h(t($notice ?: (isset($_GET['saved'])?'Document saved.':''))) ?></p>
<?php if ($type==='receipt'): ?>
<section class="card"><div class="card-body"><h2><?= h(t('Official Receipt')) ?></h2><a class="btn-sm btn-navy" href="receipts.php"><?= h(t('Official Receipt')) ?> — <?= h(t('Create document')) ?> / <?= h(t('Print')) ?></a></div></section>
<?php else: ?>
<section class="card"><div class="card-body"><h2><?= h(t(ACCOUNTING_TYPES[$type])) ?></h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="document_type" value="<?= h($type) ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(32)) ?>">
<?php
$fields=['document_date'=>['Date','date'],'party_name'=>['Payee / payer','text'],'amount'=>['Amount','number'],'description'=>[$type==='deposit'?'Particulars / Source of funds':($type==='petty_cash_voucher'?'Details of purchase / expense':'Particulars'),'text'],'reference'=>[$type==='check_voucher'?'Check Number':'Reference','text']];
if($type==='journal_voucher')$fields=['document_date'=>['Date','date'],'description'=>['Corrected particulars','text']];
foreach ($fields as $key=>[$label,$inputType]): ?>
<div class="form-group"><label><?= h(t($label)) ?><input name="<?= $key ?>" type="<?= $inputType ?>" value="<?= $key==='document_date'?date('Y-m-d'):'' ?>" <?= $key!=='reference'||$type==='check_voucher'?'required':'' ?> <?= $key==='amount'?'min="0.01" step="0.01" '.($type==='check_voucher'?'max="20000"':($type==='petty_cash_voucher'?'max="500"':'')):'' ?> maxlength="<?= $key==='description'?5000:($key==='reference'?100:255) ?>"></label></div>
<?php endforeach; ?>
<?php if(in_array($type,['check_voucher','deposit'],true)): ?>
<p><?= $type==='deposit'?'Bank receiving the deposit':'Bank providing the funds' ?></p><div class="form-group"><label>Bank Name<input name="bank_name" maxlength="255" required></label></div><div class="form-group"><label>Bank Account Number<input name="bank_account_number" maxlength="100" required></label></div>
<?php endif; ?>
<?php if($type==='check_voucher'): ?><p>Maximum: PHP 20,000.00 per voucher.</p>
<?php foreach(['secretary_signatory'=>'Secretary','finance_signatory'=>'Finance VP','priest_signatory'=>'Parish Priest'] as $key=>$label): ?><div class="form-group"><label><?= h($label) ?><input name="<?= $key ?>" required maxlength="255"></label></div><?php endforeach; endif; ?>
<?php if($type==='petty_cash_voucher'): ?><p>Maximum per entry: 500.00. Maximum per set: 10,000.00. Combine receipts only for the same transaction date.</p><?php endif; ?>
<?php if($type==='journal_voucher'): ?><div class="form-group"><label>Original check voucher or receipt<select name="original_reference" required><option value="">Select original transaction</option>
<?php foreach(accounting_rows($user,['date_from'=>'2000-01-01','date_to'=>'2100-12-31']) as $original): if(!in_array($original['document_type'],['receipt','check_voucher'],true)) continue; ?><option value="<?= $original['document_type']==='receipt'?'receipt':'document' ?>:<?= (int)$original['id'] ?>"><?= h($original['document_number']) ?></option><?php endforeach; ?></select></label></div><div class="form-group"><label>Reason for correction<textarea name="correction_reason" required minlength="5" maxlength="2000"></textarea></label></div><p>Only particulars are corrected. Amounts and original records remain unchanged.</p><?php else: ?>
<div class="form-group"><label>Receipt / Attachment (PDF, JPG, PNG; up to 5 MB)<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"></label></div><?php endif; ?><button class="btn-sm btn-navy"><?= h(t('Create document')) ?></button></form></div></section>
<?php endif;
if($type==='petty_cash_voucher') {
 $set=sqlrow("SELECT id FROM petty_cash_sets WHERE parish_id=? AND status='open' ORDER BY id DESC LIMIT 1",[$user['parish_id']]);
 $used=$set?sqlrow("SELECT COALESCE(SUM(amount),0) amount FROM accounting_documents WHERE petty_cash_set=? AND status IN ('draft','completed')",[$set['id']])['amount']:0;
 echo '<p>Current set: '.h($set['id']??'New').' · PHP '.number_format((float)$used,2).' / 10,000.00. Ask the parish Secretary to confirm replenishment after outstanding drafts are completed.</p>';
}
$rows=accounting_rows($user,['document_type'=>$type,'date_from'=>'2000-01-01','date_to'=>'2100-12-31']); ?>
<section class="card"><div class="card-body"><table><thead><tr><th><?= h(t('Reference')) ?></th><th><?= h(t('Date')) ?></th><th><?= h(t('Payee / payer')) ?></th><th><?= h(t('Amount')) ?></th><th><?= h(t('Status')) ?></th><th></th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= h($row['document_number']) ?></td><td><?= h($row['document_date']) ?></td><td><?= h($row['party_name']) ?></td><td><?= h($row['amount']) ?></td><td><?= h(t(ucfirst($row['status']))) ?></td><td>
<a href="<?= h($type==='receipt'?app_url('public/receipt.php?id='.$row['id']):'accounting.php?print='.$row['id']) ?>"><?= h(t('Print')) ?></a>
<?php if($type!=='receipt' && $row['status']==='draft'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="complete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><?php if(in_array($type,['check_voucher','deposit'],true)): foreach(['bank_name','bank_account_number',...($type==='check_voucher'?['secretary_signatory','finance_signatory','priest_signatory']:[])] as $key): if(empty($row[$key])): ?><label><?= h(ucwords(str_replace('_',' ',$key))) ?><input name="<?= $key ?>" required maxlength="<?= $key==='bank_account_number'?100:255 ?>"></label><?php endif; endforeach; endif; ?><button><?= h(t('Complete document')) ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table><a href="accounting_report.php"><?= h(t('Export Reports')) ?></a></div></section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
