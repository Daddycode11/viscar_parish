<?php
require_once __DIR__.'/workflows.php';
function manual_payment_methods(int $parish): array {
    global $conn;
    return $conn->execute_query('SELECT id,name,instructions FROM parish_payment_methods WHERE parish_id=? AND active=1 ORDER BY name',[$parish])->fetch_all(MYSQLI_ASSOC);
}
function save_manual_method(array $actor,array $input,array $files): void {
    global $conn;
    must($actor['role']==='secretary','Secretary access required.');
    if(($input['_action']??'')==='retire'){
        $conn->execute_query('UPDATE parish_payment_methods SET active=0 WHERE id=? AND parish_id=?',[(int)($input['id']??0),$actor['parish_id']]);return;
    }
    $name=input_text($input,'name',100);$instructions=input_text($input,'instructions',2000);
    must($name!==''&&$instructions!=='','Enter a method name and payment instructions.');
    $validated=validate_uploaded_files(['qr'=>['required'=>true,'label'=>'Payment QR image']],$files);
    must(in_array(strtolower(pathinfo($validated['qr']['name'],PATHINFO_EXTENSION)),['jpg','jpeg','png'],true),'Upload a PNG or JPG QR image.');
    $saved=save_uploaded_files($validated);
    $conn->execute_query('INSERT INTO parish_payment_methods(parish_id,name,instructions,qr_file,created_by) VALUES(?,?,?,?,?)',[$actor['parish_id'],$name,$instructions,$saved['qr'],$actor['id']]);
    auditLog($actor['id'],'create_payment_method','parish_payment_method',$conn->insert_id);
}
