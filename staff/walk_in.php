<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/application_revisions.php';
$notice='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $GLOBALS['new_uploads']=[];$GLOBALS['after_commit']=[];
    try {
        $result=revision_transaction(fn()=>submit_walk_in($user,$_POST,$_FILES));
        dispatch_after_commit();
        header('Location: walk_in.php?saved='.(int)$result['app_id'],true,303);exit;
    } catch(Throwable $error) {
        foreach($GLOBALS['new_uploads'] as $path) { if(is_file($path)) unlink($path); }
        http_response_code(422);$notice=$error instanceof DomainException?$error->getMessage():'Unable to submit application.';
    }
}
$services=$conn->execute_query("SELECT id,name,fee FROM services WHERE parish_id=? AND status='active'",[$user['parish_id']])->fetch_all(MYSQLI_ASSOC);
$serviceId=(int)($_GET['service_id']??$services[0]['id']??0);
$service=sqlrow("SELECT * FROM services WHERE id=? AND parish_id=? AND status='active'",[$serviceId,$user['parish_id']]);
$members=$conn->execute_query("SELECT id,name,email FROM users WHERE role='parishioner' AND status='active' AND (parish_id=? OR id IN (SELECT user_id FROM applications WHERE parish_id=?)) ORDER BY name",[$user['parish_id'],$user['parish_id']])->fetch_all(MYSQLI_ASSOC);
$page_id='walk_in';$page_title=t('Walk-in application');require __DIR__.'/includes/layout.php';
?>
<h1><?= h(t('Walk-in application')) ?></h1><p role="status"><?= h(t($notice ?: (isset($_GET['saved'])?'Application submitted.':''))) ?></p>
<form method="get"><label><?= h(t('Service')) ?><select name="service_id"><?php foreach($services as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $serviceId===$item['id']?'selected':'' ?>><?= h($item['name']) ?> — <?= h($item['fee']) ?></option><?php endforeach; ?></select></label><button><?= h(t('Filter')) ?></button></form>
<?php if($service): ?><section class="card"><div class="card-body"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<input type="hidden" name="service_id" value="<?= $serviceId ?>"><input type="hidden" name="parish_id" value="<?= (int)$user['parish_id'] ?>">
<p><?= h(t('Parish')) ?>: <?= h($staff_parish_name) ?>; <?= h(t('Service')) ?>: <?= h($service['name']) ?>; <?= h(t('Amount')) ?>: <?= h($service['fee']) ?></p>
<div class="form-group"><label><?= h(t('Existing parishioner')) ?><select name="user_id"><option value="0"><?= h(t('New parishioner')) ?></option><?php foreach($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= h($member['name'].' — '.$member['email']) ?></option><?php endforeach; ?></select></label></div>
<?php foreach(['name'=>'Name','email'=>'Email','phone'=>'Phone'] as $key=>$label): ?><div class="form-group"><label><?= h(t($label)) ?><input name="<?= $key ?>" type="<?= $key==='email'?'email':'text' ?>"></label></div><?php endforeach; ?>
<?php if(($service['amount_mode']??'fixed')==='user_defined'): ?><label>Applicable amount<input name="amount" type="number" min="0" step="0.01" required></label><?php endif; ?><div class="form-group"><label><?= h(t('Schedule')) ?><input name="schedule" type="datetime-local" required></label></div>
<?php require APP_ROOT.'/includes/service_form.php'; ?>
<?php foreach($conn->execute_query('SELECT * FROM service_requirements WHERE service_id=?',[$serviceId]) as $requirement): ?><div class="form-group"><label><?= h($requirement['document_name']) ?><input type="file" multiple data-multiple-attachments name="req_<?= (int)$requirement['id'] ?>[]" <?= $requirement['is_required']?'required':'' ?> accept=".pdf,.png,.jpg,.jpeg"></label></div><?php endforeach; ?>
<fieldset><legend>Payment selection</legend><label>Method<select name="payment_method" required><option value="">Select payment</option><option value="cash">Cash at parish office</option></select></label><?php require_once APP_ROOT.'/includes/manual_payments.php'; $walkMethods=manual_payment_methods((int)$user['parish_id']); ?><label>Digital-bank method<select name="manual_method_id" onchange="this.form.elements.payment_method.value=this.value?'gcash':'cash'"><option value="">Cash</option><?php foreach($walkMethods as $method): ?><option value="<?= (int)$method['id'] ?>"><?= h($method['name']) ?></option><?php endforeach; ?></select></label><script>document.querySelector('[name=payment_method]').add(new Option('Digital bank / wallet','gcash'));</script><label>Digital payment reference<input name="reference_number" maxlength="100"></label><label>Receipt screenshot<input name="payment_proof" type="file" accept="image/png,image/jpeg,application/pdf"></label><p>The Bookkeeper verifies payment and issues the receipt.</p></fieldset><button class="btn-sm btn-navy"><?= h(t('Submit')) ?></button></form></div></section><?php endif; ?>
<script defer src="<?= h(app_url('assets/js/multiple-attachments.js')) ?>"></script>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
