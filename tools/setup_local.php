<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
if(!APP_LOCAL || !in_array(setting('DB_HOST','127.0.0.1'),['127.0.0.1','localhost','::1'],true))exit("Local environment required.\n");
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=setting('DB_NAME','vicarparish_local');
if(!preg_match('/^[a-zA-Z0-9_]+$/',$db))exit("Invalid database name\n");
$c=new mysqli(setting('DB_HOST','127.0.0.1'),setting('DB_USER','root'),setting('DB_PASSWORD'),'',(int)setting('DB_PORT','3306'));
$exists=$c->execute_query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?',[$db])->num_rows;
if(!$exists){
 $c->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");$c->select_db($db);
 $sql=preg_replace('/CREATE DATABASE IF NOT EXISTS indigo_church;|USE indigo_church;/','',file_get_contents(__DIR__.'/../database/indigo_church.sql'));
 $c->multi_query($sql);do{if($r=$c->store_result())$r->free();}while($c->more_results()&&$c->next_result());
}else $c->select_db($db);
foreach(['migration_local_compatibility.sql','migration_announcements.sql','migration_admin_features.sql','migration_revisions.sql'] as $file){
 $c->multi_query(file_get_contents(__DIR__.'/../database/'.$file));do{if($r=$c->store_result())$r->free();}while($c->more_results()&&$c->next_result());
}
echo "Local schema ready: $db. No existing records removed.\n";

require __DIR__ . '/migrate_recommendations.php';

require __DIR__.'/migrate_attachments.php';
