<?php
require_once __DIR__ . '/../includes/auth.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (is_string($email) && is_string($password) && loginUser($email, $password)) {
        $destination = !empty($_SESSION['pending_2fa_user_id'])
            ? 'verify_otp.php'
            : userDashboardPath($_SESSION['user']['role'] ?? '');

        header('Location: ' . app_url($destination));
        exit;
    }

    $message = ($_SESSION['login_blocked_until']??0)>time() ? 'Too many incorrect attempts. Please try again in five minutes.' : 'Invalid email or password. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Apostolic Vicariate of San Jose</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/login.css">
<script src="../assets/js/login.js" defer></script>
<?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
</head>
<body>
<?php
$headerCtaHref = 'register.php'; $headerCtaLabel = 'Register'; $headerCtaIcon = 'user';
require APP_ROOT.'/includes/public_header.php';
?>

<!-- MAIN -->
<div class="page">

  <!-- LEFT — Welcome Panel -->
  <div class="panel-left">
    <div class="pl-grid"></div>
    <div class="welcome-card">
      <div class="welcome-cross">[icon:church]</div>
      <h2>Welcome to the Online Portal</h2>
      <p class="welcome-parish">St. Joseph the Worker Cathedral Parish</p>
      <div class="welcome-divider"></div>
      <p class="welcome-quote">
        "Whatever you do, work at it with all your heart,<br>as working for the Lord."
        <cite>— Colossians 3:23</cite>
      </p>
      <p class="welcome-body">
        This portal is a sacred extension of our parish — a place where faith meets service, and every parishioner is invited to walk more closely with Christ and His Church.
      </p>
      <ul class="welcome-list">
        <li>Apply for sacraments and parish services</li>
        <li>Reserve for Masses, events, and spiritual services</li>
        <li>Submit Mass intentions and pastoral inquiries</li>
        <li>View schedules for Masses, confessions, and activities</li>
        <li>Stay updated with parish announcements</li>
      </ul>
      <div class="welcome-footer">
        Whether you're renewing your commitment, seeking guidance, or simply staying connected — we welcome you with open arms and prayerful hearts.<br><br>
        <strong>Welcome home to your parish.</strong>
      </div>
    </div>
  </div>

  <!-- RIGHT — Login Panel -->
  <div class="panel-right">
    <div class="login-card">

      <div class="card-logo">
        <img src="../assets/img/logo-homepage.png" alt="Parish Logo" data-logo-fallback>
        <div class="card-logo-fb">AV</div>
      </div>

      <h1 class="card-title">Sign In</h1>
      <p class="card-subtitle">Access your parish account</p>

      <?php if ($message): ?>
        <div class="alert" role="alert"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email Address</label>
          <input
            type="email" id="email" name="email"
            placeholder="you@example.com"
            autocomplete="email" required>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input
            type="password" id="password" name="password"
            placeholder="••••••••"
            autocomplete="current-password" required>
        </div>
        <a href="forgot_password.php" class="forgot">Forgot password?</a>
        <button type="submit" name="login" class="btn-submit">Sign In to Your Account</button>
      </form>

      <div class="card-or">or</div>
      <p class="card-register">
        Don't have an account? <a href="signup.php">Create one here</a>
      </p>

      <div class="card-footer">
        By signing in you agree to our <a href="terms.php">Terms of Use</a> and acknowledge our <a href="privacy.php">Data Privacy Notice</a>.<br>
        &copy; <?php echo date('Y'); ?> Apostolic Vicariate of San Jose.
      </div>

    </div>
  </div>

</div>

</body>
</html>
