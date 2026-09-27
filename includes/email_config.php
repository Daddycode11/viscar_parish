<?php
require_once __DIR__.'/config.php';
// Deployment environment takes precedence over protected local configuration.
define('SMTP_USER',setting('SMTP_USER'));
define('SMTP_PASS',str_replace(' ','',setting('SMTP_PASSWORD')));
define('SMTP_HOST',setting('SMTP_HOST'));
define('SMTP_PORT',(int)setting('SMTP_PORT','587'));
define('SMTP_TLS',filter_var(setting('SMTP_TLS','1'),FILTER_VALIDATE_BOOLEAN));
define('EMAIL_FROM_NAME',setting('EMAIL_FROM_NAME','Parish Service Platform'));
define('EMAIL_FROM_ADDRESS',setting('EMAIL_FROM_ADDRESS',SMTP_USER));
define('EMAIL_REPLY_TO',setting('EMAIL_REPLY_TO',EMAIL_FROM_ADDRESS));
define('EMAIL_ENABLED',filter_var(setting('EMAIL_ENABLED','0'),FILTER_VALIDATE_BOOLEAN));
