<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/notifications.php';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!rate_limit('reverify:' . $user['id'], 5, 300)) {
            throw new DomainException('Too many attempts. Try again in five minutes.');
        }
        $password = $_POST['password'] ?? '';
        if (!is_string($password) || strlen($password) > 1024) { throw new DomainException('Incorrect password.'); }
        $row = $conn->execute_query('SELECT password FROM users WHERE id=?', [$user['id']])->fetch_assoc();
        if (!password_verify($password, $row['password'])) { throw new DomainException('Incorrect password.'); }
        session_regenerate_id(true);
        $_SESSION['sensitive_user'] = (int) $user['id'];
        $_SESSION['sensitive_verified'] = true;
        unset($_SESSION['sensitive_until']);
        auditLog($user['id'], 'password_reverified', 'user', $user['id']);
        $return = $_SESSION['sensitive_return'] ?? '';
        unset($_SESSION['sensitive_return']);
        if (!str_starts_with($return, '/') || str_starts_with($return, '//') || str_contains($return, "\r") || str_contains($return, "\n") || str_contains($return, '\\')) {
            $return = app_url('admin/main_database.php');
        }
        header('Location: ' . $return);
        exit;
    } catch (DomainException $error) { $notice = $error->getMessage(); }
}
$page_title = t('Verify password');
require __DIR__ . '/includes/layout.php';
?>
<section class="card"><div class="card-body">
    <h1><?= h(t('Verify password')) ?></h1>
    <p><?= h(t('Verification remains valid until you sign out or your session expires.')) ?></p>
    <p role="alert"><?= h(t($notice)) ?></p>
    <form method="post"><?= csrf_field() ?>
        <div class="form-group"><label><?= h(t('Password')) ?><input type="password" name="password" autocomplete="current-password" required></label></div>
        <button class="btn-sm btn-navy"><?= h(t('Verify')) ?></button>
    </form>
</div></section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
