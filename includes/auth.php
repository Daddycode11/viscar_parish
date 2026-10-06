<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/revision_helpers.php';
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/navigation.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDirectory = private_path('sessions');
    if (!is_dir($sessionDirectory)) {
        mkdir($sessionDirectory, 0700, true);
    }
    session_save_path($sessionDirectory);
    ini_set('session.use_strict_mode', '1');
    // Keep PHP garbage collection from deleting sessions before our 30-minute idle limit.
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !APP_LOCAL,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function userDashboardPath(string $role): string
{
    $dashboards = [
        'parishioner' => 'parishioner/dashboard.php',
        'secretary' => 'staff/dashboard.php',
        'bookkeeper' => 'staff/dashboard.php',
        'admin' => 'admin/dashboard.php',
    ];

    return $dashboards[$role] ?? 'public/login.php';
}

function establishUser(array $row): void
{
    session_regenerate_id(true);
    unset($_SESSION['sensitive_until'], $_SESSION['sensitive_user'], $_SESSION['sensitive_verified']);
    unset($row['password']);
    $_SESSION['user'] = $row;
    $_SESSION['last_activity'] = time();
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['otp']);

    require_once __DIR__ . '/notifications.php';
    auditLog($row['id'], 'login', 'user', $row['id']);
}

function registerUser($name, $email, $phone, $password)
{
    global $conn;

    if (!trim($name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !valid_mobile_number($phone) || strlen($password) < 8) {
        return false;
    }

    try {
        return (bool) $conn->execute_query(
            "INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,'parishioner')",
            [$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]
        );
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function loginUser($email, $password)
{
    global $conn;

    $bucket=hash('sha256','login:'.strtolower(trim($email)).':'.($_SERVER['REMOTE_ADDR']??'local'));
    $conn->begin_transaction();
    try {
    $conn->execute_query('INSERT IGNORE INTO security_rate_limits(bucket,window_started) VALUES(?,?)',[$bucket,time()]);
    $attempts=$conn->execute_query('SELECT * FROM security_rate_limits WHERE bucket=? FOR UPDATE',[$bucket])->fetch_assoc();
    if((int)$attempts['attempts']>=3 && (int)$attempts['window_started']+300>time()) {
        $_SESSION['login_blocked_until']=(int)$attempts['window_started']+300;
        $conn->commit();return false;
    }
    $row = $conn->execute_query(
        "SELECT * FROM users WHERE email=? AND status='active'",
        [$email]
    )->fetch_assoc();

    if (!$row || !password_verify($password, $row['password'])) {
        unset($_SESSION['login_blocked_until']);
        $count=(int)$attempts['window_started']+300<=time()?1:(int)$attempts['attempts']+1;
        $conn->execute_query('UPDATE security_rate_limits SET attempts=?,window_started=? WHERE bucket=?',[$count,time(),$bucket]);
        $_SESSION['login_attempts']=$count;
        if ($count >= 3) {
            $_SESSION['login_blocked_until'] = time() + 300;
        }
        $conn->commit();
        return false;
    }
    $conn->execute_query('DELETE FROM security_rate_limits WHERE bucket=?',[$bucket]);
    $conn->commit();
    }catch(Throwable $error){$conn->rollback();throw $error;}
    unset($_SESSION['login_attempts'], $_SESSION['login_blocked_until']);

    if (!empty($row['two_factor_enabled'])) {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        $_SESSION['pending_2fa_user_id'] = (int) $row['id'];
        $_SESSION['pending_auth_version'] = (int) $row['auth_version'];
        generateAndSendOTP((int) $row['id']);
        return true;
    }

    establishUser($row);
    return true;
}

function generateAndSendOTP($id)
{
    global $conn;

    if ((int) ($_SESSION['pending_2fa_user_id'] ?? 0) !== (int) $id
        || ($_SESSION['otp']['sent'] ?? 0) > time() - 60) {
        return false;
    }

    $row = $conn->execute_query(
        "SELECT email FROM users WHERE id=? AND status='active'",
        [$id]
    )->fetch_assoc();
    if (!$row) {
        return false;
    }

    $code = (string) random_int(100000, 999999);
    $_SESSION['otp'] = [
        'hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires' => time() + 600,
        'attempts' => 0,
        'sent' => time(),
    ];

    require_once __DIR__ . '/notifications.php';
    $delivery = send_email(
        $row['email'],
        'Login verification code',
        'Your login code is ' . $code . '. It expires in ten minutes.'
    );
    $_SESSION['otp_delivery_ok'] = (bool) $delivery['ok'];
    return $delivery['ok'];
}

function verifyOTP($id, $code)
{
    global $conn;

    if ((int) ($_SESSION['pending_2fa_user_id'] ?? 0) !== (int) $id || empty($_SESSION['otp'])) {
        return false;
    }

    $otp = &$_SESSION['otp'];
    if ($otp['expires'] < time() || $otp['attempts'] >= 5) {
        unset($_SESSION['otp'], $_SESSION['pending_2fa_user_id']);
        return false;
    }

    $otp['attempts']++;
    if (!password_verify($code, $otp['hash'])) {
        return false;
    }

    $row = $conn->execute_query(
        "SELECT * FROM users WHERE id=? AND status='active'",
        [$id]
    )->fetch_assoc();
    if (!$row || (int) $row['auth_version'] !== (int) ($_SESSION['pending_auth_version'] ?? 0)) {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_auth_version'], $_SESSION['otp']);
        return false;
    }

    establishUser($row);
    return true;
}

function currentUser()
{
    global $conn;

    if (empty($_SESSION['user']['id'])) {
        return null;
    }

    if (($_SESSION['last_activity'] ?? time()) < time() - 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['last_activity'] = time();

    $row = $conn->execute_query(
        "SELECT id,name,email,phone,role,parish_id,status,profile_picture,language,auth_version FROM users WHERE id=? AND status='active'",
        [$_SESSION['user']['id']]
    )->fetch_assoc();
    if (!$row) {
        unset($_SESSION['user']);
        return null;
    }

    if ((int) ($_SESSION['user']['auth_version'] ?? 1) !== (int) $row['auth_version']) {
        $_SESSION = [];
        return null;
    }
    $_SESSION['user'] = $row;
    return $row;
}

function checkRole($role)
{
    $row = currentUser();
    if (!$row || !in_array($row['role'], (array) $role, true)) {
        header('Location: ' . app_url('public/login.php'));
        exit;
    }
}

function logoutUser()
{
    $_SESSION = [];
    session_destroy();
}
