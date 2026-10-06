<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';
require_once __DIR__ . '/../includes/service_editor.php';

/**
 * Staff Service Management — Full CRUD with Dynamic Form Builder & Requirements
 * Features: list, create, edit, delete, toggle status, manage form fields, manage requirements
 */

$page_id    = 'services';
$page_title = 'Service Management';
$page_sub   = 'Services';
include 'includes/layout.php';

$parish_id = (int)($user['parish_id'] ?? 0);

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // List services
    if ($_GET['ajax'] === 'list') {
        $stmt = $conn->prepare("
            SELECT s.*,
                (SELECT COUNT(*) FROM service_fields WHERE service_id = s.id) AS field_count,
                (SELECT COUNT(*) FROM service_requirements WHERE service_id = s.id) AS req_count
            FROM services s
            WHERE s.parish_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->bind_param('i', $parish_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // Toggle status
    if ($_GET['ajax'] === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE services SET status = IF(status='active','inactive','active') WHERE id=? AND parish_id=?");
        $stmt->bind_param('ii', $id, $parish_id);
        $ok = $stmt->execute();
        $new_status = '';
        if ($ok) {
            $r = $conn->prepare("SELECT status FROM services WHERE id=?");
            $r->bind_param('i', $id);
            $r->execute();
            $new_status = $r->get_result()->fetch_assoc()['status'] ?? '';
        }
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Status toggled.' : 'Failed.', 'new_status' => $new_status]);
        exit;
    }

    // Get single service with fields and requirements
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM services WHERE id=? AND parish_id=?");
        $stmt->bind_param('ii', $id, $parish_id);
        $stmt->execute();
        $service = $stmt->get_result()->fetch_assoc();

        if (!$service) {
            echo json_encode(['success' => false, 'message' => 'Service not found.']);
            exit;
        }

        // Get fields
        $stmt = $conn->prepare("SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order ASC, id ASC");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $service['fields'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Get requirements
        $stmt = $conn->prepare("SELECT * FROM service_requirements WHERE service_id=? ORDER BY id ASC");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $service['requirements'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['success' => true, 'data' => $service]);
        exit;
    }

    // Delete field
    if ($_GET['ajax'] === 'delete_field' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        // Verify ownership
        $check = $conn->prepare("SELECT sf.id FROM service_fields sf JOIN services s ON sf.service_id = s.id WHERE sf.id=? AND s.parish_id=?");
        $check->bind_param('ii', $id, $parish_id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Field not found.']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM service_fields WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Field deleted.' : 'Failed.']);
        exit;
    }

    // Edit in place: historical application requirement snapshots remain unchanged.
    if ($_GET['ajax'] === 'update_requirement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id=(int)($_POST['id']??0);
        $name=trim($_POST['document_name']??'');
        $description=trim($_POST['description']??'');
        if ($name==='' || mb_strlen($name)>255 || mb_strlen($description)>5000) fail_request('Enter a document name (up to 255 characters) and description (up to 5000).',422);
        $requirement=$conn->execute_query('SELECT r.id FROM service_requirements r JOIN services s ON s.id=r.service_id WHERE r.id=? AND s.parish_id=?',[$id,$parish_id])->fetch_assoc();
        if(!$requirement) fail_request('Requirement not found.',404);
        $conn->execute_query('UPDATE service_requirements SET document_name=?,description=?,is_required=? WHERE id=?',[$name,$description,empty($_POST['is_required'])?0:1,$id]);
        auditLog($user['id'],'update_requirement','service_requirement',$id);
        echo json_encode(['success'=>true,'message'=>'Requirement updated.']);exit;
    }

    // Add requirement
    if ($_GET['ajax'] === 'add_requirement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service_id    = (int)($_POST['service_id'] ?? 0);
        $document_name = trim($_POST['document_name'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $is_required   = (int)($_POST['is_required'] ?? 1);

        if (!$service_id || !$document_name) {
            echo json_encode(['success' => false, 'message' => 'Document name is required.']);
            exit;
        }

        // Verify service belongs to parish
        $check = $conn->prepare("SELECT id FROM services WHERE id=? AND parish_id=?");
        $check->bind_param('ii', $service_id, $parish_id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Service not found.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO service_requirements (service_id, document_name, description, is_required) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('issi', $service_id, $document_name, $description, $is_required);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Requirement added.' : 'Failed.', 'id' => $conn->insert_id]);
        exit;
    }

    // Delete requirement
    if ($_GET['ajax'] === 'delete_requirement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        // Verify ownership
        $check = $conn->prepare("SELECT sr.id FROM service_requirements sr JOIN services s ON sr.service_id = s.id WHERE sr.id=? AND s.parish_id=?");
        $check->bind_param('ii', $id, $parish_id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Requirement not found.']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM service_requirements WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Requirement deleted.' : 'Failed.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── PAGE DATA ───────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.*,
        (SELECT COUNT(*) FROM service_fields WHERE service_id = s.id) AS field_count,
        (SELECT COUNT(*) FROM service_requirements WHERE service_id = s.id) AS req_count
    FROM services s
    WHERE s.parish_id = ?
    ORDER BY s.created_at DESC
");
$stmt->bind_param('i', $parish_id);
$stmt->execute();
$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cnt_all      = count($services);
$cnt_active   = 0;
$cnt_inactive = 0;
foreach ($services as $s) {
    if ($s['status'] === 'active') $cnt_active++;
    else $cnt_inactive++;
}
?>


<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Create/Edit Service Modal -->
<div class="modal-wrap" id="serviceModal">
  <div class="modal" style="max-width:620px">
    <h2 id="svcModalTitle">New Service</h2>
    <p id="svcModalSub">Define a new parish service that parishioners can apply for.</p>
    <input type="hidden" id="svcId" value="">
    <div class="form-grid">
      <div class="form-group form-full">
        <label>Service Name *</label>
        <select id="svcGeneral" onchange="serviceTypeChanged()">
        <?php foreach ([...GENERAL_SERVICE_TYPES,'Other / Custom'] as $type): ?><option><?= h($type) ?></option><?php endforeach; ?>
        </select><label for="svcName">Local display name</label><input type="text" id="svcName" maxlength="255">
        <label for="svcClassification">Classification</label><select id="svcClassification"><option>Sacramental</option><option>Non-Sacramental</option></select>
        <label for="svcMode">Amount mode</label><select id="svcMode"><option value="fixed">Fixed Amount</option><option value="user_defined">User-Defined Amount</option></select>
      </div>
      <div class="form-group form-full">
        <label>Description</label>
        <textarea id="svcDescription" rows="3" placeholder="Brief description of this service..."></textarea>
      </div>
      <div class="form-group">
        <label>Fee (PHP)</label>
        <input type="number" id="svcFee" min="0" step="0.01" value="0.00" placeholder="0.00">
      </div>
      <div class="form-group form-full">
        <fieldset><legend>Available weekdays</legend><?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $dayIndex=>$dayName): ?><label><input type="checkbox" class="service-weekday" value="<?= $dayIndex+1 ?>" checked> <?= h($dayName) ?></label><?php endforeach; ?></fieldset>
        <label for="svcScheduleMode">Available time</label><select id="svcScheduleMode"><option value="user_defined">User-defined time</option><option value="fixed">Fixed time slots</option></select>
        <label for="svcTimeSlots">Slot times (12-hour AM/PM, separated by commas)</label><input id="svcTimeSlots" placeholder="9:00 AM, 10:30 AM, 1:30 PM" aria-describedby="slotTimeHelp"><small id="slotTimeHelp">Use AM or PM for every time. 12:00 AM is midnight; 12:00 PM is noon.</small>
        <label for="svcSlotCapacity">Applications per time (0 = no limit)</label><input id="svcSlotCapacity" type="number" min="0" value="1">
        <label>Requirements Note</label>
        <textarea id="svcReqNote" rows="2" placeholder="General notes about requirements..."></textarea>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="svcStatus">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
    <div class="modal-actions">
      <button onclick="closeModal('serviceModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="saveService()" class="btn-sm btn-navy" id="svcSaveBtn">Create Service</button>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-wrap" id="deleteModal">
  <div class="modal">
    <h2 style="color:var(--wine)">Delete Service</h2>
    <p>Are you sure you want to permanently delete this service? All associated form fields and requirements will also be removed. This cannot be undone.</p>
    <div class="modal-actions">
      <button onclick="closeModal('deleteModal')" class="btn-sm btn-outline">Cancel</button>
      <button onclick="confirmDelete()" class="btn-sm btn-wine">Delete</button>
    </div>
  </div>
</div>

<!-- Manage Fields Modal -->
<div class="modal-wrap" id="fieldsModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:800px;width:100%">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <h2 id="fieldsModalTitle">Manage Form Fields</h2>
      <button onclick="closeModal('fieldsModal')" class="act-btn act-navy" style="font-size:.9rem">&times;</button>
    </div>
    <p id="fieldsModalSub">Configure the custom form fields for this service.</p>
    <input type="hidden" id="fieldsServiceId" value="">

    <!-- Add/Edit Field Form -->
    <div class="card" style="margin-bottom:16px;box-shadow:none;border:1.5px solid var(--ink-10)">
      <div class="card-head" style="padding:12px 16px">
        <h3 id="fieldFormTitle" style="font-size:.9rem">Add New Field</h3>
      </div>
      <div class="card-body" style="padding:14px 16px">
        <input type="hidden" id="fieldId" value="">
        <div class="form-grid">
          <div class="form-group">
            <label>Field Label *</label>
            <input type="text" id="fieldLabel" placeholder="e.g. Date of Birth" oninput="autoFieldName()">
          </div>
          <div class="form-group">
            <label>Field Name</label>
            <input type="text" id="fieldName" placeholder="Auto-generated from label">
          </div>
          <div class="form-group">
            <label>Field Type</label>
            <select id="fieldType" onchange="toggleFieldOptions()">
              <option value="text">Text</option>
              <option value="number">Number</option>
              <option value="date">Date</option>
              <option value="select">Select (Dropdown)</option>
              <option value="textarea">Textarea</option>
              <option value="file">File Upload</option>
              <option value="email">Email</option>
              <option value="phone">Phone</option>
            </select>
          </div>
          <div class="form-group">
            <label>Sort Order</label>
            <input type="number" id="fieldSortOrder" min="0" value="0">
          </div>
          <div class="form-group form-full" id="fieldOptionsGroup" style="display:none">
            <label>Options (comma-separated)</label>
            <input type="text" id="fieldOptions" placeholder="e.g. Male,Female,Other">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:8px">
            <input type="checkbox" id="fieldRequired" style="width:auto;accent-color:var(--navy)">
            <label for="fieldRequired" style="margin-bottom:0;text-transform:none;font-size:.82rem;color:var(--ink)">Required field</label>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
          <button onclick="resetFieldForm()" class="btn-sm btn-outline" style="font-size:.72rem">Reset</button>
          <button onclick="saveField()" class="btn-sm btn-navy" id="fieldSaveBtn" style="font-size:.72rem">Add Field</button>
        </div>
      </div>
    </div>

    <!-- Fields List -->
    <div id="fieldsList">
      <p style="text-align:center;padding:30px;color:var(--ink-30);font-style:italic">Loading fields...</p>
    </div>
  </div>
</div>

<!-- Manage Requirements Modal -->
<div class="modal-wrap" id="reqsModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:700px;width:100%">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <h2 id="reqsModalTitle">Required Documents</h2>
      <button onclick="closeModal('reqsModal')" class="act-btn act-navy" style="font-size:.9rem">&times;</button>
    </div>
    <p id="reqsModalSub">Manage the required documents for this service.</p>
    <input type="hidden" id="reqsServiceId" value="">
    <input type="hidden" id="reqEditId" value="">

    <!-- Add Requirement Form -->
    <div class="card" style="margin-bottom:16px;box-shadow:none;border:1.5px solid var(--ink-10)">
      <div class="card-head" style="padding:12px 16px">
        <h3 style="font-size:.9rem">Add Requirement</h3>
      </div>
      <div class="card-body" style="padding:14px 16px">
        <div class="form-grid">
          <div class="form-group">
            <label>Document Name *</label>
            <input type="text" id="reqDocName" placeholder="e.g. Birth Certificate">
          </div>
          <div class="form-group">
            <label>Description</label>
            <input type="text" id="reqDescription" placeholder="e.g. Original or certified copy">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:8px">
            <input type="checkbox" id="reqIsRequired" checked style="width:auto;accent-color:var(--navy)">
            <label for="reqIsRequired" style="margin-bottom:0;text-transform:none;font-size:.82rem;color:var(--ink)">Mandatory document</label>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
          <button onclick="resetRequirementEditor()" type="button" class="btn-sm btn-outline">Clear</button>
          <button id="saveRequirementBtn" onclick="saveRequirement()" class="btn-sm btn-navy" style="font-size:.72rem">Add Requirement</button>
        </div>
      </div>
    </div>

    <!-- Requirements List -->
    <div id="reqsList">
      <p style="text-align:center;padding:30px;color:var(--ink-30);font-style:italic">Loading requirements...</p>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Management</div>
    <h1 class="sec-title">Services</h1>
    <p class="sec-sub">Create and manage parish services, custom form fields, and requirements.</p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="openCreate()" class="btn-sm btn-navy">+ New Service</button>
  </div>
</div>

<!-- STATUS CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card stat-navy" style="padding:16px 20px">
    <div class="stat-icon">[icon:settings]</div>
    <div class="stat-label">Total Services</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_all; ?></div>
  </div>
  <div class="stat-card stat-green" style="padding:16px 20px">
    <div class="stat-icon">[icon:check]</div>
    <div class="stat-label">Active</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_active; ?></div>
  </div>
  <div class="stat-card stat-wine" style="padding:16px 20px">
    <div class="stat-icon">◼</div>
    <div class="stat-label">Inactive</div>
    <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_inactive; ?></div>
  </div>
</div>

<!-- SERVICES LIST -->
<?php if (empty($services)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:60px 20px">
    <div style="font-size:2.5rem;margin-bottom:12px;opacity:.3">[icon:settings]</div>
    <p style="color:var(--ink-30);font-style:italic;margin-bottom:16px">No services have been created yet.</p>
    <button onclick="openCreate()" class="btn-sm btn-navy">+ Create Your First Service</button>
  </div>
</div>
<?php else: ?>
<div class="grid-2" id="servicesGrid">
  <?php foreach ($services as $svc):
    $is_active = $svc['status'] === 'active';
  ?>
  <div class="card" id="svc-card-<?php echo $svc['id']; ?>" style="margin-bottom:0">
    <div class="card-head" style="padding:14px 18px">
      <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0">
        <span style="font-family:var(--fh);font-size:1rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($svc['name']); ?></span>
        <span class="pill <?php echo $is_active ? 'pill-green' : 'pill-wine'; ?>" id="svc-status-<?php echo $svc['id']; ?>"><?php echo ucfirst($svc['status']); ?></span>
      </div>
      <div style="display:flex;gap:3px;flex-shrink:0">
        <button onclick="openEdit(<?php echo $svc['id']; ?>)" class="act-btn act-gold" title="Edit">[icon:edit]</button>
        <button onclick="toggleStatus(<?php echo $svc['id']; ?>)" class="act-btn <?php echo $is_active ? 'act-wine' : 'act-green'; ?>" title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>">
          <?php echo $is_active ? '◼' : '▶'; ?>
        </button>
        <button onclick="deleteSvc(<?php echo $svc['id']; ?>)" class="act-btn act-wine" title="Delete">[icon:close]</button>
      </div>
    </div>
    <div class="card-body" style="padding:14px 18px">
      <?php if ($svc['description']): ?>
      <p style="font-size:.82rem;color:var(--ink-60);line-height:1.5;margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo htmlspecialchars($svc['description']); ?></p>
      <?php endif; ?>

      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-size:.72rem;color:var(--ink-30)">Fee:</span>
          <span style="font-size:.82rem;font-weight:500;color:var(--ink)"><?php echo $svc['fee'] > 0 ? '₱' . number_format($svc['fee'], 2) : 'Free'; ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-size:.72rem;color:var(--ink-30)">Daily Limit:</span>
          <span style="font-size:.82rem;font-weight:500;color:var(--ink)"><?php echo $svc['max_daily_limit'] > 0 ? $svc['max_daily_limit'] : 'Unlimited'; ?></span>
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <span class="pill pill-navy"><?php echo (int)$svc['field_count']; ?> Form Field<?php echo $svc['field_count'] != 1 ? 's' : ''; ?></span>
        <span class="pill pill-amber"><?php echo (int)$svc['req_count']; ?> Requirement<?php echo $svc['req_count'] != 1 ? 's' : ''; ?></span>
      </div>

      <div style="display:flex;gap:6px;border-top:1px solid var(--ink-10);padding-top:12px">
        <button onclick="openFields(<?php echo $svc['id']; ?>, '<?php echo htmlspecialchars(addslashes($svc['name']), ENT_QUOTES); ?>')" class="btn-sm btn-outline" style="font-size:.72rem;padding:6px 14px">[icon:menu] Manage Fields</button>
        <button onclick="openRequirements(<?php echo $svc['id']; ?>, '<?php echo htmlspecialchars(addslashes($svc['name']), ENT_QUOTES); ?>')" class="btn-sm btn-outline" style="font-size:.72rem;padding:6px 14px">[icon:file] Requirements</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
let deleteId = null;

// ── Service CRUD ─────────────────────────────

function openCreate() {
    document.getElementById('svcModalTitle').textContent = 'New Service';
    document.getElementById('svcModalSub').textContent = 'Define a new parish service that parishioners can apply for.';
    document.getElementById('svcSaveBtn').textContent = 'Create Service';
    document.getElementById('svcId').value = '';
    document.getElementById('svcGeneral').value = 'Baptism';
    document.getElementById('svcMode').value = 'fixed';
    document.getElementById('svcClassification').value = 'Sacramental';
    serviceTypeChanged();
    document.getElementById('svcDescription').value = '';
    document.getElementById('svcFee').value = '0.00';
    document.getElementById('svcScheduleMode').value='user_defined';document.getElementById('svcTimeSlots').value='';document.getElementById('svcSlotCapacity').value='1';
    document.querySelectorAll('.service-weekday').forEach(el=>el.checked=true);
    document.getElementById('svcReqNote').value = '';
    document.getElementById('svcStatus').value = 'active';
    openModal('serviceModal');
}

function openEdit(id) {
    setLoading(true);
    fetch('services.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (!data.success) { showToast(data.message, 'error'); return; }
            const s = data.data;
            document.getElementById('svcModalTitle').textContent = 'Edit Service';
            document.getElementById('svcModalSub').textContent = 'Update the service details.';
            document.getElementById('svcSaveBtn').textContent = 'Save Changes';
            document.getElementById('svcId').value = s.id;
            document.getElementById('svcGeneral').value = s.general_type || 'Other / Custom';
            document.getElementById('svcMode').value = s.amount_mode || 'fixed';
            document.getElementById('svcClassification').value = s.classification || 'Non-Sacramental';
            document.getElementById('svcName').value = s.name;
            document.getElementById('svcDescription').value = s.description || '';
            document.getElementById('svcFee').value = parseFloat(s.fee || 0).toFixed(2);
            document.getElementById('svcScheduleMode').value=s.schedule_mode||'user_defined';document.getElementById('svcTimeSlots').value=JSON.parse(s.time_slots||'[]').map(ViscarTime.format).join(', ');document.getElementById('svcSlotCapacity').value=s.slot_capacity??1;
            const days=JSON.parse(s.available_weekdays||'[1,2,3,4,5,6,7]');document.querySelectorAll('.service-weekday').forEach(el=>el.checked=days.includes(Number(el.value)));
            document.getElementById('svcReqNote').value = s.requirements_note || '';
            document.getElementById('svcStatus').value = s.status;
            openModal('serviceModal');
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function serviceTypeChanged() {
    const type = document.getElementById('svcGeneral').value;
    document.getElementById('svcName').value = type === 'Other / Custom' ? '' : type;
}
function clearEditorErrors(modal){document.querySelectorAll('#'+modal+' .field-error').forEach(el=>el.remove());document.querySelectorAll('#'+modal+' [aria-invalid]').forEach(el=>el.removeAttribute('aria-invalid'));}
function showEditorErrors(modal,errors,message){
    const fields={name:'svcName',fee:'svcFee',slot_capacity:'svcSlotCapacity',time_slots:'svcTimeSlots',available_weekdays:'svcScheduleMode',field_label:'fieldLabel',field_name:'fieldName',field_options:'fieldOptions'};
    let first=null;
    for(const [key,text] of Object.entries(errors)){const input=document.getElementById(fields[key]);if(!input)continue;const error=document.createElement('span');error.className='field-error';error.id=input.id+'Error';error.textContent=text;input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',error.id);input.after(error);first??=input;}
    if(!first&&message){const error=document.createElement('p');error.className='field-error';error.setAttribute('role','alert');error.textContent=message;(document.querySelector('#'+modal+' .modal-actions')||document.getElementById('fieldSaveBtn')).before(error);}
    first?.focus();
}
function saveService() {
    clearEditorErrors('serviceModal');
    const id = document.getElementById('svcId').value;
    const name = document.getElementById('svcName').value.trim();
    if (!name) { showEditorErrors('serviceModal',{name:'Enter a service name.'});return; }

    setLoading(true);
    const fd = new FormData();
    fd.append('name', name);
    fd.append('time_slots_format','12h');fd.append('schedule_mode',document.getElementById('svcScheduleMode').value);fd.append('time_slots',document.getElementById('svcTimeSlots').value);fd.append('slot_capacity',document.getElementById('svcSlotCapacity').value);
    fd.append('general_type', document.getElementById('svcGeneral').value);
    fd.append('classification', document.getElementById('svcClassification').value);
    fd.append('amount_mode', document.getElementById('svcMode').value);
    fd.append('description', document.getElementById('svcDescription').value.trim());
    fd.append('fee', document.getElementById('svcFee').value);
    fd.append('requirements_note', document.getElementById('svcReqNote').value.trim());
    fd.append('status', document.getElementById('svcStatus').value);
    fd.append('weekdays_present','1');document.querySelectorAll('.service-weekday:checked').forEach(el=>fd.append('available_weekdays[]',el.value));

    let action = 'create';
    if (id) { action = 'update'; fd.append('id', id); }

    fetch('services.php?ajax=' + action, { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if(!data.success)showEditorErrors('serviceModal',data.errors||{},data.message);
            if (data.success) {closeModal('serviceModal');setTimeout(() => location.reload(), 800);}
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function toggleStatus(id) {
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('services.php?ajax=toggle', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                showToast('[icon:check] Status updated.', 'success');
                setTimeout(() => location.reload(), 800);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function deleteSvc(id) {
    deleteId = id;
    openModal('deleteModal');
}

function confirmDelete() {
    closeModal('deleteModal');
    setLoading(true);
    const fd = new FormData(); fd.append('id', deleteId);
    fetch('services.php?ajax=delete', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            if (data.success) {
                const card = document.getElementById('svc-card-' + deleteId);
                if (card) { card.style.opacity = '0.3'; setTimeout(() => card.remove(), 500); }
                showToast('[icon:check] ' + data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else { showToast(data.message, 'error'); }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── Form Fields Management ─────────────────────

function openFields(serviceId, serviceName) {
    document.getElementById('fieldsServiceId').value = serviceId;
    document.getElementById('fieldsModalTitle').textContent = 'Form Fields: ' + serviceName;
    document.getElementById('fieldsModalSub').textContent = 'Configure the custom form fields parishioners fill out when applying.';
    resetFieldForm();
    openModal('fieldsModal');
    loadFields(serviceId);
}

let loadedFields = {};
function loadFields(serviceId) {
    document.getElementById('fieldsList').innerHTML = '<p style="text-align:center;padding:30px;color:var(--ink-30)"><span class="spinner" style="border-top-color:var(--navy);width:24px;height:24px;display:inline-block;vertical-align:middle;margin-right:8px"></span> Loading...</p>';
    fetch('services.php?ajax=get&id=' + serviceId)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('fieldsList').innerHTML = '<p style="text-align:center;padding:20px;color:var(--wine)">' + data.message + '</p>'; return; }
            const fields = data.data.fields || [];
            loadedFields = Object.fromEntries(fields.map(field=>[field.id,field]));
            if (fields.length === 0) {
                document.getElementById('fieldsList').innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-30);font-style:italic"><p>No form fields yet. Add one above.</p></div>';
                return;
            }
            let html = '<div class="tbl-wrap"><table><thead><tr><th>Order</th><th>Label</th><th>Name</th><th>Type</th><th>Required</th><th>Options</th><th>Actions</th></tr></thead><tbody>';
            fields.forEach(f => {
                const reqBadge = f.is_required == 1 ? '<span class="pill pill-wine" style="font-size:.6rem">Required</span>' : '<span class="pill pill-navy" style="font-size:.6rem">Optional</span>';
                const opts = f.field_options ? '<span style="font-size:.72rem;color:var(--ink-60)">' + escHtml(f.field_options) + '</span>' : '<span style="color:var(--ink-30);font-size:.72rem">—</span>';
                html += '<tr>';
                html += '<td style="text-align:center;font-weight:500">' + f.sort_order + '</td>';
                html += '<td style="font-weight:500">' + escHtml(f.field_label) + '</td>';
                html += '<td><code style="font-size:.72rem;background:var(--ink-10);padding:2px 6px;border-radius:4px">' + escHtml(f.field_name) + '</code></td>';
                html += '<td><span class="pill pill-navy" style="font-size:.6rem">' + escHtml(f.field_type) + '</span></td>';
                html += '<td>' + reqBadge + '</td>';
                html += '<td>' + opts + '</td>';
                html += '<td><div style="display:flex;gap:3px"><button onclick="editField(' + f.id + ')" class="act-btn act-gold" style="font-size:.68rem">[icon:edit]</button><button onclick="deleteField(' + f.id + ')" class="act-btn act-wine" style="font-size:.68rem">[icon:close]</button></div></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            document.getElementById('fieldsList').innerHTML = html;
        }).catch(() => {
            document.getElementById('fieldsList').innerHTML = '<p style="text-align:center;padding:20px;color:var(--wine)">Failed to load fields.</p>';
        });
}

function autoFieldName() {
    if (document.getElementById('fieldId').value) return;
    const label = document.getElementById('fieldLabel').value;
    let name = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    if(/^[0-9]/.test(name))name='field_'+name;
    document.getElementById('fieldName').value = name;
}

function toggleFieldOptions() {
    const type = document.getElementById('fieldType').value;
    document.getElementById('fieldOptionsGroup').style.display = type === 'select' ? 'block' : 'none';
}

function resetFieldForm() {
    clearEditorErrors('fieldsModal');
    document.getElementById('fieldId').value = '';
    document.getElementById('fieldLabel').value = '';
    document.getElementById('fieldName').value = '';
    document.getElementById('fieldType').value = 'text';
    document.getElementById('fieldOptions').value = '';
    document.getElementById('fieldRequired').checked = false;
    document.getElementById('fieldSortOrder').value = '0';
    document.getElementById('fieldOptionsGroup').style.display = 'none';
    document.getElementById('fieldFormTitle').textContent = 'Add New Field';
    document.getElementById('fieldSaveBtn').textContent = 'Add Field';
}

function editField(id) {
    const f = loadedFields[id];
    if (!f) return;
    document.getElementById('fieldId').value = f.id;
    document.getElementById('fieldLabel').value = f.field_label;
    document.getElementById('fieldName').value = f.field_name;
    document.getElementById('fieldType').value = f.field_type;
    document.getElementById('fieldOptions').value = f.field_options || '';
    document.getElementById('fieldRequired').checked = f.is_required == 1;
    document.getElementById('fieldSortOrder').value = f.sort_order || 0;
    document.getElementById('fieldFormTitle').textContent = 'Edit Field';
    document.getElementById('fieldSaveBtn').textContent = 'Update Field';
    toggleFieldOptions();
    // Scroll to form
    document.getElementById('fieldFormTitle').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function saveField() {
    clearEditorErrors('fieldsModal');
    const serviceId = document.getElementById('fieldsServiceId').value;
    const fieldId = document.getElementById('fieldId').value;
    const label = document.getElementById('fieldLabel').value.trim();
    if (!label) { showEditorErrors('fieldsModal',{field_label:'Enter a field label.'});return; }

    const fd = new FormData();
    fd.append('field_label', label);
    fd.append('field_name', document.getElementById('fieldName').value.trim());
    fd.append('field_type', document.getElementById('fieldType').value);
    fd.append('field_options', document.getElementById('fieldOptions').value.trim());
    fd.append('is_required', document.getElementById('fieldRequired').checked ? 1 : 0);
    fd.append('sort_order', document.getElementById('fieldSortOrder').value);

    let action = 'add_field';
    if (fieldId) {
        action = 'update_field';
        fd.append('id', fieldId);
    } else {
        fd.append('service_id', serviceId);
    }

    setLoading(true);
    fetch('services.php?ajax=' + action, { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if(!data.success)showEditorErrors('fieldsModal',data.errors||{},data.message);
            if (data.success) {
                resetFieldForm();
                loadFields(serviceId);
            }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function deleteField(id) {
    if (!confirm('Delete this field?')) return;
    const serviceId = document.getElementById('fieldsServiceId').value;
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('services.php?ajax=delete_field', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) loadFields(serviceId);
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── Requirements Management ─────────────────────

function openRequirements(serviceId, serviceName) {
    resetRequirementEditor();
    document.getElementById('reqsServiceId').value = serviceId;
    document.getElementById('reqsModalTitle').textContent = 'Requirements: ' + serviceName;
    document.getElementById('reqsModalSub').textContent = 'Manage the documents required for this service application.';
    document.getElementById('reqDocName').value = '';
    document.getElementById('reqDescription').value = '';
    document.getElementById('reqIsRequired').checked = true;
    openModal('reqsModal');
    loadRequirements(serviceId);
}

function loadRequirements(serviceId) {
    document.getElementById('reqsList').innerHTML = '<p style="text-align:center;padding:30px;color:var(--ink-30)"><span class="spinner" style="border-top-color:var(--navy);width:24px;height:24px;display:inline-block;vertical-align:middle;margin-right:8px"></span> Loading...</p>';
    fetch('services.php?ajax=get&id=' + serviceId)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('reqsList').innerHTML = '<p style="text-align:center;padding:20px;color:var(--wine)">' + data.message + '</p>'; return; }
            const reqs = data.data.requirements || [];
            if (reqs.length === 0) {
                document.getElementById('reqsList').innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-30);font-style:italic"><p>No requirements yet. Add one above.</p></div>';
                return;
            }
            let html = '<div class="tbl-wrap"><table><thead><tr><th>Document</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            reqs.forEach(r => {
                const badge = r.is_required == 1 ? '<span class="pill pill-wine" style="font-size:.6rem">Mandatory</span>' : '<span class="pill pill-navy" style="font-size:.6rem">Optional</span>';
                html += '<tr>';
                html += '<td style="font-weight:500">' + escHtml(r.document_name) + '</td>';
                html += '<td style="font-size:.8rem;color:var(--ink-60)">' + escHtml(r.description || '—') + '</td>';
                html += '<td>' + badge + '</td>';
                html += '<td><button type="button" data-edit-requirement="' + Number(r.id) + '" class="act-btn act-gold" aria-label="Edit requirement">[icon:edit]</button> <button onclick="deleteRequirement(' + r.id + ')" class="act-btn act-wine" style="font-size:.68rem">[icon:close] Remove</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            document.getElementById('reqsList').innerHTML = html;
            document.querySelectorAll('[data-edit-requirement]').forEach(button => button.addEventListener('click', () => {
                const requirement=reqs.find(item=>Number(item.id)===Number(button.dataset.editRequirement));
                document.getElementById('reqEditId').value=requirement.id;
                document.getElementById('reqDocName').value=requirement.document_name;
                document.getElementById('reqDescription').value=requirement.description || '';
                document.getElementById('reqIsRequired').checked=Number(requirement.is_required)===1;
                document.getElementById('saveRequirementBtn').textContent='Save Requirement';
                document.getElementById('reqDocName').focus();
            }));
        }).catch(() => {
            document.getElementById('reqsList').innerHTML = '<p style="text-align:center;padding:20px;color:var(--wine)">Failed to load requirements.</p>';
        });
}

function resetRequirementEditor() {
    document.getElementById('reqEditId').value='';
    document.getElementById('reqDocName').value='';
    document.getElementById('reqDescription').value='';
    document.getElementById('reqIsRequired').checked=true;
    document.getElementById('saveRequirementBtn').textContent='Add Requirement';
}
function saveRequirement() {
    const serviceId = document.getElementById('reqsServiceId').value;
    const docName = document.getElementById('reqDocName').value.trim();
    if (!docName) { showToast('Document name is required.', 'error'); return; }

    const fd = new FormData();
    fd.append('service_id', serviceId);
    fd.append('document_name', docName);
    fd.append('description', document.getElementById('reqDescription').value.trim());
    fd.append('is_required', document.getElementById('reqIsRequired').checked ? 1 : 0);

    setLoading(true);
    const editId=document.getElementById('reqEditId').value;
    if(editId) fd.append('id',editId);
    fetch('services.php?ajax='+(editId?'update_requirement':'add_requirement'), { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) {
                resetRequirementEditor();
                document.getElementById('reqDocName').value = '';
                document.getElementById('reqDescription').value = '';
                document.getElementById('reqIsRequired').checked = true;
                loadRequirements(serviceId);
            }
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

function deleteRequirement(id) {
    if (!confirm('Remove this requirement?')) return;
    const serviceId = document.getElementById('reqsServiceId').value;
    setLoading(true);
    const fd = new FormData(); fd.append('id', id);
    fetch('services.php?ajax=delete_requirement', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            setLoading(false);
            showToast(data.success ? '[icon:check] ' + data.message : data.message, data.success ? 'success' : 'error');
            if (data.success) loadRequirements(serviceId);
        }).catch(() => { setLoading(false); showToast('Network error.', 'error'); });
}

// ── Utility ─────────────────────────────────────

function escHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
</script>

<?php include 'includes/layout_footer.php'; ?>
