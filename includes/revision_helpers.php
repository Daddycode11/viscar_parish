<?php

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input_text(array $input, string $key, int $max = 255): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || mb_strlen($value) > $max) {
        throw new DomainException('Invalid value: ' . $key);
    }
    return trim($value);
}

function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="_csrf" value="' . h($_SESSION['csrf']) . '">';
}

function verify_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit(t('Session verification failed. Reload the page and retry.'));
    }
}

function revision_transaction(callable $operation)
{
    global $conn;
    $conn->begin_transaction();
    try {
        $result = $operation();
        $conn->commit();
        return $result;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function valid_mobile_number(string $phone): bool
{
    return preg_match('/^(?:09[0-9]{9}|\+639[0-9]{9}|639[0-9]{9})$/',trim($phone))===1;
}

function rate_limit(string $scope, int $limit, int $seconds): bool
{
    global $conn;
    $bucket = hash('sha256', $scope);
    $now = time();
    return revision_transaction(function () use ($conn, $bucket, $now, $limit, $seconds) {
        $conn->execute_query('INSERT IGNORE INTO security_rate_limits(bucket,window_started) VALUES(?,?)', [$bucket, $now]);
        $row = $conn->execute_query('SELECT * FROM security_rate_limits WHERE bucket=? FOR UPDATE', [$bucket])->fetch_assoc();
        $count = $row['window_started'] + $seconds <= $now ? 0 : (int) $row['attempts'];
        $started = $count === 0 ? $now : $row['window_started'];
        if ($count >= $limit) { return false; }
        $conn->execute_query('UPDATE security_rate_limits SET attempts=?,window_started=? WHERE bucket=?', [$count + 1, $started, $bucket]);
        return true;
    });
}

function require_sensitive_verification(array $actor): void
{
    if (!in_array($actor['role'], ['secretary', 'bookkeeper', 'admin'], true)) { return; }
    if (($_SESSION['sensitive_user'] ?? 0) === (int) $actor['id']
        && !empty($_SESSION['sensitive_verified'])) { return; }
    if (isset($_GET['ajax']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Verify your password before accessing sensitive information.', 'verification_url' => app_url(($actor['role']==='admin'?'admin':'staff').'/verify_password.php')]);
        exit;
    }
    $return = $_SERVER['REQUEST_URI'] ?? '';
    $_SESSION['sensitive_return'] = $return;
    header('Location: ' . app_url(($actor['role']==='admin'?'admin':'staff').'/verify_password.php'));
    exit;
}

function save_validated_image(string $field, string $directory): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if (!is_array($file) || ($file['error'] ?? -1) !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])
        || $file['size'] > 5 * 1024 * 1024) {
        throw new DomainException('Upload an image smaller than 5 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!$info || !isset($extensions[$mime]) || $info['mime'] !== $mime || $info[0] * $info[1] > 16000000) {
        throw new DomainException('Upload a valid JPG, PNG, WEBP or GIF image up to 16 megapixels.');
    }
    $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$image) { throw new DomainException('The image could not be decoded.'); }
    $path = 'uploads/' . $directory . '/' . bin2hex(random_bytes(16)) . '.png';
    if (!is_dir(APP_ROOT . '/uploads/' . $directory)) { mkdir(APP_ROOT . '/uploads/' . $directory, 0755, true); }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $saved = imagepng($image, APP_ROOT . '/' . $path);
    imagedestroy($image);
    if (!$saved) { throw new RuntimeException('Image storage unavailable.'); }
    return $path;
}

function profile_avatar(array $actor): string
{
    $path = $actor['profile_picture'] ?? '';
    if (preg_match('~^uploads/profile_pictures/[a-zA-Z0-9_.-]+$~', $path)) {
        return '<img src="' . h(app_url($path)) . '" alt="' . h($actor['name']) . '" style="width:100%;height:100%;object-fit:cover;border-radius:50%">';
    }
    return h(mb_strtoupper(mb_substr($actor['name'] ?? '', 0, 1)));
}
