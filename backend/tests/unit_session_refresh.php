<?php
declare(strict_types=1);
/**
 * RC02 — регрессия гейта переавторизации (2026-11-19).
 *
 * Проверяет серверную часть плана REAUTH-GATE-PLAN.md:
 *   T1  idle-дедлайн истёк, абсолютный жив  -> refresh ПРОДЛЕЖАЕТ (200), а не 401
 *   T2  абсолютный дедлайн истёк              -> 401 session_absolute_expired
 *   T3  legacy-запись без absolute_expires   -> 403 session_legacy_not_extendable
 *   T4  отозванная сессия (revoked)           -> 401 session_revoked
 *   T5  refresh в stable-режиме не ротирует токен и продлевает idle
 *   T6  rate-guard refresh'ей жив (без регрессии)
 *
 * Никаких обращений к amoCRM/DAV: JTI-каталог — временный, секреты — синтетические.
 * Запуск:  php tests/unit_session_refresh.php [путь-к-модулю]
 *          (по умолчанию ../app/fh_session_refresh.php)
 */
$root = dirname(__DIR__);
$module = $argv[1] ?? ($root . '/app/fh_session_refresh.php');
if (!is_file($module)) { fwrite(STDERR, "FAIL: no module at $module\n"); exit(1); }

require $root . '/app/auth.php';
require $module;

function t(bool $b, string $name): void {
    if (!$b) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}

putenv('FILEHUB_DAV_MODE=prod_mail');
putenv('FILEHUB_AMO_ACCOUNT_ID=12345678');
putenv('FILEHUB_AMO_CLIENT_UUID=0b0832f6-d123-4123-9123-e73f2368330c');
putenv('FILEHUB_LINK_HMAC_KEY=' . str_repeat('a3', 32));
putenv('FILEHUB_PUBLIC_API_URL=https://filehub.example.test/wp-json/czfh/v1');
$dir = sys_get_temp_dir() . '/filehub-sess-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
putenv('FILEHUB_JTI_DIR=' . $dir);

function claims(int $expIn = 1800): array {
    $now = time();
    return [
        'user_id' => 4242, 'account_id' => '12345678',
        'client_uuid' => '0b0832f6-d123-4123-9123-e73f2368330c',
        'exp' => $now + $expIn, 'iat' => $now, 'nbf' => $now,
        'jti' => 'test-' . bin2hex(random_bytes(8)),
    ];
}

/** Создать сессию и вернуть её запись из хранилища. */
function make_session(array $overrides = []): array {
    $s = fh_sess_record_create(claims());
    $path = fh_sess_path($s['token']);
    $rec = fh_sess_read($path);
    foreach ($overrides as $k => $v) { $rec[$k] = $v; }
    fh_sess_write_atomic($path, $rec);
    // write_atomic = prepare + commit: новый inode остаётся заблокированным
    // (flock переживает rename) до явного снятия блокировки. Без этого
    // следующий fh_sess_refresh_compute() упёрся бы в собственный flock.
    fh_sess_lock_release_all();
    return ['token' => $s['token'], 'path' => $path, 'rec' => $rec];
}

try {
    // ---- T1: idle истёк, absolute жив -> refresh продлевает -------------------
    $s = make_session(['expires' => time() - 10]);
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 200, 'T1 idle-expired session refreshes (200, was 401 session_expired)');
    t(($body['expires'] ?? 0) > time(), 'T1 new expires is in the future');
    t(($body['absoluteExpires'] ?? 0) > time(), 'T1 absolute deadline preserved');
    t(($body['sessionToken'] ?? '') === $s['token'], 'T1 stable mode keeps the same token');

    // ---- T2: absolute истёк -> жёсткий отказ ---------------------------------
    $s = make_session(['expires' => time() - 10, 'absolute_expires' => time() - 1]);
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 401, 'T2 absolute-expired session rejected (401)');
    t(($body['error'] ?? '') === 'session_absolute_expired', 'T2 error code is session_absolute_expired');

    // ---- T3: legacy-запись (без absolute_expires) ---------------------------
    $s = make_session();
    $rec = fh_sess_read($s['path']);
    unset($rec['absolute_expires']);
    fh_sess_write_atomic($s['path'], $rec);
    fh_sess_lock_release_all();   // снять собственный flock перед следующей операцией
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 403, 'T3 legacy record rejected (403)');
    t(($body['error'] ?? '') === 'session_legacy_not_extendable', 'T3 error code is session_legacy_not_extendable');

    // ---- T4: отозванная сессия ----------------------------------------------
    $s = make_session(['revoked' => true]);
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 401, 'T4 revoked session rejected (401)');
    t(($body['error'] ?? '') === 'session_revoked', 'T4 error code is session_revoked');

    // ---- T5: обычный refresh продлевает idle --------------------------------
    $s = make_session();
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 200, 'T5 healthy session refreshes (200)');
    t(($body['expires'] ?? 0) > time(), 'T5 idle deadline extended');
    t(($body['sessionToken'] ?? '') === $s['token'], 'T5 token not rotated in stable mode');

    // ---- T6: rate-guard не сломан -------------------------------------------
    // 6a: refresh здоровой сессии в stable-режиме идёт «коротким путём» (200,
    //     без записи и без rate-guard) — сессию не нужно продлевать.
    $s = make_session();
    [$status] = fh_sess_refresh_compute($s['token']);
    t($status === 200, 'T6 first refresh allowed');
    $before = fh_sess_read($s['path']);
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 200, 'T6 second refresh of a healthy session short-circuits (200)');
    t((fh_sess_read($s['path'])['refresh_count'] ?? -1) === ($before['refresh_count'] ?? 0),
      'T6 short-circuit does not rewrite the record');
    // 6b: когда продление действительно нужно, действует rate-guard:
    //     два ПРОДЛЕНИЯ подряд с интервалом < FH_SESSION_REFRESH_MIN_GAP_S.
    $rec = fh_sess_read($s['path']);
    $rec['expires'] = time() - 1;
    fh_sess_write_atomic($s['path'], $rec);
    fh_sess_lock_release_all();
    [$status] = fh_sess_refresh_compute($s['token']);
    t($status === 200, 'T6 extension of an idle-expired session allowed (200)');
    // 6c: сразу после продления — второе продление отклоняется гардом
    $rec = fh_sess_read($s['path']);
    $rec['expires'] = time() - 1;
    fh_sess_write_atomic($s['path'], $rec);
    fh_sess_lock_release_all();
    [$status, $body] = fh_sess_refresh_compute($s['token']);
    t($status === 429, 'T6 extension right after another extension is rate-limited (429)');
    t(($body['error'] ?? '') === 'session_refresh_rate_limited', 'T6 rate-limit error code');

    echo "PASS: session refresh regression suite\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}
