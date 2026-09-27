<?php
require_once __DIR__ . '/auth.php';

/** Shared authorization for the viewer and the raw file endpoint. */
function application_document(): array
{
    global $conn;
    $user = currentUser();
    if (!$user) { http_response_code(401); exit; }
    require_sensitive_verification($user);
    $id = (int) ($_GET['app'] ?? 0);
    $key = $_GET['key'] ?? '';
    $application = $conn->execute_query('SELECT * FROM applications WHERE id=?', [$id])->fetch_assoc();
    $allowed = $application && ($user['role'] === 'admin'
        || ($user['role'] === 'parishioner' && (int)$application['user_id'] === (int)$user['id'])
        || ($user['role'] === 'secretary' && (int)$application['parish_id'] === (int)$user['parish_id']));
    if (!$allowed || !is_string($key)) { http_response_code(404); exit; }
    $attachmentId=(int)($_GET['attachment']??0);
    $attachment=$attachmentId?$conn->execute_query('SELECT * FROM application_attachments WHERE id=? AND application_id=?',[$attachmentId,$id])->fetch_assoc():null;
    if($attachmentId&&!$attachment){http_response_code(404);exit;}
    $files = json_decode($application['uploaded_files'] ?? '{}', true);
    $name = $attachment['stored_name'] ?? ($files[$key] ?? '');
    $key = $attachment['requirement_key']??$key;
    if (!is_string($name) || !$name || basename($name) !== $name) { http_response_code(404); exit; }
    $path = private_path($name);
    if (($attachment['storage_location']??'')==='legacy' || (!$attachment && !is_file($path))) $path = APP_ROOT . '/uploads/requirements/' . $name;
    if (!is_file($path)) { http_response_code(404); exit; }
    if ($user['role']==='admin') { require_once __DIR__.'/notifications.php'; auditLog($user['id'],'central_document_access','application',$id,$key); }
    return ['user'=>$user, 'application'=>$application, 'key'=>$key, 'name'=>$name,
        'attachment_id'=>$attachmentId,'original_name'=>$attachment['original_name']??$name,'path'=>$path, 'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($path)];
}
