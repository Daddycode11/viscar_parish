<?php
// CLI helper loaded only by the guarded production migration entrypoint.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function deployment_add_column(string $table,string $column,string $definition):void {
 global $conn;
 if(!$conn->execute_query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])->fetch_row())$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
foreach(['migration.sql','migration_local_compatibility.sql','migration_revisions.sql'] as $file) {
 $sql=file_get_contents(__DIR__.'/../database/'.$file);
 preg_match_all('/CREATE TABLE IF NOT EXISTS [a-z_]+\s*\(.*?;(?=\s|$)/s',$sql,$creates);
 foreach($creates[0] as $create)$conn->query($create);
 preg_match_all('/ALTER TABLE ([a-z_]+) ADD COLUMN IF NOT EXISTS ([a-z_]+) ([^;]+);/',$sql,$adds,PREG_SET_ORDER);
 foreach($adds as $add)deployment_add_column($add[1],$add[2],$add[3]);
 preg_match_all('/CREATE INDEX IF NOT EXISTS ([a-z_]+) ON ([a-z_]+)\(([^)]+)\);/',$sql,$indexes,PREG_SET_ORDER);
 foreach($indexes as $index)if(!$conn->execute_query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$index[2],$index[1]])->fetch_row())$conn->query("CREATE INDEX `$index[1]` ON `$index[2]` ($index[3])");
}
// Extend status without narrowing any existing enum values.
$type=$conn->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch_assoc();
if(str_starts_with($type['Type'],'enum(')) {
 $values=$type['Type'];foreach(['active','suspended','pending'] as $status)if(!str_contains($values,"'$status'"))$values=substr($values,0,-1).",'$status')";
 if($values!==$type['Type']){$default=$type['Default'];$suffix=($type['Null']==='YES'?' NULL':' NOT NULL').($default===null?'':" DEFAULT '".$conn->real_escape_string($default)."'");$conn->query('ALTER TABLE users MODIFY status '.$values.$suffix);}
}
foreach(['message'=>'TEXT NULL','target'=>'VARCHAR(64) NULL','channel'=>"VARCHAR(32) DEFAULT 'In-App'",'status'=>"ENUM('active','inactive') DEFAULT 'active'",'parish_id'=>'INT NULL','sent_by'=>'INT NULL','sent_at'=>'DATETIME NULL'] as $column=>$definition)deployment_add_column('announcements',$column,$definition);
deployment_add_column('parishes','logo','VARCHAR(255) NULL');deployment_add_column('users','profile_picture','VARCHAR(255) NULL');
deployment_add_column('payments','verified_by','INT NULL');deployment_add_column('payments','verified_at','DATETIME NULL');
$conn->query('UPDATE announcements SET sent_at=COALESCE(sent_at,created_at),sent_by=COALESCE(sent_by,created_by),message=COALESCE(message,content) WHERE sent_at IS NULL OR sent_by IS NULL OR message IS NULL');
$conn->query('UPDATE applications a JOIN services s ON s.id=a.service_id SET a.fee_snapshot=s.fee WHERE a.fee_snapshot IS NULL');
