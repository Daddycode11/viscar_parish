<?php
require_once __DIR__ . '/settings_service.php';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = input_text($_POST, '_action');
        switch ($action) {
            case 'save_profile': update_profile($user, $_POST); $notice = 'Profile saved.'; break;
            case 'change_password': update_password($user, $_POST); $notice = 'Password changed.'; break;
            case 'save_preferences': update_subscriptions($user, $_POST); $notice = 'Preferences saved.'; break;
            case 'save_system': save_site_settings($user, $_POST); $notice = 'Homepage settings saved.'; break;
            case 'save_parish':
                if ($user['role'] !== 'secretary') { throw new DomainException('Secretary access required.'); }
                $values = [];
                foreach (['parish_name','location','address','contact_number','parish_email','priest_name'] as $key) { $values[] = input_text($_POST, $key); }
                if ($values[0] === '' || ($values[4] !== '' && !filter_var($values[4], FILTER_VALIDATE_EMAIL))) { throw new DomainException('Enter valid parish details.'); }
                $values[] = save_validated_image('parish_logo', 'parish_logos');
                $values[] = $user['parish_id'];
                revision_transaction(function () use ($conn, $values, $user) {
                    $conn->execute_query('UPDATE parishes SET name=?,location=?,address=?,contact_number=?,email=?,priest_name=?,logo=COALESCE(?,logo) WHERE id=?', $values);
                    auditLog($user['id'], 'update_parish', 'parish', $user['parish_id']);
                });
                $notice = 'Saved'; break;
            default: throw new DomainException('Invalid settings action.');
        }
        $_SESSION['settings_notice'] = $notice;
        header('Location: settings.php', true, 303);
        exit;
    } catch (Throwable $error) {
        http_response_code(422);
        $notice = $error instanceof DomainException ? $error->getMessage() : 'Unable to save settings.';
    }
}
$notice = $_SESSION['settings_notice'] ?? $notice;
unset($_SESSION['settings_notice']);
$user = currentUser();
$page_id = 'settings'; $page_title = t('Settings');
$folder = $user['role'] === 'admin' ? 'admin' : ($user['role'] === 'parishioner' ? 'parishioner' : 'staff');
require APP_ROOT . '/' . $folder . '/includes/layout.php';
?>
<h1><?= h(t('Settings')) ?></h1><p role="status"><?= h(t($notice)) ?></p>
<section class="card"><div class="card-body">
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_profile">
    <div class="profile-avatar"><?= profile_avatar($user) ?></div>
    <?php foreach (['name'=>'Name','email'=>'Email','phone'=>'Phone'] as $key=>$label): ?>
    <div class="form-group"><label><?= h(t($label)) ?><input name="<?= h($key) ?>" type="<?= $key === 'email' ? 'email' : 'text' ?>" value="<?= h($user[$key]) ?>" <?= $key !== 'phone' ? 'required' : '' ?>></label></div>
    <?php endforeach; ?>
    <div class="form-group"><label><?= h(t('Profile picture')) ?><input type="file" name="profile_picture" accept="image/png,image/jpeg,image/webp,image/gif"></label><small>JPG / PNG / WEBP / GIF, 5 MB</small></div>
    <div class="form-group"><label><?= h(t('Language')) ?><select name="language"><option value="en" <?= $user['language']==='en'?'selected':'' ?>>English</option><option value="fil" <?= $user['language']==='fil'?'selected':'' ?>>Filipino</option></select></label></div>
    <button class="btn-sm btn-navy"><?= h(t('Save')) ?></button>
</form></div></section>
<section class="card"><div class="card-body"><h2><?= h(t('Change password')) ?></h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="change_password">
<?php foreach (['current_password'=>'Current password','new_password'=>'New password','confirm_password'=>'Confirm password'] as $key=>$label): ?>
<div class="form-group"><label><?= h(t($label)) ?><input type="password" name="<?= h($key) ?>" autocomplete="<?= $key==='current_password'?'current-password':'new-password' ?>" required></label></div>
<?php endforeach; ?><button class="btn-sm btn-navy"><?= h(t('Save')) ?></button></form></div></section>
<?php if ($user['role']==='parishioner'):
$subscriptions = [];
foreach ($conn->execute_query('SELECT * FROM parish_subscriptions WHERE user_id=?', [$user['id']]) as $row) { $subscriptions[$row['parish_id']]=$row; }
?>
<section class="card"><div class="card-body"><h2><?= h(t('Parish preferences')) ?></h2>
<p><?= h(t('Select your parishes and delivery channels in Settings. Clear the selections to unsubscribe from parish announcements. System-wide notices remain available.')) ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save_preferences">
<?php foreach ($conn->query("SELECT id,name FROM parishes WHERE status='active' ORDER BY name") as $parish): ?>
<fieldset><legend><?= h($parish['name']) ?></legend>
<?php foreach (['in_app'=>'In-app','email'=>'Email','sms'=>'SMS'] as $channel=>$label): ?>
<label><input type="checkbox" name="subscriptions[<?= (int)$parish['id'] ?>][<?= $channel ?>]" value="1" <?= !empty($subscriptions[$parish['id']][$channel])?'checked':'' ?>> <?= h(t($label)) ?></label>
<?php endforeach; ?></fieldset><?php endforeach; ?>
<button class="btn-sm btn-navy"><?= h(t('Save')) ?></button></form></div></section>
<?php endif; ?>
<?php if ($user['role']==='admin'): $settings=site_settings(); ?>
<section class="card"><div class="card-body"><h2><?= h(t('Homepage settings')) ?></h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="_action" value="save_system">
<?php foreach (['site_name'=>'Site name','hero_headline'=>'Hero title','hero_subtitle'=>'Hero subtitle','hero_badge'=>'Homepage text','homepage_text'=>'Homepage text','contact_details'=>'Contact details'] as $key=>$label): ?>
<div class="form-group"><label><?= h(t($label)) ?><textarea name="<?= $key ?>" maxlength="5000"><?= h($settings[$key]) ?></textarea></label></div>
<?php endforeach; ?>
<?php foreach (['color_navy','color_gold','color_wine'] as $key): ?><label><?= h(t('Primary color')) ?> (<?= h($key) ?>)<input type="color" name="<?= $key ?>" value="<?= h($settings[$key]) ?>"></label><?php endforeach; ?>
<?php foreach (['site_logo'=>'Website logo','favicon'=>'Favicon','hero_bg_image'=>'Homepage image'] as $key=>$label): ?><div class="form-group"><label><?= h(t($label)) ?><input type="file" name="<?= $key ?>" accept="image/png,image/jpeg,image/webp,image/gif"></label></div><?php endforeach; ?>
<button class="btn-sm btn-navy"><?= h(t('Save')) ?></button></form></div></section>
<?php endif; ?>
<?php if ($user['role']==='secretary'): $parish=$conn->execute_query('SELECT * FROM parishes WHERE id=?',[$user['parish_id']])->fetch_assoc(); ?>
<section class="card"><div class="card-body"><h2><?= h(t('Parish details')) ?></h2><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="_action" value="save_parish">
<?php foreach (['parish_name'=>'name','location'=>'location','address'=>'address','contact_number'=>'contact_number','parish_email'=>'email','priest_name'=>'priest_name'] as $field=>$column): ?>
<div class="form-group"><label><?= h(t(ucwords(str_replace('_',' ',$column)))) ?><input name="<?= $field ?>" value="<?= h($parish[$column]) ?>"></label></div>
<?php endforeach; ?><input type="file" name="parish_logo" accept="image/png,image/jpeg,image/webp,image/gif"><button class="btn-sm btn-navy"><?= h(t('Save')) ?></button></form></div></section>
<?php endif; require APP_ROOT . '/' . $folder . '/includes/layout_footer.php'; ?>
