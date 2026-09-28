<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/application_details.php';
if($user['role']!=='secretary')fail_request('Secretary access required.');
require_sensitive_verification($user);
$id=(int)($_GET['id']??0);$notice='';
try {$application=owned_application($id,$user);}catch(DomainException $e){fail_request('Application not found.',404);}
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        revision_transaction(function() use($user,$id,$conn){
            $a=owned_application($id,$user,true);
            must(in_array($a['status'],['pending','approved'],true)&&!$a['checked_in_at'],'This application is locked.');
            $schema=json_decode($a['form_schema']??'null',true);
            if(!is_array($schema))$schema=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$a['service_id']])->fetch_all(MYSQLI_ASSOC);
            $data=json_decode($a['form_data']??'{}',true)?:[];$fields=$_POST['fields']??[];must(is_array($fields),'Invalid form answers.');
            foreach($schema as $field){if($field['field_type']==='file')continue;$key=$field['field_name'];$value=input_text($fields,$key,10000);must(!$field['is_required']||$value!=='','Required: '.$field['field_label']);if($value!=='')validate_field_value($field,$value);$data[$key]=$value;}
            $conn->execute_query('UPDATE applications SET form_data=? WHERE id=?',[json_encode($data),$id]);
            auditLog($user['id'],'correct_application','application',$id,json_encode(['before'=>json_decode($a['form_data']??'{}',true),'after'=>$data]));
        });
        header('Location: application_details.php?id='.$id.'&saved=1',true,303);exit;
    }catch(Throwable $e){http_response_code(422);error_log('Application correction: '.$e->getMessage());$notice=$e instanceof DomainException?$e->getMessage():'Unable to save corrections.';}
}
$page_id='applications';$page_title='Application Details';require __DIR__.'/includes/layout.php';
?><h1>Application Details</h1><p role="status"><?= h($notice?: (isset($_GET['saved'])?'Corrections saved.':'')) ?></p><?php render_application_details($application); ?>
<?php if(in_array($application['status'],['pending','approved'],true)&&!$application['checked_in_at']): ?>
<details class="card" id="corrections" <?= isset($_GET['edit'])?'open':'' ?>><summary>Correct application answers</summary><div class="card-body"><form method="post"><?= csrf_field() ?>
<p>Corrections are recorded in the audit history. Existing sacramental records and issued certificates retain their original information.</p>
<?php $data=json_decode($application['form_data']??'{}',true)?:[];$editSchema=json_decode($application['form_schema']??'null',true);if(!is_array($editSchema))$editSchema=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$application['service_id']])->fetch_all(MYSQLI_ASSOC);foreach($editSchema as $field): if($field['field_type']==='file')continue; ?>
<label><?= h($field['field_label']) ?><textarea name="fields[<?= h($field['field_name']) ?>]" <?= $field['is_required']?'required':'' ?> maxlength="10000"><?= h($data[$field['field_name']]??'') ?></textarea></label><?php endforeach; ?>
<button class="btn-sm btn-navy">Save corrections</button></form></div></details><?php endif; ?>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
