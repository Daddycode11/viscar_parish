<?php
// CLI-only audit fixture. Never connects to the application's configured database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = 'vicar_revision_' . date('Ymd_His');
$conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
$conn->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
$conn->select_db($db);
$sql = file_get_contents(__DIR__ . '/../database/indigo_church.sql');
$sql = preg_replace('/CREATE DATABASE IF NOT EXISTS indigo_church;|USE indigo_church;/', '', $sql);
$conn->multi_query($sql);
do { if ($r = $conn->store_result()) $r->free(); } while ($conn->more_results() && $conn->next_result());
$sql = file_get_contents(__DIR__ . '/../database/migration_announcements.sql');
$conn->multi_query($sql);
do { if ($r = $conn->store_result()) $r->free(); } while ($conn->more_results() && $conn->next_result());
$conn->query("INSERT INTO parishes (name,address,priest_name) VALUES ('Audit Parish A','Synthetic A','Audit Priest A'),('Audit Parish B','Synthetic B','Audit Priest B')");
$password = bin2hex(random_bytes(20));
$hash = password_hash($password, PASSWORD_DEFAULT);
foreach (['admin','secretary','bookkeeper','parishioner','parishioner','secretary'] as $i => $role) {
    $id = $i + 1; $pid = $id >= 5 ? 2 : 1;
    $stmt = $conn->prepare('INSERT INTO users (name,email,password,role,parish_id) VALUES (?,?,?,?,?)');
    $name = 'Audit User ' . $id; $email = 'audit' . $id . '@example.invalid';
    $stmt->bind_param('ssssi', $name,$email,$hash,$role,$pid); $stmt->execute();
}
$conn->query("INSERT INTO services (parish_id,name,fee,max_daily_limit) VALUES (1,'Audit Baptism A',100,1),(2,'Audit Baptism B',200,1)");
$conn->query("INSERT INTO service_fields (service_id,field_name,field_label,is_required) VALUES (1,'child_name','Child Name',1)");
$conn->query("INSERT INTO service_requirements (service_id,document_name,is_required) VALUES (1,'Required Certificate',1)");
$conn->query("INSERT INTO applications (user_id,parish_id,service_id,schedule,status,form_data) VALUES (4,1,1,'2030-01-15 09:00:00','approved','{\"child_name\":\"Audit Child\"}'),(5,2,2,'2030-01-16 09:00:00','approved','{}')");
$conn->query("INSERT INTO payments (application_id,amount,status) VALUES (1,100,'pending'),(2,200,'pending')");
$conn->query("INSERT INTO mass_schedules (parish_id,day_of_week,time_start) VALUES (1,'Sunday','08:00:00'),(2,'Monday','09:00:00')");
$conn->query("INSERT INTO events (parish_id,title,event_date) VALUES (1,'Audit Event A','2030-01-15 09:00:00'),(2,'Audit Event B','2030-01-16 09:00:00')");
$conn->query("INSERT INTO faqs (parish_id,question,answer,created_by,status) VALUES (1,'Audit question A','Audit answer A',2,'active'),(2,'Audit question B','Audit answer B',6,'active'),(1,'Inactive question','Inactive answer',2,'inactive')");
$conn->query("INSERT INTO sacramental_records (application_id,parish_id,user_id,record_type,parishioner_name,date_of_sacrament,minister_name,created_by) VALUES (1,1,4,'Baptism','Audit Child','2030-01-15','Audit Priest A',2),(2,2,5,'Baptism','Audit Other Child','2030-01-16','Audit Priest B',6)");
file_put_contents(__DIR__ . '/../storage/private/revision-fixture.json', json_encode(['database'=>$db,'password'=>$password]));
echo "Created isolated synthetic fixture: $db\n";
