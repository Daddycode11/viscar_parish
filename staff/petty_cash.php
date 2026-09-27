<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/accounting.php';
$notice='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        revision_transaction(function() use($user) {
            must(($_POST['_action']??'')==='replenish','Invalid action.');
            // Lock in the same order as voucher creation, then recheck the submitted set.
            $current=current_petty_cash_set($user);
            must((int)($_POST['set_id']??0)===$current,'This set has changed. Reload before replenishing.');
            replenish_petty_cash($user);
        });
        header('Location: petty_cash.php?saved=1',true,303);exit;
    } catch(Throwable $e) {
        http_response_code(422);
        $notice=$e instanceof DomainException?$e->getMessage():'Unable to replenish petty cash.';
    }
}
$set=sqlrow("SELECT id FROM petty_cash_sets WHERE parish_id=? AND status='open' ORDER BY id DESC",[$user['parish_id']]);
$rows=$set?$conn->execute_query('SELECT document_number,document_date,description,amount,status FROM accounting_documents WHERE petty_cash_set=? AND parish_id=? ORDER BY id DESC',[$set['id'],$user['parish_id']])->fetch_all(MYSQLI_ASSOC):[];
$total=0;$drafts=0;foreach($rows as $row){if(in_array($row['status'],['draft','completed'],true))$total+=money_cents($row['amount']);if($row['status']==='draft')$drafts++;}
$page_id='petty_cash';$page_title='Petty Cash Replenishment';require __DIR__.'/includes/layout.php';
?>
<section class="card"><div class="card-body"><h1>Petty Cash Replenishment</h1>
<p role="status"><?= h($notice ?: (isset($_GET['saved'])?'Replenishment recorded. A new set is available to the Bookkeeper.':'')) ?></p>
<p>Current set: <?= h($set['id']??'None') ?> · PHP <?= number_format($total/100,2) ?> of PHP 10,000.00</p>
<p>Review the completed vouchers and confirm replenishment. Outstanding drafts must be completed by the Bookkeeper first.</p>
<div class="tbl-wrap"><table><thead><tr><th>Voucher</th><th>Date</th><th>Particulars</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><?php foreach($row as $value): ?><td><?= h($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5">No vouchers in the current set.</td></tr><?php endif; ?></tbody></table></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="replenish"><input type="hidden" name="set_id" value="<?= (int)($set['id']??0) ?>">
<button class="btn-sm btn-navy" <?= !$rows||$drafts?'disabled':'' ?>>Confirm replenishment / New set</button></form>
</div></section>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
