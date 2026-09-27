<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
$options=getopt('',['local','schema']);$failures=0;
function deployment_check(string $label,bool $ok):void{global $failures;echo ($ok?'PASS ':'BLOCKED ').$label.PHP_EOL;if(!$ok)$failures++;}
deployment_check('PHP 8.2 or later',PHP_VERSION_ID>=80200);
foreach(['mysqli','mysqlnd','mbstring','fileinfo','gd','openssl','curl','zip','json','session'] as $extension)deployment_check('Extension '.$extension,extension_loaded($extension));
foreach(['stream_socket_client','stream_socket_enable_crypto','move_uploaded_file','random_bytes'] as $function)deployment_check('Function '.$function,function_exists($function));
if(!isset($options['local'])) {
 deployment_check('Production mode',!APP_LOCAL&&setting('APP_ENV')==='production');
 deployment_check('HTTPS base URL without placeholder',str_starts_with(setting('APP_URL'),'https://')&&!preg_match('/localhost|127\.0\.0\.1|REPLACE|example\./i',setting('APP_URL')));
 deployment_check('Private database credentials configured',setting('DB_USER')!==''&&setting('DB_USER')!=='root'&&setting('DB_PASSWORD')!==''&&!str_contains(setting('DB_PASSWORD'),'REPLACE'));
 deployment_check('SMTP configured',setting('EMAIL_ENABLED')==='1'&&setting('SMTP_HOST')!==''&&setting('SMTP_USER')!==''&&setting('SMTP_PASSWORD')!==''&&!str_contains(setting('SMTP_PASSWORD'),'REPLACE'));
 deployment_check('SMTP TLS port and encryption',in_array(setting('SMTP_PORT','587'),['587','465'],true)&&filter_var(setting('SMTP_TLS','1'),FILTER_VALIDATE_BOOLEAN));
 deployment_check('SMS token configured',setting('SMS_API_TOKEN')!==''&&!str_contains(setting('SMS_API_TOKEN'),'REPLACE')&&str_starts_with(setting('SMS_ENDPOINT'),'https://'));
 deployment_check('No local config in production release',!is_file(APP_ROOT.'/config.local.php'));
}
function deployment_bytes(string $value):int{$n=(int)$value;return $n*match(strtolower(substr(trim($value),-1))){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};}
deployment_check('File upload enabled',(bool)ini_get('file_uploads'));
deployment_check('At least 20 uploaded files',(int)ini_get('max_file_uploads')>=20);
deployment_check('5 MB individual uploads',deployment_bytes(ini_get('upload_max_filesize'))>=5242880);
deployment_check('POST accommodates 20 x 5 MB plus multipart overhead',deployment_bytes(ini_get('post_max_size'))>=110*1024*1024);
deployment_check('Memory at least 256 MB',ini_get('memory_limit')==='-1'||deployment_bytes(ini_get('memory_limit'))>=256*1024*1024);
foreach(['storage/private','uploads'] as $directory)deployment_check('Writable '.$directory,is_dir(APP_ROOT.'/'.$directory)&&is_writable(APP_ROOT.'/'.$directory));
foreach(['.htaccess','includes/.htaccess','tools/.htaccess','storage/.htaccess','storage/private/.htaccess','uploads/.htaccess','uploads/requirements/.htaccess'] as $file)deployment_check('Access rule '.$file,is_file(APP_ROOT.'/'.$file));
require_once __DIR__.'/../includes/db.php';
deployment_check('Database connection and UTF-8',$conn->character_set_name()==='utf8mb4');
$engines=$conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetch_all(MYSQLI_ASSOC);
deployment_check('Transactional InnoDB tables',count($engines)===0);
if(isset($options['schema'])) {
 $required=json_decode(file_get_contents(__DIR__.'/../database/deployment_schema.json'),true);
 foreach($required as $table=>$columns){$existing=array_column($conn->execute_query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');deployment_check('Schema '.$table,!array_diff($columns,$existing));}
 $missing=0;foreach($conn->query('SELECT stored_name,storage_location FROM application_attachments') as $r){$base=$r['storage_location']==='private'?private_path():APP_ROOT.'/uploads/requirements/';if(basename($r['stored_name'])!==$r['stored_name']||!is_file($base.$r['stored_name']))$missing++;}
 deployment_check('All attachment files present',$missing===0);
}
echo "HTTP denial rules, HTTPS, browser/phone behavior, provider delivery and cron scheduling require live checks.\n";
exit($failures?1:0);
