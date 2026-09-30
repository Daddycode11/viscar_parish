<?php
if(($action??'')==='availability') {
    try {
        $month=input_text($_GET,'month');
        must((bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month),'Choose a valid month.');
        $service=sqlrow("SELECT * FROM services WHERE id=? AND parish_id=? AND status='active'",[(int)($_GET['service_id']??0),(int)($_GET['parish_id']??0)]);
        must($service!==null,'Service not available.');
        $start=new DateTimeImmutable($month.'-01');$end=$start->modify('+1 month');
        $counts=[];foreach($conn->execute_query("SELECT DATE(schedule) day,COUNT(*) total FROM applications WHERE service_id=? AND status IN ('pending','approved') AND schedule>=? AND schedule<? GROUP BY DATE(schedule)",[$service['id'],$start->format('Y-m-d'),$end->format('Y-m-d')]) as $r)$counts[$r['day']]=(int)$r['total'];
        $days=[];for($d=$start;$d<$end;$d=$d->modify('+1 day')){$day=$d->format('Y-m-d');$count=$counts[$day]??0;$days[]=['date'=>$day,'count'=>$count,'full'=>$service['max_daily_limit']>0&&$count>=$service['max_daily_limit'],'past'=>$day<date('Y-m-d'),'unavailable'=>!service_day_allowed($service,$day)];}
        header('Content-Type: application/json');echo json_encode(['ok'=>true,'days'=>$days]);exit;
    }catch(DomainException $e){fail_request($e->getMessage(),422);}
}
