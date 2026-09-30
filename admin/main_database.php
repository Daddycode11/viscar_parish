<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/application_details.php';
require_sensitive_verification($user);
$id=(int)($_GET['id']??0);
$centralWhere="a.status='approved' AND s.general_type IN ('Baptism','Matrimony','Confirmation','Funeral Mass')";
if ($id) {
    $application=sqlrow("SELECT a.*,p.name parish_name,s.name service_name FROM applications a JOIN services s ON s.id=a.service_id JOIN parishes p ON p.id=a.parish_id WHERE a.id=? AND $centralWhere",[$id]);
    if (!$application) fail_request('Approved record not found.',404);
    auditLog($user['id'],isset($_GET['download'])?'central_record_download':'central_record_view','application',$id);
    if(isset($_GET['certificate'])) {
        $record=sqlrow("SELECT * FROM sacramental_records WHERE application_id=? AND status='active' AND certificate_number IS NOT NULL",[$id]);
        if(!$record)fail_request('Certificate not available.',404);
        $parishRow=sqlrow('SELECT * FROM parishes WHERE id=?',[$application['parish_id']]);
        require_once APP_ROOT.'/includes/pdf.php';
        require_once APP_ROOT.'/includes/qr.php';
        auditLog($user['id'],'central_certificate_download','application',$id);
        header('Content-Type: text/html; charset=UTF-8');header('Content-Disposition: attachment; filename="certificate-'.$id.'.html"');
        echo generateCertificateHTML($record,$parishRow,getQRImageURL(app_url('public/verify.php?cert='.rawurlencode($record['certificate_number']))));exit;
    }
    if (isset($_GET['download'])) {
        header('Content-Type: text/html; charset=UTF-8');header('Content-Disposition: attachment; filename="application-'.$id.'.html"');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Application record</title></head><body><h1>'.h($application['parish_name']).'</h1>';
        render_application_details($application);echo '</body></html>';exit;
    }
}
try {
    $q=input_text($_GET,'q');$parish=(int)($_GET['parish']??0);$type=input_text($_GET,'service');
    $from=input_text($_GET,'date_from')?:'1900-01-01';$to=input_text($_GET,'date_to')?:date('Y-m-d');
    require_once __DIR__.'/../includes/report_data.php';[$start,$end]=report_range($from,$to);
    $pageNumber=max(1,(int)($_GET['page']??1));$offset=($pageNumber-1)*50;
    $rows=$conn->execute_query("SELECT a.id,a.schedule,a.created_at,u.name,p.name parish,s.general_type FROM applications a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.user_id JOIN parishes p ON p.id=a.parish_id WHERE $centralWhere AND (?=0 OR a.parish_id=?) AND (?='' OR s.general_type=?) AND a.created_at BETWEEN ? AND ? AND (?='' OR u.name LIKE ? OR CONCAT('APP-',a.id)=? OR CAST(a.id AS CHAR)=?) ORDER BY a.id DESC LIMIT 51 OFFSET $offset",[$parish,$parish,$type,$type,$start,$end,$q,'%'.$q.'%',$q,$q])->fetch_all(MYSQLI_ASSOC);
} catch (DomainException $e) { fail_request($e->getMessage(),422); }
$page_id='main_database';$page_title='MAIN DATABASE';require __DIR__.'/includes/layout.php';
?>
<h1>MAIN DATABASE</h1><p>Approved records across parishes. Access and downloads are logged.</p>
<?php if($id): ?><h2><?= h($application['parish_name'].' — '.$application['service_name']) ?></h2><a class="btn-sm btn-navy" href="?id=<?= $id ?>&download=1">Download application information</a><?php if(sqlrow('SELECT id FROM sacramental_records WHERE application_id=? AND certificate_number IS NOT NULL',[$id])): ?><a class="btn-sm" href="?id=<?= $id ?>&certificate=1">Download certificate</a><?php endif; ?><button class="btn-sm" onclick="print()">Print / Save PDF</button><?php render_application_details($application); ?><a class="btn-sm btn-outline" href="main_database.php">Back to records</a><?php require __DIR__.'/includes/layout_footer.php'; exit; endif; ?>
<form method="get" class="card"><div class="card-body filter-bar"><label>Name or reference<input name="q" value="<?= h($q) ?>"></label><label>Parish<select name="parish"><option value="0">All parishes</option><?php foreach($conn->query('SELECT id,name FROM parishes ORDER BY name') as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $parish===$p['id']?'selected':'' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></label><label>Service<select name="service"><option value="">All services</option><?php foreach(['Baptism','Matrimony','Confirmation','Funeral Mass'] as $t): ?><option <?= $type===$t?'selected':'' ?>><?= h($t) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="date_from" value="<?= h($from) ?>"></label><label>To<input type="date" name="date_to" value="<?= h($to) ?>"></label><button class="btn-sm btn-navy">Search</button></div></form>
<div class="card"><div class="card-body tbl-wrap"><table><tr><th>Reference</th><th>Parishioner</th><th>Parish</th><th>Service</th><th>Schedule</th></tr><?php foreach(array_slice($rows,0,50) as $r): ?><tr><td><a href="?id=<?= (int)$r['id'] ?>">APP-<?= (int)$r['id'] ?></a></td><td><?= h($r['name']) ?></td><td><?= h($r['parish']) ?></td><td><?= h($r['general_type']) ?></td><td><?= h($r['schedule']) ?></td></tr><?php endforeach; ?></table><?php if(!$rows): ?><p>No approved records match these filters.</p><?php endif; ?><?php if(count($rows)>50): ?><a href="?<?= h(http_build_query(array_merge($_GET,['page'=>$pageNumber+1]))) ?>">Next page</a><?php endif; ?></div></div>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
