<?php
// Local development server router (php -S): the same clean URLs as the Apache vhost, for the throwaway database.
//   RIFT_DB_CONFIG=/path/db.ini php -S 127.0.0.1:8099 -t . tools/dev_router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// tests only: pretend to come from another address (the counter-IP rule), when RIFT_TEST_ADDR_HEADER is set
if (getenv('RIFT_TEST_ADDR_HEADER') && !empty($_SERVER['HTTP_X_TEST_REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_TEST_REMOTE_ADDR'];
}
$map = ['/api/chart' => '/api.php', '/api/services' => '/services.php'];
if ($path === '/' || $path === '/reunioes' || $path === '/reunioes/') {
    $_GET['p'] = $path === '/' ? 'index' : 'reunioes';
    $path = '/gate.php';
} elseif (preg_match('#^/(entrar|registar|esqueci-palavra-passe|repor-palavra-passe|sair|conta)/?$#', $path, $m)) {
    $_GET['r'] = $m[1];
    $path = '/account.php';
} elseif (isset($map[$path])) {
    $path = $map[$path];
} elseif ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $path = '/admin.php';
} elseif (preg_match('#^/(sql|src|tests|tools|deploy|fixtures)/#', $path) || str_contains($path, '/.git') || $path === '/payload-example.json'
    || in_array($path, ['/index.html', '/reunioes.html'], true)) {
    http_response_code(404);
    return true;
}
$file = __DIR__ . '/..' . $path;
if (!is_file($file)) {
    http_response_code(404);
    return true;
}
if (str_ends_with($file, '.php')) {
    $_SERVER['SCRIPT_NAME'] = $path;
    chdir(dirname($file));
    require $file;
    return true;
}
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'json' => 'application/json'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: no-store');
readfile($file);
return true;
