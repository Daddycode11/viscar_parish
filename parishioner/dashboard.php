<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

require_once __DIR__.'/../includes/dashboard_filter.php';
$appDateFilter=dashboard_date_sql('a.created_at');$paymentDateFilter=dashboard_date_sql('effective_payment_date');$eventDateFilter=dashboard_date_sql('event_date');
$page_id = 'dashboard'; $page_title = 'Dashboard'; $page_sub = 'Overview';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../includes/qr.php';

// ---------------------------------------------------------------------------
// AJAX: Return parish info (mass schedules + events) as JSON
// ---------------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'parish_info' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $pid = (int) $_GET['id'];

    // Mass schedules
    $ms = $conn->prepare("SELECT * FROM mass_schedules WHERE parish_id=? AND status='active' ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), time_start");
    $ms->bind_param('i', $pid);
    $ms->execute();
    $schedules = $ms->get_result()->fetch_all(MYSQLI_ASSOC);

    // Events
    $ev = $conn->prepare("SELECT * FROM events WHERE parish_id=? AND {$eventDateFilter} AND status='active' ORDER BY event_date ASC LIMIT 20");
    $ev->bind_param('i', $pid);
    $ev->execute();
    $events = $ev->get_result()->fetch_all(MYSQLI_ASSOC);

    // Parish info
    $pi = $conn->prepare("SELECT name, address, priest_name, contact_number, email FROM parishes WHERE id=?");
    $pi->bind_param('i', $pid);
    $pi->execute();
    $parish_info = $pi->get_result()->fetch_assoc();

    echo json_encode(['schedules' => $schedules, 'events' => $events, 'parish' => $parish_info]);
    exit;
}

// ---------------------------------------------------------------------------
// DATA: Stat cards
// ---------------------------------------------------------------------------
$uid = $user['id'];

// Total applications
$q = $conn->prepare("SELECT COUNT(*) as c FROM applications WHERE user_id=? AND created_at BETWEEN ? AND ?");
$q->bind_param('iss', $uid,$dashboardStart,$dashboardEnd); $q->execute();
$total_apps = (int) $q->get_result()->fetch_assoc()['c'];
$applicationPages=max(1,(int)ceil($total_apps/50));
$applicationPage=min($applicationPages,max(1,(int)($_GET['applications_page']??1)));
$applicationOffset=($applicationPage-1)*50;

// Pending
$q = $conn->prepare("SELECT COUNT(*) as c FROM applications WHERE user_id=? AND created_at BETWEEN ? AND ? AND status='pending'");
$q->bind_param('iss', $uid,$dashboardStart,$dashboardEnd); $q->execute();
$pending_apps_count = (int) $q->get_result()->fetch_assoc()['c'];

// Approved
$q = $conn->prepare("SELECT COUNT(*) as c FROM applications WHERE user_id=? AND created_at BETWEEN ? AND ? AND status='approved'");
$q->bind_param('iss', $uid,$dashboardStart,$dashboardEnd); $q->execute();
$approved_apps = (int) $q->get_result()->fetch_assoc()['c'];

// Total payments
$q = $conn->prepare("SELECT COALESCE(SUM(p.amount),0) as total FROM payments p INNER JOIN applications a ON p.application_id=a.id WHERE a.user_id=? AND p.status IN ('completed','refunded') AND COALESCE(p.verified_at,p.paid_at) BETWEEN ? AND ?");
$q->bind_param('iss', $uid,$dashboardStart,$dashboardEnd); $q->execute();
$total_payments = (float) $q->get_result()->fetch_assoc()['total'];

// ---------------------------------------------------------------------------
// DATA: Applications with service & parish names
// ---------------------------------------------------------------------------
$q = $conn->prepare("
    SELECT a.*, s.name AS service_name, p.name AS parish_name
    FROM applications a
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN parishes p ON a.parish_id = p.id
    WHERE a.user_id = ? AND a.created_at BETWEEN ? AND ?
    ORDER BY a.created_at DESC,a.id DESC LIMIT 50 OFFSET {$applicationOffset}
");
$q->bind_param('iss', $uid,$dashboardStart,$dashboardEnd); $q->execute();
$applications = $q->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// DATA: Payment history
// ---------------------------------------------------------------------------
$q = $conn->prepare("
    SELECT pay.*, a.id AS app_id
    FROM payments pay
    INNER JOIN applications a ON pay.application_id = a.id
    WHERE a.user_id = ? AND {$paymentDateFilter}
    ORDER BY COALESCE(pay.verified_at,pay.paid_at,pay.created_at) DESC LIMIT 50
");
  $q->bind_param('i', $uid); $q->execute();
$payments = $q->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// DATA: Parishes for selector
// ---------------------------------------------------------------------------
$parishes = $conn->query("SELECT id, name FROM parishes WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$selected_parish = $user['parish_id'] ?? ($parishes[0]['id'] ?? 0);

// ---------------------------------------------------------------------------
// DATA: Initial mass schedules & events for selected parish
// ---------------------------------------------------------------------------
$ms = $conn->prepare("SELECT * FROM mass_schedules WHERE parish_id=? AND status='active' ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), time_start");
$ms->bind_param('i', $selected_parish); $ms->execute();
$init_schedules = $ms->get_result()->fetch_all(MYSQLI_ASSOC);

$ev = $conn->prepare("SELECT * FROM events WHERE parish_id=? AND {$eventDateFilter} AND status='active' ORDER BY event_date ASC LIMIT 20");
$ev->bind_param('i', $selected_parish); $ev->execute();
$init_events = $ev->get_result()->fetch_all(MYSQLI_ASSOC);

// Group schedules by day
function groupByDay($schedules) {
    $grouped = [];
    foreach ($schedules as $s) {
        $grouped[$s['day_of_week']][] = $s;
    }
    return $grouped;
}
$grouped_schedules = groupByDay($init_schedules);

// Helper: status pill
function statusPill($status) {
    $map = [
        'pending'   => 'pill-amber',
        'approved'  => 'pill-green',
        'rejected'  => 'pill-wine',
        'completed' => 'pill-navy',
        'paid'      => 'pill-green',
        'refunded'  => 'pill-wine',
    ];
    $cls = $map[$status] ?? 'pill-amber';
    return '<span class="pill ' . $cls . '">' . ucfirst(htmlspecialchars($status)) . '</span>';
}
if(($_GET['ajax']??'')==='live'){header('Content-Type: application/json');echo json_encode(['applications'=>$applications,'digest'=>hash('sha256',json_encode([$applications,$payments]))]);exit;}
?>
<?php render_dashboard_filter(); ?>

<!-- ============================================================ -->
<!-- Parish Selector -->
<!-- ============================================================ -->
<div class="card" style="margin-bottom:24px">
  <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <label style="font-size:.78rem;font-weight:500;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-60)">Select Parish</label>
    <select id="parishSelector" style="padding:8px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;color:var(--ink);background:#FAFAF8;outline:none;min-width:260px;transition:border-color var(--ease),box-shadow var(--ease)" onchange="loadParishInfo(this.value)">
      <?php foreach ($parishes as $p): ?>
        <option value="<?php echo $p['id']; ?>" <?php echo ($p['id'] == $selected_parish) ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($p['name']); ?>
        </option>
      <?php endforeach; ?>
      <?php if (empty($parishes)): ?>
        <option value="0">No active parishes found</option>
      <?php endif; ?>
    </select>
    <span id="parishSubInfo" style="font-size:.78rem;color:var(--ink-60);margin-left:auto"></span>
  </div>
</div>

<!-- ============================================================ -->
<!-- Stat Cards -->
<!-- ============================================================ -->
<div class="stats-grid">
  <div class="stat-card stat-navy">
    <div class="stat-icon"><?= ui_icon('applications') ?></div>
    <div class="stat-label">Total Applications</div>
    <div class="stat-value"><?php echo $total_apps; ?></div>
  </div>
  <div class="stat-card stat-amber">
    <div class="stat-icon"><?= ui_icon('clock') ?></div>
    <div class="stat-label">Pending</div>
    <div class="stat-value"><?php echo $pending_apps_count; ?></div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon"><?= ui_icon('check') ?></div>
    <div class="stat-label">Approved</div>
    <div class="stat-value"><?php echo $approved_apps; ?></div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon"><?= ui_icon('wallet') ?></div>
    <div class="stat-label">Total Paid</div>
    <div class="stat-value"><?php echo number_format($total_payments, 2); ?></div>
  </div>
</div>

<!-- ============================================================ -->
<!-- Quick Actions -->
<!-- ============================================================ -->
<div class="card" style="margin-bottom:24px">
  <div class="card-head">
    <h3>Quick Actions</h3>
  </div>
  <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="apply_service.php" class="btn-sm btn-navy"><?= ui_icon('plus') ?> Apply for Service</a>
    <a href="messages.php" class="btn-sm btn-gold"><?= ui_icon('mail') ?> View Messages</a>
    <a href="faq.php" class="btn-sm btn-outline">? View FAQ</a>
  </div>
</div>

<!-- ============================================================ -->
<!-- Application Status Tracker -->
<!-- ============================================================ -->
<div class="card">
  <div class="card-head">
    <h3>My Applications</h3>
    <span class="card-tag"><?= count($applications) ?> of <?= $total_apps ?> matching applications</span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($applications)): ?>
      <div class="empty-state">
        <div class="empty-icon"><?= ui_icon('file') ?></div>
        <p>No applications match the selected period.</p>
        <a href="apply_service.php" class="btn-sm btn-navy">Apply for a Service</a>
      </div>
    <?php else: ?>
      <div class="tbl-wrap dashboard-table-preview">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Service</th>
              <th>Parish</th>
              <th>Schedule</th>
              <th>Status</th>
              <th>Payment</th>
              <th>QR</th>
              <th>Date Filed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($applications as $app): ?>
              <tr>
                <td>#<?php echo $app['id']; ?></td>
                <td><?php echo htmlspecialchars($app['service_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($app['parish_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($app['schedule'] ?? '—'); ?></td>
                <td><?php echo statusPill($app['status']); ?></td>
                <td><?php echo statusPill($app['payment_status'] ?? 'pending'); ?></td>
                <td>
                  <?php if (!empty($app['qr_code'])): ?>
                    <span style="cursor:pointer;font-size:1.1rem" title="View QR Code" onclick="showQRModal(<?php echo $app['id']; ?>)"><?= ui_icon('qr') ?></span>
                  <?php else: ?>
                    <span style="color:var(--ink-30)">—</span>
                  <?php endif; ?>
                </td>
                <td><?php echo date('M d, Y', strtotime($app['created_at'])); ?></td>
                <td>
                  <button class="act-btn act-navy" onclick="showAppDetail(<?php echo $app['id']; ?>)">View</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================ -->
<!-- Payment History -->
<!-- ============================================================ -->
<?php if($applicationPages>1): ?><nav class="filter-bar" aria-label="Application pages">
<?php if($applicationPage>1): ?><a class="btn-sm btn-outline" href="?<?= h(http_build_query(['date_from'=>$dashboardFrom,'date_to'=>$dashboardTo,'applications_page'=>$applicationPage-1])) ?>">Previous applications</a><?php endif; ?>
<span>Page <?= $applicationPage ?> of <?= $applicationPages ?></span>
<?php if($applicationPage<$applicationPages): ?><a class="btn-sm btn-outline" href="?<?= h(http_build_query(['date_from'=>$dashboardFrom,'date_to'=>$dashboardTo,'applications_page'=>$applicationPage+1])) ?>">Next applications</a><?php endif; ?>
</nav><?php endif; ?>
<div class="card">
  <div class="card-head">
    <h3>Payment History</h3>
    <a class="btn-sm btn-outline" href="payments.php">View all payments</a>
    <span class="card-tag"><?php echo count($payments); ?> Records</span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($payments)): ?>
      <div class="empty-state">
        <div class="empty-icon"><?= ui_icon('wallet') ?></div>
        <p>No payment records found.</p>
      </div>
    <?php else: ?>
      <div class="tbl-wrap dashboard-table-preview">
        <table>
          <thead>
            <tr>
              <th>Reference #</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Status</th>
              <th>Date</th>
              <th>Application</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $pay): ?>
              <tr>
                <td><?php echo htmlspecialchars($pay['receipt_number'] ?? $pay['reference_number'] ?? '—'); ?></td>
                <td style="font-weight:500">₱ <?php echo number_format($pay['amount'], 2); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($pay['payment_method'] ?? '—')); ?></td>
                <td><?php echo statusPill($pay['status']); ?></td>
                <td><?php echo $pay['paid_at'] ? date('M d, Y', strtotime($pay['paid_at'])) : date('M d, Y', strtotime($pay['created_at'])); ?></td>
                <td>#<?php echo $pay['app_id']; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================ -->
<!-- Mass Schedules & Events (side by side) -->
<!-- ============================================================ -->
<div class="grid-2-1">
  <!-- Mass Schedules -->
  <div class="card">
    <div class="card-head">
      <h3>Mass Schedules</h3>
      <span class="card-tag" id="massParishLabel">Selected Parish</span>
    </div>
    <div class="card-body" id="massScheduleContent">
      <?php if (empty($init_schedules)): ?>
        <div class="empty-state">
          <div class="empty-icon"><?= ui_icon('calendar') ?></div>
          <p>No mass schedules available for this parish.</p>
        </div>
      <?php else: ?>
        <?php foreach ($grouped_schedules as $day => $scheds): ?>
          <div style="margin-bottom:16px">
            <div style="font-family:var(--fh);font-size:.95rem;font-weight:600;color:var(--navy);margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--ink-10)"><?php echo htmlspecialchars($day); ?></div>
            <?php foreach ($scheds as $s): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:6px 0;font-size:.82rem">
                <span style="color:var(--gold);font-weight:500;min-width:110px"><?php echo date('g:i A', strtotime($s['time_start'])) . ' - ' . ($s['time_end'] ? date('g:i A', strtotime($s['time_end'])) : ''); ?></span>
                <span style="color:var(--ink-60)"><?php echo htmlspecialchars($s['mass_type'] ?? ''); ?></span>
                <?php if (!empty($s['language'])): ?>
                  <span class="pill pill-navy" style="margin-left:auto"><?php echo htmlspecialchars($s['language']); ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Upcoming Events -->
  <div class="card">
    <div class="card-head">
      <h3>Upcoming Events</h3>
    </div>
    <div class="card-body" id="eventsContent">
      <?php if (empty($init_events)): ?>
        <div class="empty-state">
          <div class="empty-icon"><?= ui_icon('calendar') ?></div>
          <p>No upcoming events.</p>
        </div>
      <?php else: ?>
        <?php foreach ($init_events as $evt): ?>
          <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--ink-10);align-items:flex-start">
            <div style="min-width:48px;text-align:center;background:var(--gold-dim);border-radius:8px;padding:6px 4px">
              <div style="font-size:.65rem;font-weight:500;text-transform:uppercase;color:var(--gold);letter-spacing:.06em"><?php echo date('M', strtotime($evt['event_date'])); ?></div>
              <div style="font-family:var(--fh);font-size:1.2rem;font-weight:600;color:var(--ink)"><?php echo date('d', strtotime($evt['event_date'])); ?></div>
            </div>
            <div>
              <div style="font-weight:500;font-size:.85rem;color:var(--ink)"><?php echo htmlspecialchars($evt['title']); ?></div>
              <?php if (!empty($evt['description'])): ?>
                <div style="font-size:.76rem;color:var(--ink-60);margin-top:2px"><?php echo htmlspecialchars(mb_strimwidth($evt['description'], 0, 100, '...')); ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- MODALS -->
<!-- ============================================================ -->

<!-- Application Detail Modal -->
<div class="modal-wrap" id="modalAppDetail">
  <div class="modal" style="max-width:640px">
    <h2 id="modalAppTitle">Application Details</h2>
    <p id="modalAppSub" style="margin-bottom:16px"></p>
    <div id="modalAppBody"></div>
    <div class="modal-actions">
      <button class="btn-sm btn-outline" onclick="closeModal('modalAppDetail')">Close</button>
    </div>
  </div>
</div>

<!-- QR Code Modal -->
<div class="modal-wrap" id="modalQR">
  <div class="modal" style="max-width:380px;text-align:center">
    <h2>QR Code</h2>
    <p>Scan this code for verification.</p>
    <div id="modalQRBody" style="padding:16px 0"></div>
    <div class="modal-actions" style="justify-content:center">
      <button class="btn-sm btn-outline" onclick="closeModal('modalQR')">Close</button>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- Inline JavaScript -->
<!-- ============================================================ -->
<script>
// Application data for modals (embedded as JSON)
var appData = <?php echo json_encode($applications); ?>;

function showAppDetail(id) {
    var app = null;
    for (var i = 0; i < appData.length; i++) {
        if (parseInt(appData[i].id) === id) { app = appData[i]; break; }
    }
    if (!app) return;

    document.getElementById('modalAppTitle').textContent = 'Application #' + app.id;
    document.getElementById('modalAppSub').textContent = (app.service_name || 'N/A') + ' at ' + (app.parish_name || 'N/A');

    var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:.83rem">';
    html += detailRow('Service', app.service_name || 'N/A');
    html += detailRow('Parish', app.parish_name || 'N/A');
    html += detailRow('Schedule', app.schedule || '—');
    html += detailRow('Status', pillHtml(app.status));
    html += detailRow('Payment', pillHtml(app.payment_status || 'pending'));
    html += detailRow('Date Filed', formatDate(app.created_at));
    html += '</div>';

    if (app.rejection_reason) {
        html += '<div style="margin-top:16px;padding:12px 16px;background:var(--wine-dim);border-left:3px solid var(--wine);border-radius:8px;font-size:.82rem;color:#4A1020"><strong>Rejection Reason:</strong> ' + escHtml(app.rejection_reason) + '</div>';
    }

    if (app.qr_code) {
        var qrUrl = <?php echo json_encode(
            rtrim(app_url(), '/')
        ); ?> + '/public/verify.php?app=' + app.id + '&parish=' + app.parish_id + '&token=' + encodeURIComponent(app.qr_code);
        var imgUrl = '../public/qr.php?size=180&data=' + encodeURIComponent(qrUrl);
        html += '<div style="text-align:center;margin-top:20px;padding:16px;background:#FAFAF8;border-radius:10px">';
        html += '<img src="' + imgUrl + '" alt="QR Code" width="180" height="180" style="margin:0 auto;image-rendering:pixelated">';
        html += '<div style="font-size:.72rem;color:var(--ink-60);margin-top:8px">Scan for verification</div>';
        html += '</div>';
    }

    document.getElementById('modalAppBody').innerHTML = html;
    openModal('modalAppDetail');
}

function showQRModal(id) {
    var app = null;
    for (var i = 0; i < appData.length; i++) {
        if (parseInt(appData[i].id) === id) { app = appData[i]; break; }
    }
    if (!app || !app.qr_code) return;

    var qrUrl = <?php echo json_encode(
        rtrim(app_url(), '/')
    ); ?> + '/public/verify.php?app=' + app.id + '&parish=' + app.parish_id + '&token=' + encodeURIComponent(app.qr_code);
    var imgUrl = '../public/qr.php?size=250&data=' + encodeURIComponent(qrUrl);

    document.getElementById('modalQRBody').innerHTML =
        '<img src="' + imgUrl + '" alt="QR Code" width="250" height="250" style="margin:0 auto;image-rendering:pixelated">' +
        '<div style="font-size:.72rem;color:var(--ink-60);margin-top:10px">Application #' + app.id + '</div>';
    openModal('modalQR');
}

function detailRow(label, value) {
    return '<div style="padding:8px 0;border-bottom:1px solid var(--ink-10)"><div style="font-size:.7rem;font-weight:500;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-60);margin-bottom:3px">' + escHtml(label) + '</div><div>' + value + '</div></div>';
}

function pillHtml(status) {
    var map = {pending:'pill-amber',approved:'pill-green',rejected:'pill-wine',completed:'pill-navy',paid:'pill-green',refunded:'pill-wine'};
    var cls = map[status] || 'pill-amber';
    return '<span class="pill ' + cls + '">' + capitalize(status) + '</span>';
}

function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function escHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function formatDate(d) { if (!d) return '—'; var dt = new Date(d); var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; return months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear(); }

// ---------------------------------------------------------------------------
// Parish Selector AJAX
// ---------------------------------------------------------------------------
function loadParishInfo(pid) {
    if (!pid || pid === '0') return;

    document.getElementById('massScheduleContent').innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-30)">Loading...</div>';
    document.getElementById('eventsContent').innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-30)">Loading...</div>';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'dashboard.php?ajax=parish_info&id=' + encodeURIComponent(pid)+'&'+new URLSearchParams(location.search), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    renderSchedules(data.schedules || []);
                    renderEvents(data.events || []);
                    if (data.parish) {
                        var sub = data.parish.priest_name ? 'Fr. ' + data.parish.priest_name : '';
                        document.getElementById('parishSubInfo').textContent = sub;
                        document.getElementById('massParishLabel').textContent = data.parish.name || 'Selected Parish';
                    }
                } catch(e) {
                    document.getElementById('massScheduleContent').innerHTML = '<div class="empty-state"><p>Error loading data.</p></div>';
                    document.getElementById('eventsContent').innerHTML = '<div class="empty-state"><p>Error loading data.</p></div>';
                }
            }
        }
    };
    xhr.send();
}

function renderSchedules(schedules) {
    var el = document.getElementById('massScheduleContent');
    if (!schedules.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon"><?= ui_icon('calendar') ?></div><p>No mass schedules available for this parish.</p></div>';
        return;
    }
    // Group by day
    var grouped = {};
    var dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    for (var i = 0; i < schedules.length; i++) {
        var s = schedules[i];
        if (!grouped[s.day_of_week]) grouped[s.day_of_week] = [];
        grouped[s.day_of_week].push(s);
    }
    var html = '';
    for (var d = 0; d < dayOrder.length; d++) {
        var day = dayOrder[d];
        if (!grouped[day]) continue;
        html += '<div style="margin-bottom:16px">';
        html += '<div style="font-family:var(--fh);font-size:.95rem;font-weight:600;color:var(--navy);margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--ink-10)">' + escHtml(day) + '</div>';
        for (var j = 0; j < grouped[day].length; j++) {
            var sc = grouped[day][j];
            html += '<div style="display:flex;align-items:center;gap:12px;padding:6px 0;font-size:.82rem">';
            html += '<span style="color:var(--gold);font-weight:500;min-width:110px">' + fmtTime(sc.time_start) + ' - ' + fmtTime(sc.time_end) + '</span>';
            html += '<span style="color:var(--ink-60)">' + escHtml(sc.mass_type || '') + '</span>';
            if (sc.language) {
                html += '<span class="pill pill-navy" style="margin-left:auto">' + escHtml(sc.language) + '</span>';
            }
            html += '</div>';
        }
        html += '</div>';
    }
    el.innerHTML = html;
}

function renderEvents(events) {
    var el = document.getElementById('eventsContent');
    if (!events.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon"><?= ui_icon('calendar') ?></div><p>No upcoming events.</p></div>';
        return;
    }
    var html = '';
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    for (var i = 0; i < events.length; i++) {
        var ev = events[i];
        var dt = new Date(ev.event_date);
        html += '<div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--ink-10);align-items:flex-start">';
        html += '<div style="min-width:48px;text-align:center;background:var(--gold-dim);border-radius:8px;padding:6px 4px">';
        html += '<div style="font-size:.65rem;font-weight:500;text-transform:uppercase;color:var(--gold);letter-spacing:.06em">' + months[dt.getMonth()] + '</div>';
        html += '<div style="font-family:var(--fh);font-size:1.2rem;font-weight:600;color:var(--ink)">' + String(dt.getDate()).padStart(2, '0') + '</div>';
        html += '</div><div>';
        html += '<div style="font-weight:500;font-size:.85rem;color:var(--ink)">' + escHtml(ev.title) + '</div>';
        if (ev.description) {
            var desc = ev.description.length > 100 ? ev.description.substring(0, 100) + '...' : ev.description;
            html += '<div style="font-size:.76rem;color:var(--ink-60);margin-top:2px">' + escHtml(desc) + '</div>';
        }
        html += '</div></div>';
    }
    el.innerHTML = html;
}

function fmtTime(t) {
    if (!t) return '';
    var parts = t.split(':');
    var h = parseInt(parts[0]), m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + m + ' ' + ampm;
}

// Initialize parish sub-info on load
(function() {
    var sel = document.getElementById('parishSelector');
    if (sel && sel.value && sel.value !== '0') {
        // Set initial label from selected option text
        var opt = sel.options[sel.selectedIndex];
        if (opt) document.getElementById('massParishLabel').textContent = opt.textContent.trim();
    }
})();
</script>

<script>
let dashboardDigest=<?=json_encode(hash('sha256',json_encode([$applications,$payments])))?>;
setInterval(async()=>{if(document.hidden)return;try{const data=await fetch('dashboard.php?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),ajax:'live'})).then(r=>r.json());if(data.digest&&data.digest!==dashboardDigest){const html=await fetch('dashboard.php'+location.search).then(r=>r.text());const doc=new DOMParser().parseFromString(html,'text/html');const oldTables=document.querySelectorAll('.page-content table'),newTables=doc.querySelectorAll('.page-content table');if(oldTables.length===newTables.length)oldTables.forEach((t,i)=>t.replaceWith(newTables[i]));const stats=document.querySelector('.stats-grid');if(stats)stats.replaceWith(doc.querySelector('.stats-grid'));appData=data.applications;dashboardDigest=data.digest;}}catch(e){}},5000);
</script>
<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
