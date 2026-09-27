<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/db.php';
$conn->query("CREATE TABLE IF NOT EXISTS application_attachments (
 id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL,
 requirement_key VARCHAR(100) NOT NULL, original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(255) NOT NULL, storage_location VARCHAR(20) NOT NULL DEFAULT 'private',
 mime_type VARCHAR(100) NULL, size_bytes BIGINT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY attachment_identity(application_id,requirement_key,stored_name),
 CONSTRAINT attachment_application_fk FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach($conn->query('SELECT id,uploaded_files FROM applications') as $a) {
 foreach(json_decode($a['uploaded_files']??'{}',true)?:[] as $key=>$name) {
  if(!is_string($name)||basename($name)!==$name)throw new RuntimeException('Invalid legacy attachment path; migration stopped.');
  $private=is_file(private_path($name));$path=$private?private_path($name):APP_ROOT.'/uploads/requirements/'.$name;
  $conn->execute_query('INSERT IGNORE INTO application_attachments(application_id,requirement_key,original_name,stored_name,storage_location,mime_type,size_bytes) VALUES(?,?,?,?,?,?,?)',[$a['id'],(string)$key,$name,$name,$private?'private':'legacy',is_file($path)?(new finfo(FILEINFO_MIME_TYPE))->file($path):null,is_file($path)?filesize($path):null]);
 }
}
echo "Attachment migration complete; legacy maps and files preserved.\n";
