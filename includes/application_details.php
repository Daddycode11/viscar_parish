<?php
require_once __DIR__.'/workflows.php';

function application_detail_data(array $application): array
{
    global $conn;
    $schema=json_decode($application['form_schema']??'null',true);
    if (!is_array($schema)) $schema=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$application['service_id']])->fetch_all(MYSQLI_ASSOC);
    $labels=[];$documents=[];
    foreach($schema as $field) {
        $labels[$field['field_name']]=$field['field_label'];
        $documents['field_'.$field['id']]=$field['field_label'];
    }
    foreach((json_decode($application['requirement_schema']??'null',true) ?? $conn->execute_query('SELECT id,document_name FROM service_requirements WHERE service_id=?',[$application['service_id']])->fetch_all(MYSQLI_ASSOC)) as $r) $documents['req_'.$r['id']]=$r['document_name'];
    foreach($conn->execute_query('SELECT id,documents FROM application_document_requests WHERE application_id=?',[$application['id']]) as $r) $documents['additional_'.$r['id']]=$r['documents'];
    return ['labels'=>$labels,'documents'=>$documents];
}

function render_application_details(array $application): void
{
    global $conn;
    $detail=application_detail_data($application);
    $owner=sqlrow('SELECT name,email,phone FROM users WHERE id=?',[$application['user_id']]);
    $service=sqlrow('SELECT name FROM services WHERE id=?',[$application['service_id']]);
    echo '<section class="card"><div class="card-body"><h2>Applicant Information</h2><dl>';
    foreach(['Name'=>$owner['name'],'Email'=>$owner['email'],'Phone'=>$owner['phone'],'Service'=>$service['name'],'Reference'=>'APP-'.$application['id'],'Status'=>$application['status'],'Schedule'=>$application['schedule'],'Submitted'=>$application['created_at']] as $key=>$value) echo '<dt>'.h($key).'</dt><dd>'.h($value).'</dd>';
    echo '</dl><h2>Application Information</h2><dl>';
    foreach(json_decode($application['form_data']??'{}',true)?:[] as $key=>$value) echo '<dt>'.h($detail['labels'][$key]??ucwords(str_replace('_',' ',$key))).'</dt><dd>'.h(is_scalar($value)?$value:'').'</dd>';
    echo '</dl><h2>Documents</h2>';
    render_requirement_attachments($application,$detail['documents']);
    echo '<h2>Payments</h2><div class="tbl-wrap"><table><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Date</th></tr>';
    foreach($conn->execute_query('SELECT reference_number,amount,status,paid_at FROM payments WHERE application_id=? ORDER BY id DESC',[$application['id']]) as $row) echo '<tr><td>'.h($row['reference_number']).'</td><td>'.h($row['amount']).'</td><td>'.h($row['status']).'</td><td>'.h($row['paid_at']).'</td></tr>';
    echo '</table></div><h2>Record Details</h2>';
    foreach($conn->execute_query('SELECT * FROM sacramental_records WHERE application_id=?',[$application['id']]) as $r) echo '<p>'.h($r['record_type'].' — '.$r['certificate_number'].' — '.$r['date_of_sacrament'].' — '.$r['minister_name']).'</p>';
    echo '<h2>Status History</h2><ul>';
    foreach($conn->execute_query("SELECT t.action,t.created_at,u.name FROM audit_trail t LEFT JOIN users u ON u.id=t.user_id WHERE t.entity_type='application' AND t.entity_id=? ORDER BY t.id DESC LIMIT 100",[$application['id']]) as $r) echo '<li>'.h($r['created_at'].' — '.ucwords(str_replace('_',' ',$r['action'])).' — '.$r['name']).'</li>';
    foreach($conn->execute_query('SELECT request_type,reason,status,created_at FROM application_requests WHERE application_id=? ORDER BY id DESC',[$application['id']]) as $r) echo '<li>'.h($r['created_at'].' — '.ucfirst($r['request_type']).' — '.$r['status'].' — '.$r['reason']).'</li>';
    echo '</ul></div></section>';
}
