<section class="card"><div class="card-body"><h2><?= h(t('Application details')) ?></h2>
<?php foreach($conn->execute_query('SELECT a.id,a.source,s.name FROM applications a JOIN services s ON s.id=a.service_id WHERE a.user_id=? ORDER BY a.id DESC',[$user['id']]) as $booking): ?>
<p><a href="application.php?id=<?= (int)$booking['id'] ?>">#<?= (int)$booking['id'] ?> <?= h($booking['name']) ?> — <?= h(t('Verification QR code')) ?></a> <?= $booking['source']==='walk_in'?h(t('Walk-in application')):'' ?></p>
<?php endforeach; ?><a href="faq.php"><?= h(t('Help and FAQ')) ?></a></div></section>
