<?php
require_once __DIR__.'/config.php';
function generateQR($data=''){return bin2hex(random_bytes(32));}
function getVerificationURL($application_id,$parish_id,$token){return app_url('public/verify.php').'?'.http_build_query(['app'=>$application_id,'parish'=>$parish_id,'token'=>$token]);}
function getQRImageURL($data,$size=200){return app_url('public/qr.php').'?'.http_build_query(['data'=>$data,'size'=>$size]);}
function generateQRSvg($data,$size=200){return '<img alt="QR Code" width="'.(int)$size.'" height="'.(int)$size.'" src="'.htmlspecialchars(getQRImageURL($data,$size),ENT_QUOTES).'">';}
