<?php
function report_range($from,$to):array {
 foreach([$from,$to] as $value){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Invalid report date.');}
 if($from>$to)throw new DomainException('Report end date must follow start date.');return [$from.' 00:00:00',$to.' 23:59:59'];
}
function gen_financial_data($from,$to,$pid):array {
 global $conn;[$from,$to]=report_range($from,$to);
 return $conn->execute_query("SELECT COALESCE(p.reference_number,p.receipt_number,CONCAT('PAY-',p.id)) ref,DATE((CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END)) date,u.name parishioner,s.name service,pa.name parish,p.amount,p.payment_method method,p.status FROM payments p JOIN applications a ON a.id=p.application_id JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id JOIN parishes pa ON pa.id=a.parish_id WHERE (CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) BETWEEN ? AND ? AND (?=0 OR a.parish_id=?) ORDER BY (CASE WHEN p.status IN ('completed','refunded') THEN COALESCE(p.verified_at,p.paid_at) ELSE p.created_at END) DESC",[$from,$to,(int)$pid,(int)$pid])->fetch_all(MYSQLI_ASSOC);
}
function gen_applications_data($from,$to,$pid):array {
 global $conn;[$from,$to]=report_range($from,$to);
 return $conn->execute_query('SELECT a.id,DATE(a.created_at) date,u.name parishioner,u.email,s.name service,p.name parish,a.schedule,a.status,a.payment_status payment FROM applications a JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id JOIN parishes p ON p.id=a.parish_id WHERE a.created_at BETWEEN ? AND ? AND (?=0 OR a.parish_id=?) ORDER BY a.created_at DESC',[$from,$to,(int)$pid,(int)$pid])->fetch_all(MYSQLI_ASSOC);
}
function gen_parish_comparison($from,$to):array {
 global $conn;[$start,$end]=report_range($from,$to);
 return $conn->execute_query("SELECT p.name parish,COALESCE(a.apps,0) apps,COALESCE(a.approved,0) approved,COALESCE(a.rejected,0) rejected,COALESCE(a.pending,0) pending,COALESCE(r.revenue,0) revenue,COALESCE(u.users,0) users,COALESCE(s.services,0) services FROM parishes p LEFT JOIN (SELECT parish_id,COUNT(*) apps,SUM(status='approved') approved,SUM(status='rejected') rejected,SUM(status='pending') pending FROM applications WHERE created_at BETWEEN ? AND ? GROUP BY parish_id) a ON a.parish_id=p.id LEFT JOIN (SELECT ap.parish_id,SUM(pay.amount) revenue FROM payments pay JOIN applications ap ON ap.id=pay.application_id WHERE pay.status IN ('completed','refunded') AND COALESCE(pay.verified_at,pay.paid_at) BETWEEN ? AND ? GROUP BY ap.parish_id) r ON r.parish_id=p.id LEFT JOIN (SELECT parish_id,COUNT(*) users FROM users GROUP BY parish_id) u ON u.parish_id=p.id LEFT JOIN (SELECT parish_id,COUNT(*) services FROM services GROUP BY parish_id) s ON s.parish_id=p.id ORDER BY p.name",[$start,$end,$start,$end])->fetch_all(MYSQLI_ASSOC);
}
function gen_service_demand($from,$to,$pid):array {
 global $conn;[$start,$end]=report_range($from,$to);
 return $conn->execute_query("SELECT COALESCE(s.general_type,'Other / Custom') service,SUM(COALESCE(a.n,0)) count,SUM(COALESCE(p.revenue,0)) revenue,COALESCE(SUM(a.fees)/NULLIF(SUM(a.n),0),0) avg_fee,COALESCE(ROUND(SUM(a.approved)/NULLIF(SUM(a.n),0)*100,1),0) completion_rate FROM services s LEFT JOIN (SELECT service_id,COUNT(*) n,SUM(status='approved') approved,SUM(fee_snapshot) fees FROM applications WHERE created_at BETWEEN ? AND ? GROUP BY service_id) a ON a.service_id=s.id LEFT JOIN (SELECT a.service_id,SUM(p.amount) revenue FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.status IN ('completed','refunded') AND COALESCE(p.verified_at,p.paid_at) BETWEEN ? AND ? GROUP BY a.service_id) p ON p.service_id=s.id WHERE (?=0 OR s.parish_id=?) GROUP BY COALESCE(s.general_type,'Other / Custom') ORDER BY count DESC,service",[$start,$end,$start,$end,(int)$pid,(int)$pid])->fetch_all(MYSQLI_ASSOC);
}
function gen_user_activity($from,$to):array {
 global $conn;[$from,$to]=report_range($from,$to);
 return $conn->execute_query("SELECT u.name user,u.role,p.name parish,COALESCE(SUM(t.action='login'),0) logins,COALESCE(SUM(t.action IN ('approve','reject','assign_schedule')),0) apps_processed,COALESCE(MAX(t.created_at),'No recorded activity') last_active FROM users u LEFT JOIN parishes p ON p.id=u.parish_id LEFT JOIN audit_trail t ON t.user_id=u.id AND t.created_at BETWEEN ? AND ? WHERE u.role IN ('secretary','bookkeeper','admin') GROUP BY u.id,u.name,u.role,p.name ORDER BY u.name",[$from,$to])->fetch_all(MYSQLI_ASSOC);
}
function report_csv($out,array $row):void {fputcsv($out,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v,$row));}
