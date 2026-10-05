<?php
require_once __DIR__.'/workflows.php';

function backup_schema(): array
{
    global $conn;$tables=[];
    foreach($conn->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $r) {
        must($r['ENGINE']==='InnoDB','Backup/restore requires transactional InnoDB tables.');
        $name=$r['TABLE_NAME'];must((bool)preg_match('/^[a-zA-Z0-9_]+$/',$name),'Unsupported table name.');
        $tables[$name]=$conn->execute_query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$name])->fetch_all(MYSQLI_ASSOC);
    }
    return $tables;
}

function backup_media_paths(array $data): array
{
    $paths=[];
    foreach($data['applications']??[] as $a) foreach(json_decode($a['uploaded_files']??'{}',true)?:[] as $name) {
        must(is_string($name)&&basename($name)===$name,'Invalid stored document path.');
        $path=is_file(private_path($name))?'storage/private/'.$name:'uploads/requirements/'.$name;
        must(is_file(APP_ROOT.'/'.$path),'A referenced document is missing. Repair document storage before backup.');$paths[$path]=true;
    }
    foreach($data['application_attachments']??[] as $attachment) {
        $name=$attachment['stored_name'];must(is_string($name)&&basename($name)===$name,'Invalid attachment path.');
        must(in_array($attachment['storage_location'],['private','legacy'],true),'Invalid attachment storage location.');
        $paths[($attachment['storage_location']==='private'?'storage/private/':'uploads/requirements/').$name]=true;
    }
    foreach(['payments'=>'proof_file','parish_payment_methods'=>'qr_file'] as $table=>$column)foreach($data[$table]??[] as $row)if(!empty($row[$column]))$paths['storage/private/'.basename($row[$column])]=true;
    foreach($data['accounting_documents']??[] as $d) if(!empty($d['attachment'])) $paths['storage/private/'.basename($d['attachment'])]=true;
    if(is_dir(APP_ROOT.'/uploads')) foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/uploads',FilesystemIterator::SKIP_DOTS)) as $f) {
        if($f->isFile()&&!$f->isLink()&&preg_match('/\.(png|jpe?g|gif|webp|pdf)$/i',$f->getFilename())) $paths[str_replace('\\','/',substr($f->getPathname(),strlen(APP_ROOT)+1))]=true;
    }
    return array_keys($paths);
}

function create_system_backup(array $actor): string
{
    global $conn;must($actor['role']==='admin','Administrator access required.');$schema=backup_schema();$data=[];
    $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {foreach($schema as $table=>$columns)$data[$table]=$conn->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC);$conn->commit();}catch(Throwable $e){$conn->rollback();throw $e;}
    $dir=private_path('backups');if(!is_dir($dir))mkdir($dir,0700,true);
    $filename='backup-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.zip';$path=$dir.'/'.$filename;
    $zip=new ZipArchive();must($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)===true,'Unable to create backup.');
    try {
        $json=json_encode(['schema'=>$schema,'tables'=>$data],JSON_THROW_ON_ERROR);
        $manifest=['format'=>'VISCAR-backup','version'=>1,'created_at'=>date('c'),'database_sha256'=>hash('sha256',$json),'files'=>[]];$zip->addFromString('database.json',$json);
        foreach(backup_media_paths($data) as $media) {must(is_file(APP_ROOT.'/'.$media),'Required document missing.');$manifest['files'][$media]=hash_file('sha256',APP_ROOT.'/'.$media);$zip->addFile(APP_ROOT.'/'.$media,'media/'.$media);}
        $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));must($zip->close(),'Unable to finish backup.');
    }catch(Throwable $e){@$zip->close();if(is_file($path))unlink($path);throw $e;}
    auditLog($actor['id'],'create_backup','system',null,$filename);return $filename;
}

function restore_system_backup(array $actor,string $path): void
{
    global $conn;must($actor['role']==='admin','Administrator access required.');$zip=new ZipArchive();must($zip->open($path)===true,'Invalid backup archive.');$created=[];
    try {
        $size=0;for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$size+=$stat['size'];must($size<=268435456,'Expanded backup exceeds 256 MB.');}
        $manifest=json_decode($zip->getFromName('manifest.json')?:'',true,512,JSON_THROW_ON_ERROR);$raw=$zip->getFromName('database.json');
        must(($manifest['format']??'')==='VISCAR-backup'&&($manifest['version']??0)===1&&is_string($raw)&&hash_equals($manifest['database_sha256']??'',hash('sha256',$raw)),'Invalid backup format or checksum.');
        $database=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$schema=backup_schema();
        must(($database['schema']??null)===$schema && array_keys($database['tables']??[])===array_keys($schema),'Backup schema differs from this installation. Apply matching migrations first.');
        $current=sqlrow('SELECT id,password FROM users WHERE id=?',[$actor['id']]);$found=false;
        foreach($database['tables']['users'] as $u) if((int)$u['id']===(int)$current['id']&&$u['role']==='admin'&&$u['status']==='active'&&hash_equals($current['password'],$u['password']))$found=true;
        must($found,'Backup must contain this active administrator with the same password.');
        foreach($schema as $table=>$columns){$names=array_column($columns,'COLUMN_NAME');must(is_array($database['tables'][$table]),'Invalid table data.');foreach($database['tables'][$table] as $row)must(is_array($row)&&array_keys($row)===$names,'Invalid backup columns.');}
        foreach($manifest['files']??[] as $media=>$hash){
            must((bool)preg_match('~^(?:uploads/(?:[a-zA-Z0-9_-]+/)*|storage/private/)[a-zA-Z0-9_.-]+\.(?:png|jpe?g|gif|webp|pdf)$~i',$media)&&!str_contains($media,'..'),'Invalid backup file path.');
            $content=$zip->getFromName('media/'.$media);must(is_string($content)&&is_string($hash)&&hash_equals($hash,hash('sha256',$content)),'Backup document checksum failed.');$target=APP_ROOT.'/'.$media;
            must(!is_file($target)||hash_equals($hash,hash_file('sha256',$target)),'An existing document differs. Restore to a separate installation.');
        }
        // A valid checksum alone must not allow an archive to omit a referenced document.
        must(is_array($manifest['files']??null),'Missing backup file manifest.');
        foreach($database['tables']['applications'] as $application) {
            $documents=json_decode($application['uploaded_files']?:'{}',true);
            must(is_array($documents),'Invalid application document list.');
            foreach($documents as $document) {
                must(is_string($document)&&basename($document)===$document,'Invalid application document path.');
                must(isset($manifest['files']['storage/private/'.$document])||isset($manifest['files']['uploads/requirements/'.$document]),'Backup omits an application document.');
            }
        }
        foreach($database['tables']['accounting_documents'] as $document) if(!empty($document['attachment'])) {
            must(basename($document['attachment'])===$document['attachment']&&isset($manifest['files']['storage/private/'.$document['attachment']]),'Backup omits an accounting attachment.');
        }
        foreach($database['tables']['application_attachments']??[] as $attachment) {
            $name=$attachment['stored_name'];must(is_string($name)&&basename($name)===$name&&in_array($attachment['storage_location'],['private','legacy'],true),'Invalid attachment path.');
            $media=($attachment['storage_location']==='private'?'storage/private/':'uploads/requirements/').$name;
            must(isset($manifest['files'][$media]),'Backup omits an application attachment.');
        }
        foreach(['payments'=>'proof_file','parish_payment_methods'=>'qr_file'] as $table=>$column)foreach($database['tables'][$table]??[] as $row)if(!empty($row[$column]))must(basename($row[$column])===$row[$column]&&isset($manifest['files']['storage/private/'.$row[$column]]),'Backup omits a payment document.');
        create_system_backup($actor);
        foreach($manifest['files']??[] as $media=>$hash){$target=APP_ROOT.'/'.$media;if(!is_file($target)){if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);must(file_put_contents($target,$zip->getFromName('media/'.$media),LOCK_EX)!==false,'Unable to restore a document.');$created[]=$target;}}
        $conn->query('SET FOREIGN_KEY_CHECKS=0');$conn->begin_transaction();
        try {
            foreach($schema as $table=>$columns){$conn->query("DELETE FROM `$table`");$names=array_column($columns,'COLUMN_NAME');$sql="INSERT INTO `$table` (`".implode('`,`',$names).'`) VALUES ('.implode(',',array_fill(0,count($names),'?')).')';foreach($database['tables'][$table] as $row)$conn->execute_query($sql,array_values($row));}
            foreach($conn->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL") as $fk){[$t,$c,$rt,$rc]=array_values($fk);must(!$conn->query("SELECT 1 FROM `$t` a LEFT JOIN `$rt` b ON a.`$c`=b.`$rc` WHERE a.`$c` IS NOT NULL AND b.`$rc` IS NULL LIMIT 1")->fetch_row(),'Backup contains invalid record relationships.');}
            $conn->query('UPDATE users SET auth_version=auth_version+1');auditLog($actor['id'],'restore_backup','system');$conn->commit();
        }catch(Throwable $e){$conn->rollback();throw $e;}finally{$conn->query('SET FOREIGN_KEY_CHECKS=1');}
    }catch(Throwable $e){foreach($created as $file)if(is_file($file))unlink($file);throw $e;}finally{$zip->close();}
}
