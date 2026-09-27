<?php
require_once __DIR__.'/workflows.php';
function request_documents(array $actor,array $input):array {
 global $conn;
 must($actor['role']==='secretary','Secretary access required.');
 $a=owned_application((int)($input['id']??0),$actor,true);
 must(in_array($a['status'],['pending','approved'],true),'This application is closed.');
 $message=trim($input['message']??$input['docs']??'');$docs=trim($input['docs']??$message);
 must($message!==''&&strlen($message)<=2000&&strlen($docs)<=2000,'Describe the required documents in at most 2,000 characters.');
 $conn->execute_query('INSERT INTO application_document_requests(application_id,documents,message)VALUES(?,?,?)',[$a['id'],$docs,$message]);
 notify($a['user_id'],'Additional documents required','Booking #'.$a['id'].': '.$message,'application','documents.php');
 auditLog($actor['id'],'request_documents','application',$a['id']);
 $GLOBALS['after_commit'][]=fn()=>dispatch_to_user($a['user_id'],'Additional documents required',$message,['sms','email'],'application');
 return ['message'=>'Document request saved.'];
}
function submit_additional_document(array $actor,array $input,array $files):array {
 global $conn;
 $request=sqlrow('SELECT * FROM application_document_requests WHERE id=?',[(int)($input['request_id']??0)]);
 must($request!==null,'Document request not found.');
 $a=owned_application((int)$request['application_id'],$actor,true);
 must($actor['role']==='parishioner'&&in_array($a['status'],['pending','approved'],true),'Document submission is not available.');
 $request=sqlrow('SELECT * FROM application_document_requests WHERE id=? FOR UPDATE',[$request['id']]);
 must(!$request['submitted_at'],'This request already has a submission.');
 $saved=save_attachment_groups(validate_attachment_groups(['document'=>['required'=>true,'label'=>$request['documents']]],$files));
 foreach($saved['rows'] as &$row)$row['key']='additional_'.$request['id'];unset($row);
 insert_attachment_rows((int)$a['id'],$saved['rows']);
 $uploaded=json_decode($a['uploaded_files']??'{}',true)?:[];$uploaded['additional_'.$request['id']]??=$saved['primary']['document'];
 $conn->execute_query('UPDATE applications SET uploaded_files=? WHERE id=?',[json_encode($uploaded),$a['id']]);
 $conn->execute_query('UPDATE application_document_requests SET submitted_at=NOW() WHERE id=?',[$request['id']]);
 notifyParishStaff($a['parish_id'],'Additional document submitted','Booking #'.$a['id'].' has a new document for review.','application','applications.php');
 auditLog($actor['id'],'submit_document','application',$a['id']);return ['message'=>'Document submitted for staff review.'];
}
