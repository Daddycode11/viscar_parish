<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Staff Financial Reports — Bookkeeper finance overview
 * Features: period filter, revenue summary, service breakdown, method breakdown, daily trend, recent transactions
 */

$page_id    = 'finance';
$page_title = 'Financial Overview';
$page_sub   = 'Revenue & Analytics';
include 'includes/layout.php';

// ── PERIOD FILTER ─────────────────────────────
$period = $_GET['period'] ?? 'month';
$custom_from = $_GET['from'] ?? '';
$custom_to   = $_GET['to']   ?? '';

switch ($period) {
    case 'today':
        $date_from = date('Y-m-d 00:00:00');
        $date_to   = date('Y-m-d 23:59:59');
        $period_label = 'Today (' . date('M j, Y') . ')';
        break;
    case 'week':
        $date_from = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $date_to   = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        $period_label = 'This Week (' . date('M j', strtotime($date_from)) . ' - ' . date('M j', strtotime($date_to)) . ')';
        break;
    case 'year':
        $date_from = date('Y-01-01 00:00:00');
        $date_to   = date('Y-12-31 23:59:59');
        $period_label = 'Year ' . date('Y');
        break;
    case 'custom':
        $date_from = $custom_from ? $custom_from . ' 00:00:00' : date('Y-m-01 00:00:00');
        $date_to   = $custom_to ? $custom_to . ' 23:59:59' : date('Y-m-d 23:59:59');
        $period_label = 'Custom: ' . date('M j, Y', strtotime($date_from)) . ' - ' . date('M j, Y', strtotime($date_to));
        break;
    default: // month
        $date_from = date('Y-m-01 00:00:00');
        $date_to   = date('Y-m-t 23:59:59');
        $period_label = date('F Y');
        break;
}

// ── REVENUE SUMMARY ───────────────────────────
$rev_completed = 0; $rev_pending = 0; $rev_refunded = 0; $pay_count = 0;

$stmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='pending' AND created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$rev_pending = (float)$stmt->get_result()->fetch_assoc()['t'];

$stmt = $conn->prepare("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$pay_count = (int)$stmt->get_result()->fetch_assoc()['t'];

require_once APP_ROOT.'/includes/financial_totals.php';
try { $financial=financial_totals(substr($date_from,0,10),substr($date_to,0,10),(int)$user['parish_id']); } catch(DomainException $e){fail_request($e->getMessage(),422);}
$rev_completed=$financial['verified_revenue'];$rev_refunded=$financial['refunds'];$net_revenue=$financial['net_revenue'];

// ── REVENUE BY SERVICE ────────────────────────
$service_rev = [];
$stmt = $conn->prepare("SELECT a.service_id, s.name AS service_name,
                                COUNT(DISTINCT p.id) as pay_count,
                                IFNULL(SUM(CASE WHEN p.status IN ('completed','refunded') THEN p.amount ELSE 0 END),0) as revenue
                         FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
                         JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
                         LEFT JOIN services s ON a.service_id = s.id
                         WHERE p.status IN ('completed','refunded') AND COALESCE(p.verified_at,p.paid_at) BETWEEN ? AND ?
                         GROUP BY a.service_id, s.name
                         ORDER BY revenue DESC");
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$service_rev = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$max_srv_rev = !empty($service_rev) ? max(1,...array_column($service_rev, 'revenue')) : 1;

// ── REVENUE BY METHOD ─────────────────────────
$method_rev = [];
$stmt = $conn->prepare("SELECT payment_method, COUNT(*) as cnt, IFNULL(SUM(amount),0) as total
                         FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments
                         WHERE status IN ('completed','refunded') AND COALESCE(verified_at,paid_at) BETWEEN ? AND ?
                         GROUP BY payment_method ORDER BY total DESC");
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$method_rev = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$max_method = !empty($method_rev) ? max(1,...array_column($method_rev, 'total')) : 1;

// ── DAILY TREND (last 14 days or period) ──────
$daily_rev = [];
$stmt = $conn->prepare("SELECT DATE(COALESCE(verified_at,paid_at)) as day, IFNULL(SUM(amount),0) as total
                         FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments
                         WHERE status IN ('completed','refunded') AND COALESCE(verified_at,paid_at) BETWEEN ? AND ?
                         GROUP BY DATE(COALESCE(verified_at,paid_at)) ORDER BY day ASC");
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$daily_rev = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$max_daily = !empty($daily_rev) ? max(1,...array_column($daily_rev, 'total')) : 1;

// ── RECENT TRANSACTIONS ───────────────────────
$recent = [];
$stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_method, p.status, p.paid_at, p.created_at,
                                u.name AS parishioner_name, s.name AS service_name, a.service_id
                         FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) p
                         JOIN (SELECT * FROM applications WHERE parish_id = {$scopeParish}) a ON p.application_id = a.id
                         JOIN (SELECT * FROM users WHERE parish_id = {$scopeParish} OR id IN (SELECT user_id FROM applications WHERE parish_id = {$scopeParish})) u ON a.user_id = u.id
                         LEFT JOIN services s ON a.service_id = s.id
                         WHERE p.created_at BETWEEN ? AND ?
                         ORDER BY p.created_at DESC LIMIT 10");
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// All-time totals
$alltime_rev = (float)$conn->query("SELECT IFNULL(SUM(amount),0) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='completed'")->fetch_assoc()['t'];
$alltime_count = (int)$conn->query("SELECT COUNT(*) as t FROM (SELECT * FROM payments WHERE application_id IN (SELECT id FROM applications WHERE parish_id = {$scopeParish})) payments WHERE status='completed'")->fetch_assoc()['t'];

$pillMap = ['completed'=>'pill-green','pending'=>'pill-amber','refunded'=>'pill-wine'];
?>


<style>
.bar-h{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--ink-10)}
.bar-h:last-child{border-bottom:none}
.bar-h-label{width:140px;font-size:.8rem;font-weight:500;color:var(--ink);flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-h-track{flex:1;height:20px;background:var(--ink-10);border-radius:4px;overflow:hidden;position:relative}
.bar-h-fill{height:100%;border-radius:4px;transition:width .8s ease;display:flex;align-items:center;padding:0 8px}
.bar-h-fill span{font-size:.62rem;font-weight:500;color:white;white-space:nowrap}
.bar-h-val{width:100px;text-align:right;font-size:.82rem;font-weight:600;color:var(--green);flex-shrink:0}
.daily-bar{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}
.daily-bar-fill{width:100%;border-radius:4px 4px 0 0;background:var(--navy);transition:height .6s ease;min-height:2px}
.daily-bar-label{font-size:.55rem;color:var(--ink-30);writing-mode:horizontal-tb;white-space:nowrap}
.daily-bar-val{font-size:.58rem;color:var(--ink-60);font-weight:500}
</style>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Financial Reports</div>
    <h1 class="sec-title">Finance Overview</h1>
    <p class="sec-sub">Revenue analytics and financial reports for <?php echo $period_label; ?>.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="payments.php" class="btn-sm btn-outline">₱ All Payments</a>
  </div>
</div>

<!-- PERIOD FILTER -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px">
    <form method="GET" action="finance.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?php
      $periods = ['today'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year','custom'=>'Custom'];
      foreach ($periods as $pkey => $plabel): ?>
      <a href="?period=<?php echo $pkey; ?><?php echo $pkey==='custom'?'&from='.date('Y-m-01').'&to='.date('Y-m-d'):''; ?>"
         class="btn-sm <?php echo $period===$pkey?'btn-navy':'btn-outline'; ?>" style="padding:6px 14px;font-size:.75rem"><?php echo $plabel; ?></a>
      <?php endforeach; ?>

      <?php if ($period === 'custom'): ?>
      <div style="display:flex;gap:6px;align-items:center;margin-left:8px">
        <input type="date" name="from" value="<?php echo htmlspecialchars(substr($date_from,0,10)); ?>" style="font-size:.78rem;padding:5px 10px;border:1.5px solid var(--ink-10);border-radius:8px;outline:none;font-family:var(--fb)">
        <span style="color:var(--ink-30);font-size:.75rem">to</span>
        <input type="date" name="to" value="<?php echo htmlspecialchars(substr($date_to,0,10)); ?>" style="font-size:.78rem;padding:5px 10px;border:1.5px solid var(--ink-10);border-radius:8px;outline:none;font-family:var(--fb)">
        <input type="hidden" name="period" value="custom">
        <button type="submit" class="btn-sm btn-navy" style="padding:6px 14px;font-size:.75rem">Apply</button>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card stat-green">
    <div class="stat-icon">₱</div>
    <div class="stat-label">Verified Revenue</div>
    <div class="stat-value">₱<?php echo number_format($rev_completed, 2); ?></div>
    <div class="stat-delta"><?php echo $period_label; ?></div>
  </div>
  <div class="stat-card stat-amber">
    <div class="stat-icon">[icon:clock]</div>
    <div class="stat-label">Pending</div>
    <div class="stat-value">₱<?php echo number_format($rev_pending, 2); ?></div>
    <div class="stat-delta down">Awaiting verification</div>
  </div>
  <div class="stat-card stat-wine">
    <div class="stat-icon">[icon:refresh]</div>
    <div class="stat-label">Refunded</div>
    <div class="stat-value">₱<?php echo number_format($rev_refunded, 2); ?></div>
  </div>
  <div class="stat-card stat-navy">
    <div class="stat-icon">[icon:wallet]</div>
    <div class="stat-label">Net Revenue</div>
    <div class="stat-value">₱<?php echo number_format($net_revenue, 2); ?></div>
    <div class="stat-delta"><?php echo $pay_count; ?> transactions</div>
  </div>
</div>

<!-- ALL-TIME BANNER -->
<div class="notice notice-navy" style="margin-bottom:18px">
  <span>[icon:chart]</span>
  <span>All-time verified revenue: <strong>₱<?php echo number_format($alltime_rev, 2); ?></strong> across <strong><?php echo number_format($alltime_count); ?></strong> completed payments.</span>
</div>

<div class="grid-2">

  <!-- REVENUE BY SERVICE -->
  <div class="card">
    <div class="card-head"><h3>Revenue by Service</h3></div>
    <div class="card-body" style="padding:12px 22px">
      <?php if (empty($service_rev)): ?>
      <p style="text-align:center;padding:30px 0;color:var(--ink-30);font-size:.8rem;font-style:italic">No data for this period.</p>
      <?php endif; ?>
      <?php
      $srv_colors = ['var(--navy)','var(--gold)','var(--green)','var(--wine)','var(--amber)','var(--blue)'];
      foreach ($service_rev as $i => $sr):
        $pct = $max_srv_rev > 0 ? round(($sr['revenue'] / $max_srv_rev) * 100) : 0;
        $color = $srv_colors[$i % count($srv_colors)];
      ?>
      <div class="bar-h">
        <div class="bar-h-label"><?php echo htmlspecialchars($sr['service_name'] ?? 'Service #'.$sr['service_id']); ?></div>
        <div class="bar-h-track">
          <div class="bar-h-fill" style="width:<?php echo max($pct, 3); ?>%;background:<?php echo $color; ?>">
            <span><?php echo $sr['pay_count']; ?> pay</span>
          </div>
        </div>
        <div class="bar-h-val">₱<?php echo number_format($sr['revenue']); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- REVENUE BY METHOD -->
  <div class="card">
    <div class="card-head"><h3>Revenue by Payment Method</h3></div>
    <div class="card-body" style="padding:12px 22px">
      <?php if (empty($method_rev)): ?>
      <p style="text-align:center;padding:30px 0;color:var(--ink-30);font-size:.8rem;font-style:italic">No data for this period.</p>
      <?php endif; ?>
      <?php foreach ($method_rev as $i => $mr):
        $pct = $max_method > 0 ? round(($mr['total'] / $max_method) * 100) : 0;
        $color = $srv_colors[$i % count($srv_colors)];
      ?>
      <div class="bar-h">
        <div class="bar-h-label"><?php echo htmlspecialchars($mr['payment_method'] ?: 'Unknown'); ?></div>
        <div class="bar-h-track">
          <div class="bar-h-fill" style="width:<?php echo max($pct, 3); ?>%;background:<?php echo $color; ?>">
            <span><?php echo $mr['cnt']; ?> txn</span>
          </div>
        </div>
        <div class="bar-h-val">₱<?php echo number_format($mr['total']); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- DAILY REVENUE TREND -->
<?php if (!empty($daily_rev)): ?>
<div class="card">
  <div class="card-head">
    <h3>Daily Revenue Trend</h3>
    <span class="card-tag"><?php echo count($daily_rev); ?> days with revenue</span>
  </div>
  <div class="card-body">
    <div style="display:flex;align-items:flex-end;gap:4px;height:160px;padding:0 4px">
      <?php foreach ($daily_rev as $d):
        $h = $max_daily > 0 ? round(($d['total'] / $max_daily) * 140) : 2;
        $dt = strtotime($d['day']);
      ?>
      <div class="daily-bar">
        <div class="daily-bar-val">₱<?php echo number_format($d['total']); ?></div>
        <div style="flex:1;display:flex;align-items:flex-end;width:100%">
          <div class="daily-bar-fill" style="height:<?php echo max($h, 4); ?>px"></div>
        </div>
        <div class="daily-bar-label"><?php echo date('M j', $dt); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- RECENT TRANSACTIONS -->
<div class="card">
  <div class="card-head">
    <h3>Recent Transactions</h3>
    <a href="payments.php" class="btn-sm btn-outline" style="padding:5px 12px;font-size:.7rem">View All</a>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Parishioner</th><th>Service</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php if (empty($recent)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--ink-30);font-style:italic">No transactions for this period.</td></tr>
          <?php endif; ?>
          <?php foreach ($recent as $r):
            $pill = $pillMap[$r['status']] ?? 'pill-amber';
          ?>
          <tr>
            <td style="color:var(--ink-30);font-size:.72rem">#<?php echo $r['id']; ?></td>
            <td style="font-weight:500"><?php echo htmlspecialchars($r['parishioner_name']); ?></td>
            <td><?php echo htmlspecialchars($r['service_name'] ?? 'Service #'.$r['service_id']); ?></td>
            <td><span class="pill pill-navy"><?php echo htmlspecialchars($r['payment_method'] ?: '—'); ?></span></td>
            <td style="font-weight:600;color:var(--green)">₱<?php echo number_format($r['amount'], 2); ?></td>
            <td><span class="pill <?php echo $pill; ?>"><?php echo ucfirst($r['status']); ?></span></td>
            <td style="font-size:.73rem;color:var(--ink-30)"><?php echo date('M j, g:i A', strtotime($r['paid_at'] ?? $r['created_at'])); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include 'includes/layout_footer.php'; ?>
