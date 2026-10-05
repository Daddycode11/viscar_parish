<?php
// Additive and rerunnable. Run after the master migration on existing installations.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/migrate_master.php';
add_revision_column('services','schedule_mode',"VARCHAR(20) NOT NULL DEFAULT 'user_defined'");
add_revision_column('services','time_slots','TEXT NULL');
add_revision_column('services','slot_capacity','INT NOT NULL DEFAULT 1');
add_revision_column('application_requests','original_schedule','DATETIME NULL');
add_revision_column('payments','manual_method_id','INT NULL');
add_revision_column('payments','manual_method_name','VARCHAR(100) NULL');
add_revision_column('payments','proof_file','VARCHAR(255) NULL');
$conn->query("CREATE TABLE IF NOT EXISTS parish_payment_methods (id INT AUTO_INCREMENT PRIMARY KEY, parish_id INT NOT NULL, name VARCHAR(100) NOT NULL, instructions TEXT NOT NULL, qr_file VARCHAR(255) NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX parish_active(parish_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Recommendation migration complete. Existing bookings and payment records preserved.\n";
