<?php
/**
 * Tiny dependency-free SMTP client.
 * Supports STARTTLS + AUTH LOGIN — enough for Gmail / most providers.
 *
 * Usage:
 *   $r = smtp_send([
 *       'host' => 'smtp.gmail.com', 'port' => 587, 'tls' => true,
 *       'user' => '...@gmail.com',  'pass' => 'app password',
 *       'from' => ['noreply@example', 'Display Name'],
 *       'to'   => 'recipient@example.com',
 *       'subject' => 'Hi', 'html' => '<p>hello</p>',
 *   ]);
 *   $r['ok'] === true on success; $r['log'] always carries the SMTP transcript.
 */

function smtp_send(array $cfg): array {
    $log = [];
    $write = function ($fp, $line) use (&$log) {
        @fwrite($fp, $line . "\r\n");
        $log[] = '> command sent';
    };
    $read = function ($fp) use (&$log) {
        $resp = '';
        while (!feof($fp)) {
            $line = @fgets($fp, 8192);
            if ($line === false) break;
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break; // last line of multi-line response
        }
        $log[] = '< ' . trim($resp);
        return $resp;
    };

    $host = $cfg['host'];
    $port = (int)$cfg['port'];
    $useTls = !empty($cfg['tls']);
    $implicitTls = $port === 465;
    $scheme = $implicitTls ? 'tls' : 'tcp';

    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, 15);
    if (!$fp) return ['ok' => false, 'log' => $log, 'error' => "connect: $errstr ($errno)"];
    stream_set_timeout($fp, 15);

    $check = function ($resp, $expectedPrefix) {
        return is_string($resp) && strncmp($resp, $expectedPrefix, strlen($expectedPrefix)) === 0;
    };

    // 220 banner
    $r = $read($fp);
    if (!$check($r, '220')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'banner: ' . trim($r)]; }

    $write($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $r = $read($fp);
    if (!$check($r, '250')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'EHLO: ' . trim($r)]; }

    if ($useTls && !$implicitTls) {
        $write($fp, 'STARTTLS');
        $r = $read($fp);
        if (!$check($r, '220')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'STARTTLS: ' . trim($r)]; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'error' => 'TLS handshake failed'];
        }
        // re-EHLO after TLS
        $write($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $r = $read($fp);
        if (!$check($r, '250')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'post-TLS EHLO: ' . trim($r)]; }
    }

    if (!empty($cfg['user'])) {
        $write($fp, 'AUTH LOGIN');
        $r = $read($fp);
        if (!$check($r, '334')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'AUTH LOGIN: ' . trim($r)]; }
        $write($fp, base64_encode($cfg['user']));
        $r = $read($fp);
        if (!$check($r, '334')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'AUTH user: ' . trim($r)]; }
        $write($fp, base64_encode($cfg['pass']));
        $r = $read($fp);
        if (!$check($r, '235')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'AUTH pass: ' . trim($r)]; }
    }

    [$fromAddr, $fromName] = $cfg['from'];
    $to = $cfg['to'];

    $write($fp, "MAIL FROM:<{$fromAddr}>");
    $r = $read($fp);
    if (!$check($r, '250')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'MAIL FROM: ' . trim($r)]; }

    $write($fp, "RCPT TO:<{$to}>");
    $r = $read($fp);
    if (!$check($r, '250') && !$check($r, '251')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'RCPT TO: ' . trim($r)]; }

    $write($fp, 'DATA');
    $r = $read($fp);
    if (!$check($r, '354')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'DATA: ' . trim($r)]; }

    // Compose message
    $boundary = 'boundary-' . bin2hex(random_bytes(8));
    $subject  = $cfg['subject'] ?? '(no subject)';
    $html     = $cfg['html']    ?? '';
    $text     = $cfg['text']    ?? strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));

    $headers  = "From: " . encode_header($fromName) . " <{$fromAddr}>\r\n";
    if (!empty($cfg['reply_to'])) $headers .= "Reply-To: <{$cfg['reply_to']}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: " . encode_header($subject) . "\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . preg_replace('/[^a-z0-9.\-]/i', '', $fromAddr) . ">\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $text . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--{$boundary}--\r\n";

    $msg = $headers . "\r\n" . $body;
    // Dot-stuffing per RFC 5321
    $msg = preg_replace('/^\./m', '..', $msg);

    fwrite($fp, $msg);
    $write($fp, '.');
    $r = $read($fp);
    if (!$check($r, '250')) { fclose($fp); return ['ok' => false, 'log' => $log, 'error' => 'DATA end: ' . trim($r)]; }

    $write($fp, 'QUIT');
    $read($fp);
    fclose($fp);

    return ['ok' => true, 'log' => $log];
}

function encode_header(string $s): string {
    if (preg_match('/[^\x20-\x7e]/', $s)) {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
}
