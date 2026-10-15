<?php

// Admin area, served under /admin/... (Apache rewrites everything there to this script).
// Pages: reunioes (weekly meetings), dias-especiais, correcoes (fix counts), utilizadores (only for
// users who can manage users), palavra-passe (own password), registo (audit log).

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/auth.php");
require(__DIR__ . "/src/services.php");
require(__DIR__ . "/src/stats_pages.php");
require(__DIR__ . "/src/admin_ui.php");

$con = db_connect();

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$route = str_starts_with($path, '/admin/') ? substr($path, 6) : '/';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ---------------------------------------------------------------- JSON API
// Used by the statistics page and by the report dialog on the public pages (same origin; the session cookie is
// limited to /admin, which is why these live here). Anonymous callers only learn that they are not logged in.

if (str_starts_with($route, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $apiUser = current_user($con);
    $out = function ($data, int $status = 200): never {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($route === '/api/me') {
        $out($apiUser ? ['loggedIn' => true, 'user' => $apiUser['username'], 'csrf' => csrf_token()] : ['loggedIn' => false]);
    }
    if (!$apiUser) {
        $out(['error' => 'login'], 401);
    }
    $nowApi = new DateTimeImmutable('now', utc());

    if ($route === '/api/report') {
        if ($isPost) {
            if (!csrf_ok()) {
                $out(['error' => 'csrf'], 400);
            }
            [$err, $saved] = report_save($con, $apiUser['username'], $nowApi, $_POST);
            if ($err) {
                $out(['error' => $err], 422);
            }
            audit($con, $apiUser['username'], 'relatorio.guardar', ($_POST['day'] ?? '') . ' ' . ($_POST['time'] ?? ''));
            $out(['ok' => true, 'report' => $saved]);
        }
        $day = (string)($_GET['day'] ?? '');
        $time = (string)($_GET['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $out(['error' => 'bad request'], 400);
        }
        $out(['report' => report_get($con, $day, $time), 'suggest' => report_suggestions($con, $day, $time)]);
    }

    if ($route === '/api/stats') {
        $part = (string)($_GET['part'] ?? 'week');
        if ($part === 'week') {
            $d = (string)($_GET['week'] ?? '');
            $sun = week_start(preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : $nowApi->setTimezone(local_tz())->format('Y-m-d'));
            $out(stats_week_data($con, $nowApi, $sun));
        }
        if ($part === 'month') {
            $ym = (string)($_GET['month'] ?? $nowApi->setTimezone(local_tz())->format('Y-m'));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
                $out(['error' => 'bad request'], 400);
            }
            $prev = (new DateTimeImmutable("$ym-01"))->modify('-1 month')->format('Y-m');
            $out(['month' => stats_month_summary($con, $ym), 'previous' => stats_month_summary($con, $prev)]);
        }
        if ($part === 'year') {
            $y = (int)($_GET['year'] ?? $nowApi->setTimezone(local_tz())->format('Y'));
            if ($y < 2020 || $y > 2100) {
                $out(['error' => 'bad request'], 400);
            }
            $out(stats_year_data($con, $y));
        }
    }
    $out(['error' => 'not found'], 404);
}

// ---------------------------------------------------------------- login / logout

if ($route === '/entrar') {
    if (current_user($con)) {
        redirect('/admin/reunioes');
    }
    $error = null;
    if ($isPost) {
        if (!csrf_ok()) {
            $error = 'Sessão expirada. Tente novamente.';
        } else {
            $error = attempt_login($con, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            if ($error === null) {
                audit($con, strtolower(trim($_POST['username'])), 'login');
                redirect('/admin/reunioes');
            }
        }
    }
    page('Entrar', ($error ? '<div class="flash error" role="alert">' . h($error) . '</div>' : '') . '
        <form method="post" class="card narrow">' . csrf_field() . '
          <label for="username">Utilizador</label>
          <input type="text" id="username" name="username" value="' . h($_POST['username'] ?? '') . '" autocomplete="username" autofocus required>
          <label for="password">Palavra-passe</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <p><button class="primary">Entrar</button></p>
        </form>', null);
}

$user = current_user($con);
if (!$user) {
    redirect('/admin/entrar');
}

if ($isPost && !csrf_ok()) {
    http_response_code(400);
    page('Pedido inválido', '<p>A sessão expirou ou o pedido não é válido. <a href="' . h($path) . '">Voltar</a></p>', $user);
}

if ($route === '/sair' && $isPost) {
    audit($con, $user['username'], 'logout');
    logout();
    redirect('/admin/entrar');
}

// ---------------------------------------------------------------- helpers

function back(?string $to = null): never
{
    redirect($to ?? $_SERVER['REQUEST_URI']);
}

function is_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function is_date(string $d): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function int_in(string $v, int $min, int $max): ?int
{
    return preg_match('/^-?\d{1,6}$/', $v) && (int)$v >= $min && (int)$v <= $max ? (int)$v : null;
}

function weekday_options(int $selected): string
{
    $o = '';
    foreach (WEEKDAYS as $i => $name) {
        $o .= '<option value="' . $i . '"' . ($i === $selected ? ' selected' : '') . '>' . h(ucfirst($name)) . '</option>';
    }
    return $o;
}

function rows($con, string $sql, array $params = []): array
{
    return pg_fetch_all(pg_query_params($con, $sql, $params)) ?: [];
}

// ---------------------------------------------------------------- reuniões (weekly meetings)

if ($route === '/reunioes') {
    if ($isPost) {
        $act = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($act === 'delete') {
            $r = pg_query_params($con, 'DELETE FROM services WHERE id = $1 RETURNING name, weekday, start_time', [$id]);
            if ($old = pg_fetch_assoc($r)) {
                audit($con, $user['username'], 'reuniao.remover', "{$old['name']} (" . WEEKDAYS[$old['weekday']] . " {$old['start_time']})");
                flash('ok', 'Reunião removida. As contagens já guardadas mantêm-se.');
            }
            back();
        }
        $name = trim($_POST['name'] ?? '');
        $weekday = int_in((string)($_POST['weekday'] ?? ''), 0, 6);
        $time = (string)($_POST['start_time'] ?? '');
        $duration = int_in((string)($_POST['duration_min'] ?? ''), 15, 600);
        $active = isset($_POST['active']) || $act === 'add';
        if ($name === '' || mb_strlen($name) > 60 || $weekday === null || !is_time($time) || $duration === null) {
            flash('error', 'Dados inválidos: indique um nome (até 60 caracteres), o dia, a hora (HH:MM) e a duração (15 a 600 minutos).');
            back();
        }
        $dup = rows($con, 'SELECT 1 FROM services WHERE weekday = $1 AND start_time = $2 AND id <> $3', [$weekday, $time, $id]);
        if ($dup) {
            flash('error', 'Já existe uma reunião nesse dia e a essa hora.');
            back();
        }
        if ($act === 'add') {
            pg_query_params($con, 'INSERT INTO services (name, weekday, start_time, duration_min, active) VALUES ($1, $2, $3, $4, true)', [$name, $weekday, $time, $duration]);
            audit($con, $user['username'], 'reuniao.adicionar', "$name (" . WEEKDAYS[$weekday] . " $time, $duration min)");
            flash('ok', 'Reunião adicionada.');
        } elseif ($act === 'update') {
            pg_query_params($con, 'UPDATE services SET name = $1, weekday = $2, start_time = $3, duration_min = $4, active = $5 WHERE id = $6',
                [$name, $weekday, $time, $duration, $active ? 't' : 'f', $id]);
            audit($con, $user['username'], 'reuniao.alterar', "$name (" . WEEKDAYS[$weekday] . " $time, $duration min, " . ($active ? 'ativa' : 'inativa') . ')');
            flash('ok', 'Reunião guardada. As alterações aplicam-se às reuniões que ainda não começaram.');
        }
        back();
    }

    $body = '<p class="hint">Reuniões que se repetem todas as semanas. Um dia especial substitui as reuniões habituais da sua data. As alterações aplicam-se às reuniões que ainda não começaram; as contagens já guardadas não mudam.</p>
      <h3>Adicionar reunião</h3><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="action" value="add"><div class="row">
        <div><label for="n0">Nome</label><input type="text" id="n0" name="name" maxlength="60" required></div>
        <div><label for="w0">Dia</label><select id="w0" name="weekday">' . weekday_options(0) . '</select></div>
        <div><label for="t0">Hora</label><input type="time" id="t0" name="start_time" required></div>
        <div><label for="d0">Duração (min)</label><input type="number" id="d0" name="duration_min" value="120" min="15" max="600" required></div>
        <div><button class="primary">Adicionar</button></div></div></form>
      <h3>Reuniões semanais</h3><div class="tablewrap"><table class="admin"><thead><tr><th>Nome</th><th>Dia</th><th>Hora</th><th>Duração (min)</th><th>Ativa</th><th></th></tr></thead><tbody>';
    foreach (rows($con, 'SELECT * FROM services ORDER BY weekday, start_time') as $s) {
        $f = 'f' . $s['id'];
        $body .= '<tr><td><input form="' . $f . '" type="text" name="name" value="' . h($s['name']) . '" maxlength="60" aria-label="Nome" required></td>'
            . '<td><select form="' . $f . '" name="weekday" aria-label="Dia">' . weekday_options((int)$s['weekday']) . '</select></td>'
            . '<td><input form="' . $f . '" type="time" name="start_time" value="' . h(substr($s['start_time'], 0, 5)) . '" aria-label="Hora" required></td>'
            . '<td><input form="' . $f . '" type="number" name="duration_min" value="' . h($s['duration_min']) . '" min="15" max="600" aria-label="Duração" required></td>'
            . '<td><input form="' . $f . '" type="checkbox" name="active" value="1"' . ($s['active'] === 't' ? ' checked' : '') . ' aria-label="Ativa"></td>'
            . '<td><div class="actions"><form id="' . $f . '" method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . h($s['id']) . '"><button name="action" value="update" class="primary">Guardar</button></form>'
            . '<form method="post" data-confirm="Remover esta reunião semanal?">' . csrf_field() . '<input type="hidden" name="id" value="' . h($s['id']) . '"><button name="action" value="delete" class="danger">Remover</button></form></div></td></tr>';
    }
    $body .= '</tbody></table></div>';
    page('Reuniões', $body, $user, 'reunioes');
}

// ---------------------------------------------------------------- dias especiais

if ($route === '/dias-especiais') {
    if ($isPost) {
        $act = $_POST['action'] ?? '';
        if ($act === 'sync') {
            exec('/usr/bin/php ' . escapeshellarg(__DIR__ . '/pco_sync.php') . ' > /dev/null 2>&1 &');
            audit($con, $user['username'], 'pco.sincronizar', 'pedida manualmente');
            flash('ok', 'Sincronização com o Planning Center iniciada. Demora cerca de um minuto; atualize esta página para ver o resultado.');
            back();
        }
        $day = (string)($_POST['day'] ?? '');
        if (!is_date($day)) {
            flash('error', 'Data inválida.');
            back();
        }
        if ($act === 'delete') {
            pg_query_params($con, 'DELETE FROM special_days WHERE day = $1', [$day]);
            audit($con, $user['username'], 'dia-especial.remover', $day);
            flash('ok', 'Dia especial removido; voltam as reuniões habituais dessa data.');
            back('/admin/dias-especiais');
        }
        $name = trim($_POST['name'] ?? '');
        $times = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', (string)($_POST['times'] ?? '')))));
        $duration = int_in((string)($_POST['duration_min'] ?? ''), 15, 600);
        $bad = array_filter($times, fn($t) => !is_time($t));
        if ($name === '' || mb_strlen($name) > 60 || !$times || $bad || $duration === null) {
            flash('error', 'Dados inválidos: indique um nome, pelo menos uma hora (HH:MM, separadas por espaços, vírgulas ou linhas) e a duração.');
            back();
        }
        pg_query($con, 'BEGIN');
        pg_query_params($con, 'DELETE FROM special_days WHERE day = $1', [$day]);
        foreach ($times as $t) {
            pg_query_params($con, 'INSERT INTO special_days (day, name, start_time, duration_min) VALUES ($1, $2, $3, $4)', [$day, $name, $t, $duration]);
        }
        pg_query($con, 'COMMIT');
        audit($con, $user['username'], 'dia-especial.guardar', "$day $name: " . implode(', ', $times) . " ($duration min)");
        flash('ok', 'Dia especial guardado. Substitui as reuniões habituais de ' . $day . '.');
        back('/admin/dias-especiais');
    }

    $edit = (string)($_GET['edit'] ?? '');
    $cur = ['day' => '', 'name' => '', 'times' => '', 'duration_min' => 120];
    if (is_date($edit)) {
        $r = rows($con, "SELECT day, name, to_char(start_time, 'HH24:MI') AS t, duration_min FROM special_days WHERE day = $1 ORDER BY start_time", [$edit]);
        if ($r) {
            $cur = ['day' => $edit, 'name' => $r[0]['name'], 'times' => implode(' ', array_column($r, 't')), 'duration_min' => $r[0]['duration_min']];
        }
    }
    $st = rows($con, "SELECT last_attempt, last_ok, plans, error, detail FROM pco_sync_status WHERE id = 1")[0] ?? [];
    $syncBox = '<div class="card"><strong>Planning Center</strong> · ' . (!empty($st['last_ok']) ? 'última sincronização com sucesso: ' . h(fmt_local($st['last_ok'], 'd/m/Y H:i')) : 'ainda não sincronizado')
        . (!empty($st['error']) ? '<div class="flash error" role="alert">Último erro (' . h(fmt_local($st['last_attempt'], 'd/m/Y H:i')) . '): ' . h($st['error']) . '</div>' : '')
        . (!empty($st['detail']) ? '<div class="hint">' . h($st['detail']) . '</div>' : '')
        . '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="sync"><button>Sincronizar agora</button></form>'
        . '<p class="hint">Os dias que não são domingo e têm um plano no Planning Center (pastas Porto e Young Adults &amp; Youth, sem Kids) tornam-se dias especiais com o nome do plano. '
        . 'Um dia especial criado aqui tem prioridade sobre o do Planning Center.</p></div>';
    $body = $syncBox . '<p class="hint">Um dia especial substitui as reuniões habituais da sua data (por exemplo, o Especial de Natal a 19 de dezembro só às 11:30 e 17:30). Guardar uma data que já existe substitui as horas anteriores.</p>
      <h3>' . ($cur['day'] ? 'Editar dia especial' : 'Adicionar dia especial') . '</h3><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="action" value="save"><div class="row">
        <div><label for="day">Data</label><input type="date" id="day" name="day" value="' . h($cur['day']) . '" required></div>
        <div><label for="name">Nome</label><input type="text" id="name" name="name" value="' . h($cur['name']) . '" maxlength="60" required></div>
        <div><label for="times">Horas</label><input type="text" id="times" name="times" value="' . h($cur['times']) . '" placeholder="11:30 17:30" required></div>
        <div><label for="dur">Duração (min)</label><input type="number" id="dur" name="duration_min" value="' . h($cur['duration_min']) . '" min="15" max="600" required></div>
        <div><button class="primary">Guardar</button></div></div></form>
      <h3>Dias especiais</h3><p class="hint">Os do Planning Center só se alteram lá; para mudar um aqui, use «Substituir» (cria um dia especial manual para essa data).</p><div class="tablewrap"><table class="admin"><thead><tr><th>Data</th><th>Nome</th><th>Horas</th><th>Duração</th><th></th></tr></thead><tbody>';
    $days = rows($con, "SELECT day, name, string_agg(to_char(start_time, 'HH24:MI'), ' ' ORDER BY start_time) AS times, max(duration_min) AS dur, source FROM special_days GROUP BY day, name, source ORDER BY day DESC, name");
    foreach ($days as $d) {
        $body .= '<tr><td>' . h(date('d/m/Y', strtotime($d['day']))) . '</td><td>' . h($d['name']) . '</td><td>' . h($d['times']) . '</td><td>' . h($d['dur']) . ' min</td>'
            . '<td><div class="actions">' . ($d['source'] === 'pco' ? '<span class="tag">Planning Center</span><a href="/admin/dias-especiais?edit=' . h($d['day']) . '"><button type="button">Substituir</button></a>'
                : '<a href="/admin/dias-especiais?edit=' . h($d['day']) . '"><button type="button">Editar</button></a>'
                . '<form method="post" data-confirm="Remover este dia especial?">' . csrf_field() . '<input type="hidden" name="day" value="' . h($d['day']) . '"><button name="action" value="delete" class="danger">Remover</button></form>') . '</div></td></tr>';
    }
    if (!$days) {
        $body .= '<tr><td colspan="5" class="hint">Ainda não há dias especiais.</td></tr>';
    }
    $body .= '</tbody></table></div>';
    page('Dias especiais', $body, $user, 'dias-especiais');
}

// ---------------------------------------------------------------- correções

if ($route === '/correcoes') {
    $now = new DateTimeImmutable('now', utc());
    $weekParam = (string)($_GET['week'] ?? $_POST['week'] ?? '');
    $sunday = week_start(is_date($weekParam) ? $weekParam : $now->setTimezone(local_tz())->format('Y-m-d'));
    $self = '/admin/correcoes?week=' . $sunday;

    if ($isPost) {
        $act = $_POST['action'] ?? '';
        $kind = $_POST['kind'] ?? '';
        $day = (string)($_POST['day'] ?? '');
        $time = (string)($_POST['start_time'] ?? '');
        if (!in_array($kind, ['s', 'e'], true) || !is_date($day) || ($kind === 's' && !is_time($time))) {
            flash('error', 'Pedido inválido.');
            back($self);
        }
        [$table, $where, $keys] = $kind === 's'
            ? ['service_snapshots', 'day = $1 AND start_time = $2', [$day, $time]]
            : ['event_snapshots', 'day = $1', [$day]];
        $label = $kind === 's' ? "reunião $day $time" : "evento $day";

        if ($act === 'correct') {
            $value = int_in((string)($_POST['value'] ?? ''), 0, 100000);
            $note = trim($_POST['note'] ?? '');
            if ($value === null || $note === '' || mb_strlen($note) > 200) {
                flash('error', 'Indique o valor correto (um número) e uma nota a explicar a correção (até 200 caracteres).');
                back($self);
            }
            $n = count($keys);
            $r = pg_query_params($con, "UPDATE $table SET corrected_peak = \$" . ($n + 1) . ", correction_note = \$" . ($n + 2)
                . ", corrected_by = \$" . ($n + 3) . ", corrected_at = now() WHERE $where", array_merge($keys, [$value, $note, $user['username']]));
            if (pg_affected_rows($r)) {
                audit($con, $user['username'], 'correcao.guardar', "$label = $value ($note)");
                flash('ok', 'Correção guardada.');
            }
        } elseif ($act === 'clear') {
            $r = pg_query_params($con, "UPDATE $table SET corrected_peak = NULL, correction_note = NULL, corrected_by = NULL, corrected_at = NULL WHERE $where", $keys);
            if (pg_affected_rows($r)) {
                audit($con, $user['username'], 'correcao.remover', $label);
                flash('ok', 'Correção removida.');
            }
        } elseif ($act === 'recalc') {
            $r = pg_query_params($con, "DELETE FROM $table WHERE $where", $keys);
            if (pg_affected_rows($r)) {
                audit($con, $user['username'], 'correcao.recalcular', $label);
                flash('ok', 'Valor guardado apagado; é recalculado a partir dos dados do contador.');
            }
        }
        back($self);
    }

    services_week($con, $now, $sunday); // bring the week's snapshots up to date first
    $sv = rows($con, "SELECT *, to_char(start_time, 'HH24:MI') AS t FROM service_snapshots WHERE day BETWEEN $1::date AND $1::date + 6 ORDER BY day, start_time", [$sunday]);
    $ev = rows($con, 'SELECT * FROM event_snapshots WHERE day BETWEEN $1::date AND $1::date + 6 AND peak IS NOT NULL ORDER BY day', [$sunday]);
    $prev = (new DateTimeImmutable($sunday))->modify('-7 days')->format('Y-m-d');
    $next = (new DateTimeImmutable($sunday))->modify('+7 days')->format('Y-m-d');
    $end = (new DateTimeImmutable($sunday))->modify('+6 days');

    $line = function (string $kind, array $r, string $title, string $when) use ($sunday): string {
        $f = $kind . $r['day'] . ($r['t'] ?? '');
        $hidden = csrf_field() . '<input type="hidden" name="week" value="' . h($sunday) . '"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="day" value="' . h($r['day']) . '">'
            . ($kind === 's' ? '<input type="hidden" name="start_time" value="' . h($r['t']) . '">' : '');
        $corrected = $r['corrected_peak'] !== null;
        $state = ($r['frozen'] === 't' ? 'terminada' : 'em curso') . (($r['partial'] ?? 'f') === 't' ? ' · dados parciais' : '');
        return '<tr><td>' . h($title) . '<div class="hint">' . h($when) . '</div></td>'
            . '<td>' . h($r['peak'] ?? '–') . '<div class="hint">' . h($state) . '</div></td>'
            . '<td><input form="' . $f . '" type="number" name="value" min="0" max="100000" value="' . h($r['corrected_peak'] ?? '') . '" aria-label="Valor correto" required></td>'
            . '<td><input form="' . $f . '" type="text" name="note" maxlength="200" value="' . h($r['correction_note'] ?? '') . '" aria-label="Nota" placeholder="Motivo da correção" required>'
            . ($corrected ? '<div class="hint">por ' . h($r['corrected_by']) . ', ' . h(fmt_local($r['corrected_at'])) . '</div>' : '') . '</td>'
            . '<td><div class="actions"><form id="' . $f . '" method="post">' . $hidden . '<button name="action" value="correct" class="primary">Guardar</button></form>'
            . ($corrected ? '<form method="post">' . $hidden . '<button name="action" value="clear">Remover correção</button></form>' : '')
            . '<form method="post" data-confirm="Apagar o valor guardado e recalcular? Uma correção feita será perdida.">' . $hidden . '<button name="action" value="recalc" class="danger">Recalcular</button></form></div></td></tr>';
    };

    $rows = '';
    foreach ($sv as $r) {
        $rows .= $line('s', $r, $r['name'], ucfirst(WEEKDAYS[(int)date('w', strtotime($r['day']))]) . ', ' . date('d/m', strtotime($r['day'])) . ' às ' . $r['t']);
    }
    foreach ($ev as $r) {
        $rows .= $line('e', $r, 'Evento', ucfirst(WEEKDAYS[(int)date('w', strtotime($r['day']))]) . ', ' . date('d/m', strtotime($r['day'])));
    }

    $body = '<p class="hint">Corrija o valor mostrado de uma reunião ou evento (por exemplo, quando o contador começou depois do início da reunião). A correção substitui o valor calculado no site e fica registada com a nota.</p>
      <div class="actions"><a href="/admin/correcoes?week=' . h($prev) . '"><button type="button">◀ Semana anterior</button></a>
      <strong>' . h(date('d/m/Y', strtotime($sunday))) . ' – ' . h($end->format('d/m/Y')) . '</strong>
      <a href="/admin/correcoes?week=' . h($next) . '"><button type="button">Semana seguinte ▶</button></a></div>
      <div class="tablewrap"><table class="admin"><thead><tr><th>Reunião / evento</th><th>Calculado</th><th>Correto</th><th>Nota</th><th></th></tr></thead><tbody>'
        . ($rows ?: '<tr><td colspan="5" class="hint">Ainda não há valores guardados nesta semana (as reuniões só aparecem aqui depois de começarem).</td></tr>')
        . '</tbody></table></div>';
    page('Correções', $body, $user, 'correcoes');
}

// ---------------------------------------------------------------- pessoas (MC, oferta, líder, pregador)

if ($route === '/pessoas') {
    $now = new DateTimeImmutable('now', utc());
    $weekParam = (string)($_GET['week'] ?? $_POST['week'] ?? '');
    $sunday = week_start(is_date($weekParam) ? $weekParam : $now->setTimezone(local_tz())->format('Y-m-d'));
    $self = '/admin/pessoas?week=' . $sunday;

    if ($isPost) {
        $day = (string)($_POST['day'] ?? '');
        $time = (string)($_POST['start_time'] ?? '');
        if (!is_date($day) || !is_time($time)) {
            flash('error', 'Pedido inválido.');
            back($self);
        }
        $changes = [];
        foreach (array_keys(PEOPLE_ROLES) as $role) {
            $value = trim(preg_replace('/\s+/u', ' ', (string)($_POST[$role] ?? '')));
            if (mb_strlen($value) > 120) {
                flash('error', 'Cada nome pode ter no máximo 120 caracteres.');
                back($self);
            }
            if ($value === '') {
                $r = pg_query_params($con, 'DELETE FROM people_overrides WHERE day = $1 AND start_time = $2 AND role = $3', [$day, $time, $role]);
                if (pg_affected_rows($r)) {
                    $changes[] = "$role: removido";
                }
            } else {
                $r = pg_query_params($con, "INSERT INTO people_overrides (day, start_time, role, value, set_by) VALUES ($1, $2, $3, $4, $5)
                    ON CONFLICT (day, start_time, role) DO UPDATE SET value = EXCLUDED.value, set_by = EXCLUDED.set_by, set_at = now()
                    WHERE people_overrides.value IS DISTINCT FROM EXCLUDED.value", [$day, $time, $role, $value, $user['username']]);
                if (pg_affected_rows($r)) {
                    $changes[] = "$role = $value";
                }
            }
        }
        if ($changes) {
            audit($con, $user['username'], 'pessoas.guardar', "$day $time: " . implode('; ', $changes));
            flash('ok', 'Guardado.');
        }
        back($self);
    }

    $services = scheduled_services($con, $sunday);
    $shown = week_people($con, $sunday, $services);
    $manual = [];
    foreach (rows($con, "SELECT day, to_char(start_time, 'HH24:MI') AS t, role, value FROM people_overrides WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday]) as $r) {
        $manual[$r['day'] . ' ' . $r['t']][$r['role']] = $r['value'];
    }
    $prev = (new DateTimeImmutable($sunday))->modify('-7 days')->format('Y-m-d');
    $next = (new DateTimeImmutable($sunday))->modify('+7 days')->format('Y-m-d');
    $end = (new DateTimeImmutable($sunday))->modify('+6 days');

    $body = '<p class="hint">Os nomes vêm do Planning Center (notas «Person» dos itens MC, Oferta e Pregação, e o líder de louvor da equipa). Escreva um nome para o substituir só nesta reunião; deixe vazio para voltar ao do Planning Center. Os valores do Planning Center aparecem a cinzento.</p>
      <div class="actions"><a href="/admin/pessoas?week=' . h($prev) . '"><button type="button">◀ Semana anterior</button></a>
      <strong>' . h(date('d/m/Y', strtotime($sunday))) . ' – ' . h($end->format('d/m/Y')) . '</strong>
      <a href="/admin/pessoas?week=' . h($next) . '"><button type="button">Semana seguinte ▶</button></a></div>';
    if (!$services) {
        $body .= '<p class="hint">Sem reuniões nesta semana.</p>';
    } else {
        $body .= '<div class="tablewrap"><table class="admin"><thead><tr><th>Reunião</th>';
        foreach (PEOPLE_ROLES as $label) {
            $body .= '<th>' . h($label) . '</th>';
        }
        $body .= '<th></th></tr></thead><tbody>';
        foreach ($services as $s) {
            $t = substr($s['start_time'], 0, 5);
            $key = $s['day'] . ' ' . $t;
            $f = 'f' . preg_replace('/\D/', '', $key);
            $pco = null;
            $body .= '<tr><td>' . h($s['name']) . '<div class="hint">' . h(ucfirst(WEEKDAYS[(int)date('w', strtotime($s['day']))]) . ', ' . date('d/m', strtotime($s['day'])) . ' às ' . $t) . '</div></td>';
            foreach (PEOPLE_ROLES as $role => $label) {
                $p = $shown[$key][$role] ?? ['value' => null, 'source' => null, 'flag' => null];
                $override = $manual[$key][$role] ?? '';
                // what Planning Center alone would show: recompute without the override when one is set
                $pcoValue = $p['source'] === 'pco' ? $p['value'] : null;
                if ($override !== '') {
                    $pcoValue = pco_value_for($con, $sunday, $services, $key, $role);
                }
                $body .= '<td><input form="' . $f . '" type="text" name="' . $role . '" value="' . h($override) . '" maxlength="120" aria-label="' . h($label) . '" placeholder="' . h($pcoValue ?? '—') . '">'
                    . (!empty($p['flag']) && $override === '' ? '<div class="hint">texto livre no Planning Center</div>' : '') . '</td>';
            }
            $body .= '<td><form id="' . $f . '" method="post">' . csrf_field() . '<input type="hidden" name="week" value="' . h($sunday) . '"><input type="hidden" name="day" value="' . h($s['day']) . '"><input type="hidden" name="start_time" value="' . h($t) . '"><button class="primary">Guardar</button></form></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }
    page('Pessoas', $body, $user, 'pessoas');
}

// ---------------------------------------------------------------- estatísticas

if ($route === '/estatisticas') {
    $fields = [
        'late_grace_min' => ['Tolerância de atraso (minutos)', 'number'],
        'devotional_morning' => ['Devocional da manhã (se o plano não tiver)', 'time'],
        'devotional_afternoon' => ['Devocional da tarde (se o plano não tiver)', 'time'],
        'exit_ref_1' => ['Saída: 1.ª hora de referência', 'time'],
        'exit_ref_2' => ['Saída: 2.ª hora de referência', 'time'],
        'exit_threshold' => ['Saída: «vazio» quando o saldo é, no máximo,', 'number'],
    ];
    if ($isPost) {
        $new = [];
        foreach ($fields as $key => $_) {
            $new[$key] = trim((string)($_POST[$key] ?? ''));
            if ($problem = stats_setting_problem($key, $new[$key])) {
                flash('error', $fields[$key][0] . ': ' . $problem);
                back();
            }
        }
        $old = stats_settings($con);
        $changed = [];
        foreach ($new as $key => $value) {
            if ($old[$key] !== $value) {
                pg_query_params($con, "INSERT INTO settings (key, value, set_by) VALUES ($1, $2, $3)
                    ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, set_by = EXCLUDED.set_by, set_at = now()", [$key, $value, $user['username']]);
                $changed[] = "$key: {$old[$key]} → $value";
            }
        }
        if ($changed) {
            audit($con, $user['username'], 'estatisticas.definicoes', implode('; ', $changed));
            flash('ok', 'Definições guardadas. Aplicam-se às reuniões que ainda não terminaram; as já terminadas mantêm os valores com que foram calculadas.');
        }
        back();
    }

    $settings = stats_settings($con);
    $form = '<details class="card"><summary><strong>Definições</strong> <span class="hint">tolerância de atraso, devocionais, velocidade de saída</span></summary>'
        . '<p class="hint">Alterar uma definição só afeta as reuniões que ainda não terminaram: as já guardadas mantêm os valores com que foram calculadas (a tolerância usada fica registada em cada uma).</p>'
        . '<form method="post">' . csrf_field() . '<div class="row">';
    foreach ($fields as $key => [$label, $type]) {
        $form .= '<div><label for="s_' . $key . '">' . h($label) . '</label><input id="s_' . $key . '" name="' . $key . '" type="' . $type . '" value="' . h($settings[$key]) . '"'
            . ($type === 'number' ? ' min="0" step="1"' : '') . ' required></div>';
    }
    $form .= '</div><p><button class="primary">Guardar definições</button></p></form></details>';

    $body = '<p class="hint">Números do contador de entradas e saídas e os relatórios guardados a partir do diálogo de cada reunião (só com sessão iniciada). '
        . 'Os dados do contador começam a 11/10/2026; antes disso não há estatísticas.</p>'
        . '<div id="stats" class="stats"><div class="hint">A carregar…</div></div>'
        . $form;
    page('Estatísticas', $body, $user, 'estatisticas', ['/assets/stats.js']);
}

// ---------------------------------------------------------------- contadores (manual balance reset)

if ($route === '/contadores') {
    if ($isPost) {
        if (($_POST['action'] ?? '') === 'reset') {
            $note = trim($_POST['note'] ?? '');
            if (mb_strlen($note) > 200) {
                flash('error', 'A nota pode ter no máximo 200 caracteres.');
            } elseif (rows($con, "SELECT 1 FROM balance_resets WHERE at > (now() AT TIME ZONE 'UTC') - interval '1 minute'")) {
                flash('error', 'O saldo já foi reposto há menos de um minuto.');
            } else {
                $r = pg_fetch_assoc(pg_query_params($con, "INSERT INTO balance_resets (at, created_by, note) VALUES ((now() AT TIME ZONE 'UTC'), $1, $2) RETURNING id, at", [$user['username'], $note !== '' ? $note : null]));
                audit($con, $user['username'], 'saldo.repor', 'reposição #' . $r['id'] . ($note !== '' ? " ($note)" : ''));
                flash('ok', 'Saldo reposto a zero. As leituras do contador a partir de agora contam a partir de zero (o contador envia os dados com cerca de 4 minutos de atraso).');
            }
        } elseif (($_POST['action'] ?? '') === 'undo') {
            $r = pg_query_params($con, 'DELETE FROM balance_resets WHERE id = $1 RETURNING at', [(int)($_POST['id'] ?? 0)]);
            if ($old = pg_fetch_assoc($r)) {
                audit($con, $user['username'], 'saldo.anular-reposicao', 'reposição de ' . fmt_local($old['at']));
                flash('ok', 'Reposição anulada: o saldo volta a somar desde a reposição anterior.');
            }
        }
        back();
    }

    $last = rows($con, "SELECT balance, \"timestamp\", epoch FROM balance_entries($1, $2, $3, $4) ORDER BY \"timestamp\" DESC, logentryid DESC LIMIT 1",
        [day_start_utc(new DateTimeImmutable('now', utc())), OPENING_BALANCE, RESET_HOUR, LOCAL_TZ])[0] ?? null;
    $resetNow = manual_resets_since($con, new DateTimeImmutable('-1 day', utc()));
    $lastReset = $resetNow ? end($resetNow) : null;
    if ($last && $lastReset && $last['epoch'] < $lastReset) {
        $last = ['balance' => 0, 'timestamp' => $lastReset, 'epoch' => $lastReset, 'reset' => true];   // reset pressed, no newer reading yet
    }
    $body = '<p class="hint">O saldo de pessoas dentro do edifício repõe-se sozinho a zero todos os dias às ' . str_pad((string)RESET_HOUR, 2, '0', STR_PAD_LEFT)
        . ':00. Se tiver a certeza de que não está ninguém no edifício, pode repô-lo agora: a partir deste momento o saldo conta de zero, tal como na reposição das ' . str_pad((string)RESET_HOUR, 2, '0', STR_PAD_LEFT)
        . ':00. As contagens das reuniões já terminadas não mudam, e o máximo de uma reunião a decorrer mantém o que já atingiu. Isto não altera o contador do dispositivo.</p>'
        . '<div class="card"><p>Saldo atual estimado: <strong>' . ($last ? h($last['balance']) : '–') . '</strong>'
        . ($last ? ' <span class="hint">(' . (!empty($last['reset']) ? 'reposto às ' : 'última leitura às ') . h(fmt_local($last['timestamp'], 'H:i')) . ')</span>' : '') . '</p>'
        . '<form method="post" data-confirm="Confirma que não está ninguém no edifício? O saldo vai voltar a zero agora.">' . csrf_field() . '<input type="hidden" name="action" value="reset">'
        . '<div class="row"><div><label for="note">Nota (opcional)</label><input type="text" id="note" name="note" maxlength="200" placeholder="Ex.: edifício vazio entre reuniões"></div>'
        . '<div><button class="danger">Repor saldo a zero agora</button></div></div></form></div>'
        . '<h3>Reposições manuais</h3><div class="tablewrap"><table class="admin"><thead><tr><th>Quando</th><th>Quem</th><th>Nota</th><th></th></tr></thead><tbody>';
    $resets = rows($con, 'SELECT * FROM balance_resets ORDER BY at DESC LIMIT 30');
    foreach ($resets as $r) {
        $body .= '<tr><td>' . h(fmt_local($r['at'], 'd/m/Y H:i:s')) . '</td><td>' . h($r['created_by']) . '</td><td>' . h($r['note'] ?? '') . '</td>'
            . '<td><form method="post" data-confirm="Anular esta reposição? O saldo volta a somar desde a reposição anterior.">' . csrf_field() . '<input type="hidden" name="id" value="' . h($r['id']) . '"><button name="action" value="undo">Anular</button></form></td></tr>';
    }
    if (!$resets) {
        $body .= '<tr><td colspan="4" class="hint">Ainda não houve reposições manuais.</td></tr>';
    }
    page('Contadores', $body . '</tbody></table></div>', $user, 'contadores');
}

// ---------------------------------------------------------------- utilizadores

if ($route === '/utilizadores') {
    if (!$user['can_manage_users']) {
        http_response_code(403);
        page('Sem permissão', '<p>Não tem permissão para gerir utilizadores.</p>', $user, 'utilizadores');
    }
    $managers = (int)pg_fetch_result(pg_query($con, 'SELECT count(*) FROM admin_users WHERE can_manage_users'), 0, 0);

    if ($isPost) {
        $act = $_POST['action'] ?? '';
        if ($act === 'add') {
            $username = strtolower(trim($_POST['username'] ?? ''));
            $pw = (string)($_POST['password'] ?? '');
            if (!valid_username($username)) {
                flash('error', 'O nome de utilizador tem de ter 3 a 32 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.');
            } elseif ($problem = password_problem($pw)) {
                flash('error', $problem);
            } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
                flash('error', 'As palavras-passe não coincidem.');
            } elseif (rows($con, 'SELECT 1 FROM admin_users WHERE username = $1', [$username])) {
                flash('error', 'Esse nome de utilizador já existe.');
            } else {
                $manage = isset($_POST['can_manage_users']);
                pg_query_params($con, 'INSERT INTO admin_users (username, password_hash, can_manage_users) VALUES ($1, $2, $3)', [$username, hash_password($pw), $manage ? 't' : 'f']);
                audit($con, $user['username'], 'utilizador.criar', $username . ($manage ? ' (pode gerir utilizadores)' : ''));
                flash('ok', "Utilizador $username criado.");
            }
            back();
        }

        $target = pg_fetch_assoc(pg_query_params($con, 'SELECT id, username, can_manage_users FROM admin_users WHERE id = $1', [(int)($_POST['id'] ?? 0)]));
        if (!$target) {
            flash('error', 'Utilizador não encontrado.');
            back();
        }
        $targetManages = $target['can_manage_users'] === 't';

        if ($act === 'set_manage') {
            $want = ($_POST['value'] ?? '') === '1';
            if (!$want && $targetManages && $managers <= 1) {
                flash('error', 'Tem de existir pelo menos um utilizador que possa gerir utilizadores.');
            } else {
                pg_query_params($con, 'UPDATE admin_users SET can_manage_users = $1 WHERE id = $2', [$want ? 't' : 'f', $target['id']]);
                audit($con, $user['username'], 'utilizador.permissao', $target['username'] . ($want ? ': pode gerir utilizadores' : ': deixa de gerir utilizadores'));
                flash('ok', 'Permissão atualizada.');
            }
        } elseif ($act === 'reset_password') {
            $pw = (string)($_POST['password'] ?? '');
            if ($problem = password_problem($pw)) {
                flash('error', $problem);
            } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
                flash('error', 'As palavras-passe não coincidem.');
            } else {
                pg_query_params($con, 'UPDATE admin_users SET password_hash = $1 WHERE id = $2', [hash_password($pw), $target['id']]);
                audit($con, $user['username'], 'utilizador.palavra-passe', $target['username']);
                flash('ok', 'Palavra-passe de ' . $target['username'] . ' alterada.');
            }
        } elseif ($act === 'delete') {
            if ((int)$target['id'] === (int)$user['id']) {
                flash('error', 'Não pode remover o seu próprio utilizador.');
            } elseif ($targetManages && $managers <= 1) {
                flash('error', 'Tem de existir pelo menos um utilizador que possa gerir utilizadores.');
            } else {
                pg_query_params($con, 'DELETE FROM admin_users WHERE id = $1', [$target['id']]);
                audit($con, $user['username'], 'utilizador.remover', $target['username']);
                flash('ok', 'Utilizador ' . $target['username'] . ' removido.');
            }
        }
        back();
    }

    $body = '<p class="hint">Todos os utilizadores podem alterar reuniões, dias especiais e correções. Só quem tem a permissão «gerir utilizadores» vê esta página. As palavras-passe têm pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.</p>
      <h3>Adicionar utilizador</h3><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="action" value="add"><div class="row">
        <div><label for="u">Utilizador</label><input type="text" id="u" name="username" maxlength="32" autocomplete="off" required></div>
        <div><label for="p1">Palavra-passe</label><input type="password" id="p1" name="password" autocomplete="new-password" required></div>
        <div><label for="p2">Repetir palavra-passe</label><input type="password" id="p2" name="password2" autocomplete="new-password" required></div>
        <div><label class="check"><input type="checkbox" name="can_manage_users" value="1"> Pode gerir utilizadores</label></div>
        <div><button class="primary">Criar</button></div></div></form>
      <h3>Utilizadores</h3><div class="tablewrap"><table class="admin"><thead><tr><th>Utilizador</th><th>Gerir utilizadores</th><th>Último acesso</th><th>Nova palavra-passe</th><th></th></tr></thead><tbody>';
    foreach (rows($con, 'SELECT * FROM admin_users ORDER BY username') as $u) {
        $isSelf = (int)$u['id'] === (int)$user['id'];
        $manages = $u['can_manage_users'] === 't';
        $f = 'p' . $u['id'];
        $body .= '<tr><td>' . h($u['username']) . ($isSelf ? ' <span class="tag">você</span>' : '') . '</td>'
            . '<td><form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . h($u['id']) . '"><input type="hidden" name="action" value="set_manage">'
            . '<input type="hidden" name="value" value="' . ($manages ? '0' : '1') . '">' . ($manages ? 'Sim' : 'Não') . ' <button>' . ($manages ? 'Retirar' : 'Conceder') . '</button></form></td>'
            . '<td>' . h(fmt_local($u['last_login'])) . '</td>'
            . '<td><form method="post" id="' . $f . '" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . h($u['id']) . '"><input type="hidden" name="action" value="reset_password">'
            . '<input type="password" name="password" placeholder="Nova" autocomplete="new-password" aria-label="Nova palavra-passe" required>'
            . '<input type="password" name="password2" placeholder="Repetir" autocomplete="new-password" aria-label="Repetir palavra-passe" required><button>Alterar</button></form></td>'
            . '<td>' . ($isSelf ? '' : '<form method="post" data-confirm="Remover o utilizador ' . h($u['username']) . '?">' . csrf_field() . '<input type="hidden" name="id" value="' . h($u['id']) . '"><button name="action" value="delete" class="danger">Remover</button></form>') . '</td></tr>';
    }
    $body .= '</tbody></table></div>';
    page('Utilizadores', $body, $user, 'utilizadores');
}

// ---------------------------------------------------------------- own password

if ($route === '/palavra-passe') {
    if ($isPost) {
        $row = pg_fetch_assoc(pg_query_params($con, 'SELECT password_hash FROM admin_users WHERE id = $1', [$user['id']]));
        $new = (string)($_POST['password'] ?? '');
        if (!verify_password($row['password_hash'], (string)($_POST['current'] ?? ''))) {
            flash('error', 'A palavra-passe atual está incorreta.');
        } elseif ($problem = password_problem($new)) {
            flash('error', $problem);
        } elseif ($new !== (string)($_POST['password2'] ?? '')) {
            flash('error', 'As palavras-passe novas não coincidem.');
        } else {
            pg_query_params($con, 'UPDATE admin_users SET password_hash = $1 WHERE id = $2', [hash_password($new), $user['id']]);
            audit($con, $user['username'], 'palavra-passe.alterar');
            session_regenerate_id(true);
            flash('ok', 'Palavra-passe alterada.');
        }
        back();
    }
    page('Palavra-passe', '<form method="post" class="card narrow">' . csrf_field() . '
        <label for="c">Palavra-passe atual</label><input type="password" id="c" name="current" autocomplete="current-password" required>
        <label for="n1">Nova palavra-passe</label><input type="password" id="n1" name="password" autocomplete="new-password" required>
        <label for="n2">Repetir a nova palavra-passe</label><input type="password" id="n2" name="password2" autocomplete="new-password" required>
        <p class="hint">Pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.</p><p><button class="primary">Alterar</button></p></form>', $user, 'palavra-passe');
}

// ---------------------------------------------------------------- audit log

if ($route === '/registo') {
    $body = '<div class="tablewrap"><table class="admin"><thead><tr><th>Quando</th><th>Utilizador</th><th>Ação</th><th>Detalhe</th></tr></thead><tbody>';
    foreach (rows($con, 'SELECT * FROM admin_audit ORDER BY id DESC LIMIT 100') as $a) {
        $body .= '<tr><td>' . h(fmt_local($a['at'])) . '</td><td>' . h($a['username']) . '</td><td>' . h($a['action']) . '</td><td>' . h($a['detail']) . '</td></tr>';
    }
    $page = $body . '</tbody></table></div><p class="hint">Últimas 100 ações.</p>';
    page('Registo', $page, $user, 'registo');
}

redirect('/admin/reunioes');
