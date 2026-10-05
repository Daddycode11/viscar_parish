<?php
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/pdf.php';require_once __DIR__.'/../includes/qr.php';
$u=currentUser();if(!$u){http_response_code(401);exit;}
require_sensitive_verification($u);
$r=$conn->execute_query('SELECT r.*,COALESCE(p.manual_method_name,p.payment_method) payment_method,a.id app_id,a.user_id,a.parish_id,a.qr_code FROM receipts r JOIN payments p ON p.id=r.payment_id JOIN applications a ON a.id=p.application_id WHERE r.id=?',[(int)($_GET['id']??0)])->fetch_assoc();
if(!$r||!($u['role']==='admin'||($u['role']==='parishioner'?(int)$r['user_id']===(int)$u['id']:(int)$r['parish_id']===(int)$u['parish_id']&&$u['role']==='bookkeeper'))){http_response_code(404);exit;}
header('Cache-Control: no-store');echo generateReceiptHTML($r,$r['parish_name'],getQRImageURL(getVerificationURL($r['app_id'],$r['parish_id'],$r['qr_code'])));
