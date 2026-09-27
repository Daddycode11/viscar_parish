<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/password_recovery.php';
$notice='';$success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $token=input_text($_POST,'token',64);
        $password=$_POST['password']??'';$confirmation=$_POST['confirmation']??'';
        if (!is_string($password)||!is_string($confirmation)) { throw new DomainException('Invalid password.'); }
        reset_password($token,$password,$confirmation);
        $success=true;$notice='Password reset. You can now sign in.';
    } catch(Throwable $error) { http_response_code(422);$notice=$error instanceof DomainException?$error->getMessage():'Unable to reset password.'; }
}
$title='Reset password';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
require __DIR__.'/../includes/recovery_form.php';
