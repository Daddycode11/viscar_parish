<?php
/** Normalize PHP single/multiple upload shapes, then reuse the existing per-file validator. */
function validate_attachment_groups(array $expected,array $files): array {
 $groups=[];$count=0;
 foreach($files as $key=>$unused)must(isset($expected[$key]),'Unexpected uploaded document.');
 foreach($expected as $key=>$rule) {
  $f=$files[$key]??null;$items=[];
  if($f && is_array($f['name']??null)) {
   foreach($f['name'] as $index=>$name) {
    must(is_int($index)&&is_string($name),'Invalid attachment list.');$item=[];
    foreach(['name','tmp_name','size','error','type'] as $part){must(isset($f[$part][$index])&&!is_array($f[$part][$index]),'Invalid attachment data.');$item[$part]=$f[$part][$index];}
    $items[]=$item;
   }
  } elseif($f) $items[]=$f;
  $groups[$key]=[];
  foreach($items as $item) {
   if(($item['error']??null)===UPLOAD_ERR_NO_FILE)continue;
   must(is_string($item['tmp_name']??null)&&is_uploaded_file($item['tmp_name']),'Invalid uploaded document.');
   $valid=validate_uploaded_files([$key=>['required'=>true,'label'=>$rule['label']]],[$key=>$item]);
   must(filesize($item['tmp_name'])<=5*1024*1024,'Each attachment must be at most 5 MB.');
   $groups[$key][]=$valid[$key];$count++;
  }
  must(!$rule['required']||count($groups[$key])>0,'Upload required: '.$rule['label']);
 }
 must($count<=20,'Upload at most 20 files per submission.');
 if(isset($_POST['attachment_count']))must(ctype_digit((string)$_POST['attachment_count'])&&(int)$_POST['attachment_count']===$count,'Some selected files were not received. Reduce the upload size or file count.');
 return $groups;
}
function save_attachment_groups(array $groups): array {
 $rows=[];$primary=[];
 foreach($groups as $key=>$files)foreach($files as $file) {
  $saved=save_uploaded_files([$key=>$file]);$name=$saved[$key];
  $original=basename(str_replace('\\','/',$file['name']));
  $original=preg_replace('/[\x00-\x1f\x7f]/u','',$original)?:'document';
  $rows[]=['key'=>$key,'original'=>mb_substr($original,0,255),'stored'=>$name,'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file(private_path($name)),'size'=>filesize(private_path($name))];
  $primary[$key]??=$name;
 }
 return ['rows'=>$rows,'primary'=>$primary];
}
function insert_attachment_rows(int $applicationId,array $rows):void {
 global $conn;
 foreach($rows as $r)$conn->execute_query('INSERT INTO application_attachments(application_id,requirement_key,original_name,stored_name,mime_type,size_bytes) VALUES(?,?,?,?,?,?)',[$applicationId,$r['key'],$r['original'],$r['stored'],$r['mime'],$r['size']]);
}
function application_attachments(array $application):array {
 global $conn;
 $rows=$conn->execute_query('SELECT * FROM application_attachments WHERE application_id=? ORDER BY requirement_key,id',[$application['id']])->fetch_all(MYSQLI_ASSOC);
 // Keep pre-migration links and legacy imports visible without creating duplicate rows.
 foreach(json_decode($application['uploaded_files']??'{}',true)?:[] as $key=>$name) {
  $exists=false;foreach($rows as $r)if($r['requirement_key']===(string)$key&&$r['stored_name']===$name)$exists=true;
  if(!$exists&&is_string($name))$rows[]=['id'=>null,'requirement_key'=>(string)$key,'original_name'=>$name,'stored_name'=>$name];
 }
 return $rows;
}
function attachment_query(int $app,array $attachment):string {
 return 'app='.$app.(!empty($attachment['id'])?'&attachment='.(int)$attachment['id']:'&key='.rawurlencode($attachment['requirement_key']));
}
function render_requirement_attachments(array $application,array $labels=[],?string $onlyKey=null):void {
 $groups=[];foreach(application_attachments($application) as $row)$groups[$row['requirement_key']][]=$row;
 foreach($groups as $key=>$rows) {
  if($onlyKey!==null&&$key!==$onlyKey)continue;
  echo '<section><h3>'.h($labels[$key]??'Submitted document').'</h3><ul>';
  foreach($rows as $row){$query=attachment_query((int)$application['id'],$row);echo '<li style="overflow-wrap:anywhere">'.h($row['original_name']).' ? <a href="'.h(app_url('public/document_view.php?'.$query)).'">View</a> <a href="'.h(app_url('public/document.php?download=1&'.$query)).'">Download</a></li>';}
  echo '</ul></section>';
 }
}
