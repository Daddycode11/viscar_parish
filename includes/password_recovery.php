<?php
require_once __DIR__.'/notifications.php';

function request_password_reset(string $email): void
{
    global $conn;
    $ip=$_SERVER['REMOTE_ADDR']??'local';
    if (!rate_limit('reset-ip:'.$ip,10,900) || !rate_limit('reset-email:'.strtolower($email),3,900)) { return; }
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { return; }
    $user=$conn->execute_query("SELECT id,email FROM users WHERE email=? AND status='active'",[$email])->fetch_assoc();
    if (!$user) { return; }
    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    revision_transaction(function() use($conn,$user,$hash){
        $conn->execute_query('SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);
        $conn->execute_query('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL',[$user['id']]);
        $conn->execute_query('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$user['id'],$hash]);
        auditLog($user['id'],'request_password_reset','user',$user['id']);
    });
    // URL fragments are not sent to web servers or included in HTTP access logs.
    $link=app_url('public/reset_password.php').'#token='.$token;
    $result=send_email($user['email'],'Reset your password','Use this link within 30 minutes to reset your password: '.$link);
    if (!$result['ok']) {
        $conn->execute_query('UPDATE password_resets SET used_at=NOW() WHERE token_hash=?',[$hash]);
        error_log('Password reset email unavailable; check mail configuration.');
    }
}

function reset_password(string $token,string $password,string $confirmation): void
{
    global $conn;
    if (!preg_match('/^[a-f0-9]{64}$/',$token)) { throw new DomainException('Invalid or expired reset link.'); }
    if (strlen($password)<8 || strlen($password)>72 || $password!==$confirmation) { throw new DomainException('Use matching passwords of 8 to 72 characters.'); }
    if (!rate_limit('reset-submit:'.($_SERVER['REMOTE_ADDR']??'local'),10,900)) { throw new DomainException('Too many attempts. Try again later.'); }
    revision_transaction(function() use($conn,$token,$password){
        $hash=hash('sha256',$token);
        $pre=$conn->execute_query('SELECT user_id FROM password_resets WHERE token_hash=?',[$hash])->fetch_assoc();
        if (!$pre) { throw new DomainException('Invalid or expired reset link.'); }
        $user=$conn->execute_query("SELECT id FROM users WHERE id=? AND status='active' FOR UPDATE",[$pre['user_id']])->fetch_assoc();
        $reset=$conn->execute_query('SELECT id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[$hash])->fetch_assoc();
        if (!$reset || !$user) { throw new DomainException('Invalid or expired reset link.'); }
        $conn->execute_query('UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);
        $conn->execute_query('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL',[$user['id']]);
        auditLog($user['id'],'reset_password','user',$user['id']);
    });
    unset($_SESSION['user'],$_SESSION['pending_2fa_user_id'],$_SESSION['otp'],$_SESSION['sensitive_until'], $_SESSION['sensitive_verified']);
    session_regenerate_id(true);
}
