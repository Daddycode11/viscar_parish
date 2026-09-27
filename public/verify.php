<?php
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/icons.php';
require_once __DIR__.'/../includes/navigation.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
$valid=false;$message='This code could not be verified.';
if(isset($_GET['app'],$_GET['parish'],$_GET['token'])){
 $a=$conn->execute_query('SELECT id,parish_id,qr_code,status FROM applications WHERE id=? AND parish_id=?',[(int)$_GET['app'],(int)$_GET['parish']])->fetch_assoc();
 $valid=$a&&is_string($_GET['token'])&&$a['qr_code']&&hash_equals($a['qr_code'],$_GET['token']);
 if($valid)$message='Booking #'.(int)$a['id'].' is recorded. Status: '.$a['status'].'. Staff must verify attendance at check-in.';
}elseif(isset($_GET['cert'])&&is_string($_GET['cert'])){
 $r=$conn->execute_query("SELECT certificate_number,record_type,date_of_sacrament FROM sacramental_records WHERE certificate_number=? AND status='active'",[$_GET['cert']])->fetch_assoc();
 $valid=(bool)$r;if($valid)$message="Certificate {$r['certificate_number']} is valid. Sacrament: {$r['record_type']}. Date: {$r['date_of_sacrament']}. Compare these details with the presented certificate.";
}
http_response_code($valid?200:404);
?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify parish document</title></head><body style="font:18px Arial;background:#FAF7F2;padding:10%;color:#1B2A4A"><?= navigation_controls() ?><h1><?= $valid?'Verified':'Not verified' ?></h1><p><?=htmlspecialchars($message)?></p></body></html>
