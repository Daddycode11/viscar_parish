<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflows.php';
if(isset($_GET['method'])){
    $row=sqlrow('SELECT m.* FROM parish_payment_methods m JOIN parishes p ON p.id=m.parish_id WHERE m.id=? AND m.active=1 AND p.status=?',[(int)$_GET['method'],'active']);
    if(!$row)fail_request('Payment method not available.',404);
    $file=$row['qr_file'];
}else{
    $row=sqlrow('SELECT p.proof_file,a.user_id,a.parish_id FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.id=?',[(int)($_GET['payment']??0)]);
    if(!$row||!$row['proof_file']||!(($user['role']==='parishioner'&&(int)$row['user_id']===(int)$user['id'])||($user['role']==='bookkeeper'&&(int)$row['parish_id']===(int)$user['parish_id'])))fail_request('Payment proof not available.',404);
    $file=$row['proof_file'];
}
$path=private_path(basename($file));if(!is_file($path))fail_request('File not found.',404);
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(!in_array($mime,['image/png','image/jpeg','application/pdf'],true))fail_request('Unsupported file.',404);
header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Content-Disposition: '.(isset($_GET['download'])?'attachment':'inline').'; filename="payment-document.'.pathinfo($path,PATHINFO_EXTENSION).'"');readfile($path);
