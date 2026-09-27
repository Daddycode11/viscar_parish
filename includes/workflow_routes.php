<?php
require_once __DIR__.'/workflows.php';
require_once __DIR__.'/document_workflows.php';
$run=null;
if($area==='parishioner'&&$page==='documents'&&$action==='submit')$run=fn()=>submit_additional_document($user,$_POST,$_FILES);
if($area==='parishioner'&&$page==='apply_service'){
 if($action==='submit')$run=fn()=>submit_booking($user,$_POST,$_FILES);
 if($action==='payment')$run=fn()=>record_payment($user,$_POST);
}
if(in_array($area,['admin','staff'],true)){
 if(in_array($page,['applications','dashboard'],true)&&$action==='request_docs')$run=fn()=>request_documents($user,$_POST);
 if(in_array($page,['finance','payments','dashboard'],true)&&in_array($action,['verify','verify_payment'],true))$run=fn()=>confirm_payment($user,(int)($_POST['id']??0));
 if(in_array($page,['applications','dashboard'],true)&&in_array($action,['approve','reject','assign_schedule'],true))$run=fn()=>decide_application($user,(int)($_POST['id']??0),$action,$_POST);
 if($page==='receipts'&&$action==='generate')$run=fn()=>create_receipt($user,(int)($_POST['payment_id']??0));
 if($page==='records'&&$action==='generate_cert')$run=fn()=>issue_certificate($user,(int)($_GET['id']??$_POST['id']??0));
 if(in_array($page,['finance','payments'],true)&&$action==='refund')$run=function(){throw new DomainException('Review and complete an approved refund in Requests.');};
}
if($run){
 if($_SERVER['REQUEST_METHOD']!=='POST')fail_request('Use POST for this action.',405);
 $GLOBALS['new_uploads']=[];$GLOBALS['after_commit']=[];
 try{$conn->begin_transaction();$result=$run();$conn->commit();}
 catch(Throwable $e){$conn->rollback();foreach($GLOBALS['new_uploads'] as $path)if(is_file($path))unlink($path);if(!$e instanceof DomainException)error_log('Workflow error: '.get_class($e).' code '.(int)$e->getCode());fail_request($e instanceof DomainException?$e->getMessage():'Unable to process this request. Please retry.',422);}
 $delivery = delivery_counts();
 foreach ($GLOBALS['after_commit'] as $dispatch) {
  try {
   foreach (($dispatch() ?? []) as $channel => $count) {
    if (array_key_exists($channel, $delivery)) $delivery[$channel] += $count;
   }
  } catch (Throwable $e) {
   $result['notification_warning'] = 'Changes saved, but notification delivery could not be completed.';
   error_log('Notification delivery failed after commit.');
  }
 }
 if ($GLOBALS['after_commit']) {
  $result['delivery'] = $delivery;
  $result['notification_message'] = delivery_feedback($delivery);
  if ($delivery['sms_failed'] || $delivery['email_failed']) $result['notification_warning'] = $result['notification_message'];
 }
 header('Content-Type: application/json');echo json_encode(['ok'=>true,'success'=>true]+$result);exit;
}
