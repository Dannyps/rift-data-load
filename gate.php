<?php

// The front door of the site: serves the two public pages (charts and meetings, static HTML files) only to somebody with an
// account, or to the counter's own network. Everybody else is sent to the sign-in page and comes back here afterwards.
// Apache rewrites  /  and  /reunioes  to this script (?p=index|reunioes); nothing else is ever read from p.

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/auth.php");
require(__DIR__ . "/src/admin_ui.php");     // redirect()

const PAGES = ['index' => 'index.html', 'reunioes' => 'reunioes.html'];

$file = PAGES[$_GET['p'] ?? ''] ?? null;
if ($file === null) {
    http_response_code(404);
    exit;
}

$con = db_connect();
$viewer = viewer_user($con);
release_session();
if (!$viewer) {
    redirect(login_url($_SERVER['REQUEST_URI'] ?? '/'));
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
readfile(__DIR__ . '/' . $file);
