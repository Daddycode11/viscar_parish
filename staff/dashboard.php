<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Dashboard — Unified for Secretary & Bookkeeper
 * Adapts content dynamically based on the logged-in staff role.
 * Features: live stats, pending applications, schedule, payments, quick actions, activity feed
 */

require_once APP_ROOT.'/includes/dashboard_filter.php';
require_once APP_ROOT.'/includes/financial_totals.php';
$staffApplicationDate=dashboard_date_sql('created_at');$staffPaymentDate=dashboard_date_sql('created_at');$staffScheduleDate=dashboard_date_sql('a.schedule');
$staffFinancial=financial_totals($dashboardFrom,$dashboardTo,(int)$user['parish_id']);
$page_id    = 'dashboard';
$page_title = 'Dashboard';
$page_sub   = 'Overview';
include 'includes/layout.php';

// ── AJAX: Live stats refresh ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    $stats = [];

    $r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications WHERE status='pending'");
    $stats['pending_apps'] = $r ? (int)$r->fetch_assoc()['t'] : 0;

    $r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications WHERE status='approved'");
    $stats['approved_apps'] = $r ? (int)$r->fetch_assoc()['t'] : 0;

    $r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications");
    $stats['total_apps'] = $r ? (int)$r->fetch_assoc()['t'] : 0;

    $r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications WHERE DATE(created_at)=CURDATE()");
    $stats['today_apps'] = $r ? (int)$r->fetch_assoc()['t'] : 0;

    $r = $conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish}) AND {$staffPaymentDate}) payments WHERE status='completed'");
    $stats['total_revenue'] = $staffFinancial['verified_revenue'];
    $stats['net_revenue']=$staffFinancial['net_revenue'];

    $r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish}) AND {$staffPaymentDate}) payments WHERE status='pending'");
    $stats['pending_payments'] = $r ? (int)$r->fetch_assoc()['t'] : 0;

    $r = $conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish}) AND {$staffPaymentDate}) payments WHERE status='completed' AND DATE(paid_at)=CURDATE()");
    $stats['today_revenue'] = $r ? (float)$r->fetch_assoc()['t'] : 0;

    $r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) users WHERE role='parishioner' AND status='active'");
    $stats['total_parishioners'] = $r ? (int)$r->fetch_assoc()['t'] : 0;

    $stats['last_updated'] = date('g:i A');

    echo json_encode($stats);
    exit;
}

// ── AJAX: Assign/Update Schedule with Conflict Detection ─────────────

// Audit log helper (global for all AJAX endpoints)
function log_staff_action($conn, $staff_id, $action, $target_type, $target_id, $details = null) {
  $stmt = $conn->prepare("INSERT INTO staff_audit_log (staff_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param('issis', $staff_id, $action, $target_type, $target_id, $details);
  $stmt->execute();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'assign_schedule' && isset($_POST['id']) && isset($_POST['schedule'])) {

  header('Content-Type: application/json');
  $id = (int)$_POST['id'];
  $schedule = $_POST['schedule'];
  // Get application details
  $staff_id = isset($user['id']) ? (int)$user['id'] : 0;
  $stmt = $conn->prepare("SELECT parish_id, service_id FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $app = $stmt->get_result()->fetch_assoc();
  if (!$app) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
  }
  // Check for conflicts: same parish, service, and overlapping schedule
  $conflict = false;
  $conf_stmt = $conn->prepare("SELECT id FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE parish_id=? AND service_id=? AND schedule=? AND status='approved' AND id<>?");
  $conf_stmt->bind_param('iisi', $app['parish_id'], $app['service_id'], $schedule, $id);
  $conf_stmt->execute();
  $conf = $conf_stmt->get_result()->fetch_assoc();
  if ($conf) $conflict = true;
  if ($conflict) {
    echo json_encode(['success' => false, 'message' => 'Schedule conflict detected. Another approved application exists for this service and time.']);
    exit;
  }
  // Update schedule
  $update = $conn->prepare("UPDATE applications SET schedule=? WHERE id=?");
  $update->bind_param('si', $schedule, $id);
  $ok = $update->execute();
  if ($ok) {
    log_staff_action($conn, $staff_id, 'assign_schedule', 'application', $id, "Schedule: $schedule");
  }
  echo json_encode(['success' => $ok, 'message' => $ok ? 'Schedule updated.' : 'Failed to update schedule.']);
  exit;
}

// ── AJAX: Request Additional Documents ─────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'request_docs' && isset($_POST['id']) && isset($_POST['docs'])) {
  header('Content-Type: application/json');
  $id = (int)$_POST['id'];
  $docs = trim($_POST['docs']);
  $msg = isset($_POST['msg']) ? trim($_POST['msg']) : '';
  $staff_id = isset($user['id']) ? (int)$user['id'] : 0;
  $stmt = $conn->prepare("INSERT INTO application_document_requests (application_id, documents, message, requested_at) VALUES (?, ?, ?, NOW())");
  $stmt->bind_param('iss', $id, $docs, $msg);
  $ok = $stmt->execute();
  if ($ok) {
    log_staff_action($conn, $staff_id, 'request_docs', 'application', $id, "Docs: $docs");
  }
  echo json_encode(['success' => $ok, 'message' => $ok ? 'Document request sent.' : 'Failed to send request.']);
  exit;
}

// ── AJAX: Approve application (with conflict check) ────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'approve' && isset($_POST['id'])) {
  header('Content-Type: application/json');
  $id = (int)$_POST['id'];
  // Get application details
  $staff_id = isset($user['id']) ? (int)$user['id'] : 0;
  $stmt = $conn->prepare("SELECT parish_id, service_id, schedule FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $app = $stmt->get_result()->fetch_assoc();
  if (!$app) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
  }
  // Check for conflicts: same parish, service, and overlapping schedule
  $conflict = false;
  $conf_stmt = $conn->prepare("SELECT id FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE parish_id=? AND service_id=? AND schedule=? AND status='approved' AND id<>?");
  $conf_stmt->bind_param('iisi', $app['parish_id'], $app['service_id'], $app['schedule'], $id);
  $conf_stmt->execute();
  $conf = $conf_stmt->get_result()->fetch_assoc();
  if ($conf) $conflict = true;
  if ($conflict) {
    echo json_encode(['success' => false, 'message' => 'Schedule conflict detected. Another approved application exists for this service and time.']);
    exit;
  }
  $stmt = $conn->prepare("UPDATE applications SET status='approved' WHERE id=?");
  $stmt->bind_param('i', $id);
  $success = $stmt->execute();
  if ($success) {
    log_staff_action($conn, $staff_id, 'approve', 'application', $id, "Application approved");
  }
  echo json_encode(['success' => $success, 'message' => $success ? "Application #$id approved." : 'Failed to approve.']);
  exit;
}

// ── AJAX: Reject application ─────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'reject' && isset($_POST['id']) && isset($_POST['reason'])) {
  header('Content-Type: application/json');
  $id = (int)$_POST['id'];
  $reason = trim($_POST['reason']);
  // Store rejection reason (requires rejection_reason column)
  $staff_id = isset($user['id']) ? (int)$user['id'] : 0;
  $stmt = $conn->prepare("UPDATE applications SET status='rejected', rejection_reason=? WHERE id=?");
  $stmt->bind_param('si', $reason, $id);
  $success = $stmt->execute();
  // TODO: Notify parishioner (stub)
  if ($success) {
    log_staff_action($conn, $staff_id, 'reject', 'application', $id, "Reason: $reason");
  }
  echo json_encode(['success' => $success, 'message' => $success ? "Application #$id rejected." : 'Failed to reject.']);
  exit;
}

// ── AJAX: Verify payment ─────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'verify_payment' && isset($_POST['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $staff_id = isset($user['id']) ? (int)$user['id'] : 0;
    $stmt = $conn->prepare("UPDATE payments SET status='completed', paid_at=NOW() WHERE id=?");
    $stmt->bind_param('i', $id);
    $success = $stmt->execute();
    if ($success) {
      log_staff_action($conn, $staff_id, 'verify_payment', 'payment', $id, "Payment verified");
    }
    echo json_encode(['success' => $success, 'message' => $success ? "Payment #$id verified." : 'Verification failed.']);
    exit;
}

// ── FETCH REAL DATA ─────────────────────────────

// Stats
$pending_apps = 0;
$approved_apps = 0;
$total_apps = 0;
$today_apps = 0;
$total_revenue = 0;
$pending_payments = 0;
$today_revenue = 0;
$total_parishioners = 0;

$r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications WHERE status='pending'");
if ($r) $pending_apps = (int)$r->fetch_assoc()['t'];

$r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications WHERE status='approved'");
if ($r) $approved_apps = (int)$r->fetch_assoc()['t'];

$r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications");
if ($r) $total_apps = (int)$r->fetch_assoc()['t'];

$r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications WHERE DATE(created_at)=CURDATE()");
if ($r) $today_apps = (int)$r->fetch_assoc()['t'];

$r = $conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish}) AND {$staffPaymentDate}) payments WHERE status='completed'");
$total_revenue = $staffFinancial['verified_revenue'];

$r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish}) AND {$staffPaymentDate}) payments WHERE status='pending'");
if ($r) $pending_payments = (int)$r->fetch_assoc()['t'];

$r = $conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish}) AND {$staffPaymentDate}) payments WHERE status='completed' AND DATE(paid_at)=CURDATE()");
if ($r) $today_revenue = (float)$r->fetch_assoc()['t'];

$r = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) users WHERE role='parishioner' AND status='active'");
if ($r) $total_parishioners = (int)$r->fetch_assoc()['t'];

// Recent pending applications
$recent_apps = [];
$sql = "SELECT a.id, a.service_id, a.schedule, a.status, a.payment_status, a.created_at,
               u.name AS parishioner_name, u.email AS parishioner_email
        FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) a
        JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
        WHERE a.status = 'pending'
        ORDER BY a.created_at DESC
        LIMIT 8";
$r = $conn->query($sql);
if ($r) { while ($row = $r->fetch_assoc()) $recent_apps[] = $row; }

// Upcoming schedule (approved applications)
$upcoming = [];
$sql = "SELECT a.id, a.service_id, a.schedule, a.status, u.name AS parishioner_name
        FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
        JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
        WHERE a.status = 'approved' AND a.schedule >= NOW() AND {$staffScheduleDate}
        ORDER BY a.schedule ASC
        LIMIT 6";
$r = $conn->query($sql);
if ($r) { while ($row = $r->fetch_assoc()) $upcoming[] = $row; }

// Pending payments (for bookkeeper)
$pending_pay_list = [];
if ($staff_role === 'bookkeeper') {
    $sql = "SELECT p.id, p.amount, p.payment_method, p.created_at AS pay_date, p.application_id,
                   a.service_id, u.name AS parishioner_name
            FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
            JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) a ON p.application_id = a.id
            JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
            WHERE p.status = 'pending'
            ORDER BY p.created_at DESC
            LIMIT 8";
    $r = $conn->query($sql);
    if ($r) { while ($row = $r->fetch_assoc()) $pending_pay_list[] = $row; }
}

// Service breakdown
$service_counts = [];
$r = $conn->query("SELECT service_id, COUNT(*) as cnt FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) applications GROUP BY service_id ORDER BY cnt DESC LIMIT 6");
if ($r) { while ($row = $r->fetch_assoc()) $service_counts[] = $row; }
$max_service = !empty($service_counts) ? max(array_column($service_counts, 'cnt')) : 1;

// Recent activity (latest applications)
$recent_activity = [];
$sql = "SELECT a.id, a.service_id, a.status, a.created_at, u.name AS parishioner_name
        FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish} AND {$staffApplicationDate}) a
        JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 8";
$r = $conn->query($sql);
if ($r) { while ($row = $r->fetch_assoc()) $recent_activity[] = $row; }

$pillMap = ['approved'=>'pill-green','rejected'=>'pill-wine','pending'=>'pill-amber'];
$role_label = ucfirst($staff_role);
?>

<style>
.stat-value { transition: color .3s; }
.stat-value.updating { color: var(--gold) !important; }
.live-dot { display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }
.app-row-actions { opacity: 1; transition: opacity .2s; }
tbody tr:hover .app-row-actions { opacity: 1; }
.sched-item{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid var(--ink-10)}
.sched-item:last-child{border-bottom:none}
.sched-date{width:50px;text-align:center;flex-shrink:0}
.sched-date .day{font-family:var(--fh);font-size:1.5rem;font-weight:600;color:var(--navy);line-height:1}
.sched-date .month{font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-30)}
.sched-info{flex:1}
.sched-info .title{font-weight:500;font-size:.84rem;color:var(--ink)}
.sched-info .meta{font-size:.72rem;color:var(--ink-30);margin-top:2px}
</style>

<!-- Toast & Loading -->
<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Reject Modal -->
<div class="modal-wrap" id="rejectModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Reject Application</h2>
    <p>Provide a reason for rejection. The parishioner will be notified.</p>
    <div class="form-group">
      <label>Rejection Reason *</label>
      <textarea id="rejectReason" rows="4" placeholder="e.g. Missing PSA baptismal certificate..." style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none"></textarea>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('rejectModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="submitReject()" class="btn-sm btn-wine">Confirm Rejection</button>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag"><?php echo $role_label; ?> Dashboard</div>
    <h1 class="sec-title">Welcome, <?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?></h1>
    <p class="sec-sub">
      <?php if ($staff_role === 'secretary'): ?>
        Manage applications, schedules, and parishioner services.
      <?php else: ?>
        Monitor payments, verify transactions, and track parish finances.
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-size:.72rem;color:var(--ink-30);display:flex;align-items:center;gap:5px">
      <span class="live-dot"></span> Live &middot; Updated <span id="lastUpdated"><?php echo date('g:i A'); ?></span>
    </span>
    <button onclick="refreshStats()" class="btn-sm btn-outline" id="refreshBtn">[icon:refresh] Refresh</button>
  </div>
</div>

<!-- STAT CARDS -->
<?php render_dashboard_filter(); ?>
<div class="stats-grid">
<?php if ($staff_role === 'secretary'): ?>
  <div class="stat-card stat-amber">
    <div class="stat-icon">[icon:clock]</div>
    <div class="stat-label">Pending Applications</div>
    <div class="stat-value" id="stat-pending"><?php echo $pending_apps; ?></div>
    <div class="stat-delta down">Awaiting your review</div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:check]</div>
    <div class="stat-label">Approved</div>
    <div class="stat-value" id="stat-approved"><?php echo $approved_apps; ?></div>
    <div class="stat-delta">Processed applications</div>
  </div>
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:clipboard]</div>
    <div class="stat-label">Total Applications</div>
    <div class="stat-value" id="stat-total"><?php echo $total_apps; ?></div>
    <div class="stat-delta">All-time records</div>
  </div>
  <div class="stat-card stat-blue">
    <div class="stat-icon">[icon:sun]</div>
    <div class="stat-label">Today's Filings</div>
    <div class="stat-value" id="stat-today"><?php echo $today_apps; ?></div>
    <div class="stat-delta"><?php echo date('F j, Y'); ?></div>
  </div>
<?php else: // bookkeeper ?>
  <div class="stat-card stat-gold">
    <div class="stat-icon">₱</div>
    <div class="stat-label">Total Revenue</div>
    <div class="stat-value" id="stat-revenue">₱<?php echo number_format($total_revenue); ?></div>
    <div class="stat-delta">Completed payments</div>
  </div>
  <div class="stat-card stat-amber">
    <div class="stat-icon">[icon:clock]</div>
    <div class="stat-label">Pending Payments</div>
    <div class="stat-value" id="stat-pending-pay"><?php echo $pending_payments; ?></div>
    <div class="stat-delta down">Need verification</div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:sun]</div>
    <div class="stat-label">Today's Revenue</div>
    <div class="stat-value" id="stat-today-rev">₱<?php echo number_format($today_revenue); ?></div>
    <div class="stat-delta"><?php echo date('F j, Y'); ?></div>
  </div>
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:clipboard]</div>
    <div class="stat-label">Total Applications</div>
    <div class="stat-value" id="stat-total"><?php echo $total_apps; ?></div>
    <div class="stat-delta">All-time records</div>
  </div>
<?php endif; ?>
</div>

<!-- PENDING NOTICE -->
<?php if ($staff_role === 'secretary' && $pending_apps > 0): ?>
<div class="notice notice-amber" id="pendingNotice">
  <span>[icon:alert]</span>
  <span><strong id="pendingCount"><?php echo $pending_apps; ?> application<?php echo $pending_apps > 1 ? 's' : ''; ?></strong> pending your review. Please process them promptly to keep parishioners informed.</span>
</div>
<?php elseif ($staff_role === 'bookkeeper' && $pending_payments > 0): ?>
<div class="notice notice-amber" id="pendingNotice">
  <span>[icon:alert]</span>
  <span><strong><?php echo $pending_payments; ?> payment<?php echo $pending_payments > 1 ? 's' : ''; ?></strong> are awaiting verification. Please review and confirm transactions.</span>
</div>
<?php endif; ?>

<div class="grid-2-1">

  <!-- LEFT COLUMN: Main content -->
  <div>

    <?php if ($staff_role === 'secretary'): ?>
    <!-- PENDING APPLICATIONS TABLE -->
    <div class="card">
      <div class="card-head">
        <h3>Pending Applications</h3>
        <a href="applications.php" class="btn-sm btn-outline">View All</a>
      </div>
      <div class="card-body" style="padding:0">
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr><th>#</th><th>Parishioner</th><th>Service</th><th>Schedule</th><th>Payment</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php if (empty($recent_apps)): ?>
              <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No pending applications. All caught up!</td></tr>
              <?php endif; ?>
              <?php foreach ($recent_apps as $app):
                $ppill = $app['payment_status'] === 'paid' ? 'pill-green' : 'pill-wine';
                $sched = $app['schedule'] ? date('M j, Y', strtotime($app['schedule'])) : '—';
              ?>
              <tr id="app-row-<?php echo $app['id']; ?>">
                <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $app['id']; ?></td>
                <td>
                  <div style="font-weight:500"><?php echo htmlspecialchars($app['parishioner_name']); ?></div>
                  <div style="font-size:.72rem;color:var(--ink-30)"><?php echo htmlspecialchars($app['parishioner_email']); ?></div>
                </td>
                <td><?php echo htmlspecialchars($app['service_id']); ?></td>
                <td style="font-size:.78rem;color:var(--ink-60)"><?php echo $sched; ?>
                  <button onclick="openScheduleModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars(addslashes($app['schedule'])); ?>')" class="btn-sm btn-outline" style="margin-left:6px;font-size:.7rem;padding:2px 8px">Set</button>
                </td>
                <td><span class="pill <?php echo $ppill; ?>"><?php echo ucfirst($app['payment_status']); ?></span></td>
                <td>
                  <div class="app-row-actions" style="display:flex;gap:4px">
                    <button onclick="approveApp(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars(addslashes($app['parishioner_name'])); ?>')" class="act-btn act-green" title="Approve">[icon:check] Approve</button>
                    <button onclick="openRejectModal(<?php echo $app['id']; ?>)" class="act-btn act-wine" title="Reject">[icon:close] Reject</button>
                    <button onclick="openDocRequestModal(<?php echo $app['id']; ?>)" class="act-btn act-navy" title="Request Documents">[icon:attachment] Request Docs</button>
                  </div>
                <!-- Request Documents Modal -->
                <div class="modal-wrap" id="docRequestModal">
                  <div class="modal">
                    <h2>Request Additional Documents</h2>
                    <p>Specify the documents you need from the parishioner. They will be notified.</p>
                    <div class="form-group">
                      <label>Documents Requested *</label>
                      <input type="text" id="docRequestInput" placeholder="e.g. PSA Birth Certificate, ID" style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none">
                    </div>
                    <div class="form-group">
                      <label>Message (optional)</label>
                      <textarea id="docRequestMsg" rows="3" placeholder="Additional instructions..." style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none"></textarea>
                    </div>
                    <div class="modal-actions">
                      <button onclick="closeModal('docRequestModal')" class="btn-sm btn-outline">Cancel</button>
                      <button onclick="submitDocRequest()" class="btn-sm btn-navy">Send Request</button>
                    </div>
                  </div>
                </div>
                </td>
              </tr>
              <!-- Schedule Modal -->
              <div class="modal-wrap" id="scheduleModal">
                <div class="modal">
                  <h2>Assign/Change Schedule</h2>
                  <p>Set the schedule for this application. Conflicts will be checked automatically.</p>
                  <div class="form-group">
                    <label>Schedule *</label>
                    <input type="datetime-local" id="scheduleInput" style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none">
                  </div>
                  <div class="modal-actions">
                    <button onclick="closeModal('scheduleModal')" class="btn-sm btn-outline">Cancel</button>
                    <button onclick="submitSchedule()" class="btn-sm btn-green">Save Schedule</button>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php else: // bookkeeper ?>
    <!-- PENDING PAYMENTS TABLE -->
    <div class="card">
      <div class="card-head">
        <h3>Pending Payments</h3>
        <a href="payments.php" class="btn-sm btn-outline">View All</a>
      </div>
      <div class="card-body" style="padding:0">
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr><th>#</th><th>Parishioner</th><th>Service</th><th>Method</th><th>Amount</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php if (empty($pending_pay_list)): ?>
              <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No pending payments. All verified!</td></tr>
              <?php endif; ?>
              <?php foreach ($pending_pay_list as $pay): ?>
              <tr id="pay-row-<?php echo $pay['id']; ?>">
                <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $pay['id']; ?></td>
                <td style="font-weight:500"><?php echo htmlspecialchars($pay['parishioner_name']); ?></td>
                <td><?php echo htmlspecialchars($pay['service_id']); ?></td>
                <td><span class="pill pill-navy"><?php echo htmlspecialchars($pay['payment_method']); ?></span></td>
                <td style="font-weight:600;color:var(--green)">₱<?php echo number_format($pay['amount']); ?></td>
                <td>
                  <div class="app-row-actions" style="display:flex;gap:4px">
                    <button onclick="verifyPayment(<?php echo $pay['id']; ?>)" class="act-btn act-green" title="Verify">[icon:check] Verify</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- UPCOMING SCHEDULE -->
    <div class="card">
      <div class="card-head">
        <h3>Upcoming Schedule</h3>
        <?php if ($staff_role === 'secretary'): ?>
        <a href="schedule.php" class="btn-sm btn-outline">Full Calendar</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:10px 22px">
        <?php if (empty($upcoming)): ?>
        <p style="text-align:center;padding:30px 0;color:var(--ink-30);font-style:italic">No upcoming scheduled services.</p>
        <?php endif; ?>
        <?php foreach ($upcoming as $sched):
          $dt = strtotime($sched['schedule']);
        ?>
        <div class="sched-item">
          <div class="sched-date">
            <div class="day"><?php echo date('j', $dt); ?></div>
            <div class="month"><?php echo date('M', $dt); ?></div>
          </div>
          <div class="sched-info">
            <div class="title">Service #<?php echo htmlspecialchars($sched['service_id']); ?></div>
            <div class="meta"><?php echo htmlspecialchars($sched['parishioner_name']); ?> &middot; <?php echo date('g:i A', $dt); ?></div>
          </div>
          <span class="pill pill-green">Confirmed</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- RIGHT COLUMN: Sidebar widgets -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Quick Actions -->
    <div class="card">
      <div class="card-head"><h3>Quick Actions</h3></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">
          <?php
          if ($staff_role === 'secretary') {
              $qa = [
                  ['[icon:clipboard]','applications.php','Applications'],
                  ['[icon:calendar]','schedule.php','Schedule'],
                  ['[icon:user]','parishioners.php','Parishioners'],
                  ['[icon:announcement]','announcements.php','Announcements'],
              ];
          } else {
              $qa = [
                  ['₱','payments.php','Payments'],
                  ['[icon:chart]','finance.php','Reports'],
                  ['[icon:swap]','requests.php','Refunds'],
                  ['[icon:receipt]','accounting.php','Accounting'],
              ];
          }
          foreach ($qa as [$icon, $href, $label]): ?>
          <a href="<?php echo $href; ?>" style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:13px 8px;border-radius:10px;background:#F0EDE8;border:1px solid var(--ink-10);font-size:.7rem;font-weight:500;color:var(--ink-60);text-align:center;transition:all .2s" onmouseover="this.style.background='var(--navy)';this.style.color='white'" onmouseout="this.style.background='#F0EDE8';this.style.color='var(--ink-60)'">
            <span style="font-size:1.1rem"><?php echo $icon; ?></span>
            <?php echo $label; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Service Breakdown -->
    <div class="card">
      <div class="card-head"><h3>Service Breakdown</h3></div>
      <div class="card-body">
        <?php if (empty($service_counts)): ?>
        <p style="text-align:center;color:var(--ink-30);font-size:.8rem;font-style:italic">No application data yet.</p>
        <?php endif; ?>
        <div class="bar-list">
          <?php
          $bar_colors = ['var(--navy)','var(--gold)','var(--green)','var(--wine)','var(--amber)','var(--blue)'];
          foreach ($service_counts as $i => $sc):
            $pct = round(($sc['cnt'] / $max_service) * 100);
            $color = $bar_colors[$i % count($bar_colors)];
          ?>
          <div>
            <div class="bar-top">
              <span class="bar-label">Service #<?php echo htmlspecialchars($sc['service_id']); ?></span>
              <span class="bar-val"><?php echo $sc['cnt']; ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Recent Activity Feed -->
    <div class="card">
      <div class="card-head">
        <h3>Recent Activity</h3>
        <span style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--green)"><span class="live-dot"></span>Live</span>
      </div>
      <div class="card-body" style="padding:0">
        <?php if (empty($recent_activity)): ?>
        <p style="text-align:center;padding:30px 0;color:var(--ink-30);font-size:.8rem;font-style:italic">No recent activity.</p>
        <?php endif; ?>
        <?php
        $dot_colors = ['pending'=>'amber','approved'=>'green','rejected'=>'wine'];
        foreach ($recent_activity as $act):
          $dot = $dot_colors[$act['status']] ?? 'navy';
          $time_str = $act['created_at'];
          $time_ago = '';
          if ($time_str) {
              $diff = time() - strtotime($time_str);
              if ($diff < 60) $time_ago = 'Just now';
              elseif ($diff < 3600) $time_ago = floor($diff/60) . 'm';
              elseif ($diff < 86400) $time_ago = floor($diff/3600) . 'h';
              else $time_ago = date('M j', strtotime($time_str));
          }
          $action_word = $act['status'] === 'pending' ? 'filed' : $act['status'];
        ?>
        <div style="display:flex;gap:12px;padding:11px 20px;border-bottom:1px solid var(--ink-10)">
          <div style="width:7px;height:7px;border-radius:50%;background:var(--<?php echo $dot; ?>);flex-shrink:0;margin-top:5px"></div>
          <div style="font-size:.79rem;color:var(--ink);flex:1;line-height:1.4">
            <?php echo htmlspecialchars($act['parishioner_name']); ?> &mdash;
            Service #<?php echo htmlspecialchars($act['service_id']); ?>
            <span class="pill <?php echo $pillMap[$act['status']] ?? 'pill-amber'; ?>" style="font-size:.6rem;padding:1px 6px"><?php echo ucfirst($action_word); ?></span>
          </div>
          <div style="font-size:.68rem;color:var(--ink-30);white-space:nowrap"><?php echo $time_ago; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<?php if ($staff_role === 'bookkeeper'): ?>
<!-- SERVICE REVENUE BREAKDOWN -->
<div class="card" style="margin-top:4px">
  <div class="card-head">
    <h3>Revenue by Service</h3>
    <a href="finance.php" class="card-tag" style="color:var(--gold)">Full Reports &rarr;</a>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Service</th><th>Applications</th><th>Completed Payments</th><th>Revenue</th></tr>
        </thead>
        <tbody>
          <?php
          $rev_sql = "SELECT a.service_id,
                             COUNT(DISTINCT a.id) as app_count,
                             COUNT(DISTINCT CASE WHEN p.status='completed' THEN p.id END) as paid_count,
                             IFNULL(SUM(CASE WHEN p.status='completed' THEN p.amount ELSE 0 END),0) as revenue
                      FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
                      LEFT JOIN (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p ON p.application_id = a.id
                      GROUP BY a.service_id
                      ORDER BY revenue DESC";
          $r = $conn->query($rev_sql);
          $rev_data = [];
          if ($r) { while ($row = $r->fetch_assoc()) $rev_data[] = $row; }
          $max_rev = !empty($rev_data) ? max(array_column($rev_data, 'revenue')) : 1;

          if (empty($rev_data)): ?>
          <tr><td colspan="4" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No revenue data yet.</td></tr>
          <?php endif; ?>

          <?php foreach ($rev_data as $rv):
            $pct = $max_rev > 0 ? round(($rv['revenue'] / $max_rev) * 100) : 0;
          ?>
          <tr>
            <td style="font-weight:500">Service #<?php echo htmlspecialchars($rv['service_id']); ?></td>
            <td style="text-align:center"><?php echo $rv['app_count']; ?></td>
            <td style="text-align:center">
              <span class="pill pill-green"><?php echo $rv['paid_count']; ?> paid</span>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <span style="font-weight:600;color:var(--green);min-width:80px">₱<?php echo number_format($rv['revenue']); ?></span>
                <div class="bar-track" style="flex:1"><div class="bar-fill" style="width:<?php echo $pct; ?>%;background:var(--gold)"></div></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
let pendingActionId = null;
let refreshInterval;

let scheduleActionId = null;

function openScheduleModal(id, current) {
  scheduleActionId = id;
  const input = document.getElementById('scheduleInput');
  if (current && current !== 'null' && current !== 'undefined' && current !== '0000-00-00 00:00:00') {
    // Format for datetime-local
    let dt = new Date(current);
    if (!isNaN(dt.getTime())) {
      let iso = dt.toISOString();
      input.value = iso.substring(0,16);
    } else {
      input.value = '';
    }
  } else {
    input.value = '';
  }
  openModal('scheduleModal');
}

function submitSchedule() {
  const val = document.getElementById('scheduleInput').value;
  if (!val) { document.getElementById('scheduleInput').style.borderColor = 'var(--wine)'; return; }
  setLoading(true);
  const fd = new FormData();
  fd.append('id', scheduleActionId);
  fd.append('schedule', val.replace('T', ' ') + ':00');
  fetch('dashboard.php?ajax=assign_schedule', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      setLoading(false);
      if (data.success) {
        showToast('[icon:check] ' + data.message, 'success');
        closeModal('scheduleModal');
        // Optionally, update the schedule cell in the table
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast('[icon:close] ' + data.message, 'error');
      }
    })
    .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── REFRESH STATS ─────────────────────────────
function refreshStats() {
    const btn = document.getElementById('refreshBtn');
    btn.textContent = '[icon:refresh] Refreshing\u2026';
    btn.disabled = true;
    document.querySelectorAll('.stat-value').forEach(el => el.classList.add('updating'));

    fetch('dashboard.php?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),ajax:'stats'}))
        .then(r => r.json())
        .then(data => {
            <?php if ($staff_role === 'secretary'): ?>
            const el = (id) => document.getElementById(id);
            if (el('stat-pending'))  el('stat-pending').textContent  = data.pending_apps;
            if (el('stat-approved')) el('stat-approved').textContent  = data.approved_apps;
            if (el('stat-total'))    el('stat-total').textContent     = data.total_apps;
            if (el('stat-today'))    el('stat-today').textContent     = data.today_apps;
            <?php else: ?>
            const el = (id) => document.getElementById(id);
            if (el('stat-revenue'))     el('stat-revenue').textContent     = '\u20B1' + Number(data.total_revenue).toLocaleString();
            if (el('stat-pending-pay')) el('stat-pending-pay').textContent = data.pending_payments;
            if (el('stat-today-rev'))   el('stat-today-rev').textContent   = '\u20B1' + Number(data.today_revenue).toLocaleString();
            if (el('stat-total'))       el('stat-total').textContent       = data.total_apps;
            <?php endif; ?>
            if (document.getElementById('lastUpdated')) {
                document.getElementById('lastUpdated').textContent = data.last_updated;
            }
            setTimeout(() => document.querySelectorAll('.stat-value').forEach(el => el.classList.remove('updating')), 600);
        })
        .catch(() => {})
        .finally(() => {
            btn.textContent = '[icon:refresh] Refresh';
            btn.disabled = false;
        });
}
refreshInterval = setInterval(refreshStats, 60000);
window.addEventListener('beforeunload', () => clearInterval(refreshInterval));

// ── APPROVE APP ───────────────────────────────
function approveApp(id, name) {
    if (!confirm('Approve application #' + id + ' for ' + name + '?')) return;
    setLoading(true);
    const fd = new FormData();
    fd.append('id', id);
    fetch('dashboard.php?ajax=approve', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
            if (data.success) {
                const row = document.getElementById('app-row-' + id);
                if (row) row.style.opacity = '0.4';
                showToast('[icon:check] ' + data.message, 'success');
                setTimeout(() => { if (row) row.remove(); }, 1500);
            } else {
                showToast('[icon:close] ' + data.message, 'error');
            }
        })
        .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── REJECT MODAL ──────────────────────────────
function openRejectModal(id) {
    pendingActionId = id;
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
}

function submitReject() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { document.getElementById('rejectReason').style.borderColor = 'var(--wine)'; return; }
    closeModal('rejectModal');
    setLoading(true);
    const fd = new FormData();
    fd.append('id', pendingActionId);
    fd.append('reason', reason);
    fetch('dashboard.php?ajax=reject', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
            if (data.success) {
                const row = document.getElementById('app-row-' + pendingActionId);
                if (row) row.style.opacity = '0.4';
                showToast('[icon:check] ' + data.message, 'success');
                setTimeout(() => { if (row) row.remove(); }, 1500);
            } else {
                showToast('[icon:close] ' + data.message, 'error');
            }
        })
        .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── VERIFY PAYMENT ────────────────────────────
function verifyPayment(id) {
    if (!confirm('Verify payment #' + id + '?')) return;
    setLoading(true);
    const fd = new FormData();
    fd.append('id', id);
    fetch('dashboard.php?ajax=verify_payment', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
            if (data.success) {
                const row = document.getElementById('pay-row-' + id);
                if (row) row.style.opacity = '0.4';
                showToast('[icon:check] ' + data.message, 'success');
                setTimeout(() => { if (row) row.remove(); }, 1500);
            } else {
                showToast('[icon:close] ' + data.message, 'error');
            }
        })
        .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── DOC REQUEST ──────────────────────────────
let docRequestActionId = null;
function openDocRequestModal(id) {
  docRequestActionId = id;
  document.getElementById('docRequestInput').value = '';
  document.getElementById('docRequestMsg').value = '';
  openModal('docRequestModal');
}
function submitDocRequest() {
  const docs = document.getElementById('docRequestInput').value.trim();
  const msg = document.getElementById('docRequestMsg').value.trim();
  if (!docs) { document.getElementById('docRequestInput').style.borderColor = 'var(--wine)'; return; }
  setLoading(true);
  const fd = new FormData();
  fd.append('id', docRequestActionId);
  fd.append('docs', docs);
  fd.append('msg', msg);
  fetch('dashboard.php?ajax=request_docs', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      setLoading(false);
      if (data.success) {
        showToast('[icon:check] ' + data.message, 'success');
        closeModal('docRequestModal');
      } else {
        showToast('[icon:close] ' + data.message, 'error');
      }
    })
    .catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}
</script>

<?php include 'includes/layout_footer.php'; ?>
