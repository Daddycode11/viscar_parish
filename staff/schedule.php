<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';
if(($_GET['view']??'')==='available'){require __DIR__.'/../includes/calendar_availability.php';exit;}

/**
 * Staff Schedule — Calendar view + Event management
 * Features: monthly calendar, approved application schedule, events CRUD, list view
 */

$page_id    = 'schedule';
$page_title = 'Schedule';
$page_sub   = 'Calendar & Events';
include 'includes/layout.php';

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Create event
    if ($_GET['ajax'] === 'create_event' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $title       = trim($_POST['title'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $event_date  = trim($_POST['event_date'] ?? '');
      // Fallback: use first parish if not set
      $parish_id   = isset($user['parish_id']) ? (int)$user['parish_id'] : 1;

      if (!$title || !$event_date) {
        error_log('Event creation failed: missing title or date.');
        echo json_encode(['success' => false, 'message' => 'Title and date are required.']);
        exit;
      }
      if (!$parish_id) {
        error_log('Event creation failed: parish_id missing for user ' . ($user['id'] ?? 'unknown'));
        echo json_encode(['success' => false, 'message' => 'Parish not set for staff user. Contact admin.']);
        exit;
      }
      $stmt = $conn->prepare("INSERT INTO events (parish_id, title, description, event_date, status) VALUES (?, ?, ?, ?, 'active')");
      if (!$stmt) {
        error_log('Event creation failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error. Contact admin.']);
        exit;
      }
      $stmt->bind_param('isss', $parish_id, $title, $description, $event_date);
      $ok = $stmt->execute();
      if (!$ok) error_log('Event creation failed: ' . $stmt->error);
      echo json_encode(['success' => $ok, 'message' => $ok ? 'Event created.' : 'Failed to create event.', 'id' => $conn->insert_id]);
      exit;
    }

    // Update event
    if ($_GET['ajax'] === 'update_event' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $event_date  = trim($_POST['event_date'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active','cancelled']) ? $_POST['status'] : 'active';

        if (!$id || !$title || !$event_date) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE events SET title=?, description=?, event_date=?, status=? WHERE id=?");
        $stmt->bind_param('ssssi', $title, $description, $event_date, $status, $id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Event updated.' : 'Failed.']);
        exit;
    }

    // Delete event
    if ($_GET['ajax'] === 'delete_event' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM events WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Event deleted.' : 'Failed.']);
        exit;
    }

    // Get event
    if ($_GET['ajax'] === 'get_event' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM (SELECT * FROM events WHERE parish_id = {$scopeParish}) events WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ev = $stmt->get_result()->fetch_assoc();
        echo json_encode($ev ? ['success'=>true,'data'=>$ev] : ['success'=>false,'message'=>'Not found.']);
        exit;
    }

    // Get month data (applications + events)
    if ($_GET['ajax'] === 'month_data') {
        $year  = (int)($_GET['year']  ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        if($year<1900||$year>9999||$month<1||$month>12)fail_request('Choose a valid calendar month.',422);
        $start = "$year-" . str_pad($month,2,'0',STR_PAD_LEFT) . "-01 00:00:00";
        $end   = date('Y-m-t 23:59:59', strtotime($start));

        // Approved applications
        $stmt = $conn->prepare("SELECT a.id, a.schedule, a.service_id, u.name AS parishioner_name, s.name AS service_name
                                FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
                                JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                                LEFT JOIN services s ON a.service_id = s.id
                                WHERE a.status IN ('pending','approved') AND a.schedule BETWEEN ? AND ?
                                ORDER BY a.schedule");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $sched_apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Events
        $stmt = $conn->prepare("SELECT * FROM (SELECT * FROM events WHERE parish_id = {$scopeParish}) events WHERE status='active' AND event_date BETWEEN ? AND ? ORDER BY event_date");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['success'=>true, 'applications'=>$sched_apps, 'events'=>$events]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── CURRENT MONTH DATA ────────────────────────
$cur_year  = (int)($_GET['year']  ?? date('Y'));
$cur_month = (int)($_GET['month'] ?? date('n'));
if($cur_year<1900||$cur_year>9999||$cur_month<1||$cur_month>12)fail_request('Choose a valid calendar month.',422);
$start_date = "$cur_year-" . str_pad($cur_month,2,'0',STR_PAD_LEFT) . "-01";
$end_date   = date('Y-m-t', strtotime($start_date));
$days_in_month = (int)date('t', strtotime($start_date));
$first_dow     = (int)date('w', strtotime($start_date)); // 0=Sun

// Approved applications this month
$stmt = $conn->prepare("SELECT a.id, a.schedule, a.service_id, u.name AS parishioner_name, s.name AS service_name
                         FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a
                         JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                         LEFT JOIN services s ON a.service_id = s.id
                         WHERE a.status IN ('pending','approved') AND a.schedule BETWEEN ? AND ?
                         ORDER BY a.schedule");
$s = "$start_date 00:00:00"; $e = "$end_date 23:59:59";
$stmt->bind_param('ss', $s, $e);
$stmt->execute();
$month_apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Events this month
$stmt = $conn->prepare("SELECT * FROM (SELECT * FROM events WHERE parish_id = {$scopeParish}) events WHERE status='active' AND event_date BETWEEN ? AND ? ORDER BY event_date");
$stmt->bind_param('ss', $s, $e);
$stmt->execute();
$month_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Group by day
$day_items = [];
foreach ($month_apps as $a) {
    $day = (int)date('j', strtotime($a['schedule']));
    $day_items[$day][] = ['type'=>'app', 'data'=>$a];
}
foreach ($month_events as $ev) {
    $day = (int)date('j', strtotime($ev['event_date']));
    $day_items[$day][] = ['type'=>'event', 'data'=>$ev];
}

// Upcoming list (next 10 items)
$upcoming_apps = [];
$stmt = $conn->prepare("SELECT a.id, a.schedule, a.service_id, u.name AS parishioner_name, s.name AS service_name
                         FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id=u.id LEFT JOIN services s ON a.service_id=s.id
                         WHERE a.status='approved' AND a.schedule >= NOW()
                         ORDER BY a.schedule LIMIT 10");
$stmt->execute();
$upcoming_apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$upcoming_events = [];
$stmt = $conn->prepare("SELECT * FROM (SELECT * FROM events WHERE parish_id = {$scopeParish}) events WHERE event_date >= NOW() AND status='active' ORDER BY event_date LIMIT 10");
$stmt->execute();
$upcoming_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Navigation
$prev_month = $cur_month - 1; $prev_year = $cur_year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $cur_month + 1; $next_year = $cur_year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
$month_label = date('F Y', strtotime($start_date));
?>

<style>
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--ink-10);border-radius:var(--r);overflow:hidden}
.cal-hdr{background:#F0EDE8;padding:10px 8px;font-size:.68rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-60);text-align:center}
.cal-day{background:var(--white);min-height:90px;padding:6px 8px;font-size:.72rem;position:relative;transition:background .2s}
.cal-day:hover{background:rgba(201,168,76,.04)}
.cal-day.today{background:rgba(201,168,76,.06)}
.cal-day.empty{background:#FAFAF8}
.cal-num{font-weight:500;color:var(--ink);margin-bottom:4px;font-size:.78rem}
.cal-day.today .cal-num{color:var(--gold);font-weight:600}
.cal-dot{display:inline-block;width:6px;height:6px;border-radius:50%;margin-right:3px}
.cal-item{font-size:.62rem;color:var(--ink-60);line-height:1.3;padding:2px 4px;border-radius:3px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}
.cal-item.app{background:var(--green-dim);color:var(--green)}
.cal-item.event{background:var(--blue-dim);color:var(--blue)}
.cal-item.cancelled{background:var(--wine-dim);color:var(--wine);text-decoration:line-through}
</style>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Create/Edit Event Modal -->
<div class="modal-wrap" id="eventModal">
  <div class="modal">
    <h2 id="eventModalTitle">Create Event</h2>
    <p>Add a parish event to the calendar.</p>
    <input type="hidden" id="eventId" value="">
    <div class="form-grid">
      <div class="form-group form-full">
        <label>Event Title *</label>
        <input type="text" id="eventTitle" placeholder="e.g. Holy Week Procession">
      </div>
      <div class="form-group">
        <label>Date & Time *</label>
        <input type="datetime-local" id="eventDate">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="eventStatus">
          <option value="active">Active</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="form-group form-full">
        <label>Description</label>
        <textarea id="eventDesc" rows="3" placeholder="Event details..."></textarea>
      </div>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('eventModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="saveEvent()" class="btn-sm btn-navy" id="eventSaveBtn">Create Event</button>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-wrap" id="deleteModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Delete Event</h2>
    <p>Are you sure you want to permanently delete this event? This cannot be undone.</p>
    <div class="modal-actions">
      <button onclick="closeModal('deleteModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="confirmDeleteEvent()" class="btn-sm btn-wine">Delete</button>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Schedule Management</div>
    <h1 class="sec-title">Schedule & Events</h1>
    <p class="sec-sub">View approved service schedules and manage parish events.</p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="openCreateEvent()" class="btn-sm btn-navy">+ New Event</button>
  </div>
</div>

<nav class="request-tabs"><a href="?view=available&amp;month=<?= sprintf('%04d-%02d',$cur_year,$cur_month) ?>">Available Dates</a><a aria-current="page" href="?year=<?= $cur_year ?>&amp;month=<?= $cur_month ?>">Scheduled Dates</a></nav>
<!-- CALENDAR -->
<div class="card">
  <div class="card-head">
    <div style="display:flex;align-items:center;gap:12px">
      <a href="?year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; ?>" class="act-btn act-navy">&larr;</a>
      <h3 style="min-width:160px;text-align:center"><?php echo $month_label; ?></h3>
      <a href="?year=<?php echo $next_year; ?>&month=<?php echo $next_month; ?>" class="act-btn act-navy">&rarr;</a>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="display:flex;align-items:center;gap:4px;font-size:.68rem;color:var(--green)"><span class="cal-dot" style="background:var(--green)"></span> Services</span>
      <span style="display:flex;align-items:center;gap:4px;font-size:.68rem;color:var(--blue)"><span class="cal-dot" style="background:var(--blue)"></span> Events</span>
      <a href="?year=<?php echo date('Y'); ?>&month=<?php echo date('n'); ?>" class="btn-sm btn-outline" style="padding:5px 12px;font-size:.7rem">Today</a>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <div class="cal-grid">
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
      <div class="cal-hdr"><?php echo $d; ?></div>
      <?php endforeach; ?>

      <?php for ($i = 0; $i < $first_dow; $i++): ?>
      <div class="cal-day empty"></div>
      <?php endfor; ?>

      <?php for ($day = 1; $day <= $days_in_month; $day++):
        $is_today = ($day == date('j') && $cur_month == date('n') && $cur_year == date('Y'));
        $items = $day_items[$day] ?? [];
      ?>
      <div class="cal-day <?php echo $is_today ? 'today' : ''; ?>">
        <div class="cal-num"><?php echo $day; ?></div>
        <?php foreach (array_slice($items, 0, 3) as $item):
          if ($item['type'] === 'app'):
            $a = $item['data'];
        ?>
        <button type="button" class="cal-item app" onclick="document.getElementById('schedule-day-<?= $day ?>').showModal()">
          <?php echo htmlspecialchars(date('g:i A',strtotime($a['schedule'])).' ? '.($a['service_name'] ?? 'Service')); ?>
        </button>
        <?php else:
            $ev = $item['data'];
            $cls = $ev['status'] === 'cancelled' ? 'cancelled' : 'event';
        ?>
        <div class="cal-item <?php echo $cls; ?>" title="<?php echo htmlspecialchars($ev['title']); ?>" onclick="editEvent(<?php echo $ev['id']; ?>)">
          <?php echo htmlspecialchars(date('g:i A',strtotime($ev['event_date'])).' ? '.$ev['title']); ?>
        </div>
        <?php endif; endforeach; ?>
        <?php if (count($items) > 3): ?>
        <button onclick="document.getElementById('schedule-day-<?= $day ?>').showModal()">+<?= count($items)-3 ?> more</button>
        <?php endif; ?>
      </div>
      <dialog id="schedule-day-<?= $day ?>" style="margin:auto;padding:24px;max-width:92vw"><h2><?= h(sprintf('%04d-%02d-%02d',$cur_year,$cur_month,$day)) ?></h2><?php foreach($items as $item): $record=$item['data']; ?><article><?php if($item['type']==='app'): ?><p><?= h(date('g:i A',strtotime($record['schedule'])).' ? '.$record['service_name'].' ? '.$record['parishioner_name']) ?></p><a href="application_details.php?id=<?= (int)$record['id'] ?>">Application details</a><?php else: ?><h3><?= h($record['title']) ?></h3><p><?= h($record['description']) ?></p><?php endif; ?></article><?php endforeach; ?><form method="dialog"><button>Close</button></form></dialog>
      <?php endfor; ?>

      <?php $remaining = (7 - (($first_dow + $days_in_month) % 7)) % 7;
      for ($i = 0; $i < $remaining; $i++): ?>
      <div class="cal-day empty"></div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<div class="grid-2">
  <!-- Upcoming Services -->
  <div class="card">
    <div class="card-head"><h3>Upcoming Services</h3></div>
    <div class="card-body" style="padding:0">
      <?php if (empty($upcoming_apps)): ?>
      <p style="text-align:center;padding:30px 0;color:var(--ink-30);font-size:.8rem;font-style:italic">No upcoming services.</p>
      <?php endif; ?>
      <?php foreach ($upcoming_apps as $a):
        $dt = strtotime($a['schedule']);
      ?>
      <div style="display:flex;gap:12px;padding:12px 20px;border-bottom:1px solid var(--ink-10);align-items:center">
        <div style="width:44px;text-align:center;flex-shrink:0">
          <div style="font-family:var(--fh);font-size:1.3rem;font-weight:600;color:var(--navy);line-height:1"><?php echo date('j', $dt); ?></div>
          <div style="font-size:.58rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-30)"><?php echo date('M', $dt); ?></div>
        </div>
        <div style="flex:1">
          <div style="font-weight:500;font-size:.82rem"><?php echo htmlspecialchars($a['service_name'] ?? 'Service #'.$a['service_id']); ?></div>
          <div style="font-size:.72rem;color:var(--ink-30)"><?php echo htmlspecialchars($a['parishioner_name']); ?> &middot; <?php echo date('g:i A', $dt); ?></div>
        </div>
        <span class="pill pill-green">Confirmed</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Upcoming Events -->
  <div class="card">
    <div class="card-head">
      <h3>Upcoming Events</h3>
      <button onclick="openCreateEvent()" class="btn-sm btn-outline" style="padding:5px 12px;font-size:.7rem">+ Add</button>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($upcoming_events)): ?>
      <p style="text-align:center;padding:30px 0;color:var(--ink-30);font-size:.8rem;font-style:italic">No upcoming events.</p>
      <?php endif; ?>
      <?php foreach ($upcoming_events as $ev):
        $dt = strtotime($ev['event_date']);
      ?>
      <div style="display:flex;gap:12px;padding:12px 20px;border-bottom:1px solid var(--ink-10);align-items:center">
        <div style="width:44px;text-align:center;flex-shrink:0">
          <div style="font-family:var(--fh);font-size:1.3rem;font-weight:600;color:var(--blue);line-height:1"><?php echo date('j', $dt); ?></div>
          <div style="font-size:.58rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-30)"><?php echo date('M', $dt); ?></div>
        </div>
        <div style="flex:1">
          <div style="font-weight:500;font-size:.82rem"><?php echo htmlspecialchars($ev['title']); ?></div>
          <div style="font-size:.72rem;color:var(--ink-30)"><?php echo date('M j, Y \a\t g:i A', $dt); ?></div>
        </div>
        <div style="display:flex;gap:4px">
          <button onclick="editEvent(<?php echo $ev['id']; ?>)" class="act-btn act-navy" style="font-size:.68rem">[icon:edit]</button>
          <button onclick="deleteEvent(<?php echo $ev['id']; ?>)" class="act-btn act-wine" style="font-size:.68rem">[icon:close]</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
let deleteEventId = null;

function openCreateEvent() {
    document.getElementById('eventModalTitle').textContent = 'Create Event';
    document.getElementById('eventSaveBtn').textContent = 'Create Event';
    document.getElementById('eventId').value = '';
    document.getElementById('eventTitle').value = '';
    document.getElementById('eventDate').value = '';
    document.getElementById('eventDesc').value = '';
    document.getElementById('eventStatus').value = 'active';
    openModal('eventModal');
}

function editEvent(id) {
    document.getElementById('eventModalTitle').textContent = 'Edit Event';
    document.getElementById('eventSaveBtn').textContent = 'Save Changes';
    setLoading(true);
    fetch('schedule.php?ajax=get_event&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const ev = data.data;
            document.getElementById('eventId').value = ev.id;
            document.getElementById('eventTitle').value = ev.title;
            document.getElementById('eventDate').value = ev.event_date ? ev.event_date.replace(' ', 'T').substring(0,16) : '';
            document.getElementById('eventDesc').value = ev.description || '';
            document.getElementById('eventStatus').value = ev.status;
            openModal('eventModal');
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function saveEvent() {
    const id    = document.getElementById('eventId').value;
    const title = document.getElementById('eventTitle').value.trim();
    const date  = document.getElementById('eventDate').value;
    const desc  = document.getElementById('eventDesc').value.trim();
    const status = document.getElementById('eventStatus').value;

    if (!title || !date) { showToast('Title and date are required.', 'error'); return; }

    closeModal('eventModal');
    setLoading(true);
    const fd = new FormData();
    fd.append('title', title);
    fd.append('event_date', date.replace('T', ' ') + ':00');
    fd.append('description', desc);
    fd.append('status', status);

    const action = id ? 'update_event' : 'create_event';
    if (id) fd.append('id', id);

    fetch('schedule.php?ajax=' + action, { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) setTimeout(() => location.reload(), 800);
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function deleteEvent(id) {
    deleteEventId = id;
    openModal('deleteModal');
}

function confirmDeleteEvent() {
    closeModal('deleteModal');
    setLoading(true);
    const fd = new FormData(); fd.append('id', deleteEventId);
    fetch('schedule.php?ajax=delete_event', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) setTimeout(() => location.reload(), 800);
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}
</script>

<?php include 'includes/layout_footer.php'; ?>
