<?php

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors','0');
ini_set('log_errors','1');

$dbHost = setting('DB_HOST', '127.0.0.1');
$dbPort = (int) setting('DB_PORT', '3306');
$dbUser = setting('DB_USER', 'root');
$dbPass = setting('DB_PASSWORD', '');
$dbName = setting('DB_NAME', 'vicarparish_local');

try {
    $conn = new mysqli(
        $dbHost,
        $dbUser,
        $dbPass,
        $dbName,
        $dbPort
    );

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");

} catch (mysqli_sql_exception $e) {
    error_log('Database unavailable: ' . $e->getCode());

    http_response_code(503);
    if(PHP_SAPI==='cli'){fwrite(STDERR,"Database unavailable; check private configuration.\n");exit(1);}
    exit('The service is temporarily unavailable. Please try again later.');
}

return $conn;