<?php
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/site_settings.php';

function update_profile(array $actor, array $input): void
{
    global $conn;
    $name = input_text($input, 'name');
    $email = input_text($input, 'email');
    $phone = input_text($input, 'phone', 50);
    $language = input_text($input, 'language');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($language, ['en', 'fil'], true)) {
        throw new DomainException('Enter a name, valid email and language.');
    }
    if ($conn->execute_query('SELECT id FROM users WHERE email=? AND id<>?', [$email, $actor['id']])->fetch_row()) {
        throw new DomainException('That email is already in use.');
    }
    $picture = save_validated_image('profile_picture', 'profile_pictures');
    try {
        revision_transaction(function () use ($conn, $actor, $name, $email, $phone, $language, $picture) {
            $conn->execute_query('UPDATE users SET name=?,email=?,phone=?,language=?,profile_picture=COALESCE(?,profile_picture) WHERE id=?', [$name, $email, $phone, $language, $picture, $actor['id']]);
            auditLog($actor['id'], 'update_profile', 'user', $actor['id']);
        });
    } catch (Throwable $error) {
        if ($picture) { unlink(APP_ROOT . '/' . $picture); }
        throw $error;
    }
    currentUser();
}

function update_password(array $actor, array $input): void
{
    global $conn;
    $current = $input['current_password'] ?? '';
    $password = $input['new_password'] ?? '';
    if (!is_string($current) || !is_string($password) || strlen($password) < 8 || strlen($password) > 72
        || $password !== ($input['confirm_password'] ?? '')) {
        throw new DomainException('Use matching passwords of 8 to 72 characters.');
    }
    if (!rate_limit('change_password:' . $actor['id'], 5, 300)) { throw new DomainException('Too many attempts. Try again in five minutes.'); }
    $row = $conn->execute_query('SELECT password FROM users WHERE id=?', [$actor['id']])->fetch_assoc();
    if (!password_verify($current, $row['password'])) { throw new DomainException('Incorrect password.'); }
    revision_transaction(function () use ($conn, $actor, $password) {
        $conn->execute_query('UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $actor['id']]);
        $conn->execute_query('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL', [$actor['id']]);
        auditLog($actor['id'], 'change_password', 'user', $actor['id']);
    });
    $_SESSION['user']['auth_version'] = (int) $actor['auth_version'] + 1;
    unset($_SESSION['sensitive_until'], $_SESSION['sensitive_user'], $_SESSION['sensitive_verified']);
    session_regenerate_id(true);
}

function update_subscriptions(array $actor, array $input): void
{
    global $conn;
    if ($actor['role'] !== 'parishioner') { throw new DomainException('Parishioner access required.'); }
    $selection = $input['subscriptions'] ?? [];
    if (!is_array($selection)) { throw new DomainException('Invalid parish selection.'); }
    $active = array_column($conn->query("SELECT id FROM parishes WHERE status='active'")->fetch_all(MYSQLI_ASSOC), 'id');
    foreach ($selection as $id => $channels) {
        if (!ctype_digit((string) $id) || !in_array((int) $id, $active) || !is_array($channels)
            || array_diff(array_keys($channels), ['in_app', 'email', 'sms'])) {
            throw new DomainException('Invalid parish selection.');
        }
    }
    revision_transaction(function () use ($conn, $actor, $selection) {
        $conn->execute_query('DELETE FROM parish_subscriptions WHERE user_id=?', [$actor['id']]);
        foreach ($selection as $id => $channels) {
            $conn->execute_query('INSERT INTO parish_subscriptions(user_id,parish_id,in_app,email,sms) VALUES(?,?,?,?,?)', [$actor['id'], $id, isset($channels['in_app']) ? 1 : 0, isset($channels['email']) ? 1 : 0, isset($channels['sms']) ? 1 : 0]);
        }
        auditLog($actor['id'], 'update_subscriptions', 'user', $actor['id']);
    });
}
