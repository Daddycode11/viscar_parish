<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/backup_service.php';
require_sensitive_verification($user);
$notice='';
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if($action==='manual_backup') {$file=create_system_backup($user);$notice='Backup created: '.$file;}
  elseif($action==='restore') {
   must(($_POST['confirmation']??'')==='RESTORE','Type RESTORE to confirm replacement of current records.');
   $file=$_FILES['backup_file']??[];
   must(($file['error']??-1)===UPLOAD_ERR_OK && ($file['size']??0)<=67108864 && is_uploaded_file($file['tmp_name']??'') && strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))==='zip','Upload a VISCAR ZIP backup up to 64 MB.');
   restore_system_backup($user,$file['tmp_name']);session_destroy();header('Location: '.app_url('public/login.php?restored=1'));exit;
  } else throw new DomainException('Invalid backup action.');
 } catch(Throwable $e) {http_response_code(422);error_log('Backup operation: '.$e->getMessage());$notice=$e instanceof DomainException?$e->getMessage():'Backup operation failed. Database changes were rolled back.';}
}
if(isset($_GET['download'])) {
 $name=$_GET['download'];
 if(!is_string($name)||!preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{12}\.zip$/',$name)||!is_file(private_path('backups/'.$name)))fail_request('Backup not found.',404);
 auditLog($user['id'],'download_backup','system',null,$name);header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');readfile(private_path('backups/'.$name));exit;
}
$page_id='backup';$page_title='Backup & Restore';require __DIR__.'/includes/layout.php';
?>
<h1>Backup &amp; Restore</h1><p role="status"><?= h($notice) ?></p>
<section class="card"><div class="card-body"><h2>Create backup</h2><p>Database records and uploaded documents. Configuration credentials, sessions and logs are excluded. Store downloaded backups securely.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="manual_backup"><button class="btn-sm btn-navy">Create backup</button></form></div></section>
<section class="card"><div class="card-body"><h2>Restore backup</h2><p>Replaces current database records. A recovery backup is created first. Use a backup from the same schema version. Successful restoration signs everyone out.</p><form method="post" enctype="multipart/form-data" onsubmit="return confirm('Replace current database records with this backup?')"><?= csrf_field() ?><input type="hidden" name="_action" value="restore"><label>VISCAR backup<input type="file" name="backup_file" accept=".zip" required></label><label>Type RESTORE<input name="confirmation" required pattern="RESTORE"></label><button class="btn-sm btn-outline">Restore</button></form></div></section>
<section class="card"><div class="card-body"><h2>Backup history</h2><ul><?php $files=glob(private_path('backups/backup-*.zip'))?:[];rsort($files);foreach(array_slice($files,0,100) as $file): ?><li><a href="?download=<?= h(basename($file)) ?>"><?= h(basename($file)) ?></a> - <?= number_format(filesize($file)/1024,1) ?> KB</li><?php endforeach; ?></ul><?php if(!$files): ?><p>No backups have been created.</p><?php endif; ?></div></section>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
