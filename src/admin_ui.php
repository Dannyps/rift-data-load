<?php

// Small HTML helpers for the admin pages (pt-PT).

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $message];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Local date/time for display, from a UTC database value. */
function fmt_local(?string $ts, string $format = 'd/m/Y H:i'): string
{
    if ($ts === null || $ts === '') {
        return '–';
    }
    return (new DateTimeImmutable($ts, utc()))->setTimezone(local_tz())->format($format);
}

const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

function page(string $title, string $body, ?array $user, string $active = ''): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");

    $nav = '';
    if ($user) {
        $items = ['reunioes' => 'Reuniões', 'dias-especiais' => 'Dias especiais', 'correcoes' => 'Correções'];
        if ($user['can_manage_users']) {
            $items['utilizadores'] = 'Utilizadores';
        }
        $items['palavra-passe'] = 'Palavra-passe';
        $items['registo'] = 'Registo';
        $nav = '<nav class="nav" aria-label="Administração">';
        foreach ($items as $slug => $label) {
            $nav .= '<a href="/admin/' . $slug . '"' . ($slug === $active ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
        }
        $nav .= '</nav>';
    }

    $flashes = '';
    start_session();
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $flashes .= '<div class="flash ' . h($type) . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);

    $who = $user
        ? '<form method="post" action="/admin/sair" class="inline">' . csrf_field() . '<span class="sub">' . h($user['username']) . '</span> <button>Sair</button></form>'
        : '';

    echo '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' · Administração RIFT</title>'
        . '<link rel="stylesheet" href="/assets/common.css"><link rel="stylesheet" href="/assets/admin.css"></head><body><main>'
        . '<header><h1>Administração</h1><div class="who"><a class="sub" href="/reunioes">Ver o site</a> ' . $who . '</div></header>'
        . $nav . $flashes . '<h2>' . h($title) . '</h2>' . $body
        . '</main><script src="/assets/admin.js"></script></body></html>';
    exit;
}
