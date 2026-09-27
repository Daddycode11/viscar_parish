<?php
require_once __DIR__.'/auth.php';
function fail_request(string $message, int $status=403): never {
 $message=t($message);
 http_response_code($status);header('Content-Type: application/json');
 echo json_encode(['ok'=>false,'success'=>false,'message'=>$message,'msg'=>$message,'error'=>$message]);exit;
}
$user=currentUser();
header('Cache-Control: no-store');
if(!$user){if(isset($_GET['ajax']))fail_request('Please sign in.',401);header('Location: '.app_url('public/login.php'));exit;}
$route=str_replace('\\','/',substr(realpath($_SERVER['SCRIPT_FILENAME']),strlen(APP_ROOT)+1));
$area=explode('/',$route)[0];$page=basename($route,'.php');
if($area==='admin'&&$user['role']!=='admin')fail_request('Administrator access required.');
if($area==='parishioner'&&$user['role']!=='parishioner')fail_request('Parishioner access required.');
if($area==='staff'&&!in_array($user['role'],['secretary','bookkeeper'],true))fail_request('Staff access required.');
$scopeParish=(int)($user['parish_id']??0);
if($area==='staff'&&!$scopeParish)fail_request('Assign a parish before accessing staff services.');
$action=$_GET['ajax']??$_POST['_action']??'';
if ($area==='admin' && in_array($page,['applications','finance','dashboard'],true) && in_array($action,['approve','reject','assign_schedule','request_docs','verify','verify_payment','refund'],true)) fail_request('Parish operations are read-only for administrators.');
if ($area==='staff' && $page==='parishioners' && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') fail_request('Secretary parishioner access is view-only.');
if($area==='staff'){
 $financial=['payments','finance','receipts','export','accounting','accounting_report'];$operational=['services','records','applications','approve_application','schedule','faq','announcements','checkin','parishioners','messages','walk_in','record_application','application_details','petty_cash'];
 if(in_array($page,$financial,true)&&$user['role']!=='bookkeeper')fail_request('Bookkeeper access required.');
 if(in_array($page,$operational,true)&&$user['role']!=='secretary')fail_request('Secretary access required.');
 if($page==='dashboard'&&in_array($action,['approve','reject','assign_schedule','request_docs'],true)&&$user['role']!=='secretary')fail_request('Secretary access required.');
 if($page==='dashboard'&&$action==='verify_payment'&&$user['role']!=='bookkeeper')fail_request('Bookkeeper access required.');
}
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$mutating=in_array($action,['toggle_archive','generate_cert','mark_read','mark_all_read'],true);
if($mutating&&($_SERVER['REQUEST_METHOD']??'GET')!=='POST')fail_request('Use POST for this action.',405);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $token=$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['_csrf']??'';
 if(!is_string($token)||!hash_equals($_SESSION['csrf'],$token))fail_request('Session verification failed. Reload the page and retry.');
}
if ($area === 'admin' && in_array($action, ['refund','assign_schedule'], true)) fail_request('Refund and reschedule management is restricted to the assigned staff role.');
if ($area === 'admin' && $page === 'requests') fail_request('Request management is restricted to the assigned staff role.');
if ($area==='admin' && (in_array($page,['main_database','backup'],true) || ($page==='applications' && in_array($action,['get_app','get'],true)))) require_sensitive_verification($user);
// Verify only the sensitive areas named in the client feedback, including their actions.
$sensitivePages = $user['role'] === 'secretary'
    ? ['applications', 'approve_application', 'records', 'record_application','application_details','petty_cash']
    : ['accounting', 'accounting_report', 'receipts', 'export'];
$sensitiveDashboardAction = $user['role'] === 'secretary' && $page === 'dashboard'
    && in_array($action, ['approve', 'reject', 'request_docs', 'assign_schedule', 'view_application'], true);
if ($area === 'staff' && (in_array($page, $sensitivePages, true) || $sensitiveDashboardAction)) {
    require_sensitive_verification($user);
}
if($area==='admin'&&$page==='announcements'&&$action==='send_announcement'){
 $target=$_POST['target']??'';$channel=$_POST['channel']??'';
 if(!is_string($target)||!in_array($channel,['In-App','SMS','Email','All Channels'],true)||trim($_POST['subject']??'')===''||trim($_POST['message']??'')===''||strlen($_POST['subject']??'')>255||strlen($_POST['message']??'')>10000)fail_request('Enter a subject, message and valid channel.',422);
 if(!in_array($target,['All Parishes','All Parishioners','All Staff','Staff Only','Selected Parishes'],true)&&!$conn->execute_query("SELECT id FROM parishes WHERE name=? AND status='active'",[$target])->fetch_assoc())fail_request('Select a valid audience.',422);
}
if($area==='admin'&&$page==='users'&&in_array($action,['add_staff','edit_user'],true)){
 if(!trim($_POST['name']??'')||!filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL)||!in_array($_POST['role']??'', ['admin','secretary','bookkeeper','parishioner'],true))fail_request('Enter a valid name, email and role.',422);
 if($action==='add_staff'&&strlen($_POST['password']??'')<8)fail_request('Use at least eight password characters.',422);
 if(in_array($_POST['role'],['secretary','bookkeeper'],true)&&!$conn->execute_query("SELECT id FROM parishes WHERE id=? AND status='active'",[(int)($_POST['parish_id']??0)])->fetch_assoc())fail_request('Staff must have an active assigned parish.',422);
}
// Reject direct object access before legacy handlers execute. Read lists are scoped in their SQL.
if($area==='staff'&&$page==='schedule'&&in_array($action,['create_event','update_event'],true)){
 $eventValue=str_replace('T',' ',trim($_POST['event_date']??''));
 if(strlen($eventValue)===16)$eventValue.=':00';
 $eventDate=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$eventValue);
 if(!$eventDate||$eventDate->format('Y-m-d H:i:s')!==$eventValue||!trim($_POST['title']??'')||strlen($_POST['title']??'')>255)fail_request('Enter a title and valid event date and time.',422);
 $_POST['event_date']=$eventValue;
}
if($area==='staff'&&$page==='parishioners'&&$action==='update'){
 if(!trim($_POST['name']??'')||!filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL))fail_request('Enter a valid name and email.',422);
 if((int)($_POST['parish_id']??0)!==$scopeParish)fail_request('Only administrators can transfer members between parishes.');
}
if($area==='staff'&&$page==='parishioners'&&$action==='update_status'&&!in_array($_POST['status']??'', ['active','suspended'],true))fail_request('Select a valid status.',422);
if($area==='staff'){
 $id=(int)($_POST['id']??$_GET['id']??0);$table=null;$column='parish_id';
 $map=['applications'=>'applications','approve_application'=>'applications','records'=>'sacramental_records','schedule'=>'events','faq'=>'faqs','announcements'=>'announcements','parishioners'=>'users'];
 if(isset($map[$page]))$table=$map[$page];
 if($page==='records'&&$action==='from_application')$table='applications';
 if($page==='dashboard'&&$action!=='stats')$table=$action==='verify_payment'?'payments':'applications';
 if(in_array($page,['payments','receipts'],true))$table=$page==='payments'?'payments':'receipts';
 if($page==='receipts'&&isset($_POST['payment_id'])){$id=(int)$_POST['payment_id'];$table='payments';}
 if($page==='services'){
  $table='services';
  if(isset($_POST['service_id']))$id=(int)$_POST['service_id'];
  elseif(in_array($action,['delete_field','update_field'],true))$table='service_fields';
  elseif(in_array($action,['delete_requirement','update_requirement'],true))$table='service_requirements';
 }
 if($id&&$table){
  $sql=match($table){
   'payments'=>"SELECT p.id FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.id=? AND a.parish_id=?",
   'receipts'=>"SELECT r.id FROM receipts r JOIN payments p ON p.id=r.payment_id JOIN applications a ON a.id=p.application_id WHERE r.id=? AND a.parish_id=?",
   'service_fields','service_requirements'=>"SELECT f.id FROM $table f JOIN services s ON s.id=f.service_id WHERE f.id=? AND s.parish_id=?",
   default=>"SELECT id FROM $table WHERE id=? AND parish_id=?"
  };
  if(!$conn->execute_query($sql,[$id,$scopeParish])->fetch_assoc())fail_request('Record not available.',404);
 }
 if(!empty($_POST['application_id'])&&!$conn->execute_query('SELECT id FROM applications WHERE id=? AND parish_id=?',[(int)$_POST['application_id'],$scopeParish])->fetch_assoc())fail_request('Application not available.',404);
}
// Add tokens to existing forms and same-origin AJAX without changing their layouts.
if(!isset($_GET['ajax']))ob_start(function($html){
 if(!preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i',$html))return $html;
 $token=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES);
 $html=preg_replace_callback('/<form\b[^>]*>/i', function ($match) use ($token) {
  if (!preg_match('/\bmethod\s*=\s*["\']?post\b/i', $match[0])) return $match[0];
  return $match[0].'<input type="hidden" name="_csrf" value="'.$token.'">';
 }, $html);
 $script='<script>window.csrfToken='.json_encode($_SESSION['csrf']).';const originalFetch=window.fetch;window.fetch=function(input,options){options=options||{};const u=new URL(typeof input==="string"?input:input.url,location.href);if(u.origin===location.origin){if(["toggle_archive","generate_cert","mark_read","mark_all_read"].includes(u.searchParams.get("ajax")))options.method="POST";options.headers=new Headers(options.headers||(input instanceof Request?input.headers:{}));options.headers.set("X-CSRF-Token",window.csrfToken);}return originalFetch(input,options);};const xo=XMLHttpRequest.prototype.open,xs=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m,u){this.sameOrigin=new URL(u,location.href).origin===location.origin;return xo.apply(this,arguments);};XMLHttpRequest.prototype.send=function(){if(this.sameOrigin)this.setRequestHeader("X-CSRF-Token",window.csrfToken);return xs.apply(this,arguments);};</script>';
 return preg_replace('/<head>/i','<head>'.$script,$html,1);
});
