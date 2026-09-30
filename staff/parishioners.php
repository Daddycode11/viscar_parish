<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Parishioners — List, Search, View, Edit
 * Features: paginated list, search, view profile modal and application history
 */

$memberScope=parish_member_sql($scopeParish);
$page_id    = 'parishioners';
$page_title = 'Parishioners';
$page_sub   = 'Member Management';
include 'includes/layout.php';

// ── AJAX HANDLERS ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Get parishioner detail
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT u.id,u.name,u.email,u.phone,u.status,u.parish_id,u.created_at, p.name AS parish_name FROM (SELECT * FROM users WHERE {$memberScope}) u LEFT JOIN parishes p ON u.parish_id = p.id WHERE u.id=? AND u.role='parishioner'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        if (!$p) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

        // Application history
        $stmt2 = $conn->prepare("SELECT a.id, a.service_id, a.schedule, a.status, a.payment_status, a.created_at, s.name AS service_name
                                 FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a LEFT JOIN services s ON a.service_id = s.id
                                 WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 10");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $p['applications'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        // Remove password from response
        unset($p['password']);

        echo json_encode(['success'=>true,'data'=>$p]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

// ── FILTERS ───────────────────────────────────
$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';
$parish_filter = 0;
$page_num      = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;

$where = ["u.role = 'parishioner'"];
$params = [];
$types  = '';

if ($status_filter && in_array($status_filter, ['active','suspended'])) {
    $where[] = "u.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($parish_filter) {
    $where[] = "u.parish_id = ?";
    $params[] = $parish_filter;
    $types .= 'i';
}
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Count
$csql = "SELECT COUNT(*) as t FROM (SELECT * FROM users WHERE {$memberScope}) u $where_sql";
$stmt = $conn->prepare($csql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['t'];
$total_pages = max(1, ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

// Fetch
$sql = "SELECT u.id, u.name, u.email, u.phone, u.status, u.parish_id, u.created_at, p.name AS parish_name,
               (SELECT COUNT(*) FROM (SELECT * FROM applications WHERE parish_id = {$scopeParish}) applications WHERE user_id = u.id) AS app_count
        FROM (SELECT * FROM users WHERE {$memberScope}) u
        LEFT JOIN parishes p ON u.parish_id = p.id
        $where_sql
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?";
$btypes = $types . 'ii';
$bparams = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
if ($bparams) $stmt->bind_param($btypes, ...$bparams);
$stmt->execute();
$parishioners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Total counts
$cnt_all       = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM users WHERE {$memberScope}) users WHERE role='parishioner'")->fetch_assoc()['t'];
$cnt_active    = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM users WHERE {$memberScope}) users WHERE role='parishioner' AND status='active'")->fetch_assoc()['t'];
$cnt_suspended = $conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM users WHERE {$memberScope}) users WHERE role='parishioner' AND status='suspended'")->fetch_assoc()['t'];

// Parishes for filter

?>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- View/Edit Modal -->
<div class="modal-wrap" id="viewModal" style="align-items:flex-start;padding:40px 20px;overflow-y:auto">
  <div class="modal" style="max-width:700px;width:100%">
    <div id="viewContent" style="min-height:200px;display:block;overflow-wrap:anywhere">
      <div class="spinner" style="border-top-color:var(--navy)"></div>
    </div>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Member Management</div>
    <h1 class="sec-title">Parishioners</h1>
    <p class="sec-sub">View registered parishioners in your assigned parish.</p>
  </div>
</div>

<!-- STATUS CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <a href="parishioners.php" style="text-decoration:none">
    <div class="stat-card stat-navy" style="padding:16px 20px<?php echo !$status_filter?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:user]</div>
      <div class="stat-label">Total</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_all; ?></div>
    </div>
  </a>
  <a href="parishioners.php?status=active" style="text-decoration:none">
    <div class="stat-card stat-green" style="padding:16px 20px<?php echo $status_filter==='active'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:check]</div>
      <div class="stat-label">Active</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_active; ?></div>
    </div>
  </a>
  <a href="parishioners.php?status=suspended" style="text-decoration:none">
    <div class="stat-card stat-wine" style="padding:16px 20px<?php echo $status_filter==='suspended'?';box-shadow:0 0 0 3px var(--gold)':''; ?>">
      <div class="stat-icon">[icon:ban]</div>
      <div class="stat-label">Suspended</div>
      <div class="stat-value" style="font-size:1.6rem"><?php echo $cnt_suspended; ?></div>
    </div>
  </a>
</div>

<!-- SEARCH + FILTERS -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="parishioners.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:7px 14px;flex:1;min-width:180px">
        <span style="color:var(--ink-30)">[icon:search]</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email, phone..."
          style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.82rem;color:var(--ink);width:100%">
      </div>
      <select name="status" onchange="this.form.submit()" style="font-size:.8rem;padding:7px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer">
        <option value="">All Status</option>
        <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
        <option value="suspended" <?php echo $status_filter==='suspended'?'selected':''; ?>>Suspended</option>
      </select>

      <?php if ($status_filter || $parish_filter || $search): ?>
      <a href="parishioners.php" style="font-size:.75rem;color:var(--wine);padding:5px 12px;border:1px solid var(--wine-dim);border-radius:20px;white-space:nowrap">[icon:close] Clear</a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--ink-30);margin-left:auto"><?php echo $total; ?> results</span>
    </form>
  </div>
</div>

<!-- PARISHIONERS TABLE -->
<div class="card">
  <div class="card-head">
    <h3>Parishioners</h3>
    <span class="card-tag"><?php echo $total; ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Email</th><th>Phone</th><th>Parish</th><th>Applications</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($parishioners)): ?>
          <tr><td colspan="8" style="text-align:center;padding:50px;color:var(--ink-30);font-style:italic">No parishioners found.</td></tr>
          <?php endif; ?>
          <?php foreach ($parishioners as $p):
            $spill = $p['status']==='active' ? 'pill-green' : 'pill-wine';
          ?>
          <tr id="par-row-<?php echo $p['id']; ?>">
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:32px;height:32px;border-radius:50%;background:var(--navy);display:grid;place-items:center;font-family:var(--fh);font-size:.8rem;color:var(--gold-lt);flex-shrink:0"><?php echo strtoupper(substr($p['name'],0,1)); ?></div>
                <span style="font-weight:500"><?php echo htmlspecialchars($p['name']); ?></span>
              </div>
            </td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($p['email']); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($p['phone'] ?: '—'); ?></td>
            <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($p['parish_name'] ?? '—'); ?></td>
            <td style="text-align:center;font-weight:600;color:var(--navy)"><?php echo $p['app_count']; ?></td>
            <td><span class="pill <?php echo $spill; ?>" id="pstatus-<?php echo $p['id']; ?>"><?php echo ucfirst($p['status']); ?></span></td>
            <td style="font-size:.73rem;color:var(--ink-30)"><?php echo date('M j, Y', strtotime($p['created_at'])); ?></td>
            <td>
              <div style="display:flex;gap:4px">
                <button onclick="viewParishioner(<?php echo $p['id']; ?>)" class="act-btn act-navy" title="View">[icon:eye]</button>

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
      $base_q = http_build_query(array_filter(['status'=>$status_filter,'parish'=>$parish_filter,'q'=>$search]));
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
function viewParishioner(id) {
    document.getElementById('viewContent').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px"><div class="spinner" style="border-top-color:var(--navy)"></div></div>';
    openModal('viewModal');
    fetch('parishioners.php?ajax=get&id=' + id)
        .then(r => r.json()).then(data => {
            if (!data.success) { document.getElementById('viewContent').innerHTML = '<p style="color:var(--wine);padding:20px">' + data.message + '</p>'; return; }
            const p = data.data;
            const safe=value=>String(value??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            for(const key of ['name','email','phone','parish_name'])p[key]=safe(p[key]);
            p.applications.forEach(a=>{a.service_name=safe(a.service_name);});
            const sc = p.status === 'active' ? 'green' : 'wine';
            const apps = (p.applications || []).map(a => {
                const asc = {approved:'green',rejected:'wine',pending:'amber'}[a.status]||'amber';
                const psc = a.payment_status==='paid'?'green':(a.payment_status==='refunded'?'wine':'amber');
                return `<tr>
                    <td style="font-size:.72rem;color:var(--ink-30)">#${a.id}</td>
                    <td>${a.service_name || 'Service #'+a.service_id}</td>
                    <td style="font-size:.75rem;color:var(--ink-60)">${a.schedule ? new Date(a.schedule).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</td>
                    <td><span class="pill pill-${asc}">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span></td>
                    <td><span class="pill pill-${psc}">${a.payment_status.charAt(0).toUpperCase()+a.payment_status.slice(1)}</span></td>
                </tr>`;
            }).join('') || '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--ink-30);font-style:italic">No applications yet.</td></tr>';

            document.getElementById('viewContent').innerHTML = `
              <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
                <div style="width:56px;height:56px;border-radius:50%;background:var(--navy);display:grid;place-items:center;font-family:var(--fh);font-size:1.4rem;color:var(--gold-lt);flex-shrink:0">${p.name.charAt(0).toUpperCase()}</div>
                <div style="flex:1">
                  <h2 style="font-family:var(--fh);font-size:1.3rem;margin-bottom:2px">${p.name}</h2>
                  <p style="font-size:.8rem;color:var(--ink-60);margin:0">${p.email} &middot; ${p.parish_name || 'No parish'}</p>
                </div>
                <span class="pill pill-${sc}" style="font-size:.78rem;padding:5px 14px">${p.status.charAt(0).toUpperCase()+p.status.slice(1)}</span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:20px">
                ${[['Phone',p.phone||'—'],['Parish',p.parish_name||'—'],['Joined',new Date(p.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})]].map(([l,v])=>`
                  <div style="background:#F8F6F2;border-radius:8px;padding:10px 12px">
                    <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:3px">${l}</div>
                    <div style="font-size:.83rem;font-weight:500">${v}</div>
                  </div>`).join('')}
              </div>
              <div style="margin-bottom:14px">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-60);margin-bottom:10px;font-weight:500">Application History (${p.applications.length})</div>
                <div class="tbl-wrap"><table>
                  <thead><tr><th>#</th><th>Service</th><th>Schedule</th><th>Status</th><th>Payment</th></tr></thead>
                  <tbody>${apps}</tbody>
                </table></div>
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--ink-10)">
                <button onclick="closeModal('viewModal')" class="btn-sm btn-outline">Close</button>
              </div>`;
        });
}

</script>

<?php include 'includes/layout_footer.php'; ?>
