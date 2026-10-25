<?php

// Accounts, sign-up, login and sessions. Everything on the site needs an account. Accounts live in admin_users: a
// role ('admin' = every page, 'reporter' = reads the site and saves meeting reports, 'viewer' = only reads), the
// can_manage_users privilege (admins only) and a status: a new sign-up is 'pending' (locked) until a manager makes it
// 'active'. The built-in kind 'display' account ('contador') is what requests from the counter's own IP are signed in
// as (read only, see device_user()). Sessions are PHP sessions for the whole site, kept 30 days, and every POST
// carries a CSRF token.

const SESSION_NAME = 'rift_session';             // not 'rift_admin': an old cookie limited to /admin would shadow the new one
const IDLE_TIMEOUT = 30 * 86400;
const MAX_FAILURES_PER_USER = 5;
const MAX_FAILURES_PER_IP = 30;
const MIN_PASSWORD_LENGTH = 10;
const ROLES = ['admin' => 'Administrador', 'reporter' => 'Relatórios', 'viewer' => 'Só consulta'];
const SESSION_DIR = '/var/lib/rift-data-load/sessions';
const SITE_URL = 'https://rift.dannyps.net';     // used in mails; never taken from the Host header
const DEVICE_IP_MAX_AGE = 7 * 86400;             // the counter's IP counts for this long after its last accepted post

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
    // Own directory and lifetime: Debian's cron empties the default one after 24 minutes of inactivity.
    $dir = getenv('RIFT_SESSION_DIR') ?: SESSION_DIR;
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string)IDLE_TIMEOUT);
    session_name(SESSION_NAME);
    // Lax, not Strict: a link opened from another app (a shared card link in WhatsApp) must arrive signed in.
    // Every state-changing request is a POST with a CSRF token, so Lax costs nothing here.
    $secure = !getenv('RIFT_INSECURE_COOKIES');      // tests over plain http only
    session_set_cookie_params(['lifetime' => IDLE_TIMEOUT, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

/** The signed-in person (re-read from the database every request, so changes apply at once) or null. */
function current_user($con): ?array
{
    if (!isset($_COOKIE[SESSION_NAME])) {
        return null;      // no session cookie: do not create a session for anonymous visitors
    }
    start_session();
    if (empty($_SESSION['user_id']) || time() - ($_SESSION['seen'] ?? 0) > IDLE_TIMEOUT) {
        unset($_SESSION['user_id']);
        return null;
    }
    $res = pg_query_params($con, "SELECT id, username, name, email, role, can_manage_users, status, kind,
            extract(epoch FROM password_changed_at) AS pw_changed FROM admin_users WHERE id = $1", [$_SESSION['user_id']]);
    $user = pg_fetch_assoc($res) ?: null;
    // a locked, removed, built-in or since-changed-password account does not keep its session
    if (!$user || $user['status'] !== 'active' || $user['kind'] !== 'person'
        || ($user['pw_changed'] !== null && (float)$user['pw_changed'] > ($_SESSION['login_at'] ?? 0))) {
        unset($_SESSION['user_id']);
        return null;
    }
    $_SESSION['seen'] = time();
    $user['can_manage_users'] = $user['can_manage_users'] === 't';
    $user['display'] = false;
    unset($user['pw_changed']);
    return $user;
}

/** A setting from the settings table (null when missing). */
function setting_get($con, string $key): ?string
{
    $res = pg_query_params($con, 'SELECT value FROM settings WHERE key = $1', [$key]);
    $v = pg_fetch_result($res, 0, 0);
    return $v === false ? null : $v;
}

function setting_set($con, string $key, string $value, string $by): void
{
    pg_query_params($con, 'INSERT INTO settings (key, value, set_by) VALUES ($1, $2, $3)
        ON CONFLICT (key) DO UPDATE SET value = excluded.value, set_by = excluded.set_by, set_at = now()', [$key, $value, $by]);
}

/** Remember where the counter posts from. Call it only after the counter's token was accepted. */
function record_device_seen($con, string $ip): void
{
    pg_query_params($con, 'INSERT INTO device_seen (id, ip, seen_at) VALUES (1, $1, now())
        ON CONFLICT (id) DO UPDATE SET ip = excluded.ip, seen_at = excluded.seen_at', [$ip]);
}

/** Pure rule: does this visitor count as the counter's network? $seen = [ip, age in seconds] or null. */
function device_matches(?array $seen, string $ip, string $switch): bool
{
    return $switch === 'on' && $seen !== null && $seen[0] === $ip && $seen[1] <= DEVICE_IP_MAX_AGE;
}

/** The built-in read-only account for requests from the counter's own IP (kiosk/TV next to it), or null. */
function device_user($con): ?array
{
    $row = pg_fetch_assoc(pg_query($con, 'SELECT ip, extract(epoch FROM now() - seen_at) AS age FROM device_seen WHERE id = 1'));
    $seen = $row ? [$row['ip'], (float)$row['age']] : null;
    if (!device_matches($seen, client_ip(), (string)setting_get($con, 'display_autologin'))) {
        return null;
    }
    $user = pg_fetch_assoc(pg_query($con, "SELECT id, username, name, email, role, can_manage_users, status, kind FROM admin_users WHERE kind = 'display' AND status = 'active' LIMIT 1")) ?: null;
    if ($user) {
        $user['can_manage_users'] = false;
        $user['display'] = true;
    }
    return $user;
}

/** Whoever may READ the site: a signed-in person, or the counter's network. Never use it for anything that changes data. */
function viewer_user($con): ?array
{
    return current_user($con) ?? device_user($con);
}

/** Let go of the session file (it is locked while open): for read-only requests, once the visitor is known. */
function release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/** Roles that may save meeting reports. */
function can_report(array $user): bool
{
    return empty($user['display']) && in_array($user['role'], ['admin', 'reporter'], true);
}

function is_admin(array $user): bool
{
    return $user['role'] === 'admin';
}

/** Where a freshly signed-in user lands: admins in the admin area, everybody else on the meetings page. */
function home_for(array $user): string
{
    return is_admin($user) ? '/admin/reunioes' : '/reunioes';
}

/**
 * A safe place to go back to after signing in: a path of this site only (one leading slash, no scheme, no
 * backslashes or control characters), else null. The fragment is allowed (it is added by the login page's script).
 */
function safe_next(?string $next): ?string
{
    if ($next === null || $next === '' || strlen($next) > 300) {
        return null;
    }
    if ($next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1f\x7f]/', $next)) {
        return null;
    }
    if (preg_match('#^/(entrar|registar|esqueci-palavra-passe|repor-palavra-passe|sair)(/|\?|\#|$)#', $next)) {
        return null;                                  // never bounce back to a sign-in page
    }
    return $next;
}

function login_url(?string $next = null): string
{
    $next = safe_next($next);
    return '/entrar' . ($next !== null && $next !== '/' ? '?next=' . rawurlencode($next) : '');
}

function login_locked($con, string $username): bool
{
    $res = pg_query_params($con, "
        SELECT count(*) FILTER (WHERE username = $1) AS by_user, count(*) FILTER (WHERE ip = $2) AS by_ip
        FROM admin_login_failures WHERE at > now() - interval '15 minutes'", [$username, client_ip()]);
    $row = pg_fetch_assoc($res);
    return (int)$row['by_user'] >= MAX_FAILURES_PER_USER || (int)$row['by_ip'] >= MAX_FAILURES_PER_IP;
}

/**
 * Signs in. Returns null on success, or a message for the form. A wrong user or password always gets the same message; only
 * with the right password does the person learn that the account is still waiting for approval or was locked.
 */
function attempt_login($con, string $username, string $password): ?string
{
    $username = strtolower(trim($username));
    if (login_locked($con, $username)) {
        return 'Demasiadas tentativas falhadas. Tente novamente dentro de alguns minutos.';
    }

    $res = pg_query_params($con, 'SELECT id, password_hash, status, kind FROM admin_users WHERE username = $1', [$username]);
    $user = pg_fetch_assoc($res);
    // Verify against a throwaway hash for unknown users so the timing doesn't reveal them.
    static $dummy = '$argon2id$v=19$m=65536,t=4,p=1$QlNzOVhpWjl0TExxYzRIVQ$1bHp+Jvzs+GIjlOjSPgkAGC0kv/UbWKsdldsAVf4NhM';
    $ok = verify_password($user['password_hash'] ?? $dummy, $password) && $user && $user['kind'] === 'person';

    if (!$ok) {
        pg_query_params($con, 'INSERT INTO admin_login_failures (username, ip) VALUES ($1, $2)', [$username, client_ip()]);
        return 'Nome de utilizador ou palavra-passe incorretos.';
    }
    if ($user['status'] === 'pending') {
        return 'A sua conta ainda aguarda aprovação de um gestor. Quando for aprovada poderá entrar.';
    }
    if ($user['status'] !== 'active') {
        return 'Esta conta está desativada. Fale com um gestor.';
    }

    if (str_starts_with($user['password_hash'], '$apr1$') || password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
        pg_query_params($con, 'UPDATE admin_users SET password_hash = $1 WHERE id = $2', [hash_password($password), $user['id']]);
    }
    pg_query_params($con, 'UPDATE admin_users SET last_login = now() WHERE id = $1', [$user['id']]);
    pg_query_params($con, "DELETE FROM admin_login_failures WHERE username = $1 OR at < now() - interval '1 day'", [$username]);

    start_session();
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$user['id'], 'seen' => time(), 'login_at' => microtime(true)];
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

// ---------------------------------------------------------------- sign-up, reset, throttles

const SIGNUP_PER_IP_HOUR = 5;
const SIGNUP_PER_DAY = 30;
const CODE_FAILURES_PER_IP = 10;      // per 15 minutes
const RESET_PER_KEY_HOUR = 3;
const RESET_TOKEN_TTL = 3600;

function email_problem(string $email): ?string
{
    if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Indique um email válido.';
    }
    return null;
}

function name_problem(string $name): ?string
{
    $len = mb_strlen($name);
    return $len < 2 || $len > 80 || preg_match('/[\x00-\x1f\x7f<>]/u', $name) ? 'Indique o seu nome (2 a 80 caracteres).' : null;
}

function attempts_add($con, string $kind, string $key): void
{
    pg_query_params($con, 'INSERT INTO auth_attempts (kind, key) VALUES ($1, $2)', [$kind, $key]);
    pg_query($con, "DELETE FROM auth_attempts WHERE at < now() - interval '2 days'");
}

/** How many attempts of this kind (for this key, or all keys when null) within the last $seconds. */
function attempts_count($con, string $kind, ?string $key, int $seconds): int
{
    $res = pg_query_params($con, "SELECT count(*) FROM auth_attempts WHERE kind = $1 AND ($2::text IS NULL OR key = $2)
        AND at > now() - make_interval(secs => $3)", [$kind, $key, $seconds]);
    return (int)pg_fetch_result($res, 0, 0);
}

function signup_code_ok($con, string $given): bool
{
    $code = strtolower(trim((string)setting_get($con, 'signup_code')));
    return $code !== '' && hash_equals($code, strtolower(trim($given)));
}

/** Creates a locked sign-up. Returns null, or the message for the form. Validation of the fields is the caller's. */
function create_signup($con, string $username, string $name, string $email, string $password): ?string
{
    $taken = pg_fetch_result(pg_query_params($con, 'SELECT count(*) FROM admin_users WHERE username = $1 OR lower(email) = lower($2)', [$username, $email]), 0, 0);
    if ((int)$taken > 0) {
        return 'Não foi possível criar o pedido: esse nome de utilizador ou esse email já estão em uso.';
    }
    pg_query_params($con, "INSERT INTO admin_users (username, password_hash, role, status, name, email, requested_ip)
        VALUES ($1, $2, 'viewer', 'pending', $3, lower($4), $5)", [$username, hash_password($password), $name, $email, client_ip()]);
    return null;
}

function pending_count($con): int
{
    return (int)pg_fetch_result(pg_query($con, "SELECT count(*) FROM admin_users WHERE status = 'pending' AND kind = 'person'"), 0, 0);
}

/** A fresh reset link token for an active person with an email; returns the token (only its hash is stored) or null. */
function create_reset_token($con, string $email): ?array
{
    $u = pg_fetch_assoc(pg_query_params($con, "SELECT id, username, name, email FROM admin_users
        WHERE lower(email) = lower($1) AND status = 'active' AND kind = 'person'", [$email]));
    if (!$u) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    pg_query_params($con, "INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES ($1, $2, now() + make_interval(secs => $3), $4)",
        [$u['id'], hash('sha256', $token), RESET_TOKEN_TTL, client_ip()]);
    return $u + ['token' => $token];
}

/** The user a reset token belongs to while it is unused and unexpired, else null. */
function reset_token_user($con, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    return pg_fetch_assoc(pg_query_params($con, "SELECT r.id AS reset_id, u.id, u.username, u.name FROM password_resets r JOIN admin_users u ON u.id = r.user_id
        WHERE r.token_hash = $1 AND r.used_at IS NULL AND r.expires_at > now() AND u.status = 'active' AND u.kind = 'person'", [hash('sha256', $token)])) ?: null;
}

/** Sets a new password through a reset token (one use); signs every other session out. */
function consume_reset_token($con, array $user, string $newPassword): void
{
    pg_query($con, 'BEGIN');
    pg_query_params($con, 'UPDATE password_resets SET used_at = now() WHERE user_id = $1 AND used_at IS NULL', [$user['id']]);
    pg_query_params($con, 'UPDATE admin_users SET password_hash = $1, password_changed_at = now() WHERE id = $2', [hash_password($newPassword), $user['id']]);
    pg_query_params($con, 'DELETE FROM admin_login_failures WHERE username = $1', [$user['username']]);
    pg_query($con, 'COMMIT');
}

function audit($con, string $username, string $action, string $detail = ''): void
{
    pg_query_params($con, 'INSERT INTO admin_audit (username, action, detail) VALUES ($1, $2, $3)', [$username, $action, $detail]);
}
