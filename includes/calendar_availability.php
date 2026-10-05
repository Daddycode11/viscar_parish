<?php
require_once __DIR__.'/workflows.php';
$isStaff=$user['role']==='secretary';
$pid=$isStaff?(int)$user['parish_id']:(int)($_GET['parish_id']??$user['parish_id']??0);
$month=$_GET['month']??date('Y-m');if(!is_string($month)||!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))$month=date('Y-m');
$parishes=$conn->query("SELECT id,name FROM parishes WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
if(!$pid&&$parishes)$pid=(int)$parishes[0]['id'];
$services=$conn->execute_query("SELECT * FROM services WHERE parish_id=? AND status='active' ORDER BY name",[$pid])->fetch_all(MYSQLI_ASSOC);
$sid=(int)($_GET['service_id']??0);$selected=null;foreach($services as $service)if((int)$service['id']===$sid)$selected=$service;
$selected??=$services[0]??null;$days=$selected?service_month_availability($selected,$month):[];
$page_id=$isStaff?'schedule':'events';$page_title='Available Dates';$folder=$isStaff?'staff':'parishioner';require APP_ROOT.'/'.$folder.'/includes/layout.php';
?>
<h1>Parish calendar</h1><nav class="request-tabs"><a aria-current="page" href="?view=available">Available Dates</a><a href="<?= $isStaff?'schedule.php?year='.substr($month,0,4).'&month='.(int)substr($month,5):'events.php?parish_id='.$pid.'&month='.h($month) ?>">Scheduled Dates</a></nav>
<section class="card"><div class="card-body"><form method="get" class="filter-bar"><input type="hidden" name="view" value="available"><?php if(!$isStaff): ?><label>Parish<select name="parish_id" onchange="this.form.submit()"><?php foreach($parishes as $parish): ?><option value="<?= (int)$parish['id'] ?>" <?= $pid===(int)$parish['id']?'selected':'' ?>><?= h($parish['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Month<input type="month" name="month" value="<?= h($month) ?>" required></label><label>Service<select name="service_id"><?php foreach($services as $service): ?><option value="<?= (int)$service['id'] ?>" <?= $selected['id']===$service['id']?'selected':'' ?>><?= h($service['name']) ?></option><?php endforeach; ?></select></label><button>View dates</button></form>
<div class="availability-legend"><span><i class="available"></i>Available</span><span><i class="full"></i>Full</span><span><i class="past"></i>Past / unavailable</span></div>
<?php if(!$days): ?><p>No active services in this parish.</p><?php endif; ?>
<div class="availability-grid"><?php foreach($days as $day): $state=$day['past']||$day['unavailable']?'past':($day['full']?'full':'available'); ?><button type="button" class="<?= $state ?>" onclick="document.getElementById('available-<?= h($day['date']) ?>').showModal()" aria-label="<?= h($day['date'].' '.$state) ?>"><?= (int)substr($day['date'],-2) ?></button><?php endforeach; ?></div>
<?php foreach($days as $day): ?><dialog id="available-<?= h($day['date']) ?>" style="margin:auto;padding:24px;max-width:92vw"><h2><?= h($day['date'].' — '.$selected['name']) ?></h2><?php if($day['past']||$day['unavailable']): ?><p>This date is unavailable.</p><?php elseif($day['full']): ?><p>This date is full.</p><?php elseif($day['slots']): ?><ul><?php foreach($day['slots'] as $slot): ?><li><?= h(display_time($slot['time'])) ?> — <?= $slot['available']?'Available':'Unavailable' ?></li><?php endforeach; ?></ul><?php else: ?><p>User-defined time. Capacity is checked when you submit.</p><?php endif; ?><form method="dialog"><button>Close</button></form></dialog><?php endforeach; ?></div></section>
<?php require APP_ROOT.'/'.$folder.'/includes/layout_footer.php'; ?>
