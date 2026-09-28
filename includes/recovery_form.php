<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(t($title)) ?></title>
<link rel="stylesheet" href="<?= h(app_url('assets/css/login.css')) ?>"><?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
</head><body class="recovery-page"><main class="login-card recovery-card">
<?= navigation_controls('public/login.php') ?>
<div class="card-logo"><img src="<?= h(app_url('assets/img/logo-homepage.png')) ?>" alt="Apostolic Vicariate of San Jose"></div><h1 class="card-title"><?= h(t($title)) ?></h1><p role="status"><?= h(t($notice)) ?></p>
<?php if(empty($success)): ?><form method="post"><?= csrf_field() ?>
<?php if($title==='Forgot password'): ?><label><?= h(t('Email')) ?><input name="email" type="email" required maxlength="255"></label><button><?= h(t('Send reset link')) ?></button>
<?php else: ?><input type="hidden" name="token" id="reset-token" value="<?= h($token??'') ?>"><label><?= h(t('New password')) ?><input name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label><label><?= h(t('Confirm password')) ?><input name="confirmation" type="password" autocomplete="new-password" required></label><button><?= h(t('Reset password')) ?></button><script>
const token = new URLSearchParams(location.hash.slice(1)).get('token');
if (token && /^[a-f0-9]{64}$/.test(token)) document.getElementById('reset-token').value = token;
history.replaceState(null, '', location.pathname);
</script><?php endif; ?></form><?php endif; ?><a href="login.php"><?= h(t('Sign In')) ?></a></main></body></html>
