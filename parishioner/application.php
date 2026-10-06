<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflows.php';
require_once __DIR__.'/../includes/application_details.php';
$editNotice='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        revision_transaction(function() use($conn,$user) {
            $application=owned_application((int)($_GET['id']??0),$user,true);
            must($application['status']==='pending'&&!$application['checked_in_at'],'Only pending applications can be edited. Contact the Secretary for an approved application.');
            $schema=json_decode($application['form_schema']??'null',true);
            if(!is_array($schema))$schema=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$application['service_id']])->fetch_all(MYSQLI_ASSOC);
            $data=json_decode($application['form_data']??'{}',true)?:[];
            $fields=$_POST['fields']??[];must(is_array($fields),'Invalid form answers.');
            foreach($schema as $field) {
                if($field['field_type']==='file')continue;
                $value=input_text($fields,$field['field_name'],10000);
                must(!$field['is_required']||$value!=='','Required: '.$field['field_label']);
                if($value!=='')validate_field_value($field,$value);
                $data[$field['field_name']]=$value;
            }
            $conn->execute_query('UPDATE applications SET form_data=? WHERE id=?',[json_encode($data),$application['id']]);
            auditLog($user['id'],'edit_application','application',$application['id'],json_encode(['before'=>json_decode($application['form_data']??'{}',true),'after'=>$data]));
        });
        header('Location: application.php?id='.(int)($_GET['id']??0).'&saved=1',true,303);exit;
    } catch(DomainException $error) { http_response_code(422);$editNotice=$error->getMessage(); }
}
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
<p role="status"><?= h($editNotice?: (isset($_GET['saved'])?'Application answers saved.':'')) ?></p>
<?php if($application['status']==='pending'&&!$application['checked_in_at']): ?>
<details class="no-print" <?= isset($_GET['edit'])||$editNotice?'open':'' ?>><summary class="btn-sm btn-outline">Edit application</summary>
<form method="post"><?= csrf_field() ?><p>Update your answers before approval. Schedule changes and cancellations require Secretary approval.</p>
<?php $schema=json_decode($application['form_schema']??'null',true);if(!is_array($schema))$schema=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$application['service_id']])->fetch_all(MYSQLI_ASSOC);$answers=json_decode($application['form_data']??'{}',true)?:[];foreach($schema as $field):if($field['field_type']==='file')continue; ?>
<label style="display:block;margin:12px 0"><?= h($field['field_label']) ?><textarea style="display:block;width:100%;box-sizing:border-box" name="fields[<?= h($field['field_name']) ?>]" maxlength="10000" <?= $field['is_required']?'required':'' ?>><?= h($answers[$field['field_name']]??'') ?></textarea></label>
<?php endforeach; ?><button class="btn-sm btn-navy">Save application</button></form></details>
<?php endif; ?>
<button class="no-print" onclick="window.print()"><?= h(t('Print')) ?></button></div></section>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
