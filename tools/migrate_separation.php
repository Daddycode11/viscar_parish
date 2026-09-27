<?php
// Additive, rerunnable migration for both MySQL and MariaDB. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';

function add_revision_column(string $table, string $column, string $definition): void
{
    global $conn;
    $exists = $conn->execute_query(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
        [$table, $column]
    )->fetch_row();
    if (!$exists) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

add_revision_column('users', 'language', "VARCHAR(3) NOT NULL DEFAULT 'en'");
add_revision_column('users', 'auth_version', 'INT NOT NULL DEFAULT 1');
add_revision_column('applications', 'source', "VARCHAR(10) NOT NULL DEFAULT 'online'");
add_revision_column('applications', 'created_by', 'INT NULL');
add_revision_column('services', 'sacrament_type', 'VARCHAR(100) NULL');

$definitions = [
    'accounting_documents' => "id INT AUTO_INCREMENT PRIMARY KEY, parish_id INT NOT NULL,
        document_type VARCHAR(32) NOT NULL, document_number VARCHAR(64) NULL UNIQUE,
        document_date DATE NOT NULL, party_name VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL,
        description TEXT NOT NULL, reference VARCHAR(100) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'draft', approved_by INT NULL, approved_at DATETIME NULL,
        created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        request_key CHAR(64) NOT NULL UNIQUE, INDEX accounting_filter(parish_id,document_type,document_date)",
    'password_resets' => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX reset_user(user_id)",
    'security_rate_limits' => "bucket CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
        window_started BIGINT NOT NULL",
    'parish_subscriptions' => "user_id INT NOT NULL, parish_id INT NOT NULL,
        in_app TINYINT NOT NULL DEFAULT 1, email TINYINT NOT NULL DEFAULT 1,
        sms TINYINT NOT NULL DEFAULT 0, PRIMARY KEY(user_id,parish_id)",
    'announcement_parishes' => 'announcement_id INT NOT NULL, parish_id INT NOT NULL, PRIMARY KEY(announcement_id,parish_id)',
    'announcement_dispatches' => 'request_key CHAR(64) PRIMARY KEY, announcement_id INT NOT NULL UNIQUE',
    'announcement_deliveries' => "id INT AUTO_INCREMENT PRIMARY KEY, announcement_id INT NOT NULL,
        user_id INT NOT NULL, channel VARCHAR(10) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'queued',
        error_code VARCHAR(100) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY delivery_once(announcement_id,user_id,channel)",
];
foreach ($definitions as $table => $definition) {
    $conn->query("CREATE TABLE IF NOT EXISTS `$table` ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Seed only exact canonical service names once. Never infer a sacrament from substrings.
$seeded = $conn->execute_query('SELECT setting_value FROM site_settings WHERE setting_key=?', ['separation_sacrament_seed'])->fetch_row();
if (!$seeded) {
    $conn->begin_transaction();
    try {
        foreach (['Baptism','Confirmation','First Communion','Marriage','Anointing of the Sick','Holy Orders','Reconciliation'] as $type) {
            $conn->execute_query('UPDATE services SET sacrament_type=? WHERE sacrament_type IS NULL AND LOWER(TRIM(name))=LOWER(?)', [$type, $type]);
        }
        $conn->execute_query('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?)', ['separation_sacrament_seed','1']);
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
echo "Separation migration complete. Existing records preserved; canonical sacrament names classified once.\n";
