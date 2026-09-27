<?php
$scope=$user['role']==='admin'?'1=1':'a.parish_id=?';
$args=$user['role']==='admin'?[]:[$user['parish_id']];
$deliveryRows=$conn->execute_query("SELECT a.id,a.title,d.channel,d.status,COUNT(*) total FROM announcements a JOIN announcement_deliveries d ON d.announcement_id=a.id WHERE $scope GROUP BY a.id,a.title,d.channel,d.status ORDER BY a.id DESC LIMIT 100",$args)->fetch_all(MYSQLI_ASSOC);
?>
<section class="card"><div class="card-body"><h2><?= h(t('Delivery status')) ?></h2><p><?= h(t('Accepted means the provider accepted the message; it does not confirm delivery. Simulated messages are local tests only.')) ?></p><table>
<tr><th><?= h(t('Title')) ?></th><th><?= h(t('Channel')) ?></th><th><?= h(t('Status')) ?></th><th><?= h(t('Total')) ?></th></tr>
<?php foreach($deliveryRows as $row): ?><tr><td>#<?= (int)$row['id'] ?> <?= h($row['title']) ?></td><td><?= h($row['channel']) ?></td><td><?= h(t(ucfirst($row['status']))) ?></td><td><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
</table></div></section>
