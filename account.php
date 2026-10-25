<?php

// The public side of accounts, served under /entrar, /registar, /esqueci-palavra-passe, /repor-palavra-passe, /sair and
// /conta (Apache rewrites them here, with ?r=<name>). Everything else on the site needs a signed-in account (gate.php and the
// APIs); these are the only pages a stranger can open. Look and feel come from page() in src/admin_ui.php.

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/auth.php");
require(__DIR__ . "/src/admin_ui.php");
require(__DIR__ . "/src/mail.php");

$con = db_connect();
$route = (string)($_GET['r'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$self = '/' . $route;
$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);

/** Redirect back to the page (303), keeping the flash message. */
function back_to(string $to): never
{
    redirect($to);
}

$err = fn(string $msg) => '<div class="flash error" role="alert">' . h($msg) . '</div>';
$ok = fn(string $msg) => '<div class="flash ok" role="status">' . h($msg) . '</div>';

// ---------------------------------------------------------------- sign out

if ($route === 'sair') {
    if ($isPost && csrf_ok()) {
        if ($u = current_user($con)) {
            audit($con, $u['username'], 'logout');
        }
        logout();
    }
    redirect('/entrar');
}

// ---------------------------------------------------------------- sign in

if ($route === 'entrar') {
    if ($already = current_user($con)) {
        redirect($next ?? home_for($already));
    }
    $error = null;
    if ($isPost) {
        if (!csrf_ok()) {
            $error = 'Sessão expirada. Tente novamente.';
        } else {
            $error = attempt_login($con, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            if ($error === null) {
                $name = strtolower(trim($_POST['username']));
                audit($con, $name, 'login');
                $role = pg_fetch_result(pg_query_params($con, 'SELECT role FROM admin_users WHERE username = $1', [$name]), 0, 0);
                redirect($next ?? home_for(['role' => $role]));
            }
        }
    }
    start_session();
    $flashes = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $flashes .= '<div class="flash ' . h($type) . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    page('Entrar', $flashes . ($error ? $err($error) : '') . '
        <form method="post" class="card narrow" id="loginform" action="/entrar">' . csrf_field() . '
          <input type="hidden" name="next" id="loginnext" value="' . h($next ?? '') . '">
          <label for="username">Utilizador</label>
          <input type="text" id="username" name="username" value="' . h($_POST['username'] ?? '') . '" autocomplete="username" autocapitalize="none" autofocus required>
          <label for="password">Palavra-passe</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <p><button class="primary">Entrar</button></p>
          <p class="hint"><a href="/esqueci-palavra-passe">Esqueci-me da palavra-passe</a> · <a href="/registar">Criar conta</a></p>
        </form>', null, '', ['/assets/auth.js']);
}

// ---------------------------------------------------------------- sign up (locked until a manager approves)

if ($route === 'registar') {
    if (current_user($con)) {
        redirect('/conta');
    }
    $error = null;
    $v = ['name' => '', 'email' => '', 'username' => ''];
    if ($isPost) {
        $v = ['name' => trim((string)($_POST['name'] ?? '')), 'email' => trim((string)($_POST['email'] ?? '')), 'username' => strtolower(trim((string)($_POST['username'] ?? '')))];
        $pw = (string)($_POST['password'] ?? '');
        $code = (string)($_POST['code'] ?? '');
        $ip = client_ip();
        if (!csrf_ok()) {
            $error = 'Sessão expirada. Tente novamente.';
        } elseif (trim((string)($_POST['ref_empresa'] ?? '')) !== '') {
            // a robot filled the hidden field: look like success, do nothing
            $sent = true;
        } elseif (attempts_count($con, 'code', $ip, 900) >= CODE_FAILURES_PER_IP
            || attempts_count($con, 'signup', $ip, 3600) >= SIGNUP_PER_IP_HOUR || attempts_count($con, 'signup', null, 86400) >= SIGNUP_PER_DAY) {
            $error = 'Demasiados pedidos. Tente novamente mais tarde.';
        } elseif ($p = name_problem($v['name']) ?? email_problem($v['email'])) {
            $error = $p;
        } elseif (!valid_username($v['username'])) {
            $error = 'O nome de utilizador tem de ter 3 a 32 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
        } elseif ($p = password_problem($pw)) {
            $error = $p;
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $error = 'As palavras-passe não coincidem.';
        } elseif (!signup_code_ok($con, $code)) {
            attempts_add($con, 'code', $ip);
            $error = 'O código de acesso não está correto. Peça-o a quem o convidou.';
        } else {
            attempts_add($con, 'signup', $ip);
            $error = create_signup($con, $v['username'], $v['name'], $v['email'], $pw);
            if ($error === null) {
                audit($con, $v['username'], 'conta.pedido', $v['email']);
                send_mail(MANAGER_NOTICE_TO, 'RIFT: novo pedido de conta de ' . $v['name'],
                    "Há um pedido de conta à espera de aprovação.\n\nNome: {$v['name']}\nEmail: {$v['email']}\nUtilizador: {$v['username']}\n\n"
                    . 'Aprove ou rejeite em ' . SITE_URL . "/admin/utilizadores\n");
                $sent = true;
            }
        }
        if (!empty($sent)) {
            page('Pedido enviado', '<div class="card narrow"><p><strong>O seu pedido foi enviado.</strong></p>
                <p>A conta fica bloqueada até um gestor a aprovar. Quando isso acontecer, entre com o nome de utilizador e a palavra-passe que escolheu.</p>
                <p><a href="/entrar">Voltar à entrada</a></p></div>', null);
        }
    }
    page('Criar conta', ($error ? $err($error) : '') . '
        <form method="post" class="card narrow" action="/registar">' . csrf_field() . '
          <p class="hint">A conta só fica ativa depois de um gestor a aprovar. Precisa do código de acesso que lhe foi dado.</p>
          <label for="name">Nome</label>
          <input type="text" id="name" name="name" value="' . h($v['name']) . '" maxlength="80" autocomplete="name" required>
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="' . h($v['email']) . '" maxlength="120" autocomplete="email" required>
          <label for="username">Nome de utilizador</label>
          <input type="text" id="username" name="username" value="' . h($v['username']) . '" maxlength="32" autocomplete="username" autocapitalize="none" required>
          <label for="password">Palavra-passe</label>
          <input type="password" id="password" name="password" autocomplete="new-password" required>
          <label for="password2">Repetir a palavra-passe</label>
          <input type="password" id="password2" name="password2" autocomplete="new-password" required>
          <p class="hint">Pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres. Nome de utilizador: 3 a 32 caracteres (letras minúsculas, números, ponto, hífen ou sublinhado).</p>
          <label for="code">Código de acesso</label>
          <input type="text" id="code" name="code" autocomplete="off" autocapitalize="none" required>
          <div class="hp" aria-hidden="true"><label for="ref_empresa">Não preencher</label><input type="text" id="ref_empresa" name="ref_empresa" tabindex="-1" autocomplete="off"></div>
          <p><button class="primary">Pedir conta</button></p>
          <p class="hint"><a href="/entrar">Já tenho conta</a></p>
        </form>', null);
}

// ---------------------------------------------------------------- forgotten password: ask for the link

if ($route === 'esqueci-palavra-passe') {
    $done = false;
    if ($isPost) {
        if (csrf_ok()) {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $ip = client_ip();
            if (email_problem($email) === null
                && attempts_count($con, 'reset', $ip, 3600) < RESET_PER_KEY_HOUR && attempts_count($con, 'reset', $email, 3600) < RESET_PER_KEY_HOUR) {
                attempts_add($con, 'reset', $ip);
                attempts_add($con, 'reset', $email);
                if ($u = create_reset_token($con, $email)) {
                    audit($con, $u['username'], 'palavra-passe.pedir-reposicao');
                    send_mail($u['email'], 'RIFT: reposição da palavra-passe',
                        "Olá " . ($u['name'] ?: $u['username']) . ",\n\nPediu para repor a palavra-passe da sua conta RIFT (utilizador: {$u['username']}).\n"
                        . "Abra esta ligação (válida durante 1 hora, só pode ser usada uma vez):\n\n" . SITE_URL . '/repor-palavra-passe?token=' . $u['token']
                        . "\n\nSe não foi você, ignore esta mensagem: nada muda.\n");
                }
            }
            $done = true;     // the same answer whether or not the address has an account
        }
    }
    if ($done) {
        page('Esqueci-me da palavra-passe', '<div class="card narrow"><p>Se existir uma conta ativa com esse email, enviámos-lhe uma ligação para escolher uma nova palavra-passe. Pode demorar alguns minutos; veja também a pasta de spam.</p>
            <p><a href="/entrar">Voltar à entrada</a></p></div>', null);
    }
    page('Esqueci-me da palavra-passe', '<form method="post" class="card narrow" action="/esqueci-palavra-passe">' . csrf_field() . '
        <label for="email">Email da conta</label>
        <input type="email" id="email" name="email" maxlength="120" autocomplete="email" autofocus required>
        <p class="hint">Enviamos uma ligação para escolher uma nova palavra-passe. Só funciona se a conta tiver um email registado.</p>
        <p><button class="primary">Enviar ligação</button></p>
        <p class="hint"><a href="/entrar">Voltar à entrada</a></p></form>', null);
}

// ---------------------------------------------------------------- forgotten password: choose the new one

if ($route === 'repor-palavra-passe') {
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $who = reset_token_user($con, $token);
    if (!$who) {
        http_response_code(410);
        page('Ligação inválida', '<div class="card narrow"><p>Esta ligação já foi usada, expirou ou não é válida.</p>
            <p><a href="/esqueci-palavra-passe">Pedir outra</a></p></div>', null);
    }
    $error = null;
    if ($isPost) {
        $new = (string)($_POST['password'] ?? '');
        if (!csrf_ok()) {
            $error = 'Sessão expirada. Volte a abrir a ligação do email.';
        } elseif ($problem = password_problem($new)) {
            $error = $problem;
        } elseif ($new !== (string)($_POST['password2'] ?? '')) {
            $error = 'As palavras-passe não coincidem.';
        } else {
            consume_reset_token($con, $who, $new);
            audit($con, $who['username'], 'palavra-passe.repor');
            flash('ok', 'Palavra-passe alterada. Já pode entrar.');
            redirect('/entrar');
        }
    }
    page('Nova palavra-passe', ($error ? $err($error) : '') . '<form method="post" class="card narrow" action="/repor-palavra-passe">' . csrf_field() . '
        <input type="hidden" name="token" value="' . h($token) . '">
        <p class="hint">Conta: <strong>' . h($who['username']) . '</strong></p>
        <label for="p1">Nova palavra-passe</label><input type="password" id="p1" name="password" autocomplete="new-password" autofocus required>
        <label for="p2">Repetir a nova palavra-passe</label><input type="password" id="p2" name="password2" autocomplete="new-password" required>
        <p class="hint">Pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.</p><p><button class="primary">Guardar</button></p></form>', null);
}

// ---------------------------------------------------------------- my account (every signed-in person)

if ($route === 'conta') {
    $user = current_user($con);
    if (!$user) {
        redirect(login_url('/conta'));
    }
    if ($isPost) {
        if (!csrf_ok()) {
            flash('error', 'Sessão expirada. Tente novamente.');
            back_to('/conta');
        }
        $act = $_POST['action'] ?? '';
        if ($act === 'profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $emailGiven = $email !== '';
            if ($p = name_problem($name) ?? ($emailGiven ? email_problem($email) : null)) {
                flash('error', $p);
            } elseif ($emailGiven && (int)pg_fetch_result(pg_query_params($con, 'SELECT count(*) FROM admin_users WHERE lower(email) = $1 AND id <> $2', [$email, $user['id']]), 0, 0) > 0) {
                flash('error', 'Esse email já está associado a outra conta.');
            } else {
                pg_query_params($con, 'UPDATE admin_users SET name = $1, email = $2 WHERE id = $3', [$name, $emailGiven ? $email : null, $user['id']]);
                audit($con, $user['username'], 'conta.perfil');
                flash('ok', 'Dados guardados.');
            }
        } elseif ($act === 'password') {
            $row = pg_fetch_assoc(pg_query_params($con, 'SELECT password_hash FROM admin_users WHERE id = $1', [$user['id']]));
            $new = (string)($_POST['password'] ?? '');
            if (!verify_password($row['password_hash'], (string)($_POST['current'] ?? ''))) {
                flash('error', 'A palavra-passe atual está incorreta.');
            } elseif ($problem = password_problem($new)) {
                flash('error', $problem);
            } elseif ($new !== (string)($_POST['password2'] ?? '')) {
                flash('error', 'As palavras-passe novas não coincidem.');
            } else {
                pg_query_params($con, 'UPDATE admin_users SET password_hash = $1, password_changed_at = now() WHERE id = $2', [hash_password($new), $user['id']]);
                audit($con, $user['username'], 'palavra-passe.alterar');
                session_regenerate_id(true);
                $_SESSION['login_at'] = microtime(true);      // this session stays; every other one ends
                flash('ok', 'Palavra-passe alterada. As outras sessões foram terminadas.');
            }
        }
        back_to('/conta');
    }
    $hasEmail = (string)$user['email'] !== '';
    page('A minha conta', '
        <p class="sub">Utilizador <strong>' . h($user['username']) . '</strong> · perfil: ' . h(ROLES[$user['role']] ?? $user['role']) . '</p>'
        . ($hasEmail ? '' : '<div class="flash" role="status">Não tem email registado: sem ele não é possível repor a palavra-passe por email.</div>') . '
        <h3>Os meus dados</h3>
        <form method="post" class="card narrow" action="/conta">' . csrf_field() . '<input type="hidden" name="action" value="profile">
          <label for="name">Nome</label><input type="text" id="name" name="name" value="' . h($user['name'] ?? '') . '" maxlength="80" autocomplete="name" required>
          <label for="email">Email</label><input type="email" id="email" name="email" value="' . h($user['email'] ?? '') . '" maxlength="120" autocomplete="email">
          <p><button class="primary">Guardar</button></p></form>
        <h3>Palavra-passe</h3>
        <form method="post" class="card narrow" action="/conta">' . csrf_field() . '<input type="hidden" name="action" value="password">
          <label for="c">Palavra-passe atual</label><input type="password" id="c" name="current" autocomplete="current-password" required>
          <label for="n1">Nova palavra-passe</label><input type="password" id="n1" name="password" autocomplete="new-password" required>
          <label for="n2">Repetir a nova palavra-passe</label><input type="password" id="n2" name="password2" autocomplete="new-password" required>
          <p class="hint">Pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres. Ao mudar, as outras sessões desta conta terminam.</p><p><button class="primary">Alterar</button></p></form>', $user, 'conta');
}

http_response_code(404);
page('Não encontrado', '<p>Esta página não existe. <a href="/entrar">Entrar</a></p>', null);
