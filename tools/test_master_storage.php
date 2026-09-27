<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/backup_service.php';
require_once __DIR__.'/../includes/financial_totals.php';
if(!str_starts_with(setting('DB_NAME'),'vicar_revision_'))exit("Synthetic database required.\n");
$results=[];
function storage_check(string $name,bool $passed):void {global $results;$results[]=['test'=>$name,'passed'=>$passed];echo ($passed?'PASS ':'FAIL ').$name.PHP_EOL;}
$actor=sqlrow("SELECT id,role,parish_id FROM users WHERE role='admin' LIMIT 1");
try {
    $file=create_system_backup($actor);$path=private_path('backups/'.$file);
    storage_check('Real backup ZIP created',is_file($path)&&filesize($path)>0);
    $zip=new ZipArchive();$zip->open($path);$manifest=json_decode($zip->getFromName('manifest.json'),true);$data=json_decode($zip->getFromName('database.json'),true);
    $safe=true;for($i=0;$i<$zip->numFiles;$i++)if(preg_match('/config\.local|sessions\/|\.log$/',$zip->getNameIndex($i)))$safe=false;
    storage_check('Backup excludes configuration and session files',$safe);
    storage_check('Backup includes referenced documents',count($manifest['files'])>0);$zip->close();
    $complete=true;foreach($data['tables']['application_attachments']??[] as $attachment){$media=($attachment['storage_location']==='private'?'storage/private/':'uploads/requirements/').$attachment['stored_name'];if(!isset($manifest['files'][$media]))$complete=false;}
    storage_check('Backup includes every multi-attachment file',$complete);

    $before=(int)sqlrow('SELECT COUNT(*) n FROM applications')['n'];
    $conn->execute_query("INSERT INTO site_settings(setting_key,setting_value) VALUES('master_backup_probe','changed') ON DUPLICATE KEY UPDATE setting_value='changed'");
    restore_system_backup($actor,$path);
    storage_check('Backup round trip restores database',sqlrow("SELECT setting_value FROM site_settings WHERE setting_key='master_backup_probe'")===null && (int)sqlrow('SELECT COUNT(*) n FROM applications')['n']===$before);
    $missing=private_path('backups/missing-document-test-'.bin2hex(random_bytes(8)).'.zip');
    copy($path,$missing);$missingZip=new ZipArchive();$missingZip->open($missing);$missingManifest=$manifest;$missingManifest['files']=[];
    $missingZip->addFromString('manifest.json',json_encode($missingManifest));$missingZip->close();
    $rejected=false;try{restore_system_backup($actor,$missing);}catch(DomainException $e){$rejected=str_contains($e->getMessage(),'omits');}
    storage_check('Restore rejects omitted referenced documents',$rejected);unlink($missing);
    require_once __DIR__.'/../includes/analytics.php';
    $empty=parish_comparison('1901-01-01','1901-01-31');
    storage_check('Parish analytics honor empty historical date range',array_sum(array_column($empty,'apps'))===0 && (float)array_sum(array_column($empty,'revenue'))===0.0);
    $comparison=gen_parish_comparison('1900-01-01','9999-12-31');
    $allIncome=financial_totals('1900-01-01','9999-12-31')['verified_revenue'];
    storage_check('Parish report revenue reconciles with verified totals',abs(array_sum(array_column($comparison,'revenue'))-$allIncome)<0.001);
    $invalid=private_path('backups/invalid-test-'.bin2hex(random_bytes(8)).'.zip');
    foreach($data['tables']['applications'] as &$application){$application['user_id']=2147483000;break;}unset($application);
    $json=json_encode($data,JSON_THROW_ON_ERROR);$manifest['database_sha256']=hash('sha256',$json);
    copy($path,$invalid);$zip=new ZipArchive();$zip->open($invalid);$zip->addFromString('database.json',$json);$zip->addFromString('manifest.json',json_encode($manifest));$zip->close();
    $beforeHash=hash('sha256',json_encode($conn->query('SELECT * FROM applications ORDER BY id')->fetch_all(MYSQLI_ASSOC)));
    $rejected=false;try{restore_system_backup($actor,$invalid);}catch(DomainException $e){$rejected=true;}
    storage_check('Invalid relationship restore rolls back all rows',$rejected && $beforeHash===hash('sha256',json_encode($conn->query('SELECT * FROM applications ORDER BY id')->fetch_all(MYSQLI_ASSOC))));
    unlink($invalid);
    $totals=financial_totals('1900-01-01','9999-12-31',1);
    $income=(float)sqlrow("SELECT SUM(p.amount) n FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.parish_id=1 AND p.status IN ('completed','refunded') AND COALESCE(p.verified_at,p.paid_at) IS NOT NULL")['n'];
    storage_check('Verified revenue includes returned income before refund deduction',$totals['verified_revenue']===$income);
    storage_check('Net revenue deducts each expense category once',abs($totals['net_revenue']-($totals['verified_revenue']-$totals['refunds']-$totals['check_voucher']-$totals['petty_cash_voucher']-$totals['disbursement']))<0.001);
}catch(Throwable $e){storage_check('Storage workflow completes',false);fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);}
file_put_contents(APP_ROOT.'/master-storage-results.json',json_encode($results,JSON_PRETTY_PRINT));
exit(count(array_filter($results,fn($r)=>!$r['passed']))?1:0);
