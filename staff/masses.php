<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflows.php';
if($user['role']!=='secretary')fail_request('Secretary access required.');
$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $id=(int)($_POST['id']??0);
  if(isset($_POST['delete'])){$conn->execute_query("UPDATE mass_schedules SET status='inactive' WHERE id=? AND parish_id=?",[$id,$scopeParish]);}
  else{
   $day=$_POST['day']??'';$start=$_POST['start']??'';$end=$_POST['end']??'';
   must(in_array($day,['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],true),'Choose a day.');
   must((bool)preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$start),'Enter a valid starting time.');
   must(!$end||((bool)preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$end)&&$end>$start),'End time must be later than start.');
   $type=trim($_POST['mass_type']??'Regular Mass');$language=trim($_POST['language']??'Filipino');must($type!==''&&strlen($type)<=100&&strlen($language)<=50,'Enter a valid mass type and language.');
   if($id)$conn->execute_query("UPDATE mass_schedules SET day_of_week=?,time_start=?,time_end=?,mass_type=?,language=? WHERE id=? AND parish_id=?",[$day,$start,$end?:null,$type,$language,$id,$scopeParish]);
   else{$conn->execute_query('INSERT INTO mass_schedules(parish_id,day_of_week,time_start,time_end,mass_type,language)VALUES(?,?,?,?,?,?)',[$scopeParish,$day,$start,$end?:null,$type,$language]);$id=$conn->insert_id;}
  }auditLog($user['id'],'manage_mass','mass_schedule',$id);$notice='Mass schedule saved.';
 }catch(Throwable $e){$notice=$e instanceof DomainException?$e->getMessage():'Unable to save schedule.';}
}
$rows=$conn->execute_query("SELECT * FROM mass_schedules WHERE parish_id=? AND status='active' ORDER BY day_of_week,time_start",[$scopeParish])->fetch_all(MYSQLI_ASSOC);
$page_id='masses';$page_title='Mass Schedules';require __DIR__.'/includes/layout.php';
?><div class="card"><div class="card-body"><h2>Mass schedules</h2><p><?=htmlspecialchars($notice)?></p><form method="post"><div class="form-group"><label>Day</label><select name="day"><?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day):?><option><?=$day?></option><?php endforeach;?></select></div><div class="form-group"><label>Start</label><input name="start" type="time" required><label>End (optional)</label><input name="end" type="time"></div><div class="form-group"><label>Mass type</label><input name="mass_type" value="Regular Mass" maxlength="100" required><label>Language</label><input name="language" value="Filipino" maxlength="50"></div><button class="btn-sm btn-navy">Add schedule</button></form></div></div><div class="card"><div class="card-body"><table><thead><tr><th>Day</th><th>Time</th><th>Mass</th><th>Language</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['day_of_week'])?></td><td><?=htmlspecialchars(display_time($r['time_start']).($r['time_end']?' – '.display_time($r['time_end']):''))?></td><td><?=htmlspecialchars($r['mass_type'])?></td><td><?=htmlspecialchars($r['language'])?></td><td><form method="post"><input type="hidden" name="id" value="<?=$r['id']?>"><button name="delete" class="btn-sm btn-outline">Remove schedule</button></form></td></tr><?php endforeach;?></tbody></table></div></div><?php require __DIR__.'/includes/layout_footer.php';?>
