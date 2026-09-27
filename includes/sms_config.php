<?php
require_once __DIR__.'/config.php';
define('IPROGSMS_API_TOKEN',setting('SMS_API_TOKEN'));
define('IPROGSMS_ENDPOINT',setting('SMS_ENDPOINT','https://www.iprogsms.com/api/v1/sms_messages'));
define('IPROGSMS_PROVIDER',(int)setting('SMS_PROVIDER','0'));
