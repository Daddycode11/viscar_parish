<?php
if(($action??'')==='availability') {
    try {
        $month=input_text($_GET,'month');
        must((bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month),'Choose a valid month.');
        $service=sqlrow("SELECT * FROM services WHERE id=? AND parish_id=? AND status='active'",[(int)($_GET['service_id']??0),(int)($_GET['parish_id']??0)]);
        must($service!==null,'Service not available.');
        $days=service_month_availability($service,$month);
        header('Content-Type: application/json');echo json_encode(['ok'=>true,'days'=>$days,'schedule_mode'=>$service['schedule_mode']??'user_defined']);exit;
    }catch(DomainException $e){fail_request($e->getMessage(),422);}
}
