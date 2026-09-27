<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Dashboard — Dynamic version with AJAX refresh and live stats
 */

require_once __DIR__.'/../includes/analytics.php';
require_once APP_ROOT.'/includes/dashboard_filter.php';
$adminApplicationFilter=dashboard_date_sql('a.created_at');
$totals=dashboard_totals();
if(($_GET['ajax']??'')==='stats'){header('Content-Type: application/json');echo json_encode($totals);exit;}
extract($totals);
$page_id    = 'overview';
$page_title = 'Dashboard';
$page_sub   = 'Overview';
include 'includes/layout.php';
?>

<style>
.stat-value { transition: color .3s; }
.stat-value.updating { color: var(--gold) !important; }
.live-dot { display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }
</style>

<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Central Dashboard</div>
    <h1 class="sec-title">Overview</h1>
    <p class="sec-sub">Welcome back, <?php echo htmlspecialchars($user['name']); ?> — real-time overview of all parishes.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-size:.72rem;color:var(--ink-30);display:flex;align-items:center;gap:5px">
      <span class="live-dot"></span> Live · Updated <span id="lastUpdated"><?php echo date('g:i A'); ?></span>
    </span>
    <button onclick="refreshStats()" class="btn-sm btn-outline" id="refreshBtn">[icon:refresh] Refresh</button>
    <a href="parishes.php?action=add" class="btn-sm btn-navy">+ Add Parish</a>
  </div>
</div>

<?php render_dashboard_filter(); ?>
<!-- STAT CARDS -->
<div class="stats-grid">
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:church]</div>
    <div class="stat-label">Total Parishes</div>
    <div class="stat-value" id="stat-parishes"><?php echo $total_parishes; ?></div>
    <div class="stat-delta">Active vicariate territories</div>
  </div>
  <div class="stat-card stat-blue">
    <div class="stat-icon">[icon:user]</div>
    <div class="stat-label">Total Users</div>
    <div class="stat-value" id="stat-users"><?php echo number_format($total_users); ?></div>
    <div class="stat-delta">Registered parishioners &amp; staff</div>
  </div>
  <div class="stat-card stat-amber">
    <div class="stat-icon">[icon:clipboard]</div>
    <div class="stat-label">Applications</div>
    <div class="stat-value" id="stat-apps"><?php echo number_format($total_applications); ?></div>
    <div class="stat-delta"><span id="stat-pending-sub"><?php echo $pending_apps; ?></span> pending review</div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon">₱</div>
    <div class="stat-label">Verified Revenue</div>
    <div class="stat-value" id="stat-revenue">₱<?php echo number_format($total_revenue); ?></div>
    <div class="stat-delta">Completed payments</div>
  </div>
  <div class="stat-card stat-green">
    <div class="stat-icon">[icon:sun]</div>
    <div class="stat-label">Today's Revenue</div>
    <div class="stat-value" id="stat-today">₱<?php echo number_format($today_revenue); ?></div>
    <div class="stat-delta"><?php echo date('F j, Y'); ?></div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:clock]</div>
    <div class="stat-label">Pending Apps</div>
    <div class="stat-value" id="stat-pending"><?php echo $pending_apps; ?></div>
    <div class="stat-delta down">Awaiting action</div>
  </div>
</div>

<?php if($pending_apps > 0): ?>
<div class="notice notice-amber" id="pendingNotice">
  <span>[icon:alert]</span>
  <span><strong id="pendingCount"><?php echo $pending_apps; ?> applications</strong> are pending review across parishes. Coordinate with parish secretaries to process them promptly.</span>
</div>
<?php endif; ?>

<div class="grid-2-1">
  <!-- Recent Applications -->
  <div class="card">
    <div class="card-head">
      <h3>Recent Applications</h3>
      <a href="applications.php" class="btn-sm btn-outline">View All</a>
    </div>
    <div class="card-body" style="padding:0">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>#</th><th>Parishioner</th><th>Service</th><th>Parish</th><th>Status</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php
            $demo_apps=$conn->query("SELECT a.id,u.name,s.name service,p.name parish,a.status FROM applications a JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id JOIN parishes p ON p.id=a.parish_id WHERE {$adminApplicationFilter} ORDER BY a.id DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
            $pillMap = ['approved'=>'pill-green','rejected'=>'pill-wine','pending'=>'pill-amber'];
            foreach($demo_apps as $row):
              $pill = $pillMap[$row['status']] ?? 'pill-amber';
            ?>
            <tr>
              <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $row['id']; ?></td>
              <td style="font-weight:500"><?php echo htmlspecialchars($row['name']); ?></td>
              <td><?php echo htmlspecialchars($row['service']); ?></td>
              <td style="font-size:.78rem;color:var(--ink-60)"><?php echo htmlspecialchars($row['parish']); ?></td>
              <td><span class="pill <?php echo $pill; ?>"><?php echo ucfirst($row['status']); ?></span></td>
              <td><a href="applications.php?view=<?php echo $row['id']; ?>" class="act-btn act-navy">View</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Right sidebar -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Quick Actions -->
    <div class="card">
      <div class="card-head"><h3>Quick Actions</h3></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
          <?php
          $qa = [
            ['[icon:church]','parishes.php','Add Parish'],
            ['[icon:user]','users.php?action=add','Add Staff'],
            ['[icon:announcement]','announcements.php','Announce'],
            ['[icon:chart]','reports.php','Reports'],
            ['[icon:save]','backup.php','Backup'],
            ['₱','finance.php','Finance'],
          ];
          foreach($qa as [$icon,$href,$label]): ?>
          <a href="<?php echo $href; ?>" style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:13px 8px;border-radius:10px;background:#F0EDE8;border:1px solid var(--ink-10);font-size:.7rem;font-weight:500;color:var(--ink-60);text-align:center;transition:all .2s" onmouseover="this.style.background='var(--navy)';this.style.color='white'" onmouseout="this.style.background='#F0EDE8';this.style.color='var(--ink-60)'">
            <span style="font-size:1.1rem"><?php echo $icon; ?></span>
            <?php echo $label; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Live Activity Feed -->
    <div class="card">
      <div class="card-head">
        <h3>Live Activity</h3>
        <span style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--green)"><span class="live-dot"></span>Live</span>
      </div>
      <div class="card-body" style="padding:0" id="activityFeed">
        <?php
        $activity=[]; foreach($conn->query('SELECT action,entity_type,entity_id,created_at FROM audit_trail ORDER BY id DESC LIMIT 6') as $entry)$activity[]=['navy',$entry['action'].' '.$entry['entity_type'].' #'.$entry['entity_id'],$entry['created_at']];
        foreach($activity as [$dot,$msg,$time]): ?>
        <div style="display:flex;gap:12px;padding:11px 20px;border-bottom:1px solid var(--ink-10)">
          <div style="width:7px;height:7px;border-radius:50%;background:var(--<?php echo $dot; ?>);flex-shrink:0;margin-top:5px"></div>
          <div style="font-size:.79rem;color:var(--ink);flex:1;line-height:1.4"><?php echo htmlspecialchars($msg); ?></div>
          <div style="font-size:.68rem;color:var(--ink-30);white-space:nowrap"><?php echo $time; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<!-- Parish Performance Overview -->
<div class="card" style="margin-top:4px">
  <div class="card-head">
    <h3>Parish Performance Overview</h3>
    <a href="analytics.php" class="card-tag" style="color:var(--gold)">Full Analytics →</a>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Parish</th><th>Applications</th><th>Volume</th><th>Approval Rate</th><th>Revenue</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php
          $perf=[];foreach(parish_comparison() as $pr)$perf[]=[$pr['parish'],(int)$pr['apps'],(int)$pr['approved'],($pr['apps']?round($pr['approved']/$pr['apps']*100):0).'%',(float)$pr['revenue'],$pr['status']];
          $max_app = max(1,...array_column($perf, 1));
          foreach($perf as $pr):
            $pct = round(($pr[1]/$max_app)*100);
          ?>
          <tr>
            <td style="font-weight:500"><?php echo htmlspecialchars($pr[0]); ?></td>
            <td style="text-align:center;font-weight:600;color:var(--navy)"><?php echo $pr[1]; ?></td>
            <td style="width:120px">
              <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%;background:var(--navy)"></div></div>
            </td>
            <td style="text-align:center">
              <span class="pill pill-green"><?php echo $pr[3]; ?></span>
            </td>
            <td style="font-weight:600;color:var(--green)">₱<?php echo number_format($pr[4]); ?></td>
            <td><span class="pill <?php echo $pr[5]==='active'?'pill-green':'pill-wine'; ?>"><?php echo ucfirst($pr[5]); ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// ── AUTO-REFRESH STATS ────────────────────────
let refreshInterval;

function refreshStats() {
    const btn = document.getElementById('refreshBtn');
    btn.textContent = '[icon:refresh] Refreshing…';
    btn.disabled = true;

    // Flash all stat values
    document.querySelectorAll('.stat-value').forEach(el => el.classList.add('updating'));

    fetch('dashboard.php?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),ajax:'stats'}))
        .then(r => r.json())
        .then(data => {
            document.getElementById('stat-parishes').textContent = data.total_parishes;
            document.getElementById('stat-users').textContent    = data.total_users.toLocaleString();
            document.getElementById('stat-apps').textContent     = data.total_applications.toLocaleString();
            document.getElementById('stat-revenue').textContent  = '₱' + data.total_revenue.toLocaleString();
            document.getElementById('stat-today').textContent    = '₱' + data.today_revenue.toLocaleString();
            document.getElementById('stat-pending').textContent  = data.pending_apps;
            document.getElementById('stat-pending-sub').textContent = data.pending_apps;
            document.getElementById('lastUpdated').textContent   = data.last_updated;

            // Update pending notice
            const notice = document.getElementById('pendingNotice');
            const pCount = document.getElementById('pendingCount');
            if (notice && pCount) {
                pCount.textContent = data.pending_apps + ' applications';
                notice.style.display = data.pending_apps > 0 ? 'flex' : 'none';
            }

            setTimeout(() => document.querySelectorAll('.stat-value').forEach(el => el.classList.remove('updating')), 600);
        })
        .catch(() => {
            // Silent fail — keep existing values
        })
        .finally(() => {
            btn.textContent = '[icon:refresh] Refresh';
            btn.disabled = false;
        });
}

// Auto-refresh every 60 seconds
refreshInterval = setInterval(refreshStats, 60000);

// Cleanup on page leave
window.addEventListener('beforeunload', () => clearInterval(refreshInterval));
</script>

<?php include 'includes/layout_footer.php'; ?>