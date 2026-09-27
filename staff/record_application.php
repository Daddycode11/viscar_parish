<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflows.php';
$recordApplication=sqlrow('SELECT a.*,r.id record_id,r.certificate_number FROM sacramental_records r JOIN applications a ON a.id=r.application_id WHERE r.id=? AND r.parish_id=?',[(int)($_GET['id']??0),$user['parish_id']]);
if(!$recordApplication) { fail_request('Record not found.',404); }
$page_title=t('Application details');require __DIR__.'/includes/layout.php';
?>
<section class="card"><div class="card-body"><h1><?= h(t('Application details')) ?> #<?= (int)$recordApplication['id'] ?></h1><p><?= h($recordApplication['source']) ?></p><p><?= h($recordApplication['certificate_number']) ?></p>
<?php require_once APP_ROOT.'/includes/application_details.php';render_application_details($recordApplication); ?>
<a href="records.php"><?= h(t('Documents')) ?></a></div></section>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
