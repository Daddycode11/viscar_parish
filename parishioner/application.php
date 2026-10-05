<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflows.php';
$application=sqlrow('SELECT a.*,s.name service_name,p.name parish_name FROM applications a JOIN services s ON s.id=a.service_id JOIN parishes p ON p.id=a.parish_id WHERE a.id=? AND a.user_id=?',[(int)($_GET['id']??0),$user['id']]);
if(!$application) { fail_request('Application not found.',404); }
// Legacy applications receive one stable token under a row lock.
if(!$application['qr_code']) {
    $application['qr_code']=revision_transaction(function() use($conn,$application){
        $row=sqlrow('SELECT qr_code FROM applications WHERE id=? FOR UPDATE',[$application['id']]);
        $token=$row['qr_code']?:generateQR('');
        $conn->execute_query('UPDATE applications SET qr_code=? WHERE id=?',[$token,$application['id']]);return $token;
    });
}
$verification=getVerificationURL($application['id'],$application['parish_id'],$application['qr_code']);
$page_title=t('Application details');require __DIR__.'/includes/layout.php';
?>
<style>@media print{.sidebar,.topbar,.no-print{display:none!important}.main{margin:0!important}.application-qr{width:45mm!important;height:45mm!important}}</style>
<section class="card"><div class="card-body"><h1><?= h(t('Application details')) ?> #<?= (int)$application['id'] ?></h1>
<p><?= h($application['parish_name'].' — '.$application['service_name']) ?></p><p><?= h(t('Status')) ?>: <?= h($application['status']) ?></p><p><?= h(t('Schedule')) ?>: <?= h(display_datetime($application['schedule'])) ?></p>
<h2><?= h(t('Verification QR code')) ?></h2><img class="application-qr" width="220" height="220" src="<?= h(getQRImageURL($verification)) ?>" alt="<?= h(t('Verification QR code')) ?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><p hidden><?= h(t('QR image unavailable. Use the verification link below.')) ?></p><p><a href="<?= h($verification) ?>"><?= h(t('Verify')) ?> #<?= (int)$application['id'] ?></a></p>
<?php require_once APP_ROOT.'/includes/application_details.php';render_application_details($application); ?>
<button class="no-print" onclick="window.print()"><?= h(t('Print')) ?></button></div></section>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
