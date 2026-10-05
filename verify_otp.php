<?php
require_once __DIR__.'/includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['cancel_login'])) {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_auth_version'], $_SESSION['otp'], $_SESSION['otp_delivery_ok']);
        header('Location: ' . app_url('public/login.php'), true, 303);
        exit;
    }
}

// Must have a pending 2FA login (password already verified in login.php)
if (empty($_SESSION['pending_2fa_user_id'])) {
    header('Location: '.app_url('public/login.php'));
    exit;
}
$pending_id = (int) $_SESSION['pending_2fa_user_id'];

$message = empty($_SESSION['otp_delivery_ok']) ? "The verification email could not be sent. Wait one minute and retry, or contact your parish." : "";
$notice  = "";

if (isset($_POST['verify_otp'])) {
    $code = trim($_POST['otp'] ?? '');
    if (verifyOTP($pending_id, $code)) {
        header('Location: ' . app_url(userDashboardPath($_SESSION['user']['role'] ?? '')));
        exit;
    } else {
        $message = "Invalid or expired code. Please try again.";
    }
}

if (isset($_POST['resend_otp'])) {
    if (generateAndSendOTP($pending_id)) {
        $notice = "A new code was accepted for sending. Check your email and spam folder.";
        $message = '';
    } else {
        $message = "Unable to send a new code. Wait one minute before retrying; contact your parish if this continues.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Login — Apostolic Vicariate of San Jose</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
  --gold: #C9A84C; --gold-lt: #E8C97A; --navy: #43658b; --navy-deep: #365675;
  --cream: #FAF7F2; --ink: #1A1510; --ink-70: rgba(26,21,16,.7); --ink-40: rgba(26,21,16,.4);
  --ink-10: rgba(26,21,16,.08); --white: #FFFFFF; --fh: "Cormorant Garamond", Georgia, serif;
  --fb: "DM Sans", sans-serif; --ease: .3s cubic-bezier(.4,0,.2,1); --r: 16px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--fb); min-height: 100vh; display: grid; place-items: center;
  background: radial-gradient(circle at 30% 20%, #23335a 0%, var(--navy-deep) 70%);
  padding: 24px;
}
.card {
  width: 100%; max-width: 400px; background: var(--white); border-radius: var(--r);
  padding: 40px 32px; box-shadow: 0 20px 60px rgba(0,0,0,.35); text-align: center;
}
.icon { font-size: 2.4rem; margin-bottom: 10px; }
h1 { font-family: var(--fh); font-size: 1.7rem; color: var(--navy); margin-bottom: 6px; }
p.sub { color: var(--ink-70); font-size: .88rem; margin-bottom: 24px; }
.field { text-align: left; margin-bottom: 16px; }
label { display: block; font-size: .75rem; font-weight: 500; color: var(--ink-70); margin-bottom: 6px; }
input[type="text"] {
  width: 100%; padding: 14px; border: 1px solid var(--ink-10); border-radius: 10px;
  font-size: 1.3rem; letter-spacing: 6px; text-align: center; font-weight: 600; color: var(--navy);
}
input[type="text"]:focus { outline: none; border-color: var(--gold); }
.btn { width: 100%; padding: 13px; border: none; border-radius: 10px; background: var(--navy);
  color: var(--white); font-weight: 500; font-size: .9rem; cursor: pointer; transition: var(--ease); }
.btn:hover { background: var(--navy-deep); }
.btn-link { background: none; border: none; color: var(--navy); font-size: .8rem; text-decoration: underline;
  cursor: pointer; margin-top: 14px; }
.alert { background: #FBEAEA; color: #8C2A2A; padding: 10px 14px; border-radius: 8px; font-size: .82rem; margin-bottom: 16px; }
.notice { background: #edf2f8; color: #365675; padding: 10px 14px; border-radius: 8px; font-size: .82rem; margin-bottom: 16px; }
</style>
<link rel="stylesheet" href="assets/css/palette.css"></head>
<body>
  <div class="card">
    <div class="icon">[icon:lock]</div>
    <h1>Two-Factor Verification</h1>
    <p class="sub">Enter the 6-digit code sent to your registered email address. Enter it below to finish signing in.</p>

    <?php if ($message): ?><div class="alert"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="notice"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      <div class="field">
        <label for="otp">Verification Code</label>
        <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" required autofocus>
      </div>
      <button type="submit" name="verify_otp" class="btn">Verify &amp; Continue</button>
    </form>
    <form method="POST">
      <?= csrf_field() ?>
      <button type="submit" name="resend_otp" class="btn-link">Resend code</button>
    </form>
    <form method="POST">
      <?= csrf_field() ?>
      <button type="submit" name="cancel_login" class="btn-link">Cancel sign-in / Back to login</button>
    </form>
  </div>
</body>
</html>
