<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

require_once '../includes/db.php';
// require_once '../includes/auth.php';
// checkRole('admin');
$user = currentUser();

$action = $_GET['action'] ?? 'list';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editUser = $edit_id ? $conn->execute_query('SELECT id,name,email,role,parish_id FROM users WHERE id=?', [$edit_id])->fetch_assoc() : null;
if ($action === 'edit' && !$editUser) { fail_request('User not found.', 404); }
$flash  = '';
$role_filter   = $_GET['role']   ?? '';
$status_filter = $_GET['status'] ?? '';
$parish_filter = $_GET['parish_id'] ?? '';

/* ── HANDLE POST ACTIONS ──────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['_action'] ?? '';

  if ($act === 'add_staff') {
    $name      = trim($_POST['name']);
    $email     = trim($_POST['email']);
    $role      = $_POST['role'];
    $parish_id = (int)$_POST['parish_id'];
    $password  = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param('s', $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
      $flash = 'error:A user with that email already exists.';
      $action = 'add';
    } else {
      $stmt = $conn->prepare("INSERT INTO users (name,email,password,role,parish_id,status,created_at) VALUES (?,?,?,?,?,'pending',NOW())");
      $stmt->bind_param('ssssi', $name,$email,$password,$role,$parish_id);
      $stmt->execute();
      $flash = 'success:Staff account created and is pending approval.';
      $action = 'list';
    }
  }
  if ($act === 'approve_user') {
    $uid = (int)$_POST['id'];
    $conn->query("UPDATE users SET status='active' WHERE id=$uid AND status='pending'");
    $flash = 'success:User approved and activated.';
  }
  if ($act === 'reject_user') {
    $uid = (int)$_POST['id'];
    $conn->query("DELETE FROM users WHERE id=$uid AND status='pending'");
    $flash = 'success:Pending user rejected and removed.';
  }

  if ($act === 'edit_user') {
    $uid = (int)$_POST['id'];
    $name      = trim($_POST['name']);
    $email     = trim($_POST['email']);
    $role      = $_POST['role'];
    $parish_id = (int)$_POST['parish_id'];
    $check = $conn->prepare("SELECT id FROM users WHERE email=? AND id!=?");
    $check->bind_param('si', $email, $uid);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
      $flash = 'error:Another user with that email already exists.';
      $action = 'list';
    } else {
      $update_sql = "UPDATE users SET name=?, email=?, role=?, parish_id=? WHERE id=?";
      $stmt = $conn->prepare($update_sql);
      $stmt->bind_param('sssii', $name, $email, $role, $parish_id, $uid);
      $stmt->execute();
      $flash = 'success:User updated successfully.';
      $action = 'list';
    }
  }

  if ($act === 'toggle_user') {
    $uid = (int)$_POST['id'];
    $cur = $conn->query("SELECT status FROM users WHERE id=$uid")->fetch_assoc()['status'];
    $new = ($cur==='active') ? 'suspended' : 'active';
    $conn->query("UPDATE users SET status='$new' WHERE id=$uid");
    $flash = 'success:User status updated.';
  }

  if ($act === 'delete_user') {
    $uid = (int)$_POST['id'];
    $conn->query("DELETE FROM users WHERE id=$uid AND role != 'admin'");
    $flash = 'success:User removed.';
  }
}


// Get parishes
$parishes_list = [];
$res = $conn->query("SELECT id, name FROM parishes ORDER BY name");
while($row = $res->fetch_assoc()) $parishes_list[] = $row;

// Get users
$sql = "SELECT u.*, p.name as parish FROM users u LEFT JOIN parishes p ON u.parish_id = p.id WHERE 1";
if($role_filter)   $sql .= " AND u.role='".$conn->real_escape_string($role_filter)."'";
if($status_filter) $sql .= " AND u.status='".$conn->real_escape_string($status_filter)."'";
if($parish_filter) $sql .= " AND u.parish_id=".(int)$parish_filter;
$sql .= " ORDER BY u.created_at DESC";
$filtered = [];
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) $filtered[] = $row;

$page_id    = 'users';
$page_title = 'User Management';
$page_sub   = 'Users';
include 'includes/layout.php';
?>

<?php if($flash): [$ftype,$fmsg] = explode(':',$flash,2); ?>
<div class="notice notice-<?php echo $ftype==='success'?'green':'amber'; ?> flash-msg" style="margin-bottom:20px;transition:opacity .5s">
  <span><?php echo $ftype==='success'?'[icon:check]':'[icon:alert]'; ?></span>
  <span><?php echo htmlspecialchars($fmsg); ?></span>
</div>
<?php endif; ?>

<?php if($action === 'add' || ($action === 'edit' && $edit_id)): ?>
<!-- ═══ ADD/EDIT STAFF FORM ════════════════ -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">User Management</div>
    <h1 class="sec-title">Create Staff Account</h1>
    <p class="sec-sub">Add a Parish Secretary or Bookkeeper to a specific parish.</p>
  </div>
  <a href="users.php" class="btn-sm btn-outline">← Back to Users</a>
</div>

<div class="card" style="max-width:620px">
  <div class="card-head"><h3>Staff Account Details</h3></div>
  <div class="card-body">
    <form method="POST" action="users.php<?php echo $action==='edit' ? '?action=edit&id='.$edit_id : ''; ?>">
      <input type="hidden" name="_action" value="<?php echo $action==='edit' ? 'edit_user' : 'add_staff'; ?>">
      <?php if($action==='edit'): ?>
        <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
      <?php endif; ?>
      <div class="form-grid">
        <div class="form-group">
          <label>Full Name *</label>
          <input type="text" name="name" required placeholder="First and Last Name" value="<?php echo $action==='edit' ? htmlspecialchars($editUser['name']) : ''; ?>">
        </div>
        <div class="form-group">
          <label>Email Address *</label>
          <input type="email" name="email" required placeholder="staff@vicariate.ph" value="<?php echo $action==='edit' ? htmlspecialchars($editUser['email']) : ''; ?>">
        </div>
        <div class="form-group">
          <label>Role *</label>
          <select name="role" required>
            <option value="">-- Select Role --</option>
            <option value="secretary" <?php echo $action==='edit' && $editUser['role']==='secretary'?'selected':''; ?>>Parish Secretary</option>
            <option value="bookkeeper" <?php echo $action==='edit' && $editUser['role']==='bookkeeper'?'selected':''; ?>>Parish Bookkeeper</option>
          </select>
        </div>
        <div class="form-group">
          <label>Assign to Parish *</label>
          <select name="parish_id" required>
            <option value="">-- Select Parish --</option>
            <?php foreach($parishes_list as $pl): ?>
            <option value="<?php echo $pl['id']; ?>"
              <?php echo ($action==='edit' && $editUser['parish_id']==$pl['id']) || ($action !== 'edit' && $parish_filter == $pl['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($pl['name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if($action!=='edit'): ?>
        <div class="form-group">
          <label>Temporary Password *</label>
          <input type="password" name="password" required placeholder="Min 8 characters">
        </div>
        <div class="form-group">
          <label>Confirm Password *</label>
          <input type="password" name="password_confirm" required placeholder="Re-enter password">
        </div>
        <?php endif; ?>
      </div>
      <?php if($action!=='edit'): ?>
      <div class="notice notice-navy" style="margin-bottom:16px">
        <span>ℹ</span>
        <span>The staff member will receive an email with their login credentials and will be required to change their password on first login.</span>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-sm btn-navy" style="padding:10px 28px"><?php echo $action==='edit' ? 'Update Account' : 'Create Account'; ?></button>
        <a href="users.php" class="btn-sm btn-outline" style="padding:10px 20px">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══ USER LIST ═══════════════════════════ -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">User Management</div>
    <h1 class="sec-title">Users</h1>
    <p class="sec-sub">Manage <?php echo count($filtered); ?> accounts — parishioners and staff across all parishes.</p>
  </div>
  <a href="users.php?action=add" class="btn-sm btn-navy">+ Add Staff Account</a>
</div>

<!-- Stats row -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <?php
  $r_counts = array_count_values(array_column($filtered, 'role'));
  $s_counts = array_count_values(array_column($filtered, 'status'));
  ?>
  <div class="stat-card stat-blue">
    <div class="stat-icon">[icon:users]</div>
    <div class="stat-label">Total Users</div>
    <div class="stat-value"><?php echo count($filtered); ?></div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon">[icon:file]</div>
    <div class="stat-label">Secretaries</div>
    <div class="stat-value"><?php echo $r_counts['secretary'] ?? 0; ?></div>
  </div>
  <div class="stat-card stat-amber">
    <div class="stat-icon">[icon:wallet]</div>
    <div class="stat-label">Bookkeepers</div>
    <div class="stat-value"><?php echo $r_counts['bookkeeper'] ?? 0; ?></div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:ban]</div>
    <div class="stat-label">Suspended</div>
    <div class="stat-value"><?php echo $s_counts['suspended'] ?? 0; ?></div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="users.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <select name="role" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Roles</option>
        <option value="parishioner" <?php echo $role_filter==='parishioner'?'selected':''; ?>>Parishioner</option>
        <option value="secretary"   <?php echo $role_filter==='secretary'?'selected':''; ?>>Secretary</option>
        <option value="bookkeeper"  <?php echo $role_filter==='bookkeeper'?'selected':''; ?>>Bookkeeper</option>
        <option value="admin"       <?php echo $role_filter==='admin'?'selected':''; ?>>Admin</option>
      </select>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <option value="active"    <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
        <option value="suspended" <?php echo $status_filter==='suspended'?'selected':''; ?>>Suspended</option>
      </select>
      <select name="parish_id" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Parishes</option>
        <?php foreach($parishes_list as $pl): ?>
        <option value="<?php echo $pl['id']; ?>" <?php echo $parish_filter==$pl['id']?'selected':''; ?>>
          <?php echo htmlspecialchars($pl['name']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php if($role_filter || $status_filter || $parish_filter): ?>
      <a href="users.php" style="font-size:.75rem;color:var(--wine);padding:4px 10px;border:1px solid var(--wine-dim);border-radius:20px">[icon:close] Clear Filters</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo count($filtered); ?> results</span>
    </form>
  </div>
</div>

<!-- User Table -->
<div class="card">
  <div class="card-head">
    <h3>All Users</h3>
    <span class="card-tag"><?php echo count($filtered); ?> shown</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Parish</th>
            <th>Status</th>
            <th>Registered</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($filtered)): ?>
          <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No users match the current filter.</td></tr>
          <?php endif; ?>
          <?php foreach($filtered as $u):
            $sPill = $u['status']==='active' ? 'pill-green' : 'pill-wine';
            $rPill = match($u['role']) {
              'admin'      => 'pill-navy',
              'secretary'  => 'pill-gold',
              'bookkeeper' => 'pill-amber',
              default      => 'pill-green'
            };
          ?>
          <tr>
            <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $u['id']; ?></td>
            <td style="font-weight:500"><?php echo htmlspecialchars($u['name']); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($u['email']); ?></td>
            <td><span class="pill <?php echo $rPill; ?>"><?php echo ucfirst($u['role']); ?></span></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($u['parish'] ?? ''); ?></td>
            <td><span class="pill <?php echo $sPill; ?>"><?php echo ucfirst($u['status']); ?></span></td>
            <td style="font-size:.75rem;color:var(--ink-30)"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
            <td>
              <a href="users.php?action=edit&id=<?php echo $u['id']; ?>" class="act-btn act-blue" style="margin-right:4px">Edit</a>
              <?php if($u['status']==='pending'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Approve this pending user?')">
                  <input type="hidden" name="_action" value="approve_user">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="act-btn act-green">Approve</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Reject and remove this pending user?')">
                  <input type="hidden" name="_action" value="reject_user">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="act-btn act-wine">Reject</button>
                </form>
              <?php else: ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Toggle status for this user?')">
                  <input type="hidden" name="_action" value="toggle_user">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="act-btn <?php echo $u['status']==='active' ? 'act-wine' : 'act-green'; ?>">
                    <?php echo $u['status']==='active' ? 'Suspend' : 'Activate'; ?>
                  </button>
                </form>
                <?php if($u['role'] !== 'admin'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this user permanently?')">
                  <input type="hidden" name="_action" value="delete_user">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="act-btn" style="background:rgba(122,42,58,.05);color:var(--wine);border-color:rgba(122,42,58,.1);font-size:.68rem">[icon:close]</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<?php include 'includes/layout_footer.php'; ?>