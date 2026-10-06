<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';
require_once __DIR__.'/../includes/application_revisions.php';

/**
 * Sacramental Records & Certificate Generation — Secretary Role
 * Features: CRUD records, search/filter, generate certificates, archive/restore
 */

$page_id    = 'records';
$page_title = 'Sacramental Records';
$page_sub   = 'Records';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../includes/notifications.php';


if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_GET['ajax']??'', ['create','update'],true)) {
 if(!in_array($_POST['record_type']??'',SACRAMENT_TYPES,true))fail_request('Select a sacramental record type.',422);
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$_POST['date_of_sacrament']??'');
 if(!$d || $d->format('Y-m-d')!==($_POST['date_of_sacrament']??''))fail_request('Enter a valid sacrament date.',422);
 if(!empty($_POST['application_id'])) {
  $application=$conn->execute_query("SELECT a.* FROM applications a JOIN services s ON s.id=a.service_id WHERE a.id=? AND a.parish_id=? AND a.status='approved' AND s.classification='Sacramental'",[(int)$_POST['application_id'],$user['parish_id']])->fetch_assoc();
  if(!$application)fail_request('Select an approved application in your parish.',422);
 }
}
// ── AJAX HANDLERS ─────────────────────────────
if(($_GET['ajax']??'')==='manual_fields'){
    $service=sqlrow("SELECT id FROM services WHERE parish_id=? AND classification='Sacramental' AND sacrament_type=? ORDER BY status='active' DESC,id LIMIT 1",[$user['parish_id'],input_text($_GET,'type')]);
    $serviceId=$service['id']??0;$scalarFieldsOnly=true;
    header('Content-Type: text/html; charset=UTF-8');require APP_ROOT.'/includes/service_form.php';exit;
}
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Create record
    if ($_GET['ajax'] === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $record_type      = trim($_POST['record_type'] ?? '');
        $parishioner_name = trim($_POST['parishioner_name'] ?? '');
        $date_of_sacrament= trim($_POST['date_of_sacrament'] ?? '');
        $minister_name    = trim($_POST['minister_name'] ?? '');
        $sponsors         = trim($_POST['sponsors'] ?? '');
        $remarks          = trim($_POST['remarks'] ?? '');
        $application_id   = !empty($_POST['application_id']) ? (int)$_POST['application_id'] : null;

        $manualSchema=[];$manualAnswers=[];
        if(!$application_id){
            $manualService=sqlrow("SELECT id FROM services WHERE parish_id=? AND classification='Sacramental' AND sacrament_type=? ORDER BY status='active' DESC,id LIMIT 1",[$user['parish_id'],$record_type]);
            if($manualService)$manualSchema=$conn->execute_query("SELECT * FROM service_fields WHERE service_id=? AND field_type<>'file' ORDER BY sort_order,id",[$manualService['id']])->fetch_all(MYSQLI_ASSOC);
            try { foreach($manualSchema as $field){$key=$field['field_name']?:'field_'.$field['id'];$value=input_text($_POST['fields']??[],$key,10000);must(!$field['is_required']||$value!=='','Required: '.$field['field_label']);if($value!=='')validate_field_value($field,$value);$manualAnswers[$key]=$value;} }catch(DomainException $error){fail_request($error->getMessage(),422);}
        }
        $conn->begin_transaction();
        if ($application_id) {
            $conn->execute_query('SELECT id FROM applications WHERE id=? FOR UPDATE', [$application_id]);
            if ($conn->execute_query('SELECT id FROM sacramental_records WHERE application_id=?', [$application_id])->fetch_row()) {
                $conn->rollback(); fail_request('A sacramental record already exists for this application.', 422);
            }
        }
        if (!$record_type || !$parishioner_name || !$date_of_sacrament) {
            echo json_encode(['success' => false, 'message' => 'Record type, parishioner name, and date are required.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO sacramental_records (application_id, parish_id, user_id, record_type, parishioner_name, date_of_sacrament, minister_name, sponsors, remarks, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
        $parish_id = $user['parish_id'];
        $user_id   = $application_id ? null : null;

        // If from application, get user_id
        if ($application_id) {
            $a = $conn->prepare("SELECT user_id FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE id=?");
            $a->bind_param('i', $application_id);
            $a->execute();
            $ar = $a->get_result()->fetch_assoc();
            $user_id = $ar ? (int)$ar['user_id'] : null;
        }

        $stmt = $conn->prepare("INSERT INTO sacramental_records (application_id, parish_id, user_id, record_type, parishioner_name, date_of_sacrament, minister_name, sponsors, remarks, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
        $stmt->bind_param('iiissssssi', $application_id, $parish_id, $user_id, $record_type, $parishioner_name, $date_of_sacrament, $minister_name, $sponsors, $remarks, $user['id']);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            if(!$application_id)$conn->execute_query('UPDATE sacramental_records SET form_data=?,form_schema=? WHERE id=?',[json_encode($manualAnswers),json_encode($manualSchema),$new_id]);
            auditLog($user['id'], 'create_record', 'sacramental_record', $new_id, "Created record: $record_type for $parishioner_name");
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Record created successfully.', 'id' => $new_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create record: ' . $conn->error]);
        }
        exit;
    }

    // Update record
    if ($_GET['ajax'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id               = (int)($_POST['id'] ?? 0);
        $record_type      = trim($_POST['record_type'] ?? '');
        $parishioner_name = trim($_POST['parishioner_name'] ?? '');
        $date_of_sacrament= trim($_POST['date_of_sacrament'] ?? '');
        $minister_name    = trim($_POST['minister_name'] ?? '');
        $sponsors         = trim($_POST['sponsors'] ?? '');
        $remarks          = trim($_POST['remarks'] ?? '');

        if (!$id || !$record_type || !$parishioner_name || !$date_of_sacrament) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE sacramental_records SET record_type=?, parishioner_name=?, date_of_sacrament=?, minister_name=?, remarks=? WHERE id=?");
        $stmt->bind_param('sssssi', $record_type, $parishioner_name, $date_of_sacrament, $minister_name, $remarks, $id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            auditLog($user['id'], 'update_record', 'sacramental_record', $id, "Updated record: $record_type for $parishioner_name");
            echo json_encode(['success' => true, 'message' => 'Record updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update record.']);
        }
        exit;
    }

    // Get record detail
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT sr.*, p.name AS parish_name, p.address AS parish_address, p.priest_name,
                                       u.name AS created_by_name
                                FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sr
                                LEFT JOIN parishes p ON sr.parish_id = p.id
                                LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON sr.created_by = u.id
                                WHERE sr.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Record not found.']); exit; }
        echo json_encode(['success' => true, 'data' => $rec]);
        exit;
    }

    // Print plain record (no certificate number generation)
    if ($_GET['ajax'] === 'print_record' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT sr.*, p.name AS parish_name, p.address AS parish_address, u.name AS created_by_name
                                FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sr
                                LEFT JOIN parishes p ON sr.parish_id = p.id
                                LEFT JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON sr.created_by = u.id
                                WHERE sr.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Record not found.']); exit; }

        $type   = htmlspecialchars($rec['record_type']);
        $name   = htmlspecialchars($rec['parishioner_name']);
        $sdate  = $rec['date_of_sacrament'] ? date('F j, Y', strtotime($rec['date_of_sacrament'])) : '—';
        $minister = htmlspecialchars($rec['minister_name'] ?? '—');
        $sponsors = htmlspecialchars($rec['sponsors'] ?? '—');
        $remarks  = htmlspecialchars($rec['remarks'] ?? '');
        $parish_n = htmlspecialchars($rec['parish_name'] ?? '');
        $parish_a = htmlspecialchars($rec['parish_address'] ?? '');
        $cert_no  = htmlspecialchars($rec['certificate_number'] ?? '— not yet issued —');
        $created  = $rec['created_at'] ? date('M j, Y g:i A', strtotime($rec['created_at'])) : '—';
        $by       = htmlspecialchars($rec['created_by_name'] ?? '—');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>'.$type.' Record &mdash; '.$name.'</title>
<style>
@page { size: A4 portrait; margin: 18mm; }
body { font-family: "DM Sans", Arial, sans-serif; color:#222; font-size: 11pt; line-height: 1.6; }
.hdr { border-bottom: 2px solid #43658b; padding-bottom: 10px; margin-bottom: 18px; display:flex; justify-content:space-between; align-items:flex-start; }
.hdr-l h2 { font-family: "Cormorant Garamond", Georgia, serif; color:#43658b; font-size: 16pt; margin:0; }
.hdr-l small { color:#666; font-size: 9pt; letter-spacing:.08em; text-transform: uppercase; }
.hdr-r { text-align:right; font-size: 9pt; color:#666; }
.hdr-r strong { display:block; font-size: 12pt; color:#43658b; }
.title { font-family: "Cormorant Garamond", serif; font-size: 22pt; color:#43658b; margin: 14px 0 6px; }
.tag { display:inline-block; padding: 2px 12px; border-radius: 14px; background: rgba(201,168,76,.18); color:#8B6914; font-weight:500; font-size: 10pt; }
table.kv { width:100%; border-collapse: collapse; margin-top: 18px; }
table.kv td { padding: 9px 12px; border-bottom: 1px solid #E8E4DE; vertical-align: top; font-size: 11pt; }
table.kv td:first-child { width: 32%; color:#666; text-transform: uppercase; letter-spacing:.06em; font-size: 9pt; }
table.kv td:last-child { font-weight: 500; color:#1A1510; }
.foot { margin-top: 30px; padding-top: 14px; border-top: 1px solid #E8E4DE; display:flex; justify-content:space-between; font-size: 9pt; color:#666; }
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style></head><body>'.navigation_controls('staff/records.php').'
<div class="hdr">
  <div class="hdr-l">
    <h2>'.$parish_n.'</h2>
    <small>Apostolic Vicariate of San Jose</small><br>
    <small style="text-transform:none;letter-spacing:0;color:#888">'.$parish_a.'</small>
  </div>
  <div class="hdr-r"><strong>Record Slip</strong>Issued '.date('F j, Y').'</div>
</div>

<div class="title">'.$type.' Record</div>
<span class="tag">'.$name.'</span>

<table class="kv">
  <tr><td>Parishioner</td><td>'.$name.'</td></tr>
  <tr><td>Sacrament</td><td>'.$type.'</td></tr>
  <tr><td>Date Administered</td><td>'.$sdate.'</td></tr>
  <tr><td>Minister</td><td>'.$minister.'</td></tr>
  <tr><td>Sponsor(s)</td><td>'.$sponsors.'</td></tr>
  '.($remarks ? '<tr><td>Remarks</td><td>'.$remarks.'</td></tr>' : '').'
  <tr><td>Certificate Number</td><td>'.$cert_no.'</td></tr>
  <tr><td>Recorded By</td><td>'.$by.' &middot; '.$created.'</td></tr>
</table>

<div class="foot">
  <span>Apostolic Vicariate of San Jose &middot; '.$parish_n.'</span>
  <span>For internal record-keeping &mdash; not a substitute for the official certificate.</span>
</div>
<script>
window.onload=function(){window.print();};</script>
</body></html>';

        echo json_encode(['success' => true, 'html' => $html]);
        exit;
    }

    // Generate certificate
    if ($_GET['ajax'] === 'generate_cert' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT sr.*, p.name AS parish_name, p.address AS parish_address, p.priest_name
                                FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sr
                                LEFT JOIN parishes p ON sr.parish_id = p.id
                                WHERE sr.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();

        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Record not found.']); exit; }

        // Generate certificate number if not exists
        $cert_number = $rec['certificate_number'];
        if (!$cert_number) {
            $abbrev_map = [
                'Baptism' => 'BAP', 'Wedding' => 'WED', 'Confirmation' => 'CON',
                'Funeral' => 'FUN', 'Blessing' => 'BLS', 'Mass Intention' => 'MAS'
            ];
            $abbr = $abbrev_map[$rec['record_type']] ?? strtoupper(substr($rec['record_type'], 0, 3));
            $cert_number = 'AVSJ-' . date('Y') . '-' . $abbr . '-' . str_pad($rec['id'], 5, '0', STR_PAD_LEFT);

            $upd = $conn->prepare("UPDATE sacramental_records SET certificate_number=?, certificate_generated_at=NOW() WHERE id=?");
            $upd->bind_param('si', $cert_number, $id);
            $upd->execute();

            $rec['certificate_number'] = $cert_number;
            auditLog($user['id'], 'generate_certificate', 'sacramental_record', $id, "Certificate: $cert_number");
        }

        // Build QR verification URL
        $qr_data = app_url('public/verify.php?cert=' . urlencode($cert_number));
        $qr_url = getQRImageURL($qr_data, 100);

        $parish = ['name' => $rec['parish_name'], 'address' => $rec['parish_address']];
        $html = generateCertificateHTML($rec, $parish, $qr_url);

        echo json_encode(['success' => true, 'certificate_number' => $cert_number, 'html' => $html]);
        exit;
    }

    // Toggle archive
    if ($_GET['ajax'] === 'toggle_archive' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT status FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sacramental_records WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

        $new_status = $rec['status'] === 'active' ? 'archived' : 'active';
        $upd = $conn->prepare("UPDATE sacramental_records SET status=? WHERE id=?");
        $upd->bind_param('si', $new_status, $id);
        $upd->execute();

        auditLog($user['id'], $new_status === 'archived' ? 'archive_record' : 'restore_record', 'sacramental_record', $id);
        echo json_encode(['success' => true, 'message' => 'Record ' . ($new_status === 'archived' ? 'archived' : 'restored') . '.', 'new_status' => $new_status]);
        exit;
    }

    // Get application data to prefill record
    if ($_GET['ajax'] === 'from_application' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT a.*, u.name AS parishioner_name, s.name AS service_name, p.priest_name, p.name AS parish_name
                                FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
                                JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                                LEFT JOIN services s ON a.service_id = s.id
                                LEFT JOIN parishes p ON a.parish_id = p.id
                                WHERE a.id = ? AND a.status = 'approved'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        if (!$app) { echo json_encode(['success' => false, 'message' => 'Application not found or not approved.']); exit; }
        $form=json_decode($app['form_data']??'{}',true)?:[];
        foreach(['parishioner_name','child_name','candidate_name','full_name'] as $key){if(!empty($form[$key])&&is_string($form[$key])){$app['parishioner_name']=$form[$key];break;}}
        echo json_encode(['success' => true, 'data' => $app]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
$search      = trim($_GET['q'] ?? '');
$type_filter = $_GET['type'] ?? '';
$date_from   = $_GET['from'] ?? '';
$date_to     = $_GET['to'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page_num    = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;

$where  = [];
$params = [];
$types  = '';

if ($search) {
    $where[] = "(sr.parishioner_name LIKE ? OR sr.certificate_number LIKE ? OR sr.minister_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($type_filter) {
    $where[] = "sr.record_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}
if ($date_from) {
    $where[] = "sr.date_of_sacrament >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where[] = "sr.date_of_sacrament <= ?";
    $params[] = $date_to;
    $types .= 's';
}
if ($status_filter && in_array($status_filter, ['active','archived'])) {
    $where[] = "sr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$csql = "SELECT COUNT(*) as t FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sr $where_sql";
$stmt = $conn->prepare($csql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Fetch records
$sql = "SELECT sr.id, sr.application_id, sr.record_type, sr.parishioner_name, sr.date_of_sacrament, sr.minister_name,
               sr.certificate_number, sr.certificate_generated_at, sr.status, sr.created_at
        FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sr
        $where_sql
        ORDER BY sr.created_at DESC
        LIMIT ? OFFSET ?";
$btypes = $types . 'ii';
$bparams = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
if ($bparams) $stmt->bind_param($btypes, ...$bparams);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stat counts
$cnt_total      = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sacramental_records")->fetch_assoc()['t'];
$cnt_month      = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sacramental_records WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetch_assoc()['t'];
$cnt_certs      = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sacramental_records WHERE certificate_number IS NOT NULL")->fetch_assoc()['t'];
$cnt_active     = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sacramental_records WHERE status='active'")->fetch_assoc()['t'];

// Approved applications for dropdown
$app_stmt = $conn->prepare("SELECT a.id, u.name AS parishioner_name, s.name AS service_name, a.schedule
                            FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
                            JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                            LEFT JOIN services s ON a.service_id = s.id
                            WHERE a.status = 'approved' AND s.classification='Sacramental'
                            AND a.id NOT IN (SELECT application_id FROM (SELECT * FROM sacramental_records WHERE parish_id = {$scopeParish} AND (application_id IS NULL OR application_id IN (SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE s.classification='Sacramental'))) sacramental_records WHERE application_id IS NOT NULL)
                            ORDER BY a.created_at DESC");
$app_stmt->execute();
$approved_apps = $app_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get parish priest name for default minister
$parish_stmt = $conn->prepare("SELECT priest_name FROM parishes WHERE id = ?");
$parish_stmt->bind_param('i', $user['parish_id']);
$parish_stmt->execute();
$parish_info = $parish_stmt->get_result()->fetch_assoc();
$default_minister = $parish_info['priest_name'] ?? '';

$record_types = SACRAMENT_TYPES;
$pillMap = ['active' => 'pill-green', 'archived' => 'pill-wine'];
?>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Create Record Modal -->
<div class="modal-wrap" id="createModal">
  <div class="modal" style="max-width:600px">
    <h2>Create Sacramental Record</h2>
    <p>Fill in the details below or select an approved application to auto-fill.</p>
    <form id="createForm" onsubmit="return createRecord(event)">
      <div class="form-group">
        <label>From Approved Application (optional)</label>
        <select id="cr_application_id" onchange="loadApplication(this.value)" style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;background:#FAFAF8">
          <option value="">— Create from scratch —</option>
          <?php foreach ($approved_apps as $aa): ?>
          <option value="<?php echo $aa['id']; ?>">App #<?php echo $aa['id']; ?> — <?php echo htmlspecialchars($aa['parishioner_name']); ?> (<?php echo htmlspecialchars($aa['service_name'] ?? 'N/A'); ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Record Type *</label>
          <select onchange="loadManualFields()" id="cr_record_type" name="record_type" required style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;background:#FAFAF8">
            <option value="">Select type</option>
            <?php foreach ($record_types as $rt): ?>
            <option value="<?php echo $rt; ?>"><?php echo $rt; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date of Sacrament *</label>
          <input type="date" id="cr_date_of_sacrament" name="date_of_sacrament" required>
        </div>
        <div class="form-group form-full">
          <label>Parishioner Name *</label>
          <input type="text" id="cr_parishioner_name" name="parishioner_name" required placeholder="Full name">
        </div>
        <div class="form-group form-full">
          <label>Minister / Priest</label>
          <input type="text" id="cr_minister_name" name="minister_name" value="<?php echo htmlspecialchars($default_minister); ?>" placeholder="Name of minister">
        </div>
        <div class="form-group form-full">
          <label>Sponsors</label>
          <textarea id="cr_sponsors" name="sponsors" rows="2" placeholder="Names of sponsors (if any)"></textarea>
        </div>
        <div class="form-group form-full">
          <label>Remarks</label>
          <textarea id="cr_remarks" name="remarks" rows="2" placeholder="Additional notes"></textarea>
        </div>
      </div>
      <div id="manualFields"></div><div class="modal-actions">
        <button type="button" onclick="closeModal('createModal')" class="btn-sm btn-outline">Cancel</button>
        <button type="submit" class="btn-sm btn-navy">Create Record</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Record Modal -->
<div class="modal-wrap" id="editModal">
  <div class="modal" style="max-width:600px">
    <h2>Edit Sacramental Record</h2>
    <p>Update the record details below.</p>
    <form id="editForm" onsubmit="return updateRecord(event)">
      <input type="hidden" id="ed_id" name="id">
      <div class="form-grid">
        <div class="form-group">
          <label>Record Type *</label>
          <select id="ed_record_type" name="record_type" required style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;background:#FAFAF8">
            <?php foreach ($record_types as $rt): ?>
            <option value="<?php echo $rt; ?>"><?php echo $rt; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date of Sacrament *</label>
          <input type="date" id="ed_date_of_sacrament" name="date_of_sacrament" required>
        </div>
        <div class="form-group form-full">
          <label>Parishioner Name *</label>
          <input type="text" id="ed_parishioner_name" name="parishioner_name" required>
        </div>
        <div class="form-group form-full">
          <label>Minister / Priest</label>
          <input type="text" id="ed_minister_name" name="minister_name">
        </div>
        <div class="form-group form-full">
          <label>Remarks</label>
          <textarea id="ed_remarks" name="remarks" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="closeModal('editModal')" class="btn-sm btn-outline">Cancel</button>
        <button type="submit" class="btn-sm btn-gold">Update Record</button>
      </div>
    </form>
  </div>
</div>

<!-- View Record Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:650px;width:100%">
    <div id="viewContent" style="min-height:200px">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Parish Management</div>
    <h1 class="sec-title">Sacramental Records</h1>
    <p class="sec-sub">Manage parish sacramental records and generate certificates.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button onclick="openModal('createModal')" class="btn-sm btn-navy">[icon:plus] New Record</button>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stats-grid">
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:file]</div>
    <div class="stat-label">Total Records</div>
    <div class="stat-value"><?php echo $cnt_total; ?></div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon">[icon:calendar]</div>
    <div class="stat-label">This Month</div>
    <div class="stat-value"><?php echo $cnt_month; ?></div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:file]</div>
    <div class="stat-label">Certificates Generated</div>
    <div class="stat-value"><?php echo $cnt_certs; ?></div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:check]</div>
    <div class="stat-label">Active Records</div>
    <div class="stat-value"><?php echo $cnt_active; ?></div>
  </div>
</div>

<!-- SEARCH & FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="records.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, certificate #, minister..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <select name="type" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Types</option>
        <?php foreach ($record_types as $rt): ?>
        <option value="<?php echo $rt; ?>" <?php echo $type_filter===$rt?'selected':''; ?>><?php echo $rt; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
        <option value="archived" <?php echo $status_filter==='archived'?'selected':''; ?>>Archived</option>
      </select>
      <input type="date" name="from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From"
        style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none" onchange="this.form.submit()">
      <input type="date" name="to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To"
        style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none" onchange="this.form.submit()">
      <?php if ($search || $type_filter || $date_from || $date_to || $status_filter): ?>
      <a href="records.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- RECORDS TABLE -->
<div class="card">
  <div class="card-head">
    <h3>Sacramental Records</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Parishioner</th>
            <th>Record Type</th>
            <th>Date of Sacrament</th>
            <th>Minister</th>
            <th>Certificate #</th>
            <th>Status</th>
            <th style="width:200px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($records)): ?>
          <tr><td colspan="7" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No records found.</td></tr>
          <?php endif; ?>
          <?php foreach ($records as $r):
            $pill = $pillMap[$r['status']] ?? 'pill-amber';
          ?>
          <tr id="rec-row-<?php echo $r['id']; ?>">
            <td><div style="font-weight:500"><?php echo htmlspecialchars($r['parishioner_name']); ?></div></td>
            <td><span class="pill pill-navy"><?php echo htmlspecialchars($r['record_type']); ?></span></td>
            <td><?php echo date('M j, Y', strtotime($r['date_of_sacrament'])); ?></td>
            <td style="font-size:.8rem;color:var(--ink-60)"><?php echo htmlspecialchars($r['minister_name'] ?: '—'); ?></td>
            <td>
              <?php if ($r['certificate_number']): ?>
              <span style="font-family:monospace;font-size:.75rem;color:var(--green);font-weight:500"><?php echo htmlspecialchars($r['certificate_number']); ?></span>
              <?php else: ?>
              <span style="font-size:.72rem;color:var(--ink-30)">Not generated</span>
              <?php endif; ?>
            </td>
            <td><span class="pill <?php echo $pill; ?>" id="rstatus-<?php echo $r['id']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <?php if ($r['application_id']): ?><a class="act-btn" href="record_application.php?id=<?= (int)$r['id'] ?>"><?= h(t('Application details')) ?></a><?php endif; ?>
                <button onclick="viewRecord(<?php echo $r['id']; ?>)" class="act-btn act-navy" title="View">[icon:eye]</button>
                <button onclick="editRecord(<?php echo $r['id']; ?>)" class="act-btn act-gold" title="Edit">[icon:edit]</button>
                <button onclick="printRecord(<?php echo $r['id']; ?>)" class="act-btn act-navy" title="Print Record" style="border-color:rgba(27,42,74,.3)">[icon:print] Print</button>
                <button onclick="generateCert(<?php echo $r['id']; ?>)" class="act-btn act-green" title="Generate Certificate">[icon:file] Cert</button>
                <button onclick="toggleArchive(<?php echo $r['id']; ?>)" class="act-btn act-wine" title="<?php echo $r['status']==='active'?'Delete from active records':'Restore'; ?>" id="arch-btn-<?php echo $r['id']; ?>">
                  <?php echo $r['status']==='active' ? '[icon:archive]' : '[icon:refresh]'; ?>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($total_pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--ink-10);flex-wrap:wrap;gap:10px">
    <span style="font-size:.78rem;color:var(--ink-30)">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?></span>
    <div style="display:flex;gap:4px">
      <?php
      $base_q = http_build_query(array_filter(['q'=>$search,'type'=>$type_filter,'status'=>$status_filter,'from'=>$date_from,'to'=>$date_to]));
      if ($page_num > 1): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num-1; ?>" class="act-btn act-navy">&larr; Prev</a>
      <?php endif;
      for ($pp = max(1,$page_num-2); $pp <= min($total_pages,$page_num+2); $pp++):
        $as = $pp===$page_num ? 'background:var(--navy);color:var(--white);' : '';
      ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $pp; ?>" class="act-btn" style="<?php echo $as; ?>min-width:32px;justify-content:center;border:1px solid var(--ink-10)"><?php echo $pp; ?></a>
      <?php endfor;
      if ($page_num < $total_pages): ?>
      <a href="?<?php echo $base_q; ?>&page=<?php echo $page_num+1; ?>" class="act-btn act-navy">Next &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
async function loadManualFields(){const box=document.getElementById('manualFields');box.replaceChildren();if(document.getElementById('cr_application_id').value)return;try{const result=await fetch('records.php?ajax=manual_fields&type='+encodeURIComponent(document.getElementById('cr_record_type').value));if(!result.ok)throw Error();box.innerHTML=await result.text();}catch(e){showToast('Unable to load record fields. Please retry.','error');}}
function loadApplication(appId) {
    loadManualFields();
    if (!appId) {
        document.getElementById('cr_record_type').value = '';
        document.getElementById('cr_parishioner_name').value = '';
        document.getElementById('cr_date_of_sacrament').value = '';
        document.getElementById('cr_minister_name').value = '<?php echo addslashes($default_minister); ?>';
        document.getElementById('cr_sponsors').value = '';
        document.getElementById('cr_remarks').value = '';
        return;
    }
    setLoading(true);
    fetch('records.php?ajax=from_application&id=' + appId)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const d = data.data;
            document.getElementById('cr_parishioner_name').value = d.parishioner_name || '';
            document.getElementById('cr_minister_name').value = d.priest_name || '<?php echo addslashes($default_minister); ?>';
            if (d.schedule) document.getElementById('cr_date_of_sacrament').value = d.schedule.substring(0, 10);
            // Try to match service name to record type
            const sn = (d.service_name || '').toLowerCase();
            const typeMap = {'baptism':'Baptism','wedding':'Wedding','confirmation':'Confirmation','funeral':'Funeral','blessing':'Blessing','mass':'Mass Intention'};
            for (const [k, v] of Object.entries(typeMap)) {
                if (sn.includes(k)) { document.getElementById('cr_record_type').value = v; break; }
            }
        }).catch(() => { setLoading(false); showToast('Failed to load application.', 'error'); });
}

function createRecord(e) {
    e.preventDefault();
    setLoading(true);
    const fd = new FormData(document.getElementById('createForm'));
    fd.append('record_type', document.getElementById('cr_record_type').value);
    fd.append('parishioner_name', document.getElementById('cr_parishioner_name').value);
    fd.append('date_of_sacrament', document.getElementById('cr_date_of_sacrament').value);
    fd.append('minister_name', document.getElementById('cr_minister_name').value);
    fd.append('sponsors', document.getElementById('cr_sponsors').value);
    fd.append('remarks', document.getElementById('cr_remarks').value);
    fd.append('application_id', document.getElementById('cr_application_id').value);

    fetch('records.php?ajax=create', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast(data.message, 'success');
                closeModal('createModal');
                setTimeout(() => location.reload(), 1000);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
    return false;
}

function editRecord(id) {
    setLoading(true);
    fetch('records.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const d = data.data;
            document.getElementById('ed_id').value = d.id;
            document.getElementById('ed_record_type').value = d.record_type;
            document.getElementById('ed_parishioner_name').value = d.parishioner_name;
            document.getElementById('ed_date_of_sacrament').value = d.date_of_sacrament;
            document.getElementById('ed_minister_name').value = d.minister_name || '';

            document.getElementById('ed_remarks').value = d.remarks || '';
            openModal('editModal');
        }).catch(() => { setLoading(false); showToast('Failed to load record.', 'error'); });
}

function updateRecord(e) {
    e.preventDefault();
    setLoading(true);
    const fd = new FormData();
    fd.append('id', document.getElementById('ed_id').value);
    fd.append('record_type', document.getElementById('ed_record_type').value);
    fd.append('parishioner_name', document.getElementById('ed_parishioner_name').value);
    fd.append('date_of_sacrament', document.getElementById('ed_date_of_sacrament').value);
    fd.append('minister_name', document.getElementById('ed_minister_name').value);

    fd.append('remarks', document.getElementById('ed_remarks').value);

    fetch('records.php?ajax=update', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast(data.message, 'success');
                closeModal('editModal');
                setTimeout(() => location.reload(), 1000);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
    return false;
}

function viewRecord(id) {
    document.getElementById('viewContent').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px"><div class="spinner" style="border-top-color:var(--navy)"></div></div>';
    openModal('viewModal');
    fetch('records.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('viewContent').innerHTML = '<p style="color:var(--wine);padding:20px">' + data.message + '</p>'; return; }
            const d = data.data;
            const original={...d};const safe=value=>{const el=document.createElement('span');el.textContent=value??'';return el.innerHTML;};for(const key of ['parishioner_name','record_type','minister_name','parish_name','sponsors','remarks','certificate_number','created_by_name'])d[key]=safe(d[key]);
            const sc = d.status === 'active' ? 'green' : 'wine';
            document.getElementById('viewContent').innerHTML = `
              <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px">
                <div>
                  <h2 style="font-family:var(--fh);font-size:1.3rem">${d.parishioner_name}</h2>
                  <p style="font-size:.8rem;color:var(--ink-60);margin-top:2px">${d.record_type} &middot; Record #${d.id}</p>
                </div>
                <span class="pill pill-${sc}" style="font-size:.78rem;padding:5px 14px">${d.status.charAt(0).toUpperCase()+d.status.slice(1)}</span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
                ${[
                  ['Record Type', d.record_type],
                  ['Date of Sacrament', d.date_of_sacrament ? new Date(d.date_of_sacrament + 'T00:00:00').toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'}) : '\u2014'],
                  ['Minister', d.minister_name || '\u2014'],
                  ['Parish', d.parish_name || '\u2014'],
                  ['Sponsors', d.sponsors || '\u2014'],
                  ['Remarks', d.remarks || '\u2014'],
                  ['Certificate #', d.certificate_number || 'Not generated'],
                  ['Certificate Date', d.certificate_generated_at ? new Date(d.certificate_generated_at).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true}) : '\u2014'],
                  ['Created By', d.created_by_name || '\u2014'],
                  ['Created At', new Date(d.created_at).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true})]
                ].map(([l,v])=>`
                  <div style="background:#F8F6F2;border-radius:8px;padding:10px 12px">
                    <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:3px">${l}</div>
                    <div style="font-size:.83rem;font-weight:500;color:var(--ink)">${v}</div>
                  </div>`).join('')}
              </div>
              ${d.certificate_number ? `
              <div class="notice notice-green" style="margin-bottom:14px">
                <span>[icon:file]</span>
                <span>Certificate <strong>${d.certificate_number}</strong> has been generated. Click below to print.</span>
              </div>` : ''}
              <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--ink-10)">
                <button onclick="closeModal('viewModal');generateCert(${d.id})" class="btn-sm btn-green">[icon:file] ${d.certificate_number ? 'Print' : 'Generate'} Certificate</button>
                <button onclick="closeModal('viewModal');editRecord(${d.id})" class="btn-sm btn-gold">[icon:edit] Edit</button>
                <button onclick="closeModal('viewModal')" class="btn-sm btn-outline">Close</button>
              </div>`;
            const answers=JSON.parse(original.form_data||'{}'),schema=JSON.parse(original.form_schema||'[]');
            for(const field of schema){const row=document.createElement('p');row.textContent=field.field_label+': '+(answers[field.field_name||'field_'+field.id]||'');document.getElementById('viewContent').append(row);}
        });
}

function printRecord(id) {
    setLoading(true);
    fetch('records.php?ajax=print_record&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const w = window.open('', '_blank');
            w.document.write(data.html);
            w.document.close();
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function generateCert(id) {
    setLoading(true);
    fetch('records.php?ajax=generate_cert&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            showToast('Certificate ' + data.certificate_number + ' generated!', 'success');
            // Open printable certificate in new tab
            const w = window.open('', '_blank');
            w.document.write(data.html);
            w.document.close();
            setTimeout(() => location.reload(), 1500);
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function toggleArchive(id) {
    const action = document.getElementById('arch-btn-' + id).title;
    if (!confirm(action + ' this record? History and issued certificates are retained.')) return;
    setLoading(true);
    fetch('records.php?ajax=toggle_archive&id=' + id, {method:'POST'})
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast(data.message, 'success');
                const badge = document.getElementById('rstatus-' + id);
                if (badge) {
                    badge.className = 'pill ' + (data.new_status === 'active' ? 'pill-green' : 'pill-wine');
                    badge.textContent = data.new_status.charAt(0).toUpperCase() + data.new_status.slice(1);
                }
                const btn = document.getElementById('arch-btn-' + id);
                if (btn) {
                    btn.title = data.new_status === 'active' ? 'Delete from active records' : 'Restore';
                    btn.innerHTML = data.new_status === 'active' ? '[icon:archive]' : '[icon:refresh]';
                }
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
