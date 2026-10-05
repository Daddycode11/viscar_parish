<?php
/**
 * Notification Helper — Apostolic Vicariate of San Jose
 * Creates notifications for users across the system.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms_config.php';
require_once __DIR__ . '/email_config.php';

// Role → channels the role is permitted to dispatch through.
const ROLE_CHANNELS = [
    'admin'      => ['in-app', 'sms', 'email'],
    'secretary'  => ['in-app', 'sms', 'email'],
    'bookkeeper' => ['in-app', 'sms', 'email'],
];

// Recipient scopes each role can target.
const ROLE_TARGETS = [
    'admin'      => ['all_staff', 'all_parishioners', 'role', 'parish', 'user'],
    'secretary'  => ['parish_parishioners', 'user'],   // own parish only
    'bookkeeper' => ['user'],                          // individual parishioners (payment-related)
];

function role_can_channel($role, $channel) {
    return in_array($channel, ROLE_CHANNELS[$role] ?? [], true);
}

function role_can_target($role, $target) {
    return in_array($target, ROLE_TARGETS[$role] ?? [], true);
}

/**
 * Send a notification to a specific user
 */
function notify($user_id, $title, $message, $type = 'system', $link = null) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $user_id, $title, $message, $type, $link);
    return $stmt->execute();
}

/**
 * Send an SMS via iProgSMS API.
 * Returns [ok => bool, response => mixed]. Number is normalized to 09XXXXXXXXX.
 */
function send_sms($number, $message) {
    $number = trim((string)$number);
    if(!preg_match('/^(?:09[0-9]{9}|\+639[0-9]{9}|639[0-9]{9})$/',$number))return ['ok'=>false,'response'=>'invalid_number','status'=>'failed'];
    if (strpos($number, '+63') === 0) $number = '0' . substr($number, 3);
    elseif (strpos($number, '63') === 0 && strlen($number) === 12) $number = '0' . substr($number, 2);
    if (!preg_match('/^09\d{9}$/', $number)) {
        return ['ok' => false, 'response' => 'invalid_number:' . $number];
    }

    if (APP_LOCAL) { file_put_contents(private_path('sms.log'), json_encode(['development'=>true,'to'=>$number,'message'=>$message,'at'=>date('c')]).PHP_EOL, FILE_APPEND | LOCK_EX); return ['ok'=>true,'response'=>'development log only','status'=>'simulated']; }
    if (!IPROGSMS_API_TOKEN || !function_exists('curl_init')) { return ['ok'=>false,'response'=>'sms_not_configured','status'=>'failed']; }
    $payload = json_encode([
        'api_token'    => IPROGSMS_API_TOKEN,
        'phone_number' => $number,
        'message'      => mb_substr($message, 0, 1000),
        'sms_provider' => IPROGSMS_PROVIDER,
    ]);

    $ch = curl_init(IPROGSMS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode(is_string($res) ? $res : '', true);
    $ok = ($code >= 200 && $code < 300 && !$err && is_array($decoded) && isset($decoded['status']) && (int)$decoded['status'] === 200);

    return [
        'ok'       => $ok,
        'response' => $err ?: ($decoded ?: $res),
        'http'     => $code,
    ];
}

/**
 * Send via configured SMTP; local development simulates delivery in private storage.
 */
function send_email($to, $subject, $body) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'response' => 'invalid_email:' . $to];
    }

    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.5">'
          . nl2br(htmlspecialchars($body))
          . '<hr style="margin-top:24px"><p style="font-size:12px;color:#888">Apostolic Vicariate of San Jose &mdash; please do not reply to this address.</p></div>';

    if (!EMAIL_ENABLED && !APP_LOCAL) { return ['ok'=>false,'response'=>'email_not_configured','status'=>'failed']; }
    if (APP_LOCAL) {
        $logDir = private_path();
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        @file_put_contents(
            $logDir . '/email.log',
            '[' . date('c') . "] TO:$to\nSUBJECT:$subject\n$body\n----\n",
            FILE_APPEND
        );
        return ['ok' => true, 'response' => 'logged', 'status' => 'simulated'];
    }

    require_once __DIR__ . '/smtp_mailer.php';
    $r = smtp_send([
        'host'     => SMTP_HOST,
        'port'     => SMTP_PORT,
        'tls'      => SMTP_TLS,
        'user'     => SMTP_USER,
        'pass'     => SMTP_PASS,
        'from'     => [EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME],
        'reply_to' => EMAIL_REPLY_TO,
        'to'       => $to,
        'subject'  => $subject,
        'html'     => $html,
        'text'     => $body,
    ]);

    if (!$r['ok']) {
        $logDir = private_path();
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        error_log('SMTP delivery failed. Check provider configuration.');
    }
    return ['ok' => $r['ok'], 'response' => $r['ok'] ? 'sent' : ($r['error'] ?? 'smtp_failed')];
}

/**
 * Dispatch a notification to a single user across the requested channels.
 * $channels is any subset of ['in-app','sms','email']. Returns counts.
 */
function delivery_counts(): array {
    return ['in_app' => 0, 'sms_sent' => 0, 'sms_failed' => 0, 'sms_simulated' => 0,
        'email_sent' => 0, 'email_failed' => 0, 'email_simulated' => 0];
}

function delivery_feedback(array $counts): string {
    $parts = [];
    if (!empty($counts['in_app'])) $parts[] = 'In-app saved: ' . $counts['in_app'];
    foreach (['sms' => 'SMS', 'email' => 'Email'] as $channel => $label) {
        foreach (['sent' => 'accepted by provider', 'failed' => 'failed', 'simulated' => 'simulated locally'] as $key => $description) {
            if (!empty($counts[$channel . '_' . $key])) {
                $parts[] = $label . ' ' . $description . ': ' . $counts[$channel . '_' . $key];
            }
        }
    }
    return implode('; ', $parts) . ($parts ? '.' : 'No recipients.');
}

function dispatch_to_user($user_id, $title, $message, array $channels = ['in-app'], $type = 'system', $link = null) {
    global $conn;
    $result = delivery_counts();
    if (in_array('in-app', $channels, true) && notify($user_id, $title, $message, $type, $link)) {
        $result['in_app']++;
    }
    if (!array_intersect(['sms', 'email'], $channels)) return $result;
    $row = $conn->execute_query("SELECT phone, email FROM users WHERE id=? AND status='active'", [$user_id])->fetch_assoc();
    foreach (['sms' => 'phone', 'email' => 'email'] as $channel => $column) {
        if (!in_array($channel, $channels, true)) continue;
        $state = 'failed';
        if ($row && trim((string) $row[$column]) !== '') {
            try {
                $delivery = $channel === 'sms'
                    ? send_sms($row[$column], $title . ': ' . $message)
                    : send_email($row[$column], $title, $message);
                if ($delivery['ok']) $state = ($delivery['status'] ?? '') === 'simulated' ? 'simulated' : 'sent';
            } catch (Throwable $error) {
                error_log('Notification transport unavailable: ' . $channel);
            }
        }
        if ($state === 'failed') error_log('Notification delivery failed: user='.(int)$user_id.' channel='.$channel.' type='.$type);
        $result[$channel . '_' . $state]++;
    }
    return $result;
}

/** Call only after commit; failure cannot roll back a saved business transaction. */
function dispatch_after_commit(): array
{
    $totals=delivery_counts();$queue=$GLOBALS['after_commit']??[];$GLOBALS['after_commit']=[];
    foreach($queue as $send) {
        try {foreach(($send()??[]) as $key=>$count)if(array_key_exists($key,$totals))$totals[$key]+=$count;}
        catch(Throwable $e){error_log('Deferred notification delivery failed.');$totals['email_failed']++;}
    }
    return $totals;
}

/** Dispatch to authorized recipients selected by the caller. */
function dispatch_to_query($sql, array $params, $types, $title, $message, array $channels, $type = 'system', $link = null) {
    global $conn;
    $totals = ['recipients' => 0] + delivery_counts();
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    foreach ($stmt->get_result() as $recipient) {
        $totals['recipients']++;
        foreach (dispatch_to_user($recipient['id'], $title, $message, $channels, $type, $link) as $key => $count) {
            $totals[$key] += $count;
        }
    }
    return $totals;
}

/**
 * Resolve admin announcement targets to (sql, params, types). Roles checked by caller.
 */
function resolve_admin_target($target) {
    if ($target === 'All Parishioners' || $target === 'all_parishioners') {
        return ["SELECT id, phone, email FROM users WHERE status='active' AND role='parishioner'", [], ''];
    }
    if ($target === 'All Staff' || $target === 'all_staff') {
        return ["SELECT id, phone, email FROM users WHERE status='active' AND role IN ('secretary','bookkeeper','admin')", [], ''];
    }
    if ($target === 'All Parishes') {
        return ["SELECT id, phone, email FROM users WHERE status='active'", [], ''];
    }
    if ($target === 'Staff Only') {
        return ["SELECT id, phone, email FROM users WHERE status='active' AND role IN ('secretary','bookkeeper','admin')", [], ''];
    }
    // specific parish name
    return [
        "SELECT u.id, u.phone, u.email FROM users u JOIN parishes p ON u.parish_id = p.id WHERE u.status='active' AND p.name=?",
        [$target], 's'
    ];
}

/**
 * @deprecated kept for backwards compatibility — prefer dispatch_to_user().
 */
function notifyWithSMS($user_id, $title, $message, $type = 'system', $link = null) {
    return dispatch_to_user($user_id, $title, $message, ['in-app', 'sms'], $type, $link);
}

/**
 * Send notification to all users with a specific role
 */
function notifyRole($role, $title, $message, $type = 'system', $link = null) {
    global $conn;
    $r = $conn->prepare("SELECT id FROM users WHERE role=? AND status='active'");
    $r->bind_param('s', $role);
    $r->execute();
    $users = $r->get_result();
    while ($u = $users->fetch_assoc()) {
        notify($u['id'], $title, $message, $type, $link);
    }
}

/**
 * Send notification to all staff of a parish
 */
function notifyParishStaff($parish_id, $title, $message, $type = 'system', $link = null) {
    global $conn;
    $r = $conn->prepare("SELECT id FROM users WHERE parish_id=? AND role IN ('secretary','bookkeeper') AND status='active'");
    $r->bind_param('i', $parish_id);
    $r->execute();
    $users = $r->get_result();
    while ($u = $users->fetch_assoc()) {
        notify($u['id'], $title, $message, $type, $link);
    }
}

/**
 * Get unread notification count for a user
 */
function getUnreadCount($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
}

/**
 * Mark notification as read
 */
function markNotificationRead($id, $user_id) {
    global $conn;
    $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $id, $user_id);
    return $stmt->execute();
}

/**
 * Mark all notifications as read for a user
 */
function markAllRead($user_id) {
    global $conn;
    $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $stmt->bind_param('i', $user_id);
    return $stmt->execute();
}

/**
 * Log an action to the audit trail
 */
function auditLog($user_id, $action, $entity_type, $entity_id = null, $details = null) {
    global $conn;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $conn->prepare("INSERT INTO audit_trail (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ississ', $user_id, $action, $entity_type, $entity_id, $details, $ip);
    return $stmt->execute();
}
