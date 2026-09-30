<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

require_once __DIR__.'/../includes/service_availability.php';
$page_id = 'apply'; $page_title = 'Apply for Service'; $page_sub = 'Application';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../includes/notifications.php';

/* ─── AJAX ENDPOINTS ─── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    /* Services for a parish */
    if ($_GET['ajax'] === 'services' && isset($_GET['parish_id'])) {
        $pid = (int)$_GET['parish_id'];
        $stmt = $conn->prepare("SELECT id, name, description, fee, max_daily_limit, requirements_note, amount_mode FROM services WHERE parish_id=? AND status='active' ORDER BY name");
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok' => true, 'services' => $rows]);
        exit;
    }

    /* Fields + requirements for a service */
    if ($_GET['ajax'] === 'fields' && isset($_GET['service_id'])) {
        $sid = (int)$_GET['service_id'];
        $f = $conn->prepare("SELECT id, field_name, field_label, field_type, field_options, is_required, sort_order FROM service_fields WHERE service_id=? ORDER BY sort_order, id");
        $f->bind_param('i', $sid);
        $f->execute();
        $fields = $f->get_result()->fetch_all(MYSQLI_ASSOC);

        $r = $conn->prepare("SELECT id, document_name, description, is_required FROM service_requirements WHERE service_id=? ORDER BY id");
        $r->bind_param('i', $sid);
        $r->execute();
        $reqs = $r->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['ok' => true, 'fields' => $fields, 'requirements' => $reqs]);
        exit;
    }

    /* Check schedule conflicts */
    if ($_GET['ajax'] === 'check_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pid = (int)($_POST['parish_id'] ?? 0);
        $sid = (int)($_POST['service_id'] ?? 0);
        $date = $_POST['date'] ?? '';
        if (!$pid || !$sid || !$date) {
            echo json_encode(['ok' => false, 'msg' => 'Missing data']);
            exit;
        }
        $service=sqlrow("SELECT * FROM services WHERE id=? AND parish_id=? AND status='active'",[$sid,$pid]);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$service||!$parsed||$parsed->format('Y-m-d')!==$date)fail_request('Choose an active service and valid date.',422);
        $limit=(int)$service['max_daily_limit'];
        $unavailable=!service_day_allowed($service,$date);
        $sc = $conn->prepare("SELECT COUNT(*) as c FROM applications WHERE parish_id=? AND service_id=? AND DATE(schedule)=? AND status IN ('pending','approved')");
        $sc->bind_param('iis', $pid, $sid, $date);
        $sc->execute();
        $count = (int)($sc->get_result()->fetch_assoc()['c'] ?? 0);

        $full = ($limit > 0 && $count >= $limit);
        echo json_encode(['ok' => true, 'count' => $count, 'limit' => $limit, 'full' => $full || $unavailable,'unavailable'=>$unavailable]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown endpoint']);
    exit;
}

/* ─── Fetch active parishes for Step 1 ─── */
$parishes = $conn->query("SELECT id, name FROM parishes WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>

<!-- Step Indicator -->
<div class="card" style="margin-bottom:24px">
  <div class="card-body" style="padding:16px 22px">
    <div id="stepBar" style="display:flex;align-items:center;gap:4px;flex-wrap:wrap">
      <?php
      $steps = ['Parish','Service','Schedule','Form','Documents','Payment & Review','Confirmation'];
      foreach ($steps as $i => $label): $n = $i + 1; ?>
        <div class="step-dot" data-step="<?= $n ?>" style="display:flex;align-items:center;gap:4px;<?= $n < count($steps) ? 'flex:1;' : '' ?>">
          <span class="step-num" id="stepDot<?= $n ?>" style="width:28px;height:28px;border-radius:50%;display:inline-grid;place-items:center;font-size:.72rem;font-weight:600;border:2px solid var(--ink-10);color:var(--ink-30);background:var(--white);flex-shrink:0;transition:var(--ease)"><?= $n ?></span>
          <span class="step-label" style="font-size:.7rem;color:var(--ink-30);white-space:nowrap;transition:var(--ease)"><?= $label ?></span>
          <?php if ($n < count($steps)): ?>
            <span style="flex:1;height:2px;background:var(--ink-10);border-radius:1px;margin:0 4px"></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ STEP 1: Select Parish ═══ -->
<div class="wizard-step" id="step1">
  <div class="card">
    <div class="card-head">
      <h3>Step 1 — Select Parish</h3>
      <span class="card-tag">Step 1 of 7</span>
    </div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--ink-60);margin-bottom:16px">Choose the parish where you would like to apply for a service.</p>
      <div class="form-group">
        <label for="selParish">Parish</label>
        <select id="selParish">
          <option value="">— Select a parish —</option>
          <?php foreach ($parishes as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:16px">
        <button class="btn-sm btn-navy" onclick="goStep(2)" id="btnStep1">Next &rarr;</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ STEP 2: Select Service ═══ -->
<div class="wizard-step" id="step2" style="display:none">
  <div class="card">
    <div class="card-head">
      <h3>Step 2 — Select Service</h3>
      <span class="card-tag">Step 2 of 7</span>
    </div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--ink-60);margin-bottom:16px">Choose a service from the available options.</p>
      <div id="servicesList" class="empty-state"><div class="empty-icon">[icon:clock]</div><p>Loading services...</p></div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn-sm btn-outline" onclick="goStep(1)">&larr; Back</button>
        <button class="btn-sm btn-navy" onclick="goStep(3)" id="btnStep2" disabled>Next &rarr;</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ STEP 3: Schedule ═══ -->
<section class="wizard-step card" id="requirementNotesStep" style="display:none" aria-labelledby="requirementNotesTitle">
  <div class="card-head"><h3 id="requirementNotesTitle">Service Requirement Notes</h3><span class="card-tag">Before scheduling</span></div>
  <div class="card-body">
    <p id="requirementServiceName" class="requirement-service-name"></p>
    <div id="requirementNotesText" class="requirement-notes"></div>
    <div class="wizard-actions">
      <button type="button" class="btn-sm btn-outline" onclick="goStep(2)">&larr; Back to services</button>
      <button type="button" class="btn-sm btn-navy" onclick="continueAfterRequirements()">Continue to schedule &rarr;</button>
    </div>
  </div>
</section>

<div class="wizard-step" id="step3" style="display:none">
  <div class="card">
    <div class="card-head">
      <h3>Step 3 — Select Schedule</h3>
      <span class="card-tag">Step 3 of 7</span>
    </div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--ink-60);margin-bottom:16px">Pick your preferred date and time for this service.</p>
      <div class="form-group">
        <label for="selSchedule">Date &amp; Time</label>
        <input type="datetime-local" id="selSchedule">
      </div>
      <div id="scheduleWarning" style="display:none"></div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn-sm btn-outline" onclick="goStep(2)">&larr; Back</button>
        <button class="btn-sm btn-navy" onclick="goStep(4)" id="btnStep3">Next &rarr;</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ STEP 4: Dynamic Form ═══ -->
<div class="wizard-step" id="step4" style="display:none">
  <div class="card">
    <div class="card-head">
      <h3>Step 4 — Fill Application Form</h3>
      <span class="card-tag">Step 4 of 7</span>
    </div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--ink-60);margin-bottom:16px">Complete the required information for this service.</p>
      <div id="dynamicForm" class="form-grid"></div>
      <div id="noFields" class="notice notice-green" style="display:none">No additional form fields required for this service.</div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn-sm btn-outline" onclick="goStep(3)">&larr; Back</button>
        <button class="btn-sm btn-navy" onclick="goStep(5)" id="btnStep4">Next &rarr;</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ STEP 5: Upload Requirements ═══ -->
<div class="wizard-step" id="step5" style="display:none">
  <div class="card">
    <div class="card-head">
      <h3>Step 5 — Upload Requirements</h3>
      <span class="card-tag">Step 5 of 7</span>
    </div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--ink-60);margin-bottom:16px">Upload the required documents. Accepted formats: PDF, JPG, JPEG, PNG (max 5MB each).</p>
      <div id="requirementsList"></div>
      <div id="noReqs" class="notice notice-green" style="display:none">No document uploads required for this service.</div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn-sm btn-outline" onclick="goStep(4)">&larr; Back</button>
        <button class="btn-sm btn-navy" onclick="goStep(6)" id="btnStep5">Next &rarr;</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ STEP 6: Review & Submit ═══ -->
<div class="wizard-step" id="step6" style="display:none">
  <div class="card">
    <div class="card-head">
      <h3>Step 6 — Review &amp; Submit</h3>
      <span class="card-tag">Step 6 of 7</span>
    </div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--ink-60);margin-bottom:16px">Please review your application details before submitting.</p>
      <div id="reviewSummary"></div>
      <fieldset id="bookingPayment"><legend>Payment method</legend><p>Select payment before final submission. Payment remains pending until verified by the Bookkeeper.</p><label><input type="radio" name="booking_payment_method" value="cash"> Cash at the parish office</label><label><input type="radio" name="booking_payment_method" value="gcash"> GCash</label><label>GCash reference (required for GCash)<input id="bookingPaymentReference" maxlength="100"></label></fieldset>
      <div style="display:flex;justify-content:space-between;margin-top:20px">
        <button class="btn-sm btn-outline" onclick="goStep(5)">&larr; Back</button>
        <button class="btn-sm btn-green" onclick="submitApplication()" id="btnSubmit">[icon:check] Submit Application</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ STEP 7: Payment / Success ═══ -->
<div class="wizard-step" id="step7" style="display:none">
  <div class="card">
    <div class="card-head">
      <h3>Step 7 — Confirmation</h3>
      <span class="card-tag">Step 7 of 7</span>
    </div>
    <div class="card-body" id="step7Body">
      <!-- Filled by JS after submission -->
    </div>
  </div>
</div>

<style>
.svc-card{background:var(--white);border:2px solid var(--ink-10);border-radius:var(--r);padding:18px 20px;cursor:pointer;transition:var(--ease)}
.svc-card:hover{border-color:var(--gold);box-shadow:0 4px 16px var(--gold-dim)}
.svc-card.selected{border-color:var(--gold);background:var(--gold-dim)}
.svc-card h4{font-family:var(--fh);font-size:1rem;font-weight:600;margin-bottom:4px}
.svc-card p{font-size:.8rem;color:var(--ink-60);margin-bottom:8px}
.svc-card .svc-meta{display:flex;gap:12px;font-size:.74rem}
.svc-card .svc-meta span{display:inline-flex;align-items:center;gap:4px}
.review-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--ink-10);font-size:.84rem}
.review-row:last-child{border-bottom:none}
.review-label{color:var(--ink-60);font-weight:500;font-size:.74rem;letter-spacing:.05em;text-transform:uppercase}
.review-value{color:var(--ink);text-align:right;max-width:60%}
.file-input-wrap{margin-bottom:14px;padding:14px 16px;border:1.5px dashed var(--ink-10);border-radius:10px;background:#FAFAF8;transition:var(--ease)}
.file-input-wrap:hover{border-color:var(--gold)}
.file-input-wrap label{display:block;font-size:.74rem;font-weight:500;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-60);margin-bottom:6px}
.file-input-wrap small{display:block;font-size:.72rem;color:var(--ink-30);margin-bottom:8px}
.file-input-wrap input[type=file]{font-size:.8rem}
.payment-option{display:flex;align-items:center;gap:12px;padding:14px 18px;border:2px solid var(--ink-10);border-radius:10px;cursor:pointer;transition:var(--ease);margin-bottom:10px}
.payment-option:hover{border-color:var(--gold)}
.payment-option.selected{border-color:var(--gold);background:var(--gold-dim)}
.payment-option input[type=radio]{accent-color:var(--gold)}
.qr-box{text-align:center;padding:20px;margin:16px 0}
.qr-box img{margin:0 auto 12px;border-radius:8px;box-shadow:var(--sh)}
</style>

<script src="<?= h(app_url('assets/js/apply-service.js')) ?>"></script>
<script src="<?= h(app_url('assets/js/availability-calendar.js')) ?>"></script>

<script defer src="<?= h(app_url('assets/js/multiple-attachments.js')) ?>"></script>
<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
