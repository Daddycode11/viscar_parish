<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

require_once '../includes/db.php';
// require_once '../includes/auth.php';
// checkRole('admin');
$user = currentUser();


$action = $_GET['action'] ?? 'list';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$flash  = '';

/**
 * Save uploaded image to a sub-folder under /uploads. Returns relative web path,
 * or null on failure / no upload.
 */
function save_image_upload($field, $subdir) {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $info = getimagesize($_FILES[$field]['tmp_name']);
    if (!$info) return null;
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $ext = $allowed[$info['mime']] ?? null;
    if (!$ext) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null; // 5 MB cap

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) return null;
    return 'uploads/' . $subdir . '/' . $filename;
}

/* ── HANDLE POST ACTIONS ──────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['_action'] ?? '';

  if ($act === 'add_parish') {
    $name     = trim($_POST['name']);
    $location = trim($_POST['location']);
    $address  = trim($_POST['address']);
    $contact  = trim($_POST['contact']);
    $email    = trim($_POST['email']);
    $priest   = trim($_POST['priest_name']);
    $logo     = save_image_upload('logo', 'parish_logos');
    $stmt = $conn->prepare("INSERT INTO parishes (name,location,address,contact_number,email,priest_name,logo,status) VALUES (?,?,?,?,?,?,?,'active')");
    $stmt->bind_param('sssssss', $name,$location,$address,$contact,$email,$priest,$logo);
    $stmt->execute();
    $flash = 'success:Parish added successfully.';
    $action = 'list';
  }

  if ($act === 'edit_parish') {
    $id       = (int)$_POST['id'];
    $name     = trim($_POST['name']);
    $location = trim($_POST['location']);
    $address  = trim($_POST['address']);
    $contact  = trim($_POST['contact']);
    $email    = trim($_POST['email']);
    $priest   = trim($_POST['priest_name']);
    $status   = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
    $logo     = save_image_upload('logo', 'parish_logos');

    if ($logo) {
      $stmt = $conn->prepare("UPDATE parishes SET name=?,location=?,address=?,contact_number=?,email=?,priest_name=?,logo=?,status=? WHERE id=?");
      $stmt->bind_param('ssssssssi', $name,$location,$address,$contact,$email,$priest,$logo,$status,$id);
    } else {
      $stmt = $conn->prepare("UPDATE parishes SET name=?,location=?,address=?,contact_number=?,email=?,priest_name=?,status=? WHERE id=?");
      $stmt->bind_param('sssssssi', $name,$location,$address,$contact,$email,$priest,$status,$id);
    }
    $stmt->execute();
    $flash = 'success:Parish updated successfully.';
    $action = 'list';
  }

  if ($act === 'toggle_parish') {
    $id = (int)$_POST['id'];
    $cur = $conn->query("SELECT status FROM parishes WHERE id=$id")->fetch_assoc()['status'];
    $new = ($cur === 'active') ? 'inactive' : 'active';
    $conn->query("UPDATE parishes SET status='$new' WHERE id=$id");
    $flash = 'success:Parish status updated.';
  }

  if ($act === 'delete_parish') {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM parishes WHERE id=$id");
    $flash = 'success:Parish deleted.';
  }
}

/* ── FETCH DATA ───────────────────────────── */

// Fetch parishes with user and application counts
$parishes = [];
$res = $conn->query("
    SELECT p.*,
           COUNT(DISTINCT u.id) AS total_users,
           COUNT(DISTINCT a.id) AS total_apps
    FROM parishes p
    LEFT JOIN users u ON u.parish_id = p.id
    LEFT JOIN applications a ON a.parish_id = p.id
    GROUP BY p.id
    ORDER BY p.name
");
while($row = $res->fetch_assoc()) $parishes[] = $row;

// Fetch single parish for edit
$edit_parish = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $eid = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM parishes WHERE id=?");
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $edit_parish = $stmt->get_result()->fetch_assoc();
}

$page_id    = 'parishes';
$page_title = 'Parish Management';
$page_sub   = 'Parishes';
include 'includes/layout.php';
?>

<?php if($flash): [$ftype,$fmsg] = explode(':',$flash,2); ?>
<div class="notice notice-<?php echo $ftype === 'success' ? 'green' : 'amber'; ?> flash-msg" style="margin-bottom:20px;transition:opacity .5s">
  <span><?php echo $ftype === 'success' ? '[icon:check]' : '[icon:alert]'; ?></span>
  <span><?php echo htmlspecialchars($fmsg); ?></span>
</div>
<?php endif; ?>

<?php if($action === 'add' || ($action === 'edit' && $edit_parish)): ?>
<!-- ═══ ADD / EDIT FORM ════════════════════════ -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Parish Management</div>
    <h1 class="sec-title"><?php echo $action === 'add' ? 'Add New Parish' : 'Edit Parish'; ?></h1>
    <p class="sec-sub"><?php echo $action === 'add' ? 'Register a new parish under the Apostolic Vicariate of San Jose.' : 'Update parish information and settings.'; ?></p>
  </div>
  <a href="parishes.php" class="btn-sm btn-outline">← Back to Parishes</a>
</div>

<div class="card" style="max-width:700px">
  <div class="card-head">
    <h3><?php echo $action === 'add' ? 'Parish Details' : 'Edit Parish Details'; ?></h3>
  </div>
  <div class="card-body">
    <form method="POST" action="parishes.php" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="<?php echo $action === 'add' ? 'add_parish' : 'edit_parish'; ?>">
      <?php if($edit_parish): ?><input type="hidden" name="id" value="<?php echo $edit_parish['id']; ?>"><?php endif; ?>

      <?php
        $current_logo = $edit_parish['logo'] ?? '';
        $logo_src     = $current_logo ? '../' . htmlspecialchars($current_logo) : '';
      ?>
      <div class="form-group form-full" style="display:flex;align-items:center;gap:18px">
        <div id="logoPreview" style="width:90px;height:90px;border-radius:14px;background:<?php echo $logo_src ? '#fff' : 'linear-gradient(135deg,var(--navy),var(--navy-mid))'; ?>;background-size:cover;background-position:center;<?php if($logo_src): ?>background-image:url('<?php echo $logo_src; ?>');<?php endif; ?>display:grid;place-items:center;font-family:var(--fh);font-size:1.6rem;color:var(--gold-lt);border:1px solid var(--ink-10);overflow:hidden;flex-shrink:0">
          <?php if(!$logo_src): ?>[icon:church]<?php endif; ?>
        </div>
        <div style="flex:1">
          <label style="font-weight:500;font-size:.85rem">Parish Logo / Profile Picture</label>
          <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" onchange="previewLogo(this)" style="display:block;margin-top:6px;font-size:.8rem">
          <div style="font-size:.7rem;color:var(--ink-30);margin-top:4px">PNG, JPG, WEBP or GIF · max 5 MB</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group form-full">
          <label>Parish Name *</label>
          <input type="text" name="name" required placeholder="e.g. San Jose Cathedral"
            value="<?php echo htmlspecialchars($edit_parish['name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Municipality / Location *</label>
          <input type="text" name="location" required placeholder="e.g. San Jose, Occidental Mindoro"
            value="<?php echo htmlspecialchars($edit_parish['location'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Full Address</label>
          <input type="text" name="address" placeholder="Street, Barangay, Town"
            value="<?php echo htmlspecialchars($edit_parish['address'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Parish Priest Name</label>
          <input type="text" name="priest_name" placeholder="Fr. Juan dela Cruz"
            value="<?php echo htmlspecialchars($edit_parish['priest_name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Contact Number</label>
          <input type="text" name="contact" placeholder="09XXXXXXXXX"
            value="<?php echo htmlspecialchars($edit_parish['contact_number'] ?? $edit_parish['contact'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" placeholder="parish@vicariate.ph"
            value="<?php echo htmlspecialchars($edit_parish['email'] ?? ''); ?>">
        </div>
        <?php if($action === 'edit'): ?>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="active" <?php echo ($edit_parish['status']??'active')==='active'?'selected':''; ?>>Active</option>
            <option value="inactive" <?php echo ($edit_parish['status']??'')==='inactive'?'selected':''; ?>>Inactive</option>
          </select>
        </div>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:10px;margin-top:8px">
        <button type="submit" class="btn-sm btn-navy" style="padding:10px 28px">
          <?php echo $action === 'add' ? '+ Save Parish' : 'Update Parish'; ?>
        </button>
        <a href="parishes.php" class="btn-sm btn-outline" style="padding:10px 20px">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══ PARISH LIST ════════════════════════════ -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Parish Management</div>
    <h1 class="sec-title">Parishes</h1>
    <p class="sec-sub">Manage all <?php echo count($parishes); ?> parishes within the Apostolic Vicariate of San Jose.</p>
  </div>
  <a href="parishes.php?action=add" class="btn-sm btn-navy">+ Add Parish</a>
</div>

<!-- Stats row -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
  <?php
  $active_count = count(array_filter($parishes, fn($p) => $p['status']==='active'));
  $total_users_sum = array_sum(array_column($parishes,'total_users'));
  $total_apps_sum  = array_sum(array_column($parishes,'total_apps'));
  ?>
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:church]</div>
    <div class="stat-label">Total Parishes</div>
    <div class="stat-value"><?php echo count($parishes); ?></div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:check]</div>
    <div class="stat-label">Active</div>
    <div class="stat-value"><?php echo $active_count; ?></div>
  </div>
  <div class="stat-card stat-blue">
    <div class="stat-icon">[icon:user]</div>
    <div class="stat-label">Total Users</div>
    <div class="stat-value"><?php echo $total_users_sum; ?></div>
  </div>
  <div class="stat-card stat-amber">
    <div class="stat-icon">[icon:clipboard]</div>
    <div class="stat-label">Total Apps</div>
    <div class="stat-value"><?php echo $total_apps_sum; ?></div>
  </div>
</div>

<!-- Parish Cards Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
  <?php foreach($parishes as $p): $active = $p['status']==='active'; ?>
  <div style="background:var(--white);border-radius:var(--r);border:1px solid var(--ink-10);padding:22px;box-shadow:var(--sh);transition:var(--ease)" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='var(--sh-md)'" onmouseout="this.style.transform='none';this.style.boxShadow='var(--sh)'">
    
    <!-- Top row -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px">
      <div style="display:flex;align-items:center;gap:12px">
        <?php if(!empty($p['logo'])): ?>
        <div style="width:46px;height:46px;border-radius:12px;background:#fff url('../<?php echo htmlspecialchars($p['logo']); ?>') center/cover no-repeat;border:1px solid var(--ink-10);flex-shrink:0"></div>
        <?php else: ?>
        <div style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,var(--navy),var(--navy-mid));display:grid;place-items:center;font-family:var(--fh);font-size:1.2rem;color:var(--gold-lt);flex-shrink:0">
          <?php echo strtoupper(substr($p['name'],0,1)); ?>
        </div>
        <?php endif; ?>
        <div>
          <div style="font-family:var(--fh);font-size:1rem;font-weight:600;color:var(--ink)"><?php echo htmlspecialchars($p['name']); ?></div>
          <div style="font-size:.73rem;color:var(--ink-60);margin-top:2px">[icon:pin] <?php echo htmlspecialchars($p['location']); ?></div>
        </div>
      </div>
      <span class="pill <?php echo $active ? 'pill-green' : 'pill-wine'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
    </div>

    <!-- Info rows -->
    <div style="display:flex;flex-direction:column;gap:5px;margin-bottom:14px;padding:12px;background:#F8F6F2;border-radius:8px">
      <?php if(isset($p['priest_name']) && $p['priest_name']): ?>
      <div style="font-size:.75rem;color:var(--ink-60)">[icon:sun] <?php echo htmlspecialchars($p['priest_name']); ?></div>
      <?php endif; ?>
      <?php $contactVal = $p['contact'] ?? ($p['contact_number'] ?? ''); if($contactVal): ?>
      <div style="font-size:.75rem;color:var(--ink-60)">[icon:phone] <?php echo htmlspecialchars($contactVal); ?></div>
      <?php endif; ?>
      <?php if($p['email']): ?>
      <div style="font-size:.75rem;color:var(--ink-60)">[icon:mail] <?php echo htmlspecialchars($p['email']); ?></div>
      <?php endif; ?>
    </div>

    <!-- Stats mini -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
      <div style="background:#F0EDE8;border-radius:8px;padding:8px 12px">
        <div style="font-family:var(--fh);font-size:1.3rem;font-weight:600;color:var(--navy)"><?php echo isset($p['total_users']) ? $p['total_users'] : 0; ?></div>
        <div style="font-size:.64rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60)">Users</div>
      </div>
      <div style="background:#F0EDE8;border-radius:8px;padding:8px 12px">
        <div style="font-family:var(--fh);font-size:1.3rem;font-weight:600;color:var(--navy)"><?php echo isset($p['total_apps']) ? $p['total_apps'] : 0; ?></div>
        <div style="font-size:.64rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60)">Applications</div>
      </div>
    </div>

    <!-- Action buttons -->
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a href="parishes.php?action=edit&id=<?php echo $p['id']; ?>" class="act-btn act-navy">[icon:edit] Edit</a>
      <a href="users.php?parish_id=<?php echo $p['id']; ?>" class="act-btn act-gold">Assign Staff</a>
      <form method="POST" style="display:inline" onsubmit="return confirm('Toggle status for this parish?')">
        <input type="hidden" name="_action" value="toggle_parish">
        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
        <button type="submit" class="act-btn <?php echo $active ? 'act-wine' : 'act-green'; ?>">
          <?php echo $active ? 'Deactivate' : 'Activate'; ?>
        </button>
      </form>
      <button onclick="confirmDeleteParish(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')" class="act-btn act-wine" title="Delete Parish">[icon:close] Delete</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Delete Parish Confirmation Modal -->
<div class="modal-wrap" id="deleteParishModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div class="modal" style="max-width:420px;background:#fff;border-radius:14px;padding:32px 28px;box-shadow:0 8px 32px rgba(0,0,0,.18)">
    <h2 style="color:var(--wine);margin-bottom:8px">Delete Parish</h2>
    <p style="color:var(--ink-60);margin-bottom:20px">Are you sure you want to permanently delete <strong id="deleteParishName"></strong>? This will also remove all associated services and applications. This cannot be undone.</p>
    <form method="POST" id="deleteParishForm">
      <input type="hidden" name="_action" value="delete_parish">
      <input type="hidden" name="id" id="deleteParishId" value="">
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" onclick="closeDeleteParishModal()" class="btn-sm btn-outline">Cancel</button>
        <button type="submit" class="btn-sm btn-wine">Delete Parish</button>
      </div>
    </form>
  </div>
</div>

<script>
function previewLogo(inp) {
    if (!inp.files || !inp.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const box = document.getElementById('logoPreview');
        box.style.backgroundImage = `url('${e.target.result}')`;
        box.style.background = `#fff url('${e.target.result}') center/cover no-repeat`;
        box.innerHTML = '';
    };
    reader.readAsDataURL(inp.files[0]);
}
function confirmDeleteParish(id, name) {
    document.getElementById('deleteParishId').value = id;
    document.getElementById('deleteParishName').textContent = name;
    const modal = document.getElementById('deleteParishModal');
    modal.style.display = 'flex';
}
function closeDeleteParishModal() {
    document.getElementById('deleteParishModal').style.display = 'none';
}
document.getElementById('deleteParishModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteParishModal();
});
</script>

<?php include 'includes/layout_footer.php'; ?>