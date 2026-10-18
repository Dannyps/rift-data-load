<?php
// Local development server router (php -S): the same clean URLs as the Apache vhost, for the throwaway database.
//   RIFT_DB_CONFIG=/path/db.ini php -S 127.0.0.1:8099 -t . tools/dev_router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$map = ['/reunioes' => '/reunioes.html', '/api/chart' => '/api.php', '/api/services' => '/services.php'];
if (isset($map[$path])) {
    $path = $map[$path];
} elseif ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $path = '/admin.php';
} elseif (preg_match('#^/(sql|src|tests|tools|deploy|fixtures)/#', $path) || str_contains($path, '/.git')) {
    http_response_code(404);
    return true;
} elseif ($path === '/') {
    $path = '/index.html';
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
