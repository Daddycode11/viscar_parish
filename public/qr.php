<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/vendor/qrcode.php';
$data=$_GET['data']??'';
if(!is_string($data)||strlen($data)>1000||!str_starts_with($data,app_url('public/verify.php').'?')){http_response_code(400);exit;}
header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');
(new QRCode($data,['w'=>max(120,min(400,(int)($_GET['size']??200))),'h'=>max(120,min(400,(int)($_GET['size']??200))),'p'=>16]))->output_image();
