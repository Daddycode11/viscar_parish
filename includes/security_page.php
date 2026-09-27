<?php
$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $row=$conn->execute_query('SELECT password FROM users WHERE id=?',[$user['id']])->fetch_assoc();
 if(!password_verify($_POST['password']??'',$row['password']))$notice='Current password is incorrect.';
 else{$enabled=isset($_POST['enabled'])?1:0;$conn->execute_query('UPDATE users SET two_factor_enabled=? WHERE id=?',[$enabled,$user['id']]);require_once __DIR__.'/notifications.php';auditLog($user['id'],'change_2fa','user',$user['id']);$notice='Login verification preference saved.';}
}
$enabled=(bool)$conn->execute_query('SELECT two_factor_enabled FROM users WHERE id=?',[$user['id']])->fetch_assoc()['two_factor_enabled'];
$section=$user['role']==='parishioner'?'parishioner':($user['role']==='admin'?'admin':'staff');$page_title='Login Security';$page_id='security';require APP_ROOT.'/'.$section.'/includes/layout.php';
?><div class="card"><div class="card-body"><h2>Two-factor login verification</h2><p><?=htmlspecialchars($notice)?></p><p>When enabled, signing in requires your password and a six-digit email code.</p><form method="post"><div class="form-group"><label><input type="checkbox" name="enabled" <?=$enabled?'checked':''?>> Require an email code at login</label></div><div class="form-group"><label>Current password</label><input type="password" name="password" required autocomplete="current-password"></div><button class="btn-sm btn-navy">Save security preference</button></form></div></div><?php require APP_ROOT.'/'.$section.'/includes/layout_footer.php';?>
