<?php
require_once __DIR__.'/workflows.php';
require_once __DIR__.'/revision_helpers.php';
require_once __DIR__.'/translations.php';

function publish_announcement(array $actor,array $input): array
{
    global $conn;
    must(in_array($actor['role'],['admin','secretary'],true),'Announcement publishing is restricted.');
    $title=input_text($input,'subject') ?: input_text($input,'title');
    $content=input_text($input,'message',10000) ?: input_text($input,'content',10000);
    must($title!=='' && $content!=='','Enter a title and message.');
    $target=$actor['role']==='secretary'?'Selected Parishes':input_text($input,'target');
    $parishes=$input['parish_ids']??[];
    must(is_array($parishes),'Invalid parish selection.');
    if ($actor['role']==='secretary') {
        must(!$parishes || array_unique(array_map('intval',$parishes))===[(int)$actor['parish_id']],'Secretaries may target only their assigned parish.');
        $parishes=[(int)$actor['parish_id']];
    } elseif (!in_array($target,['All Parishes','All Parishioners','All Staff','Staff Only','Selected Parishes'],true)) {
        $parish=sqlrow("SELECT id FROM parishes WHERE name=? AND status='active'",[$target]);
        must($parish!==null,'Select a valid audience.');$parishes=[(int)$parish['id']];$target='Selected Parishes';
    }
    $parishes=array_values(array_unique(array_map('intval',$parishes)));sort($parishes);
    if($target==='Selected Parishes') {
        must(count($parishes)>0,'Select at least one parish.');
        $active=array_map('intval',array_column($conn->query("SELECT id FROM parishes WHERE status='active'")->fetch_all(MYSQLI_ASSOC),'id'));
        must(!array_diff($parishes,$active),'Select active parishes.');
    } else { $parishes=[]; }
    $channels=['in-app'];$channel=input_text($input,'channel')?:'In-App';
    must(in_array($channel,['In-App','SMS','Email','All Channels'],true),'Select a valid channel.');
    if(!empty($input['send_sms'])||in_array($channel,['SMS','All Channels'],true))$channels[]='sms';
    if(!empty($input['send_email'])||in_array($channel,['Email','All Channels'],true))$channels[]='email';
    $requestKey=input_text($input,'request_key');
    if($requestKey==='') { $requestKey=hash('sha256',json_encode([$title,$content,$target,$parishes,$channels])); }
    must((bool)preg_match('/^[a-f0-9]{64}$/',$requestKey),'Reload the announcement form.');
    $key=hash('sha256',$actor['id'].':'.$requestKey);
    $id=revision_transaction(function() use($conn,$actor,$key,$title,$content,$target,$parishes,$channels,$channel){
        // Serialize repeated submissions by the author, then inspect the durable idempotency key.
        $conn->execute_query('SELECT id FROM users WHERE id=? FOR UPDATE',[$actor['id']]);
        $existing=sqlrow('SELECT announcement_id FROM announcement_dispatches WHERE request_key=?',[$key]);
        if($existing) { return (int)$existing['announcement_id']; }
        $conn->execute_query("INSERT INTO announcements(title,content,message,target,channel,parish_id,sent_by,created_by,status,sent_at) VALUES(?,?,?,?,?,?,?,?,'active',NOW())",[$title,$content,$content,$target,$channel,count($parishes)===1?$parishes[0]:null,$actor['id'],$actor['id']]);
        $id=(int)$conn->insert_id;
        $conn->execute_query('INSERT INTO announcement_dispatches(request_key,announcement_id) VALUES(?,?)',[$key,$id]);
        foreach($parishes as $pid) { $conn->execute_query('INSERT INTO announcement_parishes(announcement_id,parish_id) VALUES(?,?)',[$id,$pid]); }
        if($target==='Selected Parishes') {
            $placeholders=implode(',',array_fill(0,count($parishes),'?'));
            $recipients=$conn->execute_query("SELECT u.id,MAX(s.in_app) in_app,MAX(s.email) email,MAX(s.sms) sms FROM users u JOIN parish_subscriptions s ON s.user_id=u.id WHERE u.status='active' AND u.role='parishioner' AND s.parish_id IN ($placeholders) GROUP BY u.id",$parishes)->fetch_all(MYSQLI_ASSOC);
        } else {
            $roleFilter=match($target){'All Parishioners'=>" AND role='parishioner'",'All Staff','Staff Only'=>" AND role IN ('secretary','bookkeeper','admin')",default=>''};
            $recipients=$conn->query("SELECT id,1 in_app,1 email,1 sms FROM users WHERE status='active'$roleFilter")->fetch_all(MYSQLI_ASSOC);
        }
        foreach($recipients as $recipient) {
            foreach($channels as $deliveryChannel) {
                $preference=$deliveryChannel==='in-app'?'in_app':$deliveryChannel;
                if(!$recipient[$preference])continue;
                $state='queued';
                if($deliveryChannel==='in-app') {
                    notify($recipient['id'],$title,$content,'announcement','announcements.php');$state='sent';
                }
                $conn->execute_query('INSERT INTO announcement_deliveries(announcement_id,user_id,channel,status) VALUES(?,?,?,?)',[$id,$recipient['id'],$deliveryChannel,$state]);
            }
        }
        auditLog($actor['id'],'publish_announcement','announcement',$id);
        return $id;
    });
    // Production bulk delivery runs through the CLI worker so publishing cannot time out.
    if (APP_LOCAL) dispatch_announcement($id);
    $statuses=$conn->execute_query('SELECT status,COUNT(*) total FROM announcement_deliveries WHERE announcement_id=? GROUP BY status',[$id])->fetch_all(MYSQLI_ASSOC);
    $parts=[];foreach($statuses as $status) { $parts[]=t(ucfirst($status['status'])).': '.$status['total']; }
    return ['id'=>$id,'message'=>t('Announcement saved.').' '.($parts?implode('; ',$parts):t('No subscribed recipients.')),'delivery'=>$statuses,'notification_message'=>($parts ? implode('; ', $parts) : t('No subscribed recipients.'))];
}

function dispatch_announcement(int $id, int $limit = 100): void
{
    global $conn;
    $limit = max(1, min(100, $limit));
    $rows=$conn->execute_query("SELECT d.*,u.email,u.phone,a.title,a.content FROM announcement_deliveries d JOIN users u ON u.id=d.user_id JOIN announcements a ON a.id=d.announcement_id WHERE d.announcement_id=? AND a.status='active' AND d.status='queued' ORDER BY d.id LIMIT $limit",[$id])->fetch_all(MYSQLI_ASSOC);
    foreach($rows as $row) {
        $conn->execute_query("UPDATE announcement_deliveries SET status='sending',updated_at=NOW() WHERE id=? AND status='queued'",[$row['id']]);
        if($conn->affected_rows!==1)continue;
        try {
            $result=$row['channel']==='email'?send_email($row['email'],$row['title'],$row['content']):send_sms($row['phone'],$row['title'].': '.$row['content']);
            $status=$result['ok']?($result['status']??'accepted'):'failed';
        } catch(Throwable $error) { $status='failed'; }
        // Do not log provider bodies, credentials, recipient addresses or message contents.
        $conn->execute_query('UPDATE announcement_deliveries SET status=?,error_code=?,updated_at=NOW() WHERE id=?',[$status,$status==='failed'?'transport_unavailable_or_rejected':null,$row['id']]);
    }
}
