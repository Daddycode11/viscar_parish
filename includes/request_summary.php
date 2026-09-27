<?php
if ($user['role'] !== 'admin') { return; }
$requestSummary = $conn->query('SELECT request_type,status,COUNT(*) total FROM application_requests GROUP BY request_type,status')->fetch_all(MYSQLI_ASSOC);
?>
<section class="card"><div class="card-body"><h2><?= h(t('Request summaries')) ?></h2><table>
<tr><th><?= h(t('Refunds')) ?> / <?= h(t('Reschedules')) ?></th><th><?= h(t('Status')) ?></th><th><?= h(t('Total')) ?></th></tr>
<?php foreach($requestSummary as $summary): ?><tr><td><?= h(t($summary['request_type']==='refund'?'Refunds':'Reschedules')) ?></td><td><?= h(t(ucfirst($summary['status']))) ?></td><td><?= (int)$summary['total'] ?></td></tr><?php endforeach; ?>
</table></div></section>
