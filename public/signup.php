<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

const OTP_TTL_SECONDS  = 600; // 10 minutes
const OTP_RESEND_COOL  =  60; // 60 s between resends
const OTP_MAX_ATTEMPTS =   5;

$message      = '';
$message_type = 'error'; // error | info | success
$success      = false;
$step         = 'form'; // form | verify | done

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
    } elseif (!valid_mobile_number($phone)) {
        $message = 'Enter an 11-digit mobile number starting with 09, or its +63 equivalent.';
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
            if ($delivery['ok']) {
                $message      = 'Your verification email was accepted for sending. Check your inbox and spam folder.';
                $message_type = 'info';
            } else {
                $message = 'The verification email could not be sent. Wait one minute and use Resend code, or contact your parish.';
            }
        }
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
            $success      = true;
            $step         = 'done';
            $message      = 'Account verified and created. You can now sign in.';
            $message_type = 'success';
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

        if ($delivery['ok']) {
            $message      = 'A new verification email was accepted for sending.';
            $message_type = 'info';
        } else {
            $message = 'The email could not be sent. Wait one minute and retry, or contact your parish.';
        }
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
<title>Sign Up — Apostolic Vicariate of San Jose</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/login.css">
<script src="../assets/js/login.js" defer></script>
<style>
/* Small additions for the signup flow; everything else comes from login.css */
.alert.info    { background: #EEF3FB; border-color: #B9C8E4; color: #43658b; }
.alert.success { background: #edf2f8; border-color: #c4d8ef; color: #365675; }
.otp-input { font-family: monospace; font-size: 1.6rem; letter-spacing: .6em; text-align: center; padding: 14px 16px !important; }
.dev-hint { background: #FFF6D8; border: 1px solid #C9A84C; border-radius: 8px; padding: 10px 14px; font-size: .82rem; color: #6E5208; margin-bottom: 16px; text-align: left; }
.dev-hint code { font-family: monospace; font-size: 1.1rem; font-weight: 600; letter-spacing: .25em; }
.dev-hint small code { font-size: .85rem; letter-spacing: .05em; font-weight: 400; }
.aux-row { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: .85rem; }
.btn-link { background: none; border: none; color: #43658b; font: inherit; text-decoration: underline; cursor: pointer; padding: 0; }
.btn-link:hover { color: #C9A84C; }
.btn-as-link { display: block; text-align: center; text-decoration: none; line-height: 1.5; }
.verify-email { display: block; margin-top: 2px; color: #43658b; font-weight: 500; }
</style>
<?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
</head>
<body>

<?php
$headerCtaHref = 'login.php'; $headerCtaLabel = 'Sign In'; $headerCtaIcon = 'lock';
require APP_ROOT.'/includes/public_header.php';
?>

<!-- MAIN -->
<div class="page">

  <!-- LEFT — Welcome Panel -->
  <div class="panel-left">
    <div class="pl-grid"></div>
    <div class="welcome-card">
      <div class="welcome-cross">[icon:church]</div>
      <h2>Join the Online Portal</h2>
      <p class="welcome-parish">Apostolic Vicariate of San Jose in Occidental Mindoro</p>
      <div class="welcome-divider"></div>
      <p class="welcome-quote">
        "Just as each of us has one body with many members,<br>so in Christ we, though many, form one body."
        <cite>— Romans 12:4–5</cite>
      </p>
      <p class="welcome-body">
        Creating an account takes only a minute. Once verified, you can walk with your parish family wherever you are.
      </p>
      <ul class="welcome-list">
        <li>Enter your details and choose a password</li>
        <li>Receive a 6-digit code by email</li>
        <li>Verify your email to activate your account</li>
        <li>Apply for sacraments, reserve Masses, and more</li>
      </ul>
      <div class="welcome-footer">
        We only use your contact details to serve you and keep you informed about parish life.<br><br>
        <strong>Welcome to the family.</strong>
      </div>
    </div>
  </div>

  <!-- RIGHT — Signup Panel -->
  <div class="panel-right">
    <div class="login-card">

      <div class="card-logo">
        <img src="../assets/img/logo-homepage.png" alt="Parish Logo" data-logo-fallback>
        <div class="card-logo-fb">AV</div>
      </div>

      <?php if ($step === 'form'): ?>

        <h1 class="card-title">Create Account</h1>
        <p class="card-subtitle">We'll send a verification code to your email</p>

        <?php if ($message): ?>
          <div class="alert <?php echo htmlspecialchars($message_type); ?>" role="alert"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="signup.php" novalidate>
          <?= csrf_field() ?>
          <div class="field">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" placeholder="e.g. Juan dela Cruz"
                   autocomplete="name" required
                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" placeholder="you@example.com"
                   autocomplete="email" required
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="phone">Mobile Number</label>
            <input type="tel" id="phone" name="phone" placeholder="09XX XXX XXXX"
                   autocomplete="tel" maxlength="13" pattern="(?:09[0-9]{9}|[+]639[0-9]{9}|639[0-9]{9})" required
                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="At least 8 characters"
                   autocomplete="new-password" required>
          </div>
          <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password"
                   autocomplete="new-password" required>
          </div>
          <button type="submit" name="register" class="btn-submit">Send Verification Code</button>
        </form>

        <div class="card-or">or</div>
        <p class="card-register">
          Already have an account? <a href="login.php">Sign in here</a>
        </p>

      <?php elseif ($step === 'verify'): ?>

        <h1 class="card-title">Verify Your Email</h1>
        <p class="card-subtitle">
          Enter the 6-digit code we sent to
          <span class="verify-email"><?php echo htmlspecialchars($signup_email); ?></span>
        </p>

        <?php if ($message): ?>
          <div class="alert <?php echo htmlspecialchars($message_type); ?>" role="alert"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($dev_otp): ?>
          <div class="dev-hint">
            <strong>Development mode:</strong> email sending is disabled. Your OTP is
            <code><?php echo htmlspecialchars($dev_otp); ?></code><br>
            <small>(Set <code>EMAIL_ENABLED</code> to <code>true</code> in <code>includes/email_config.php</code> to send for real.)</small>
          </div>
        <?php endif; ?>

        <form method="POST" action="signup.php" novalidate>
          <?= csrf_field() ?>
          <div class="field">
            <label for="otp">Verification Code</label>
            <input type="text" id="otp" name="otp" class="otp-input"
                   inputmode="numeric" pattern="\d{6}" maxlength="6"
                   autocomplete="one-time-code" autofocus required>
          </div>
          <button type="submit" name="verify_otp" class="btn-submit">Verify &amp; Create Account</button>
        </form>

        <form method="POST" action="signup.php">
          <?= csrf_field() ?>
          <div class="aux-row">
            <button type="submit" name="resend_otp" class="btn-link">Resend code</button>
            <a class="btn-link" href="signup.php?cancel=1">Use a different email</a>
          </div>
        </form>

      <?php else: /* done */ ?>

        <h1 class="card-title">Welcome!</h1>
        <p class="card-subtitle">Your account is verified and ready</p>

        <?php if ($message): ?>
          <div class="alert <?php echo htmlspecialchars($message_type); ?>" role="alert"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <a href="login.php" class="btn-submit btn-as-link">Sign In to Your Account</a>

      <?php endif; ?>

      <div class="card-footer">
        By creating an account you agree to our terms of service.<br>
        &copy; <?php echo date('Y'); ?> Apostolic Vicariate of San Jose.
      </div>

    </div>
  </div>

</div>

</body>
</html>
