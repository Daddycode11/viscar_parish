<?php
require_once __DIR__.'/financial_totals.php';
try {
    $financialFrom=input_text($_GET,'date_from')?:($analyticsFrom??date('Y-m-01'));
    $financialTo=input_text($_GET,'date_to')?:($analyticsTo??date('Y-m-d'));
    $financial=financial_totals($financialFrom,$financialTo,$user['role']==='admin'?0:(int)$user['parish_id']);
} catch (DomainException $e) { fail_request($e->getMessage(),422); }
?>
<section class="card"><div class="card-body"><h2>Financial overview</h2>
<form method="get" class="accounting-tabs"><label>From <input type="date" name="date_from" value="<?= h($financialFrom) ?>" required></label><label>To <input type="date" name="date_to" value="<?= h($financialTo) ?>" required></label><button class="btn-sm btn-navy">Apply period</button></form>
<div class="tbl-wrap"><table><thead><tr><th>Verified Revenue</th><th>Refunds</th><th>Checks</th><th>Petty Cash</th><th>Disbursements</th><th>Net Revenue</th></tr></thead><tbody><tr><?php foreach($financial as $amount): ?><td><?= number_format($amount,2) ?></td><?php endforeach; ?></tr></tbody></table></div>
<?php if($user['role']==='admin'): ?><h3>Completed refunds by parish</h3><div class="tbl-wrap"><table><thead><tr><th>Parish</th><th>Refund transactions</th><th>Amount</th></tr></thead><tbody><?php foreach(refund_totals_by_parish($financialFrom,$financialTo) as $refund): ?><tr><td><?= h($refund['parish']) ?></td><td><?= (int)$refund['transactions'] ?></td><td><?= number_format((float)$refund['amount'],2) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></section>
