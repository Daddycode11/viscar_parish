<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

$user = currentUser();

require_once __DIR__.'/../includes/analytics.php';
require_once __DIR__.'/../includes/report_data.php';
try {
 $analyticsFrom=input_text($_GET,'date_from')?:date('Y-01-01');$analyticsTo=input_text($_GET,'date_to')?:date('Y-m-d');
 $top_services=array_map(fn($row)=>['service'=>$row['service'],'total'=>$row['count']],gen_service_demand($analyticsFrom,$analyticsTo,0));
} catch (DomainException $e) { fail_request($e->getMessage(),422); }
$max_svc=max(1,...array_column($top_services,'total'));foreach($top_services as &$s)$s['pct']=round($s['total']/$max_svc*100);unset($s);
[$analyticsStart,$analyticsEnd]=report_range($analyticsFrom,$analyticsTo);
$monthly_data=[];
$lastMonth=new DateTimeImmutable(substr($analyticsTo,0,7).'-01');
$firstMonth=new DateTimeImmutable(substr($analyticsFrom,0,7).'-01');
// Keep the chart readable for wide ranges; all other summaries cover the full selection.
$chartStart=max($firstMonth,$lastMonth->modify('-11 months'));
for($month=$chartStart;$month<=$lastMonth;$month=$month->modify('+1 month'))$monthly_data[$month->format('Y-m')]=0;
$chartRows=$conn->execute_query("SELECT DATE_FORMAT(created_at,'%Y-%m') month,COUNT(*) n FROM applications WHERE created_at BETWEEN ? AND ? AND created_at>=? GROUP BY month",[$analyticsStart,$analyticsEnd,$chartStart->format('Y-m-d')])->fetch_all(MYSQLI_ASSOC);
foreach($chartRows as $row)$monthly_data[$row['month']]=(int)$row['n'];
$parish_compare=parish_comparison($analyticsFrom,$analyticsTo);$max_apps=max(1,...array_column($parish_compare,'apps'));
$colors = ['#C9A84C','#43658b','#43658b','#C97A20','#7A2A3A'];

$page_id    = 'analytics';
$page_title = 'Analytics';
$page_sub   = 'Advanced Analytics';
include 'includes/layout.php';
?>

<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Advanced Analytics</div>
    <h1 class="sec-title">Analytics</h1>
    <p class="sec-sub">Insights, trends, and performance across the Apostolic Vicariate of San Jose.</p>
  </div>

</div>

<form method="get" class="filter-bar"><label>From<input type="date" name="date_from" value="<?= h($analyticsFrom) ?>" required></label><label>To<input type="date" name="date_to" value="<?= h($analyticsTo) ?>" required></label><button class="btn-sm btn-navy">Apply period</button></form>
<p>Selected period: <?=h($analyticsFrom)?> to <?=h($analyticsTo)?>. The monthly chart shows up to the final twelve months within this period.</p>
<div class="grid-2">
  <!-- Most Requested Services -->
  <div class="card">
    <div class="card-head"><h3>Most Requested Services</h3><span class="card-tag">Selected period</span></div>
    <div class="card-body">
      <div class="bar-list">
        <?php foreach($top_services as $i => $s): ?>
        <div class="bar-item">
          <div class="bar-top">
            <span class="bar-label"><?php echo htmlspecialchars($s['service']); ?></span>
            <span class="bar-val"><?php echo $s['total']; ?> apps</span>
          </div>
          <div class="bar-track">
            <div class="bar-fill" style="width:<?php echo $s['pct']; ?>%;background:<?php echo $colors[$i%5]; ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Monthly Applications Chart (CSS-based) -->
  <div class="card">
    <div class="card-head"><h3>Monthly Applications</h3><span class="card-tag">Selected period</span></div>
    <div class="card-body">
      <?php $max_m = max(1,...array_values($monthly_data)); ?>
      <div style="display:flex;align-items:flex-end;gap:10px;overflow-x:auto;height:140px;margin-bottom:12px">
        <?php foreach($monthly_data as $month => $val):
          $h = round(($val/$max_m)*100);
        ?>
        <div style="flex:1;min-width:48px;display:flex;flex-direction:column;align-items:center;gap:6px">
          <span style="font-size:.72rem;color:var(--ink-60);font-weight:500"><?php echo $val; ?></span>
          <div style="width:100%;background:var(--navy);border-radius:6px 6px 0 0;height:<?php echo $h; ?>%;transition:height .6s ease;min-height:8px"></div>
          <span style="font-size:.65rem;color:var(--ink-30);text-transform:uppercase;letter-spacing:.05em"><?php echo $month; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      
    </div>
  </div>
</div>

<!-- Parish Comparison -->
<div class="card">
  <div class="card-head">
    <h3>Parish Comparison</h3>
    <span class="card-tag">Applications vs Revenue</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Parish</th><th>Applications</th><th>Volume</th><th>Revenue</th><th>Avg per App</th></tr>
        </thead>
        <tbody>
          <?php foreach($parish_compare as $i => $p):
            $pct  = round(($p['apps']/$max_apps)*100);
            $avg  = $p['apps'] > 0 ? round($p['revenue']/$p['apps']) : 0;
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:10px;height:10px;border-radius:50%;background:<?php echo $colors[$i%5]; ?>"></div>
                <span style="font-weight:500"><?php echo htmlspecialchars($p['parish']); ?></span>
              </div>
            </td>
            <td><?php echo $p['apps']; ?></td>
            <td style="width:200px">
              <div class="bar-track">
                <div class="bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $colors[$i%5]; ?>"></div>
              </div>
            </td>
            <td style="font-weight:600;color:var(--navy)">₱<?php echo number_format($p['revenue']); ?></td>
            <td style="color:var(--ink-60)">₱<?php echo number_format($avg); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card"><div class="card-body"><h3>Revenue estimate</h3><?php $rev=$conn->query("SELECT COALESCE(SUM(amount),0)/6 amount FROM payments WHERE status IN ('completed','refunded') AND COALESCE(verified_at,paid_at)>=DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 6 MONTH) AND COALESCE(verified_at,paid_at)<DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch_assoc();?><p>Next-month estimate: PHP <?=number_format($rev['amount'],2)?></p><p>Simple average of verified revenue over the previous six complete calendar months, including months with zero revenue. This estimate is not a guaranteed collection.</p></div></div>
<?php require APP_ROOT.'/includes/service_trend.php'; ?>
<?php include 'includes/layout_footer.php'; ?>
