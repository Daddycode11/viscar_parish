<?php
require_once __DIR__ . '/workflows.php';

const SACRAMENT_TYPES = ['Baptism', 'Confirmation', 'First Communion', 'Marriage', 'Anointing of the Sick', 'Holy Orders', 'Reconciliation', 'Funeral Mass'];

function create_approved_sacramental_record(array $actor, array $application, array $service): ?int
{
    global $conn;
    if (empty($service['sacrament_type'])) { return null; }
    if (!in_array($service['sacrament_type'], SACRAMENT_TYPES, true)) return null;
    // Caller holds the application lock, shared with manual record creation.
    $existing = sqlrow('SELECT id FROM sacramental_records WHERE application_id=?', [$application['id']]);
    if ($existing) { return (int) $existing['id']; }
    $owner = sqlrow('SELECT name FROM users WHERE id=?', [$application['user_id']]);
    $data = json_decode($application['form_data'] ?? '{}', true) ?: [];
    $name = $data['child_name'] ?? $data['full_name'] ?? $owner['name'];
    $conn->execute_query("INSERT INTO sacramental_records(application_id,parish_id,user_id,record_type,parishioner_name,date_of_sacrament,minister_name,remarks,created_by) VALUES(?,?,?,?,?,?,'','',?)", [$application['id'],$application['parish_id'],$application['user_id'],$service['sacrament_type'],mb_substr((string)$name,0,255),substr($application['schedule'],0,10),$actor['id']]);
    $id = (int) $conn->insert_id;
    auditLog($actor['id'], 'automatic_sacramental_record', 'sacramental_record', $id);
    issue_certificate($actor, $id);
    return $id;
}

function submit_walk_in(array $actor, array $input, array $files): array
{
    global $conn;
    must($actor['role'] === 'secretary', 'Secretary access required.');
    must((int)($input['parish_id']??0) === (int)$actor['parish_id'], 'Select your assigned parish.');
    $id = (int)($input['user_id']??0);
    if ($id) {
        $owner = sqlrow("SELECT * FROM users WHERE id=? AND role='parishioner' AND status='active' AND (parish_id=? OR id IN (SELECT user_id FROM applications WHERE parish_id=?)) FOR UPDATE", [$id,$actor['parish_id'],$actor['parish_id']]);
        must($owner !== null, 'Select a parishioner in your parish.');
    } else {
        $name = input_text($input,'name'); $email = input_text($input,'email'); $phone = input_text($input,'phone',50);
        must($phone===''||valid_mobile_number($phone),'Enter a valid mobile number starting with 09 or +639.');
        must($name !== '' && (bool)filter_var($email,FILTER_VALIDATE_EMAIL), 'A name and valid email are required.');
        must(sqlrow('SELECT id FROM users WHERE email=?',[$email]) === null, 'That email already exists. Select the existing parishioner or ask the administrator to verify their parish.');
        $conn->execute_query("INSERT INTO users(name,email,phone,password,role,parish_id,status) VALUES(?,?,?,?,'parishioner',?,'active')",[$name,$email,$phone,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$actor['parish_id']]);
        $owner = ['id'=>(int)$conn->insert_id,'role'=>'parishioner','parish_id'=>$actor['parish_id']];
        auditLog($actor['id'],'create_walk_in_parishioner','user',$owner['id']);
    }
    $input['form_data'] = json_encode($input['fields']??[]);
    $result = submit_booking($owner,$input,$files);
    $conn->execute_query("UPDATE applications SET source='walk_in',created_by=? WHERE id=?",[$actor['id'],$result['app_id']]);
    auditLog($actor['id'],'submit_walk_in','application',$result['app_id']);
    return $result;
}
