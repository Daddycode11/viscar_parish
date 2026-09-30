<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflows.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $url=trim($_POST['code']??'');parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);
  must(isset($q['app'],$q['parish'],$q['token'])&&is_string($q['token']),'Scan or paste the complete booking QR URL.');
  $conn->begin_transaction();$a=owned_application((int)$q['app'],$user,true);
  must((int)$q['parish']===(int)$a['parish_id']&&hash_equals($a['qr_code']??'',$q['token']),'Invalid booking code.');
  if (($_POST['intent']??'')==='open') { $conn->commit(); require_sensitive_verification($user); header('Location: application_details.php?id='.$a['id']);exit; }
  must($a['status']==='approved','Only approved bookings can check in.');must(substr($a['schedule'],0,10)===date('Y-m-d'),'Check-in is available on the scheduled event day.');must(!$a['checked_in_at'],'This booking has already checked in.');
  $conn->execute_query('UPDATE applications SET checked_in_at=NOW(),checked_in_by=? WHERE id=?',[$user['id'],$a['id']]);auditLog($user['id'],'check_in','application',$a['id']);$conn->commit();$notice='Booking #'.$a['id'].' checked in.';
 }catch(Throwable $e){$conn->rollback();$notice=$e instanceof DomainException?$e->getMessage():'Unable to check in.';}
}
$page_title='Booking Check-in';$page_id='checkin';require __DIR__.'/includes/layout.php';
?><div class="card"><div class="card-body"><h2>Booking check-in</h2><p><?=htmlspecialchars($notice??'Scan a booking code to open its application.')?></p><form method="post"><div class="form-group"><label>Booking QR URL</label><input name="code" id="bookingCode" required autofocus placeholder="Scan with a USB scanner or paste the QR URL"></div><button class="btn-sm btn-navy" name="intent" value="open">Open application</button></form><p><button type="button" class="btn-sm btn-outline" id="cameraButton">Scan using camera</button></p><video id="camera" autoplay playsinline style="max-width:100%;width:400px"></video><p id="cameraStatus"></p></div></div>
<script>document.getElementById('cameraButton').onclick=async()=>{const status=document.getElementById('cameraStatus');try{if(!('BarcodeDetector'in window))throw Error('Camera scanning is unavailable in this browser. Use a USB scanner or paste the QR URL.');const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});const video=document.getElementById('camera');video.srcObject=stream;const detector=new BarcodeDetector({formats:['qr_code']});const tick=setInterval(async()=>{try{const codes=await detector.detect(video);if(codes.length){document.getElementById('bookingCode').value=codes[0].rawValue;clearInterval(tick);stream.getTracks().forEach(t=>t.stop());status.textContent='Code captured. Select Open application.';}}catch(e){}},500);}catch(e){status.textContent=e.message;}};</script>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
