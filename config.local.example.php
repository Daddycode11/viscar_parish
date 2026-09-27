<?php
// Local development only. Copy to config.local.php; do not commit filled secrets.
$localSettings=['APP_ENV'=>'local','APP_URL'=>'http://localhost:8080/vicarparish-update',
 'DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_NAME'=>'vicarparish_local','DB_USER'=>'root','DB_PASSWORD'=>'',
 'EMAIL_ENABLED'=>'0','SMS_API_TOKEN'=>''];
foreach($localSettings as $key=>$value)if(getenv($key)===false)putenv($key.'='.$value);
unset($localSettings);
