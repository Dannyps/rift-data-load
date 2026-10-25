<?php

// Outgoing mail (sign-up notices to the managers, password reset links). Plain text through the local sendmail shim
// (Stalwart on this host signs it with DKIM), as the monitor does. It is handed to a background shell so the page does
// not wait for it: the answer takes the same time whether or not a mail was sent (no way to probe which addresses exist).

const MAIL_FROM_ADDRESS = 'rift@altair.dannyps.net';
const MAIL_FROM_NAME = 'RIFT contador';
const MANAGER_NOTICE_TO = 'root@altair.dannyps.net';

/** The complete message (headers + body), ready for sendmail -t. Pure, so it can be tested. */
function build_mail(string $to, string $subject, string $text, ?int $now = null): string
{
    $now ??= time();
    $encode = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $headers = [
        'From: ' . $encode(MAIL_FROM_NAME) . ' <' . MAIL_FROM_ADDRESS . '>',
        'To: ' . $to,
        'Subject: ' . $encode($subject),
        'Date: ' . gmdate('D, d M Y H:i:s', $now) . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@altair.dannyps.net>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
    ];
    return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n");
}

/** Queue a mail (returns at once). $to must be a single plain address. RIFT_SENDMAIL replaces the sendmail binary in tests. */
function send_mail(string $to, string $subject, string $text): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
        return false;
    }
    $file = tempnam(sys_get_temp_dir(), 'riftmail');
    if ($file === false || file_put_contents($file, build_mail($to, $subject, $text)) === false) {
        return false;
    }
    $sendmail = getenv('RIFT_SENDMAIL') ?: '/usr/sbin/sendmail';
    // in a subshell in the background: send, then remove the temporary file
    $cmd = '(' . escapeshellarg($sendmail) . ' -t -f ' . escapeshellarg(MAIL_FROM_ADDRESS) . ' < ' . escapeshellarg($file)
        . '; rm -f ' . escapeshellarg($file) . ') > /dev/null 2>&1 &';
    exec($cmd);
    return true;
}
