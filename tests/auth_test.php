<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tests for the pure functions of accounts and sign-in: php tests/auth_test.php  (no database: see tools/ for the browser flows)
require(__DIR__ . "/../src/auth.php");
require(__DIR__ . "/../src/mail.php");

$failures = 0;
function check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name\n   got  " . json_encode($got) . "\n   want " . json_encode($want) . "\n";
    }
}

// ---- where to go after signing in: this site only
check('next: a path', safe_next('/reunioes'), '/reunioes');
check('next: a path with query and fragment', safe_next('/reunioes?week=2026-10-11#r-2026-10-11-0930'), '/reunioes?week=2026-10-11#r-2026-10-11-0930');
check('next: the admin area', safe_next('/admin/utilizadores'), '/admin/utilizadores');
check('next: empty', safe_next(''), null);
check('next: null', safe_next(null), null);
check('next: another site', safe_next('https://evil.example/'), null);
check('next: scheme-relative', safe_next('//evil.example/x'), null);
check('next: backslash trick', safe_next('/\\evil.example'), null);
check('next: no leading slash', safe_next('evil.example'), null);
check('next: javascript', safe_next('javascript:alert(1)'), null);
check('next: control characters', safe_next("/a\r\nSet-Cookie: x=1"), null);
check('next: never back to the sign-in page', safe_next('/entrar?next=/x'), null);
check('next: nor sign-out', safe_next('/sair'), null);
check('next: nor the password reset', safe_next('/repor-palavra-passe?token=abc'), null);
check('next: too long', safe_next('/' . str_repeat('a', 400)), null);
check('login url without next', login_url(null), '/entrar');
check('login url for the home page needs no next', login_url('/'), '/entrar');
check('login url keeps the page and fragment', login_url('/reunioes#r-2026-10-18-1130'), '/entrar?next=%2Freunioes%23r-2026-10-18-1130');
check('login url drops an unsafe next', login_url('//evil.example'), '/entrar');

// ---- fields
check('email ok', email_problem('maria.silva@example.org'), null);
check('email without @', email_problem('maria') !== null, true);
check('email empty', email_problem('') !== null, true);
check('email with a newline (header injection)', email_problem("a@b.pt\nBcc: x@y.pt") !== null, true);
check('email too long', email_problem(str_repeat('a', 130) . '@example.org') !== null, true);
check('name ok', name_problem('Maria da Silva'), null);
check('name accents ok', name_problem('João Conceição'), null);
check('name too short', name_problem('M') !== null, true);
check('name with markup', name_problem('<b>Maria</b>') !== null, true);
check('name too long', name_problem(str_repeat('a', 81)) !== null, true);
check('username ok', valid_username('maria.silva'), true);
check('username with capitals refused', valid_username('Maria'), false);
check('username too short', valid_username('ab'), false);
check('password too short', password_problem('curta') !== null, true);
check('password long enough', password_problem('uma palavra-passe'), null);

// ---- roles
check('admin can report', can_report(['role' => 'admin', 'display' => false]), true);
check('reporter can report', can_report(['role' => 'reporter']), true);
check('viewer cannot report', can_report(['role' => 'viewer']), false);
check('the counter network cannot report even with a reporter role', can_report(['role' => 'reporter', 'display' => true]), false);
check('roles are named', array_keys(ROLES), ['admin', 'reporter', 'viewer']);
check('home: admin', home_for(['role' => 'admin']), '/admin/reunioes');
check('home: viewer', home_for(['role' => 'viewer']), '/reunioes');

// ---- the counter's network: same address, recent enough, switched on
check('device: same ip, recent, on', device_matches(['2.80.147.204', 60.0], '2.80.147.204', 'on'), true);
check('device: other ip', device_matches(['2.80.147.204', 60.0], '2.80.147.205', 'on'), false);
check('device: switched off', device_matches(['2.80.147.204', 60.0], '2.80.147.204', 'off'), false);
check('device: no post ever seen', device_matches(null, '2.80.147.204', 'on'), false);
check('device: seen 6 days ago still counts', device_matches(['2.80.147.204', 6 * 86400.0], '2.80.147.204', 'on'), true);
check('device: seen 8 days ago is stale', device_matches(['2.80.147.204', 8 * 86400.0], '2.80.147.204', 'on'), false);
check('device: the setting is not "on" by accident', device_matches(['1.2.3.4', 1.0], '1.2.3.4', ''), false);

// ---- mail
$m = build_mail('maria@example.org', 'RIFT: reposição da palavra-passe', "Olá\nligação", 1792000000);
check('mail: from the rift address', str_contains($m, '<rift@altair.dannyps.net>'), true);
check('mail: to', str_contains($m, "\r\nTo: maria@example.org\r\n"), true);
check('mail: subject is encoded UTF-8', str_contains($m, 'Subject: =?UTF-8?B?' . base64_encode('RIFT: reposição da palavra-passe') . '?='), true);
check('mail: body is base64', str_contains($m, base64_encode("Olá\nligação")), true);
check('mail: dated', str_contains($m, 'Date: ' . gmdate('D, d M Y H:i:s', 1792000000) . ' +0000'), true);
check('mail: refuses a bad recipient', send_mail("a@b.pt\nBcc: x@y.pt", 's', 't'), false);
check('mail: refuses a subject with a newline', send_mail('a@b.pt', "s\nBcc: x@y.pt", 't'), false);

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
