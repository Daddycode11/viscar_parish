<?php
require_once __DIR__.'/report_data.php';
$trendFrom=input_text($_GET,'date_from')?:date('Y-01-01');$trendTo=input_text($_GET,'date_to')?:date('Y-m-d');
[$trendStart,$trendEnd]=report_range($trendFrom,$trendTo);
$trendService=(int)($_GET['trend_service']??0);$trendRevenue=$user['role']==='bookkeeper'||($user['role']==='admin'&&($_GET['trend_metric']??'')==='revenue');
$trendWhere='';$trendArgs=[$trendStart,$trendEnd];
if($user['role']==='parishioner'){$trendWhere.=' AND a.user_id=?';$trendArgs[]=$user['id'];}
elseif($user['role']!=='admin'){$trendWhere.=' AND a.parish_id=?';$trendArgs[]=$user['parish_id'];}
if($trendService){$trendWhere.=' AND a.service_id=?';$trendArgs[]=$trendService;}
$trendSql=$trendRevenue?"SELECT DATE_FORMAT(COALESCE(p.verified_at,p.paid_at),'%Y-%m') month,SUM(p.amount) value FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.status IN ('completed','refunded') AND COALESCE(p.verified_at,p.paid_at) BETWEEN ? AND ? $trendWhere GROUP BY month":"SELECT DATE_FORMAT(a.created_at,'%Y-%m') month,COUNT(*) value FROM applications a WHERE a.created_at BETWEEN ? AND ? $trendWhere GROUP BY month";
$trendValues=[];$last=new DateTimeImmutable(substr($trendTo,0,7).'-01');$first=max(new DateTimeImmutable(substr($trendFrom,0,7).'-01'),$last->modify('-11 months'));
for($date=$first;$date<=$last;$date=$date->modify('+1 month'))$trendValues[$date->format('Y-m')]=0;
foreach($conn->execute_query($trendSql,$trendArgs) as $row)if(array_key_exists($row['month'],$trendValues))$trendValues[$row['month']]=(float)$row['value'];
$trendServices=$user['role']==='admin'?$conn->query('SELECT id,name FROM services ORDER BY name')->fetch_all(MYSQLI_ASSOC):($user['role']==='parishioner'?$conn->execute_query('SELECT DISTINCT s.id,s.name FROM services s JOIN applications a ON a.service_id=s.id WHERE a.user_id=? ORDER BY s.name',[$user['id']])->fetch_all(MYSQLI_ASSOC):$conn->execute_query('SELECT id,name FROM services WHERE parish_id=? ORDER BY name',[$user['parish_id']])->fetch_all(MYSQLI_ASSOC));
$maximum=max(1,...array_values($trendValues));$points=[];$i=0;foreach($trendValues as $value){$points[]=(40+$i*620/max(1,count($trendValues)-1)).','.(190-160*$value/$maximum);$i++;}
$series=[];$colors=['#21633f','#b68b24','#87354c','#43658b','#715798','#a34e18'];
$seriesSql=str_replace(['SELECT DATE_FORMAT','GROUP BY month'],['SELECT a.service_id,DATE_FORMAT','GROUP BY a.service_id,month'],$trendSql);
foreach($conn->execute_query($seriesSql,$trendArgs) as $row){
    if(!array_key_exists($row['month'],$trendValues))continue;
    $series[$row['service_id']]??=array_fill_keys(array_keys($trendValues),0);
    $series[$row['service_id']][$row['month']]=(float)$row['value'];
}
$seriesNames=array_column($trendServices,'name','id');
$maximum=1;foreach($series as $values)$maximum=max($maximum,...array_values($values));
?>
<section class="card"><div class="card-body"><h2><?= $trendRevenue?'Verified revenue':'Service applications' ?> over time</h2><form method="get" class="filter-bar"><input type="hidden" name="date_from" value="<?= h($trendFrom) ?>"><input type="hidden" name="date_to" value="<?= h($trendTo) ?>"><label>Service<select name="trend_service"><option value="0">All services</option><?php foreach($trendServices as $service): ?><option value="<?= (int)$service['id'] ?>" <?= $trendService===(int)$service['id']?'selected':'' ?>><?= h($service['name']) ?></option><?php endforeach; ?></select></label><?php if($user['role']==='admin'): ?><label>Measure<select name="trend_metric"><option value="applications">Applications</option><option value="revenue" <?= $trendRevenue?'selected':'' ?>>Verified revenue</option></select></label><?php endif; ?><button>Update trend</button></form>
<p><?= h($trendFrom) ?> to <?= h($trendTo) ?>; showing up to the last 12 months. <?= $trendRevenue?'Gross verified payments; refunds are shown separately in financial reports.':'Counts by application submission date.' ?></p>
<div class="availability-legend" aria-label="Service trend legend"><?php $seriesIndex=0;foreach($series as $serviceId=>$values): ?><span><i style="background:<?= $colors[$seriesIndex++%count($colors)] ?>"></i><?= h($seriesNames[$serviceId]??'Service #'.$serviceId) ?></span><?php endforeach; ?></div>
<?php if(!$series): ?><p>No activity in this period.</p><?php endif; ?>
<svg viewBox="0 0 700 235" role="img" aria-label="Monthly <?= $trendRevenue?'verified revenue':'applications' ?> by service; exact values below" style="width:100%;max-height:280px"><path d="M40 20V190H670" fill="none" stroke="#94a3b8"/><text x="0" y="20" font-size="12"><?= h(number_format($maximum)) ?></text><text x="20" y="195" font-size="12">0</text>
<?php $seriesIndex=0;foreach($series as $serviceId=>$values):$color=$colors[$seriesIndex++%count($colors)];$seriesPoints=[];$i=0;foreach($values as $value)$seriesPoints[]=(40+$i++*620/max(1,count($values)-1)).','.(190-160*$value/$maximum); ?>
<polyline points="<?= h(implode(' ',$seriesPoints)) ?>" fill="none" stroke="<?= $color ?>" stroke-width="3"/>
<?php $i=0;foreach($values as $month=>$value):[$x,$y]=explode(',',$seriesPoints[$i++]); ?><circle cx="<?= $x ?>" cy="<?= $y ?>" r="4" fill="<?= $color ?>"><title><?= h(($seriesNames[$serviceId]??'Service').' '.$month.': '.number_format($value,$trendRevenue?2:0)) ?></title></circle><?php endforeach;endforeach; ?>
<?php $i=0;foreach($trendValues as $month=>$value): ?><text x="<?= 40+$i++*620/max(1,count($trendValues)-1) ?>" y="215" text-anchor="middle" font-size="10"><?= h(substr($month,2)) ?></text><?php endforeach; ?></svg>
<details><summary>View exact monthly values</summary><div class="tbl-wrap"><table><thead><tr><th>Month</th><?php foreach($series as $serviceId=>$values): ?><th><?= h($seriesNames[$serviceId]??'Service #'.$serviceId) ?><?= $trendRevenue?' (PHP)':'' ?></th><?php endforeach; ?><th>Total</th></tr></thead><tbody><?php foreach($trendValues as $month=>$value): ?><tr><td><?= h($month) ?></td><?php foreach($series as $values): ?><td><?= number_format($values[$month],$trendRevenue?2:0) ?></td><?php endforeach; ?><td><?= number_format($value,$trendRevenue?2:0) ?></td></tr><?php endforeach; ?></tbody></table></div></details></div></section>
