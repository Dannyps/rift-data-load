<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Admin accounts from the command line (the admin pages manage them once you can log in).
//   php admin_user.php list
//   php admin_user.php create USER [--manage]        (asks for the password)
//   php admin_user.php import-htpasswd USER [--manage] [FILE]   (reuses an Apache htpasswd entry; the hash
//                                                     is upgraded to argon2id at the first login)

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/auth.php");

function fail(string $msg): never
{
    fwrite(STDERR, "$msg\n");
    exit(1);
}

$args = array_slice($argv, 1);
$manage = in_array('--manage', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--manage'));
$cmd = array_shift($args);
$con = db_connect();

switch ($cmd) {
    case 'list':
        $res = pg_query($con, "SELECT username, can_manage_users, to_char(last_login, 'YYYY-MM-DD HH24:MI') AS last FROM admin_users ORDER BY username");
        foreach (pg_fetch_all($res) ?: [] as $r) {
            echo str_pad($r['username'], 20), $r['can_manage_users'] === 't' ? 'manages users  ' : '               ', $r['last'] ?? 'never logged in', "\n";
        }
        break;
    case 'create':
    case 'import-htpasswd':
        $username = $args[0] ?? '';
        if (!valid_username($username)) {
            fail('usage: admin_user.php ' . $cmd . ' USER [--manage]');
        }
        if ($cmd === 'create') {
            fwrite(STDERR, "Password for $username: ");
            system('stty -echo 2>/dev/null');
            $pw = rtrim((string)fgets(STDIN), "\r\n");
            system('stty echo 2>/dev/null');
            fwrite(STDERR, "\n");
            if ($problem = password_problem($pw)) {
                fail($problem);
            }
            $hash = hash_password($pw);
        } else {
            $hash = null;
            foreach (file($args[1] ?? '/etc/apache2/.htpasswd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                [$u, $h] = array_pad(explode(':', $line, 2), 2, '');
                if ($u === $username) {
                    $hash = $h;
                }
            }
            if ($hash === null || !str_starts_with($hash, '$apr1$')) {
                fail("no \$apr1\$ entry for $username in the htpasswd file");
            }
        }
        $res = pg_query_params($con, 'INSERT INTO admin_users (username, password_hash, can_manage_users) VALUES ($1, $2, $3)
                                      ON CONFLICT (username) DO NOTHING RETURNING id', [$username, $hash, $manage ? 't' : 'f']);
        if (!pg_fetch_assoc($res)) {
            fail("$username already exists");
        }
        audit($con, 'cli', 'utilizador.criar', $username . ($manage ? ' (pode gerir utilizadores)' : ''));
        echo "created $username\n";
        break;
    default:
        fail('usage: admin_user.php list | create USER [--manage] | import-htpasswd USER [--manage] [FILE]');
}
