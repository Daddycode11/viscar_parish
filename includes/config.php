<?php
// Existing environment values take precedence over private configuration files.
$explicitConfig=getenv('VISCAR_CONFIG_FILE');
$productionConfig=__DIR__.'/../config.production.php';
$localConfig=__DIR__.'/../config.local.php';
if($explicitConfig!==false && $explicitConfig!=='') {
    if(!is_file($explicitConfig)){http_response_code(503);exit('Application configuration is unavailable.');}
    require_once $explicitConfig;
} elseif(is_file($productionConfig)) require_once $productionConfig;
elseif(getenv('APP_ENV')!=='production' && is_file($localConfig)) require_once $localConfig;
function setting(string $name,string $default=''):string{$v=getenv($name);return $v===false?$default:$v;}
define('APP_LOCAL',setting('APP_ENV','production')==='local');
define('APP_ROOT',dirname(__DIR__));
date_default_timezone_set('Asia/Manila');
function private_path(string $path=''):string{$base=APP_ROOT.'/storage/private';if(!is_dir($base))mkdir($base,0700,true);return $base.'/'.ltrim($path,'/');}
function app_url(string $path=''):string{return rtrim(setting('APP_URL',APP_LOCAL?'http://localhost:8080/vicarparish-update':''),'/').'/'.ltrim($path,'/');}
if(!APP_LOCAL) {
    ini_set('display_errors','0');ini_set('display_startup_errors','0');ini_set('log_errors','1');
    ini_set('error_log',private_path('php-error.log'));
    set_exception_handler(function(Throwable $error):void {
        error_log(get_class($error).' code '.$error->getCode().' at '.$error->getFile().':'.$error->getLine());
        http_response_code(500);if(PHP_SAPI==='cli'){fwrite(STDERR,"Operation failed; inspect the private error log.\n");exit(1);}exit('Unable to process this request. Please try again later.');
    });
    $valid=filter_var(setting('APP_URL'),FILTER_VALIDATE_URL)&&parse_url(setting('APP_URL'),PHP_URL_SCHEME)==='https';
    foreach(['DB_HOST','DB_USER','DB_NAME','DB_PASSWORD'] as $key)$valid=$valid&&getenv($key)!==false;
    if(!$valid){http_response_code(503);if(PHP_SAPI==='cli'){fwrite(STDERR,"Production configuration is incomplete.\n");exit(1);}exit('Application configuration is unavailable.');}
    if(PHP_SAPI!=='cli' && basename($_SERVER['SCRIPT_NAME']??'')==='setup_admin.php'){http_response_code(404);exit;}
}
if(PHP_SAPI!=='cli' && is_file(private_path('maintenance.flag'))) {
    http_response_code(503);header('Retry-After: 600');header('Cache-Control: no-store');exit('The parish system is temporarily unavailable for maintenance. Please try again shortly.');
}
