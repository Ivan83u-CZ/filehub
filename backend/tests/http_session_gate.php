<?php
declare(strict_types=1);
/**
 * RC02 — HTTP-регрессия гейта сессии (2026-11-19).
 *
 * Гоняет НАСТОЯЩИЙ app/api.php через тестовый HTTP-роутер и проверяет
 * сквозной контракт правок S1 + S2 (REAUTH-GATE-PLAN.md):
 *
 *   1) POST /session                 -> 200, sessionToken + absoluteExpires
 *   2) GET  /files                   -> 200 (сессия живая)
 *   3) idle-дедлайн истёк (правка записи сессии в хранилище)
 *      GET  /files                   -> 401 {"error":"session_refreshable"}   <- S2
 *   4) POST /session/refresh         -> 200, idle-дедлайн продлён           <- S1
 *   5) GET  /files                   -> 200 (восстановление)
 *   6) GET  /files с чужим токеном   -> 401 {"error":"session_invalid"}
 *
 * Внешних обращений нет: amoCRM-секрет синтетический, JTI-каталог временный,
 * состояние файлов только читается. Порт и каталоги — временные.
 *
 * Запуск: php tests/http_session_gate.php [каталог-проекта]
 */
$root = $argv[1] ?? dirname(__DIR__);
$app  = $root . '/app';
if (!is_file($app . '/api.php')) { fwrite(STDERR, "FAIL: no app at $app\n"); exit(1); }
require $app . '/auth.php';

$port   = 8000 + random_int(100, 900);
$jtiDir = sys_get_temp_dir() . '/fh-gate-jti-' . bin2hex(random_bytes(4));
mkdir($jtiDir, 0700, true);
$secret = 'synthetic-gate-secret-not-a-real-credential';

$env = [];
foreach ($_SERVER as $k => $v) { if (is_string($k) && is_string($v)) $env[$k] = $v; }
$env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
$env['FILEHUB_DAV_MODE']       = 'prod_mail';
$env['FILEHUB_AMO_CLIENT_SECRET'] = $secret;
$env['FILEHUB_AMO_ACCOUNT_ID']  = '12345678';
$env['FILEHUB_AMO_CLIENT_UUID'] = '0b0832f6-d123-4123-9123-e73f2368330c';
$env['FILEHUB_AMO_ISSUER']      = 'https://chinazub.amocrm.ru';
$env['FILEHUB_AMO_AUDIENCE']    = 'https://filehub.example.test';
$env['FILEHUB_LINK_HMAC_KEY']   = str_repeat('a3', 32);
$env['FILEHUB_PUBLIC_API_URL']  = 'https://filehub.example.test/wp-json/czfh/v1';
$env['FILEHUB_JTI_DIR']         = $jtiDir;
$env['FILEHUB_PROD_WRITES']     = '0';
$env['FILEHUB_PROD_ACK']        = '';
// Тестовый seam приложения: состояние файлов — во временный каталог,
// хост DAV — loopback (внешних обращений не будет).
$stateDir = sys_get_temp_dir() . '/fh-gate-state-' . bin2hex(random_bytes(4));
mkdir($stateDir . '/34186815/photos', 0700, true);
mkdir($stateDir . '/34186815/other', 0700, true);
$env['FH12_TEST_MODE']  = '1';
$env['FH12_DAV_HOST']   = 'http://127.0.0.1:1';
$env['FH12_STATE_BASE'] = $stateDir;

$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($app), escapeshellarg($root . '/tests/router.php'));
$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, $root, $env);
if (!is_resource($proc)) { fwrite(STDERR, "FAIL: cannot start php -S\n"); exit(1); }

function req(string $path, ?string $token = null, ?string $session = null, ?string $method = null): array {
    global $port;
    $headers = [];
    if ($token !== null)   $headers[] = 'X-Auth-Token: ' . $token;
    if ($session !== null) $headers[] = 'X-FH-Session: ' . $session;
    $ctx = stream_context_create(['http' => [
        'method' => $method ?? ($token !== null ? 'POST' : 'GET'),
        'header' => implode("\r\n", $headers),
        'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1]; }
    return [$status, is_string($body) ? $body : ''];
}
function t(bool $b, string $name, string $extra = ''): void {
    if (!$b) { fwrite(STDERR, "FAIL: $name" . ($extra !== '' ? " ($extra)" : '') . "\n"); exit(1); }
    echo "PASS: $name\n";
}

try {
    // ждём подъёма сервера
    for ($i = 0; $i < 60; $i++) {
        $s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 0.2);
        if ($s) { fclose($s); break; }
        usleep(100000);
    }
    $now = time();
    $claims = ['iss' => 'https://chinazub.amocrm.ru', 'aud' => 'https://filehub.example.test',
        'iat' => $now, 'nbf' => $now, 'exp' => $now + 1800,
        'account_id' => 12345678, 'user_id' => 4242,
        'client_uuid' => '0b0832f6-d123-4123-9123-e73f2368330c', 'jti' => 'gate-' . bin2hex(random_bytes(8))];
    $h = fh_b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = fh_b64url_encode(json_encode($claims));
    $jwt = "$h.$p." . fh_b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));

    // 1) выдача сессии
    [$status, $body] = req('/wp-json/czfh/v1/session', $jwt);
    t($status === 200, 'H1 POST /session issues a session', "HTTP $status $body");
    $sess = json_decode($body, true);
    t(is_array($sess) && !empty($sess['sessionToken']), 'H1 sessionToken present');
    t(isset($sess['absoluteExpires']) && $sess['absoluteExpires'] > time(), 'H1 absoluteExpires present');
    $token = $sess['sessionToken'];

    // 2) чтение со свежей сессией
    [$status] = req('/wp-json/czfh/v1/activity?leadId=34186815&limit=5', null, $token);
    t($status === 200, 'H2 GET /activity with a live session', "HTTP $status");

    // 3) роняем idle-дедлайн прямо в хранилище
    $path = $jtiDir . '/session-' . hash('sha256', $token);
    $rec = json_decode((string)file_get_contents($path), true);
    $rec['expires'] = time() - 10;
    file_put_contents($path . '.tmp', json_encode($rec));
    rename($path . '.tmp', $path);

    [$status, $body] = req('/wp-json/czfh/v1/activity?leadId=34186815&limit=5', null, $token);
    t($status === 401, 'H3 idle-expired session is rejected', "HTTP $status");
    $err = json_decode($body, true)['error'] ?? '';
    t($err === 'session_refreshable', 'H3 error code is session_refreshable (not session_invalid)', $err);

    // 4) refresh продлевает сессию
    [$status, $body] = req('/wp-json/czfh/v1/session/refresh', null, $token, 'POST');
    t($status === 200, 'H4 POST /session/refresh extends the idle-expired session', "HTTP $status $body");
    $new = json_decode($body, true);
    t(($new['expires'] ?? 0) > time(), 'H4 idle deadline extended');

    // 5) доступ восстановлен
    [$status] = req('/wp-json/czfh/v1/activity?leadId=34186815&limit=5', null, $token);
    t($status === 200, 'H5 GET /activity works again after refresh', "HTTP $status");

    // 6) чужой токен — по-прежнему session_invalid
    [$status, $body] = req('/wp-json/czfh/v1/activity?leadId=34186815&limit=5', null, str_repeat('a', 64));
    t($status === 401, 'H6 unknown token rejected', "HTTP $status");
    $err = json_decode($body, true)['error'] ?? '';
    t($err === 'session_invalid', 'H6 error code is session_invalid', $err);

    // 7) вытесненный ротацией токен — это конфликт, а не «сессию можно продлить».
    //    Tombstone сохраняет прежний absolute_expires (будущее время), поэтому
    //    раньше диспетчер отвечал 401 session_refreshable, а refresh на этом
    //    токене отвечал 409: клиент получал противоречивый ответ.
    $path = $jtiDir . '/session-' . hash('sha256', $token);
    $rec = json_decode((string)file_get_contents($path), true);
    $rec['revoked'] = true;
    $rec['replaced_by'] = hash('sha256', 'next-token');
    // Настоящий tombstone после ротации: expires = now (целое > 0), absolute
    // прежний (будущее время) — именно из-за этого старый код отвечал
    // «session_refreshable».
    $rec['expires'] = time() - 1;
    file_put_contents($path . '.tmp', json_encode($rec));
    rename($path . '.tmp', $path);
    [$status, $body] = req('/wp-json/czfh/v1/activity?leadId=34186815&limit=5', null, $token);
    t($status === 409, 'H7 revoked session is a conflict', "HTTP $status $body");
    $err = json_decode($body, true)['error'] ?? '';
    t($err === 'session_refresh_conflict', 'H7 error code is session_refresh_conflict', $err);

    echo "PASS: HTTP session gate regression suite\n";
} finally {
    proc_terminate($proc);
    foreach (glob($jtiDir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($jtiDir);
    foreach (glob($stateDir . '/*/*', GLOB_ONLYDIR) ?: [] as $d) { @rmdir($d); }
    @rmdir($stateDir);
}
