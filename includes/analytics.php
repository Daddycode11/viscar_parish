<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/financial_totals.php';
function dashboard_totals():array {
 global $conn;
 $from=input_text($_GET,'date_from')?:'1900-01-01';$to=input_text($_GET,'date_to')?:'9999-12-31';
 [$start,$end]=report_range($from,$to);
 $r=$conn->execute_query("SELECT (SELECT COUNT(*) FROM parishes) total_parishes,(SELECT COUNT(*) FROM users) total_users,COUNT(*) total_applications,COALESCE(SUM(status='pending'),0) pending_apps FROM applications WHERE created_at BETWEEN ? AND ?",[$start,$end])->fetch_assoc();
 $financial=financial_totals($from,$to);$r['total_revenue']=$financial['verified_revenue'];$r['net_revenue']=$financial['net_revenue'];$r['today_revenue']=financial_totals(date('Y-m-d'),date('Y-m-d'))['verified_revenue'];
 foreach($r as &$v)$v=(float)$v;unset($v);$r['last_updated']=date('g:i A');return $r;
}
function parish_comparison(string $from='1900-01-01', string $to='9999-12-31'):array {
 global $conn;[$start,$end]=report_range($from,$to);
 return $conn->execute_query("SELECT p.name parish,p.status,COALESCE(a.apps,0) apps,COALESCE(a.approved,0) approved,COALESCE(r.revenue,0) revenue FROM parishes p LEFT JOIN (SELECT parish_id,COUNT(*) apps,SUM(status='approved') approved FROM applications WHERE created_at BETWEEN ? AND ? GROUP BY parish_id) a ON a.parish_id=p.id LEFT JOIN (SELECT ap.parish_id,SUM(pay.amount) revenue FROM payments pay JOIN applications ap ON ap.id=pay.application_id WHERE pay.status IN ('completed','refunded') AND COALESCE(pay.verified_at,pay.paid_at) BETWEEN ? AND ? GROUP BY ap.parish_id) r ON r.parish_id=p.id ORDER BY apps DESC,p.name",[$start,$end,$start,$end])->fetch_all(MYSQLI_ASSOC);
}
