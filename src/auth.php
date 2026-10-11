<?php

// Admin accounts, login and sessions. Accounts live in admin_users; the only privilege is
// can_manage_users. Sessions are PHP sessions limited to /admin, every POST carries a CSRF token.

const SESSION_NAME = 'rift_admin';
const IDLE_TIMEOUT = 8 * 3600;
const MAX_FAILURES_PER_USER = 5;
const MAX_FAILURES_PER_IP = 30;
const MIN_PASSWORD_LENGTH = 10;

/** Apache's MD5-based $apr1$ hash (what htpasswd writes by default). */
function apr1_md5(string $password, string $salt): string
{
    $len = strlen($password);
    $text = $password . '$apr1$' . $salt;
    $bin = pack('H*', md5($password . $salt . $password));
    for ($i = $len; $i > 0; $i -= 16) {
        $text .= substr($bin, 0, min(16, $i));
    }
    for ($i = $len; $i > 0; $i >>= 1) {
        $text .= ($i & 1) ? chr(0) : $password[0];
    }
    $bin = pack('H*', md5($text));
    for ($i = 0; $i < 1000; $i++) {
        $new = ($i & 1) ? $password : $bin;
        if ($i % 3) $new .= $salt;
        if ($i % 7) $new .= $password;
        $new .= ($i & 1) ? $bin : $password;
        $bin = pack('H*', md5($new));
    }
    $tmp = '';
    for ($i = 0; $i < 5; $i++) {
        $k = $i + 6;
        $j = $i + 12;
        if ($j === 16) $j = 5;
        $tmp = $bin[$i] . $bin[$k] . $bin[$j] . $tmp;
    }
    $tmp = chr(0) . chr(0) . $bin[11] . $tmp;
    $tmp = strtr(strrev(substr(base64_encode($tmp), 2)),
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
        './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
    return '$apr1$' . $salt . '$' . $tmp;
}

function verify_password(string $hash, string $password): bool
{
    if (str_starts_with($hash, '$apr1$')) {
        $parts = explode('$', $hash);
        return count($parts) === 4 && hash_equals($hash, apr1_md5($password, $parts[2]));
    }
    return password_verify($password, $hash);
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

/** The logged-in user (re-read from the database every request, so changes apply at once) or null. */
function current_user($con): ?array
{
    start_session();
    if (empty($_SESSION['user_id']) || time() - ($_SESSION['seen'] ?? 0) > IDLE_TIMEOUT) {
        unset($_SESSION['user_id']);
        return null;
    }
    $res = pg_query_params($con, 'SELECT id, username, can_manage_users FROM admin_users WHERE id = $1', [$_SESSION['user_id']]);
    $user = pg_fetch_assoc($res) ?: null;
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    $_SESSION['seen'] = time();
    $user['can_manage_users'] = $user['can_manage_users'] === 't';
    return $user;
}

function login_locked($con, string $username): bool
{
    $res = pg_query_params($con, "
        SELECT count(*) FILTER (WHERE username = $1) AS by_user, count(*) FILTER (WHERE ip = $2) AS by_ip
        FROM admin_login_failures WHERE at > now() - interval '15 minutes'", [$username, client_ip()]);
    $row = pg_fetch_assoc($res);
    return (int)$row['by_user'] >= MAX_FAILURES_PER_USER || (int)$row['by_ip'] >= MAX_FAILURES_PER_IP;
}

/** Returns null on success, or a message for the form. */
function attempt_login($con, string $username, string $password): ?string
{
    $username = strtolower(trim($username));
    if (login_locked($con, $username)) {
        return 'Demasiadas tentativas falhadas. Tente novamente dentro de alguns minutos.';
    }

    $res = pg_query_params($con, 'SELECT id, password_hash FROM admin_users WHERE username = $1', [$username]);
    $user = pg_fetch_assoc($res);
    // Verify against a throwaway hash for unknown users so the timing doesn't reveal them.
    static $dummy = '$argon2id$v=19$m=65536,t=4,p=1$QlNzOVhpWjl0TExxYzRIVQ$1bHp+Jvzs+GIjlOjSPgkAGC0kv/UbWKsdldsAVf4NhM';
    $ok = verify_password($user['password_hash'] ?? $dummy, $password) && $user;

    if (!$ok) {
        pg_query_params($con, 'INSERT INTO admin_login_failures (username, ip) VALUES ($1, $2)', [$username, client_ip()]);
        return 'Nome de utilizador ou palavra-passe incorretos.';
    }

    if (str_starts_with($user['password_hash'], '$apr1$') || password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
        pg_query_params($con, 'UPDATE admin_users SET password_hash = $1 WHERE id = $2', [hash_password($password), $user['id']]);
    }
    pg_query_params($con, 'UPDATE admin_users SET last_login = now() WHERE id = $1', [$user['id']]);
    pg_query_params($con, "DELETE FROM admin_login_failures WHERE username = $1 OR at < now() - interval '1 day'", [$username]);

    start_session();
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$user['id'], 'seen' => time()];
    return null;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_ok(): bool
{
    start_session();
    return isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/** Why a password is not acceptable, or null. */
function password_problem(string $password): ?string
{
    return strlen($password) < MIN_PASSWORD_LENGTH ? 'A palavra-passe tem de ter pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.' : null;
}

function valid_username(string $username): bool
{
    return (bool)preg_match('/^[a-z0-9._-]{3,32}$/', $username);
}

function audit($con, string $username, string $action, string $detail = ''): void
{
    pg_query_params($con, 'INSERT INTO admin_audit (username, action, detail) VALUES ($1, $2, $3)', [$username, $action, $detail]);
}
