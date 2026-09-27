<?php
// Copy to config.production.php on the server ONLY and replace every REPLACE value.
// Alternatively set VISCAR_CONFIG_FILE to an absolute private path outside the web root.
// Never copy config.local.php to production or commit filled credentials.
$productionSettings = [
 'APP_ENV'=>'production',
 'APP_URL'=>'https://REPLACE_WITH_DOMAIN', // include /subfolder only if deployed there
 'DB_HOST'=>'REPLACE_FROM_HPANEL', 'DB_PORT'=>'3306',
 'DB_NAME'=>'REPLACE_FROM_HPANEL', 'DB_USER'=>'REPLACE_FROM_HPANEL',
 'DB_PASSWORD'=>'REPLACE_PRIVATELY',
 'EMAIL_ENABLED'=>'1', 'SMTP_HOST'=>'REPLACE_WITH_SMTP_HOST',
 'SMTP_PORT'=>'587', 'SMTP_TLS'=>'1', // STARTTLS 587, or implicit TLS on port 465
 'SMTP_USER'=>'REPLACE_WITH_MAILBOX', 'SMTP_PASSWORD'=>'REPLACE_PRIVATELY',
 'EMAIL_FROM_ADDRESS'=>'REPLACE_WITH_VERIFIED_SENDER',
 'EMAIL_FROM_NAME'=>'VISCAR Parish System', 'EMAIL_REPLY_TO'=>'REPLACE_WITH_REPLY_ADDRESS',
 'SMS_ENDPOINT'=>'https://www.iprogsms.com/api/v1/sms_messages',
 'SMS_API_TOKEN'=>'REPLACE_PRIVATELY', 'SMS_PROVIDER'=>'0',
];
foreach($productionSettings as $key=>$value)if(getenv($key)===false)putenv($key.'='.$value);
unset($productionSettings);
