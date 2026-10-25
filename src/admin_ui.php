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

/** An asset URL with its modification time, so a changed file is never served from a browser's cached copy. */
function asset_url(string $path): string
{
    $file = dirname(__DIR__) . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function page(string $title, string $body, ?array $user, string $active = '', array $scripts = []): never
{
    global $con;      // the page's database connection (for the pending-requests badge)
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; script-src 'self'; frame-ancestors 'none'; form-action 'self'");

    $nav = $toggle = '';
    if ($user) {
        $items = is_admin($user)
            ? ['reunioes' => 'Reuniões', 'dias-especiais' => 'Dias especiais', 'pessoas' => 'Pessoas', 'correcoes' => 'Correções', 'contadores' => 'Contadores', 'estatisticas' => 'Estatísticas']
            : [];
        if ($user['can_manage_users']) {
            $items['utilizadores'] = 'Utilizadores';
        }
        $items['conta'] = 'A minha conta';
        if (is_admin($user)) {
            $items['registo'] = 'Registo';
        }
        $pending = !empty($user['can_manage_users']) && isset($con) ? pending_count($con) : 0;
        // Top bar on wide screens; on phones the same nav is a slide-in sidebar opened by the menu button.
        $nav = '<nav class="nav" id="adminnav" aria-label="' . (is_admin($user) ? 'Administração' : 'Conta') . '">';
        foreach ($items as $slug => $label) {
            $href = $slug === 'conta' ? '/conta' : '/admin/' . $slug;
            $badge = $slug === 'utilizadores' && $pending > 0
                ? ' <span class="count-badge" title="' . $pending . ' pedido(s) de conta por aprovar"><span class="sr">pedidos por aprovar: </span>' . $pending . '</span>' : '';
            $nav .= '<a href="' . $href . '"' . ($slug === $active ? ' aria-current="page"' : '') . '>' . h($label) . $badge . '</a>';
        }
        $nav .= '<a class="nav-site" href="/reunioes">Ver o site</a></nav><div class="nav-backdrop" id="navbackdrop" hidden></div>';
        $toggle = '<button type="button" class="menu-toggle" id="menutoggle" aria-controls="adminnav" aria-expanded="false" aria-label="Abrir o menu"><span class="bars" aria-hidden="true"></span></button>';
    }

    $flashes = '';
    start_session();
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $flashes .= '<div class="flash ' . h($type) . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);

    $who = $user
        ? '<form method="post" action="/sair" class="inline">' . csrf_field() . '<span class="sub">' . h($user['name'] ?: $user['username']) . '</span> <button>Sair</button></form>'
        : '';
    $heading = $user && is_admin($user) ? 'Administração' : 'Contador de pessoas RIFT';

    echo '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><link rel="icon" href="/favicon.ico" sizes="48x48"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><title>' . h($title) . ' · RIFT</title>'
        . '<link rel="stylesheet" href="' . asset_url('/assets/site.css') . '"></head><body class="admin"><main>'
        . '<header><div class="title">' . $toggle . '<h1>' . $heading . '</h1></div><div class="who">' . ($user ? '<a class="sub" href="/reunioes">Ver o site</a> ' : '') . $who . '</div></header>'
        . $nav . $flashes . '<h2>' . h($title) . '</h2>' . $body
        . '</main><script src="' . asset_url('/assets/admin.js') . '"></script>'
        . implode('', array_map(fn($s) => '<script src="' . h(asset_url($s)) . '"></script>', $scripts)) . '</body></html>';
    exit;
}
