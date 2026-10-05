<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/notifications.php';
// In-app reminder and delivery marker commit together; transport is attempted afterwards.
$rows=$conn->query("SELECT id,user_id,schedule FROM applications WHERE status='approved' AND checked_in_at IS NULL AND schedule>NOW() AND schedule<=DATE_ADD(NOW(),INTERVAL 1 DAY)");$count=0;
foreach($rows as $a){
 $conn->begin_transaction();
 try{$conn->execute_query('INSERT IGNORE INTO reminder_deliveries(application_id,schedule)VALUES(?,?)',[$a['id'],$a['schedule']]);if(!$conn->affected_rows){$conn->rollback();continue;}
 $message='Booking #'.$a['id'].' is scheduled for '.display_datetime($a['schedule']).'.';notify($a['user_id'],'Event Reminder',$message,'schedule','dashboard.php');$conn->commit();$count++;
 $delivery=dispatch_to_user($a['user_id'],'Event Reminder',$message,['sms','email'],'schedule');
 auditLog($a['user_id'],'reminder_delivery','application',$a['id'],json_encode($delivery));
 echo 'Booking #'.$a['id'].': '.delivery_feedback($delivery).PHP_EOL;
 }catch(Throwable $e){$conn->rollback();error_log('Reminder processing failed; inspect configuration and delivery audit.');}
}
echo "$count reminders recorded.\n";
