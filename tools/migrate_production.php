<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/db.php';
$options=getopt('',['apply','backup:','expected-database:']);
try {
 $actual=$conn->query('SELECT DATABASE()')->fetch_row()[0];
 if(($options['expected-database']??'')!==$actual)throw new RuntimeException('Pass --expected-database with the exact configured database name.');
 foreach(['users','parishes','services','applications','payments','announcements','application_document_requests'] as $table)if(!$conn->execute_query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_row())throw new RuntimeException('Missing base table: '.$table.'. This migrator is not a fresh installer.');
 $deploymentSchemaRequirements=json_decode(file_get_contents(__DIR__.'/../database/deployment_schema.json'),true);
 $missing=[];foreach($deploymentSchemaRequirements as $table=>$columns){$current=array_column($conn->execute_query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');foreach(array_diff($columns,$current) as $column)$missing[]=$table.'.'.$column;}
 echo 'Plan: compatibility additions, separation/master, attachment backfill. Missing expected columns: '.count($missing).PHP_EOL;
 if(!isset($options['apply'])){foreach($missing as $column)echo $column.PHP_EOL;echo "No database changes made. Review plan, back up, pause writes, then use --apply --backup=/absolute/backup.sql.\n";exit;}
 $backup=realpath($options['backup']??'');
 if(!$backup||!is_file($backup)||!is_readable($backup)||filesize($backup)<1024)throw new RuntimeException('A readable, nonempty SQL backup is required.');
 if(!is_file(private_path('maintenance.flag')))throw new RuntimeException('Create storage/private/maintenance.flag and pause cron jobs before migration.');
 if(!$conn->query("SELECT GET_LOCK('viscar_deployment_migration',0)")->fetch_row()[0])throw new RuntimeException('Another migration is running.');
 $counts=[];foreach($conn->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'") as $row){$table=array_values($row)[0];if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Unsupported table name.');$counts[$table]=(int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];}
 try {
  require __DIR__.'/migrate_compatibility.php';
  require __DIR__.'/migrate_master.php';
  require __DIR__.'/migrate_attachments.php';
  foreach($counts as $table=>$count)if((int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]<$count)throw new RuntimeException('Record count decreased: '.$table);
  $remaining=[];foreach($deploymentSchemaRequirements as $table=>$columns){$current=array_column($conn->execute_query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');foreach(array_diff($columns,$current) as $column)$remaining[]=$table.'.'.$column;}
  if($remaining)throw new RuntimeException('Schema still requires manual review: '.implode(', ',$remaining));
  file_put_contents(private_path('deployment-migration-'.date('Ymd-His').'.json'),json_encode(['at'=>date('c'),'database'=>$actual,'backup_sha256'=>hash_file('sha256',$backup),'before_counts'=>$counts],JSON_PRETTY_PRINT));
  echo "Migration complete; pre-existing table row counts preserved. Keep maintenance enabled until checks pass.\n";
 }finally{$conn->query("SELECT RELEASE_LOCK('viscar_deployment_migration')");}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\nStopped. DDL auto-commits; retain maintenance and inspect before retrying.\n");exit(1);}
