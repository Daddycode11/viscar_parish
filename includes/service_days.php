<?php
// ISO weekdays; NULL on older services means available every day.
function service_weekdays(array $service): array {
    $days=json_decode($service['available_weekdays']??'null',true);
    return is_array($days)?array_map('intval',$days):range(1,7);
}
function service_day_allowed(array $service,string $date): bool {
    return in_array((int)(new DateTimeImmutable($date))->format('N'),service_weekdays($service),true);
}

function service_time_slots(array $service): array {
    return json_decode($service['time_slots']??'[]',true) ?: [];
}

// Aggregate capacity only: never exposes applicant names or documents.
function service_month_availability(array $service, string $month): array {
    global $conn;
    $start=new DateTimeImmutable($month.'-01'); $end=$start->modify('+1 month');
    $counts=[]; $times=[];
    foreach($conn->execute_query("SELECT DATE(schedule) day,DATE_FORMAT(schedule,'%H:%i') time,COUNT(*) total FROM applications WHERE service_id=? AND status IN ('pending','approved') AND schedule>=? AND schedule<? GROUP BY DATE(schedule),DATE_FORMAT(schedule,'%H:%i')",[$service['id'],$start->format('Y-m-d'),$end->format('Y-m-d')]) as $r) {
        $counts[$r['day']]=($counts[$r['day']]??0)+(int)$r['total']; $times[$r['day']][$r['time']]=(int)$r['total'];
    }
    $days=[]; $fixed=($service['schedule_mode']??'user_defined')==='fixed'; $limit=(int)($service['slot_capacity']??1);
    for($d=$start;$d<$end;$d=$d->modify('+1 day')) {
        $day=$d->format('Y-m-d'); $count=$counts[$day]??0;
        $unavailable=!service_day_allowed($service,$day); $past=$day<date('Y-m-d');
        $full=(int)$service['max_daily_limit']>0 && $count>=(int)$service['max_daily_limit']; $slots=[];
        foreach($fixed?service_time_slots($service):[] as $time) {
            $used=$times[$day][$time]??0;
            $slots[]=['time'=>$time,'available'=>!$past&&!$unavailable&&!$full&&strtotime($day.' '.$time)>time()&&($limit===0||$used<$limit)];
        }
        if($fixed&&!$past&&!$unavailable&&!array_filter($slots,fn($slot)=>$slot['available'])) $full=true;
        $days[]=['date'=>$day,'count'=>$count,'full'=>$full,'past'=>$past,'unavailable'=>$unavailable,'slots'=>$slots];
    }
    return $days;
}
