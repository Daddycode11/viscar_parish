<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

const OTP_TTL_SECONDS  = 600; // 10 minutes
const OTP_RESEND_COOL  =  60; // 60 s between resends
const OTP_MAX_ATTEMPTS =   5;

$message = '';
$success = false;
$step    = 'form'; // form | verify | done

/* ─────────── STEP 1 — submit the registration form ─────────── */
if (isset($_POST['register'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$name || !$email || !$phone || !$password) {
        $message = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match. Please try again.';
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email=?");
        $check->bind_param('s', $email);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $message = 'This email is already registered. Try signing in instead.';
        } else {
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['signup'] = [
                'name'        => $name,
                'email'       => $email,
                'phone'       => $phone,
                'pass_hash'   => password_hash($password, PASSWORD_DEFAULT),
                'otp'         => $otp,
                'otp_expires' => time() + OTP_TTL_SECONDS,
                'sent_at'     => time(),
                'attempts'    => 0,
            ];

            $body = "Hi {$name},\n\n"
                  . "Your verification code is: {$otp}\n\n"
                  . "This code expires in 10 minutes. If you did not request this, you can safely ignore this email.\n\n"
                  . "— Apostolic Vicariate of San Jose";
            $delivery = send_email($email, 'Your verification code', $body);

            $step = 'verify';
            $message = $delivery['ok'] ? 'Your verification email was accepted for sending. Check your inbox and spam folder.' : 'The verification email could not be sent. Wait one minute and use Resend code, or contact your parish.';
        }
    }
    if ($message && $step === 'form') {
        // keep typed-in values for convenience
    }
}

/* ─────────── STEP 2 — verify the OTP ─────────── */
if (isset($_POST['verify_otp'])) {
    $entered = preg_replace('/\D+/', '', $_POST['otp'] ?? '');
    $session_signup = $_SESSION['signup'] ?? null;

    if (!$session_signup) {
        $message = 'Your verification session has expired. Please sign up again.';
        $step = 'form';
    } elseif (time() > ($session_signup['otp_expires'] ?? 0)) {
        $message = 'This code has expired. Click "Resend code" to get a new one.';
        $step = 'verify';
    } elseif (($session_signup['attempts'] ?? 0) >= OTP_MAX_ATTEMPTS) {
        unset($_SESSION['signup']);
        $message = 'Too many failed attempts. Please sign up again.';
        $step = 'form';
    } elseif ($entered !== ($session_signup['otp'] ?? '')) {
        $_SESSION['signup']['attempts'] = ($session_signup['attempts'] ?? 0) + 1;
        $remaining = OTP_MAX_ATTEMPTS - $_SESSION['signup']['attempts'];
        $message = "Incorrect code. {$remaining} attempt(s) remaining.";
        $step = 'verify';
    } else {
        // Code matches — create the account.
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'parishioner', 'active')");
        $stmt->bind_param('ssss',
            $session_signup['name'],
            $session_signup['email'],
            $session_signup['phone'],
            $session_signup['pass_hash']
        );
        if ($stmt->execute()) {
            unset($_SESSION['signup']);
            $success = true;
            $step = 'done';
            $message = 'Account verified and created. You can now sign in.';
        } else {
            $message = 'Failed to create your account. Please try again.';
            $step = 'verify';
        }
    }
}

/* ─────────── Resend OTP ─────────── */
if (isset($_POST['resend_otp'])) {
    $session_signup = $_SESSION['signup'] ?? null;
    if (!$session_signup) {
        $message = 'Your verification session has expired. Please sign up again.';
        $step = 'form';
    } elseif (time() - ($session_signup['sent_at'] ?? 0) < OTP_RESEND_COOL) {
        $wait = OTP_RESEND_COOL - (time() - $session_signup['sent_at']);
        $message = "Please wait {$wait} seconds before requesting another code.";
        $step = 'verify';
    } else {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['signup']['otp']         = $otp;
        $_SESSION['signup']['otp_expires'] = time() + OTP_TTL_SECONDS;
        $_SESSION['signup']['sent_at']     = time();
        $_SESSION['signup']['attempts']    = 0;

        $body = "Hi {$session_signup['name']},\n\n"
              . "Your new verification code is: {$otp}\n\n"
              . "This code expires in 10 minutes.\n\n"
              . "— Apostolic Vicariate of San Jose";
        $delivery = send_email($session_signup['email'], 'Your new verification code', $body);

        $message = $delivery['ok'] ? 'A new verification email was accepted for sending.' : 'The email could not be sent. Wait one minute and retry, or contact your parish.';
        $step = 'verify';
    }
}

/* If no POST but session has signup data, resume verification */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['signup'])) {
    $step = 'verify';
}

/* Cancel verification */
if (isset($_GET['cancel'])) {
    unset($_SESSION['signup']);
    $step = 'form';
}

$signup_email = $_SESSION['signup']['email'] ?? '';
$dev_otp      = (defined('EMAIL_ENABLED') && !EMAIL_ENABLED) ? ($_SESSION['signup']['otp'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up &mdash; Apostolic Vicariate of San Jose</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
body { font-family: 'DM Sans', sans-serif; background: #f4f6fa; margin: 0; }
.modal-overlay { position: fixed; inset: 0; background: rgba(27,42,74,.45); z-index: 9999; display: flex; align-items: center; justify-content: center; }
.modal-card { background: #fff; border-radius: 18px; padding: 32px 28px; box-shadow: 0 8px 32px rgba(27,42,74,.18); text-align: center; min-width: 320px; max-width: 90vw; }
.modal-icon { font-size: 2.5rem; margin-bottom: 12px; color: #2A5C3F; }
.modal-card.error .modal-icon { color: #6B2737; }
.modal-message { font-size: 1.1rem; margin-bottom: 18px; }
.modal-close { background: #1B2A4A; color: #fff; border: none; border-radius: 30px; padding: 10px 32px; font-size: 1rem; font-weight: 500; cursor: pointer; }
.modal-close:hover { background: #C9A84C; color: #1A1510; }
.form-container { max-width: 420px; margin: 60px auto; background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(27,42,74,.08); padding: 32px 28px; }
.form-title { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 600; margin-bottom: 4px; color: #1B2A4A; text-align: center; }
.form-sub { font-size: .85rem; color: #6b7280; text-align: center; margin-bottom: 20px; line-height: 1.5; }
.form-sub strong { color: #1B2A4A; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: .92rem; font-weight: 500; margin-bottom: 6px; color: #1B2A4A; }
.form-group input { width: 100%; padding: 10px 16px; border: 1.5px solid #e0e4ea; border-radius: 8px; font-size: 1rem; background: #f9fafc; box-sizing: border-box; }
.form-group input:focus { border-color: #C9A84C; outline: none; background: #fff; }
.btn-submit { width: 100%; padding: 12px; background: #1B2A4A; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 500; cursor: pointer; transition: background .2s; }
.btn-submit:hover { background: #C9A84C; color: #1A1510; }
.btn-link { background: none; border: none; color: #1B2A4A; font-size: .85rem; text-decoration: underline; cursor: pointer; padding: 0; }
.btn-link:hover { color: #C9A84C; }
.otp-input { font-family: monospace; font-size: 1.6rem; letter-spacing: .6em; text-align: center; padding: 14px 16px !important; }
.aux-row { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: .82rem; }
.dev-hint { background: #FFF6D8; border: 1px solid #C9A84C; border-radius: 8px; padding: 10px 14px; font-size: .82rem; color: #6E5208; margin-bottom: 16px; }
.dev-hint code { font-family: monospace; font-size: 1.1rem; font-weight: 600; letter-spacing: .25em; }
.foot-link { text-align: center; margin-top: 18px; font-size: .85rem; color: #6b7280; }
.foot-link a { color: #1B2A4A; font-weight: 500; }
</style>
<?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
</head>
<body>
<div class="form-container">

  <?php if ($step === 'form'): ?>
    <div class="form-title">Create Account</div>
    <div class="form-sub">We'll send a verification code to your email.</div>
    <form method="POST" action="signup.php" novalidate><?= csrf_field() ?>
      <div class="form-group">
        <label for="name">Full Name</label>
        <input type="text" id="name" name="name" placeholder="e.g. Juan dela Cruz" autocomplete="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label for="phone">Mobile Number</label>
        <input type="tel" id="phone" name="phone" placeholder="09XX XXX XXXX" autocomplete="tel" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="At least 6 characters" autocomplete="new-password" required>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password" required>
      </div>
      <button type="submit" name="register" class="btn-submit">Send verification code</button>
    </form>
    <div class="foot-link">Already have an account? <a href="login.php">Sign in</a></div>

  <?php elseif ($step === 'verify'): ?>
    <div class="form-title">Verify your email</div>
    <div class="form-sub">Enter the 6-digit code we sent to<br><strong><?php echo htmlspecialchars($signup_email); ?></strong></div>

    <?php if ($dev_otp): ?>
    <div class="dev-hint">
      <strong>Development mode:</strong> email sending is disabled. Your OTP is <code><?php echo htmlspecialchars($dev_otp); ?></code><br>
      <span style="font-size:.75rem">(Set <code style="font-size:.85rem;letter-spacing:.05em">EMAIL_ENABLED</code> to <code style="font-size:.85rem;letter-spacing:.05em">true</code> in <code style="font-size:.85rem;letter-spacing:.05em">includes/email_config.php</code> to send for real.)</span>
    </div>
    <?php endif; ?>

    <form method="POST" action="signup.php" novalidate><?= csrf_field() ?>
      <div class="form-group">
        <label for="otp">Verification Code</label>
        <input type="text" id="otp" name="otp" class="otp-input" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" autofocus>
      </div>
      <button type="submit" name="verify_otp" class="btn-submit">Verify &amp; Create Account</button>
    </form>

    <form method="POST" action="signup.php" style="margin-top:14px"><?= csrf_field() ?>
      <div class="aux-row">
        <button type="submit" name="resend_otp" class="btn-link">Resend code</button>
        <a class="btn-link" href="signup.php?cancel=1">Use a different email</a>
      </div>
    </form>

  <?php else: /* done */ ?>
    <div class="form-title">Welcome!</div>
    <div class="form-sub">Your account is verified and ready.</div>
    <a href="login.php" class="btn-submit" style="display:block;text-align:center;text-decoration:none;line-height:1.5">Sign In</a>
  <?php endif; ?>

</div>

<script>
function showModal(type, message) {
  const modal = document.createElement('div');
  modal.className = 'modal-overlay';
  modal.innerHTML = `
    <div class="modal-card ${type}">
      <div class="modal-icon">${type === 'success' ? '[icon:check]' : '[icon:alert]'}</div>
      <div class="modal-message">${message}</div>
      <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">OK</button>
    </div>`;
  document.body.appendChild(modal);
}
window.onload = function() {
  var msg = <?php echo json_encode($message); ?>;
  var success = <?php echo json_encode($success); ?>;
  if (msg && !success && msg.indexOf('We sent') !== 0 && msg.indexOf('A new code') !== 0) {
    showModal('error', msg);
  } else if (success) {
    showModal('success', msg || 'Account created!');
  }
};
</script>
</body>
</html>
