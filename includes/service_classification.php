<?php
require_once __DIR__.'/application_revisions.php';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='classify_service') {
    $type=input_text($_POST,'sacrament_type');
    $id=(int)($_POST['service_id']??0);
    if ($type!=='' && !in_array($type,SACRAMENT_TYPES,true)) { fail_request('Invalid sacrament type.',422); }
    if (!sqlrow('SELECT id FROM services WHERE id=? AND parish_id=?',[$id,$user['parish_id']])) { fail_request('Service not found.',404); }
    revision_transaction(function() use($conn,$type,$id,$user){
        $conn->execute_query('UPDATE services SET sacrament_type=? WHERE id=? AND parish_id=?',[$type?:null,$id,$user['parish_id']]);
        auditLog($user['id'],'classify_sacrament','service',$id);
    });
    header('Location: services.php',true,303);exit;
}
