<?php

// Edit API of the CSS preview site (https://rift-css.dannyps.net/_edit/): lets one person (or their Claude) replace the
// site's single stylesheet, assets/site.css, in a separate git clone of the project on the branch css-rework.
// Plain HTTP, JSON answers with plain-language errors. Reading is public; writing needs `Authorization: Bearer <token>`.
// Lives OUTSIDE the preview docroot (installed by deploy/css-preview/install.sh) and never touches the live site.

const REPO        = '@DOCROOT@';
const BRANCH      = 'css-rework';
const CSS_FILE    = 'assets/site.css';
const TOKEN_FILE  = '@CONF_DIR@/css-token';
const STATE_DIR   = '@STATE_DIR@';
const BRIEFING    = __DIR__ . '/../briefing.md';
const MAX_BYTES   = 256 * 1024;
const MAX_WRITES_PER_HOUR = 120;
const MAX_AUTH_FAILURES   = 10;      // per client address and 10 minutes

const PREVIEW_PAGES = [
    '/' => 'Charts page (/?range=1h|6h|24h|7d|30d, add &view=balance for the balance chart)',
    '/reunioes' => 'Meetings page, the current week: one meeting done, one running, one not started',
    '/reunioes?week=2026-10-11' => 'Meetings page, a past week: partial data, no data, special day, event card',
    '/reunioes?week=2026-10-04' => 'Meetings page, an empty week',
    '/reunioes?week=2026-10-25' => 'Meetings page, a future week with a special day',
    '/reunioes#r-2026-10-18-1130' => 'Meetings page with one card highlighted by its link',
    '/admin/entrar' => 'Admin: login',
    '/admin/entrar?state=erro' => 'Admin: login with an error message',
    '/admin/reunioes' => 'Admin: weekly meetings (a table, cards on phones)',
    '/admin/dias-especiais' => 'Admin: special days',
    '/admin/dias-especiais?state=erro' => 'Admin: error message (flash.error)',
    '/admin/dias-especiais?state=ok' => 'Admin: success message (flash.ok)',
    '/admin/pessoas' => 'Admin: people on the meetings',
    '/admin/correcoes' => 'Admin: corrections',
    '/admin/contadores' => 'Admin: counters',
    '/admin/estatisticas' => 'Admin: statistics (charts)',
    '/admin/utilizadores' => 'Admin: users',
    '/admin/palavra-passe' => 'Admin: change password',
    '/admin/registo' => 'Admin: audit log',
];

function out(int $status, $body, string $type = 'application/json; charset=utf-8', array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $h) {
        header($h);
    }
    echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit;
}

function fail(int $status, string $error, string $hint = '', array $extra = [], array $headers = []): never
{
    out($status, ['ok' => false, 'error' => $error] + ($hint !== '' ? ['hint' => $hint] : []) + $extra, 'application/json; charset=utf-8', $headers);
}

function base_url(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? 'rift-css.dannyps.net');
}

// ------------------------------------------------------------------ git (always as an argument array, never a shell string)

function git(array $args, ?string &$stderr = null): ?string
{
    $cmd = array_merge(['git', '-C', REPO, '-c', 'user.name=CSS editor', '-c', 'user.email=css@rift-css.dannyps.net', '-c', 'core.hooksPath=/dev/null'], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['HOME' => STATE_DIR, 'PATH' => '/usr/bin:/bin', 'GIT_TERMINAL_PROMPT' => '0']);
    if (!is_resource($p)) {
        return null;
    }
    $o = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($p) === 0 ? $o : null;
}

function require_branch(): void
{
    $b = git(['rev-parse', '--abbrev-ref', 'HEAD']);
    if ($b === null || trim($b) !== BRANCH) {
        fail(500, 'The preview checkout is not on the expected branch.', 'Tell the site owner: the clone must be on ' . BRANCH . '.');
    }
}

// ------------------------------------------------------------------ authentication and limits

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0';
}

function throttle_file(): string
{
    return STATE_DIR . '/auth-failures.json';
}

function load_failures(): array
{
    $d = json_decode((string)@file_get_contents(throttle_file()), true);
    $now = time();
    $d = is_array($d) ? $d : [];
    foreach ($d as $ip => $times) {
        $d[$ip] = array_values(array_filter($times, fn($t) => $t > $now - 600));
        if (!$d[$ip]) {
            unset($d[$ip]);
        }
    }
    return $d;
}

function require_token(): void
{
    $failures = load_failures();
    if (count($failures[client_ip()] ?? []) >= MAX_AUTH_FAILURES) {
        fail(429, 'Too many wrong tokens from this address.', 'Wait 10 minutes and try again with the right token.', [], ['Retry-After: 600']);
    }
    $expected = trim((string)@file_get_contents(TOKEN_FILE));
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    $given = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        $failures[client_ip()][] = time();
        @file_put_contents(throttle_file(), json_encode($failures), LOCK_EX);
        fail(401, 'Missing or wrong token.', 'Send the header  Authorization: Bearer <token>  (the token you were given).', [], ['WWW-Authenticate: Bearer realm="rift-css"']);
    }
}

// ------------------------------------------------------------------ CSS inspection

/** Walk the CSS ignoring comments and strings. Returns [errors, selectors, customProperties]. */
function inspect_css(string $css): array
{
    $errors = [];
    $selectors = [];
    $props = [];
    $n = strlen($css);
    $stack = [];            // [char, line]
    $line = 1;
    $prelude = '';          // text since the last ; { }
    $inBlockOf = [];        // stack of 'at' | 'rule'
    for ($i = 0; $i < $n; $i++) {
        $c = $css[$i];
        if ($c === "\n") {
            $line++;
        }
        if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $i + 2);
            if ($end === false) {
                $errors[] = "Line $line: a /* comment */ is never closed.";
                break;
            }
            $line += substr_count($css, "\n", $i, $end + 2 - $i);
            $i = $end + 1;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $n && $css[$j] !== $c) {
                if ($css[$j] === '\\') {
                    $j++;
                } elseif ($css[$j] === "\n") {
                    break;
                }
                $j++;
            }
            if ($j >= $n || $css[$j] !== $c) {
                $errors[] = "Line $line: a quoted string is never closed.";
                break;
            }
            $prelude .= substr($css, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        $top = $stack ? $stack[count($stack) - 1][0] : '';
        $inParen = $top === '(' || $top === '[';      // inside url(...) or [attr]: braces and semicolons are data
        if ($c === '(' || $c === '[') {
            $stack[] = [$c, $line];
        } elseif ($c === ')' || $c === ']') {
            $open = array_pop($stack);
            if (!$open || ($c === ')' ? '(' : '[') !== $open[0]) {
                $errors[] = "Line $line: unexpected '$c'.";
                break;
            }
        } elseif ($c === '{' && !$inParen) {
            $stack[] = ['{', $line];
            $p = trim(preg_replace('/\s+/', ' ', $prelude));
            $isAt = str_starts_with($p, '@');
            $inBlockOf[] = $isAt ? 'at' : 'rule';
            if (!$isAt && !in_array('rule', array_slice($inBlockOf, 0, -1), true) && !in_array('keyframes', $inBlockOf, true)) {
                foreach (preg_split('/,(?![^(\[]*[)\]])/', $p) as $s) {
                    $s = trim($s);
                    if ($s !== '' && !preg_match('/^(from|to|\d+(\.\d+)?%)$/', $s)) {
                        $selectors[$s] = true;
                    }
                }
            }
            $prelude = '';
            continue;
        } elseif ($c === '}' && !$inParen) {
            $open = array_pop($stack);
            if (!$open || $open[0] !== '{') {
                $errors[] = "Line $line: unexpected '}' (more closing braces than opening ones).";
                break;
            }
            array_pop($inBlockOf);
            $prelude = '';
            continue;
        } elseif ($c === ';' && !$inParen) {
            $prelude = '';
            continue;
        }
        $prelude .= $c;
    }
    if (!$errors && $stack) {
        $open = array_pop($stack);
        $errors[] = "Line {$open[1]}: '{$open[0]}' is never closed.";
    }
    // custom properties defined anywhere (comments removed)
    $plain = preg_replace('!/\*.*?\*/!s', '', $css);
    if (preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:/', $plain, $m)) {
        foreach ($m[1] as $p) {
            $props[$p] = true;
        }
    }
    return [$errors, array_keys($selectors), array_keys($props)];
}

function check_forbidden(string $css): array
{
    $errors = [];
    $plain = preg_replace('!/\*.*?\*/!s', ' ', $css);
    if (preg_match('/@import\b/i', $plain)) {
        $errors[] = '@import is not allowed: put everything in this one file.';
    }
    if (preg_match('/expression\s*\(|behaviou?r\s*:|-moz-binding|javascript\s*:/i', $plain)) {
        $errors[] = 'expression(), behavior:, -moz-binding and javascript: are not allowed.';
    }
    if (preg_match_all('/url\(\s*([^)]*)\)/i', $plain, $m)) {
        foreach ($m[1] as $raw) {
            $u = trim($raw, " \t\r\n\"'");
            $ok = $u === '' ? false : ($u[0] === '#' || preg_match('#^data:image/#i', $u) || (preg_match('#^/[^/]#', $u)));
            if (!$ok) {
                $errors[] = 'url(' . substr($u, 0, 60) . ') is not allowed: only #fragments, data:image/... and same-site paths starting with a single / are accepted (no external files, no web fonts; use system fonts).';
            }
        }
    }
    return $errors;
}

function current_css(): string
{
    return (string)@file_get_contents(REPO . '/' . CSS_FILE);
}

// ------------------------------------------------------------------ the briefing

function briefing(): string
{
    $md = (string)@file_get_contents(BRIEFING);
    $css = current_css();
    $tokens = [];
    if (preg_match_all('/:root\s*\{([^}]*)\}/', $css, $blocks)) {
        foreach ($blocks[1] as $i => $body) {
            if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $t) {
                    $tokens[$t[1]][$i] = trim($t[2]);
                }
            }
        }
    }
    $rows = '';
    foreach ($tokens as $name => $vals) {
        $rows .= '- `' . $name . '`: ' . implode('  |  dark: ', $vals) . "\n";
    }
    $pages = '';
    $base = base_url();
    foreach (PREVIEW_PAGES as $path => $what) {
        $pages .= "- $base$path : $what\n";
    }
    return strtr($md, ['{{BASE}}' => $base, '{{TOKENS}}' => rtrim($rows), '{{PAGES}}' => rtrim($pages), '{{MAX_KB}}' => (string)(MAX_BYTES / 1024)]);
}

// ------------------------------------------------------------------ routes

$path = '/' . trim(preg_replace('#^/_edit#', '', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_dir(STATE_DIR)) {
    fail(500, 'The edit API is not installed completely (state directory missing).');
}

if (($path === '/' || $path === '/index.php' || $path === '/briefing') && in_array($method, ['GET', 'HEAD'], true)) {
    out(200, briefing(), 'text/markdown; charset=utf-8');
}

if ($path === '/pages' && $method === 'GET') {
    $base = base_url();
    out(200, ['pages' => array_map(fn($p, $d) => ['url' => $base . $p, 'what' => $d], array_keys(PREVIEW_PAGES), PREVIEW_PAGES)]);
}

if ($path === '/css' && $method === 'GET') {
    require_branch();
    out(200, current_css(), 'text/css; charset=utf-8');
}

if ($path === '/log' && $method === 'GET') {
    require_branch();
    $raw = git(['log', '-n', '30', '--format=%h%x09%cI%x09%s', '--', CSS_FILE]) ?? '';
    $items = [];
    foreach (array_filter(explode("\n", $raw)) as $l) {
        [$sha, $when, $msg] = explode("\t", $l, 3);
        $items[] = ['commit' => $sha, 'time' => $when, 'message' => $msg];
    }
    out(200, ['branch' => BRANCH, 'commits' => $items]);
}

if ($path === '/css' && in_array($method, ['PUT', 'POST'], true)) {
    require_token();
    require_branch();
    $css = file_get_contents('php://input');
    if ($css === '' || $css === false) {
        fail(400, 'The request body is empty.', 'Send the complete new stylesheet as the body, e.g.  curl -X PUT --data-binary @site.css -H "Authorization: Bearer $TOKEN" ' . base_url() . '/_edit/css');
    }
    if (strlen($css) > MAX_BYTES) {
        fail(413, 'The stylesheet is too large (' . strlen($css) . ' bytes; the limit is ' . MAX_BYTES . ').', 'Remove unused rules; the whole site needs far less.');
    }
    $message = substr(trim(preg_replace('/[\r\n]+/', ' ', (string)($_GET['message'] ?? ''))), 0, 200) ?: 'CSS update';
    commit_css($css, $message);
}

if ($path === '/revert' && $method === 'POST') {
    require_token();
    require_branch();
    $to = (string)($_GET['to'] ?? '');
    if (!preg_match('/^[0-9a-f]{7,40}$/', $to)) {
        fail(400, 'Give the commit to go back to:  POST /_edit/revert?to=<commit>  (see GET /_edit/log).');
    }
    $css = git(['show', "$to:" . CSS_FILE]);
    $inHistory = git(['merge-base', '--is-ancestor', $to, 'HEAD']) !== null;
    if ($css === null || !$inHistory) {
        fail(404, 'That commit is not part of this branch\'s history.', 'Use a commit from GET /_edit/log.');
    }
    commit_css($css, "Revert to $to");
}

fail(404, 'Unknown endpoint or method.', 'GET ' . base_url() . '/_edit/ explains everything.');

// ------------------------------------------------------------------ writing

function commit_css(string $css, string $message): never
{
    if (!mb_check_encoding($css, 'UTF-8') || str_contains($css, "\0")) {
        fail(422, 'The stylesheet must be plain UTF-8 text.');
    }
    $errors = check_forbidden($css);
    [$syntax, $newSelectors, $newProps] = inspect_css($css);
    $errors = array_merge($syntax, $errors);
    if ($errors) {
        fail(422, 'The stylesheet was NOT saved: ' . count($errors) . ' problem(s).', 'Fix them and send the whole file again.', ['problems' => $errors]);
    }

    $fh = fopen(STATE_DIR . '/lock', 'c');
    $waited = 0;
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if ($waited++ >= 50) {
            fail(409, 'Another save is in progress.', 'Wait a few seconds and send it again.');
        }
        usleep(100000);
    }

    $recent = (int)trim((string)git(['rev-list', '--count', '--since=1.hour.ago', 'HEAD', '--', CSS_FILE]));
    if ($recent >= MAX_WRITES_PER_HOUR) {
        fail(429, 'Too many saves in the last hour.', 'Batch your changes into fewer, larger saves.', [], ['Retry-After: 600']);
    }

    $old = current_css();
    [, $oldSelectors, $oldProps] = inspect_css($old);
    $warnings = [];
    $gone = array_values(array_diff($oldSelectors, $newSelectors));
    if ($gone) {
        $warnings[] = count($gone) . ' selector(s) of the previous version no longer exist; check that nothing lost its styling: ' . implode(' | ', array_slice($gone, 0, 25)) . (count($gone) > 25 ? ' | ...' : '');
    }
    if (count($oldSelectors) >= 20 && count($gone) > count($oldSelectors) / 2 && !isset($_GET['force'])) {
        fail(422, 'The stylesheet was NOT saved: it would remove ' . count($gone) . ' of the ' . count($oldSelectors) . ' selectors of the current one.',
            'That looks like a truncated or partial upload. Send the COMPLETE stylesheet. If you really mean to drop most of it, repeat the request with &force=1 added to the URL.',
            ['would_remove' => array_slice($gone, 0, 25)]);
    }
    $lostProps = array_values(array_diff($oldProps, $newProps));
    if ($lostProps) {
        $warnings[] = 'Design tokens (custom properties) removed: ' . implode(', ', $lostProps) . '. Anything still using var() of these now falls back to nothing.';
    }
    if (str_contains($old, 'prefers-color-scheme: dark') && !str_contains($css, 'prefers-color-scheme: dark')) {
        $warnings[] = 'The new stylesheet has no dark-mode rules (@media (prefers-color-scheme: dark)); the site supports light and dark.';
    }
    if (!preg_match('/max-width\s*:\s*\d+px/', $css)) {
        $warnings[] = 'The new stylesheet has no max-width media query; check the phone layout (390 px wide).';
    }

    $file = REPO . '/' . CSS_FILE;
    if ($css === $old) {
        out(200, ['ok' => true, 'changed' => false, 'message' => 'Identical to the current stylesheet; nothing to save.', 'warnings' => $warnings]);
    }
    $tmp = $file . '.tmp' . getmypid();
    if (file_put_contents($tmp, $css) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        fail(500, 'Could not write the stylesheet.');
    }
    $err = '';
    if (git(['add', '--', CSS_FILE], $err) === null || git(['commit', '-q', '-m', $message, '--', CSS_FILE], $err) === null) {
        file_put_contents($file, $old);          // put the previous version back
        fail(500, 'Saving to git failed; the previous version was restored.', trim($err));
    }
    $sha = trim((string)git(['rev-parse', '--short', 'HEAD']));
    $base = base_url();
    out(200, [
        'ok' => true, 'changed' => true, 'commit' => $sha, 'bytes' => strlen($css), 'warnings' => $warnings,
        'next' => 'Reload the preview pages (they are never cached): ' . $base . '/ , ' . $base . '/reunioes , ' . $base . '/admin/reunioes . All pages: GET ' . $base . '/_edit/pages',
    ]);
}
