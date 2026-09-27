<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/password_recovery.php';
$notice='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try { request_password_reset(input_text($_POST,'email')); }
    catch(Throwable $error) { error_log('Password recovery request could not be processed.'); }
    $notice='If the address is registered, a reset link will be sent.';
}
$title='Forgot password';
require __DIR__.'/../includes/recovery_form.php';
