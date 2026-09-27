<?php
// Additive, rerunnable; does not remove records or rename existing columns.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/migrate_separation.php';
require_once __DIR__.'/../includes/service_types.php';
add_revision_column('services','general_type','VARCHAR(100) NULL');
add_revision_column('services','classification',"VARCHAR(32) NOT NULL DEFAULT 'Non-Sacramental'");
add_revision_column('services','amount_mode',"VARCHAR(16) NOT NULL DEFAULT 'fixed'");
add_revision_column('applications','form_schema','LONGTEXT NULL');
add_revision_column('applications','requirement_schema','LONGTEXT NULL');
add_revision_column('payments','refunded_at','DATETIME NULL');
add_revision_column('payments','refund_request_id','INT NULL');
foreach (['bank_name'=>'VARCHAR(255)','bank_account_number'=>'VARCHAR(100)','secretary_signatory'=>'VARCHAR(255)','finance_signatory'=>'VARCHAR(255)','priest_signatory'=>'VARCHAR(255)','attachment'=>'VARCHAR(255)','original_particulars'=>'TEXT','correction_reason'=>'TEXT'] as $column=>$type) {
    add_revision_column('accounting_documents',$column,$type.' NULL');
}
add_revision_column('accounting_documents','petty_cash_set','INT NULL');
add_revision_column('accounting_documents','original_document_id','INT NULL');
add_revision_column('accounting_documents','original_receipt_id','INT NULL');
$conn->query("CREATE TABLE IF NOT EXISTS petty_cash_sets (id INT AUTO_INCREMENT PRIMARY KEY,parish_id INT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'open',created_by INT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,closed_at DATETIME NULL,INDEX parish_sets(parish_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Preserve any previously extended status values rather than replacing the enumeration.
foreach (['applications'=>['status','cancelled'],'application_requests'=>['request_type','cancel']] as $table=>[$column,$value]) {
    $type=$conn->execute_query('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])->fetch_row()[0];
    if (str_starts_with($type,'enum(') && !str_contains($type,"'$value'")) {
        $default=$table==='applications'?" DEFAULT 'pending'":'';
        $conn->query("ALTER TABLE `$table` MODIFY `$column` ".substr($type,0,-1).",'$value') NOT NULL".$default);
    }
}
$conn->begin_transaction();
try {
    foreach ($conn->query('SELECT id,name,sacrament_type FROM services WHERE general_type IS NULL') as $row) {
        $conn->execute_query('UPDATE services SET general_type=?,classification=? WHERE id=?',[canonical_service_type($row['name']),$row['sacrament_type']?'Sacramental':'Non-Sacramental',$row['id']]);
    }
    foreach ($conn->query('SELECT DISTINCT service_id FROM applications WHERE requirement_schema IS NULL') as $row) {
        $requirements=$conn->execute_query('SELECT * FROM service_requirements WHERE service_id=?',[$row['service_id']])->fetch_all(MYSQLI_ASSOC);
        $conn->execute_query('UPDATE applications SET requirement_schema=? WHERE service_id=? AND requirement_schema IS NULL',[json_encode($requirements),$row['service_id']]);
    }
    // Snapshot legacy definitions before later edits; stored answers are never rewritten.
    foreach ($conn->query('SELECT DISTINCT service_id FROM applications WHERE form_schema IS NULL') as $row) {
        $fields=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$row['service_id']])->fetch_all(MYSQLI_ASSOC);
        $conn->execute_query('UPDATE applications SET form_schema=? WHERE service_id=? AND form_schema IS NULL',[json_encode($fields),$row['service_id']]);
    }
    $conn->query("UPDATE payments p JOIN application_requests r ON r.application_id=p.application_id AND r.request_type='refund' AND r.status='completed' SET p.refunded_at=r.completed_at,p.refund_request_id=r.id WHERE p.status='refunded' AND p.refunded_at IS NULL");
    $conn->commit();
} catch (Throwable $e) { $conn->rollback(); throw $e; }
echo "Master migration complete. Unrecognized legacy names retained as Other / Custom.\n";
