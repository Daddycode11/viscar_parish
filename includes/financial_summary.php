<?php
require_once __DIR__.'/financial_totals.php';
try {
    $financialFrom=input_text($_GET,'date_from')?:($analyticsFrom??date('Y-m-01'));
    $financialTo=input_text($_GET,'date_to')?:($analyticsTo??date('Y-m-d'));
    $financial=financial_totals($financialFrom,$financialTo,$user['role']==='admin'?0:(int)$user['parish_id']);
} catch (DomainException $e) { fail_request($e->getMessage(),422); }
?>
<section class="card"><div class="card-body"><h2>Period summary</h2>
<form method="get" class="filter-bar"><label>From <input type="date" name="date_from" value="<?= h($financialFrom) ?>" required></label><label>To <input type="date" name="date_to" value="<?= h($financialTo) ?>" required></label><button class="btn-sm btn-navy">Apply period</button></form>
<div class="tbl-wrap"><table><thead><tr><th>Verified Revenue</th><th>Refunds</th><th>Checks</th><th>Petty Cash</th><th>Disbursements</th><th>Net Revenue</th></tr></thead><tbody><tr><?php foreach($financial as $amount): ?><td><?= number_format($amount,2) ?></td><?php endforeach; ?></tr></tbody></table></div>
<?php if($user['role']==='admin'): ?><h3>Financial totals by parish</h3><div class="tbl-wrap"><table><thead><tr><th>Parish</th><th>Verified Revenue</th><th>Refunds</th><th>Checks</th><th>Petty Cash</th><th>Disbursements</th><th>Net Revenue</th></tr></thead><tbody><?php foreach($conn->query('SELECT id,name FROM parishes ORDER BY name') as $financialParish): $parishTotals=financial_totals($financialFrom,$financialTo,(int)$financialParish['id']); ?><tr><td><?= h($financialParish['name']) ?></td><?php foreach($parishTotals as $amount): ?><td class="money-cell"><?= number_format($amount,2) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></section>
