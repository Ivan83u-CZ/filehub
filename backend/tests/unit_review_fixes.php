<?php
declare(strict_types=1);
/**
 * RC02 — регрессия правок ревью 19.11.2026 (серверная часть).
 *
 *   R1  атомарный append в audit-цепочку: короткая запись (ENOSPC) НЕ оставляет
 *       обрезанную строку, цепочка лида остаётся целой;
 *   R2  режим refresh по умолчанию — 'stable': нераспознанное значение
 *       переменной окружения больше не трактуется как 'rotate' (клиент такой
 *       ответ отвергает и остаётся без сессии);
 *   R3  ответ refresh содержит признак 'extended' и серверное время;
 *   R4  дедупликация телеметрии сканирует ограниченный объём файла;
 *   R5  allowlist признаков телеметрии принимает rename/restore.
 *
 * Внешних обращений нет. Часть R1 требует маленькой файловой системы
 * (запускается с --tmpfs); на большой ФС этот пункт пропускается с явной
 * пометкой, остальные проверки выполняются всегда.
 *
 * Запуск: php tests/unit_review_fixes.php [каталог-проекта]
 */
$root = $argv[1] ?? dirname(__DIR__);
$app  = $root . '/app';
if (!is_file($app . '/fh12_prod.php')) { fwrite(STDERR, "FAIL: no app at $app\n"); exit(1); }

$pass = 0;
function t(bool $b, string $name, string $extra = ''): void {
    global $pass;
    if (!$b) { fwrite(STDERR, "FAIL: $name" . ($extra !== '' ? " ($extra)" : '') . "\n"); exit(1); }
    $pass++;
    echo "PASS: $name\n";
}
function skip(string $name, string $why): void { echo "SKIP: $name ($why)\n"; }

/* ======================= окружение тестового модуля ======================= */
$stateBase = getenv('FH_REVIEW_STATE_DIR') ?: (sys_get_temp_dir() . '/fh-review-state-' . bin2hex(random_bytes(4)));
@mkdir($stateBase, 0700, true);
$jtiDir = $stateBase . '/jti';
@mkdir($jtiDir, 0700, true);
putenv('FH12_STATE_BASE=' . $stateBase);
putenv('FH12_TEST_MODE=1');
putenv('FH12_DAV_HOST=http://127.0.0.1:1');
putenv('FILEHUB_JTI_DIR=' . $jtiDir);
putenv('FILEHUB_DAV_MODE=prod_mail');
putenv('FILEHUB_PROD_WRITES=1');
putenv('FILEHUB_PROD_ACK=review-fixes');
putenv('FILEHUB_AMO_ACCOUNT_ID=12345678');
putenv('FILEHUB_AMO_CLIENT_UUID=0b0832f6-d123-4123-9123-e73f2368330c');
putenv('FILEHUB_LINK_HMAC_KEY=' . str_repeat('a3', 32));
putenv('FILEHUB_PUBLIC_API_URL=https://filehub.example.test/wp-json/czfh/v1');

require $app . '/auth.php';
require $app . '/fh_session_refresh.php';
require $app . '/fh_client_events.php';
require $app . '/fh12_prod.php';

try {
    /* ===================== R1: атомарный append в audit ===================== */
    $lead = '34186815';
    $dir  = fh12_state_root($lead);
    $event = fh12_result('upload', $lead, 'photos', 4242, 'op-1', 'photo.jpg', 'intent', 'ok');
    fh12_audit($dir, $event);
    $file = $dir . '/audit.jsonl';
    t(is_file($file) && filesize($file) > 0, 'R1 первая запись audit создана');
    t(fh12_audit_chain_ok($dir), 'R1 цепочка audit цела после первой записи');

    $before = (string)file_get_contents($file);
    $free   = @disk_free_space($dir);
    if ($free === false || $free > 67108864) {
        skip('R1 короткая запись (ENOSPC) откатывается', 'нужна файловая система меньше 64 МиБ (запуск с --tmpfs)');
    } else {
        // Заполняем файловую систему так, чтобы запись в цепочку не помещалась.
        $filler = $dir . '/.filler';
        $fp = @fopen($filler, 'wb');
        $left = (int)$free - 64;              // запись в цепочку (сотни байт) не поместится
        $chunk = str_repeat('x', 8192);
        while ($left > 0) { $n = @fwrite($fp, substr($chunk, 0, min(8192, $left))); if ($n === false || $n === 0) break; $left -= $n; }
        @fflush($fp); @fclose($fp);
        $freeNow = @disk_free_space($dir);
        $caught = '';
        try {
            // Большая запись: на заполненной ФС она не помещается даже в последнюю
            // страницу файла, поэтому fwrite возвращает короткий счётчик (ENOSPC).
            $big = fh12_result('upload', $lead, 'photos', 4242, 'op-2', 'photo2.jpg', 'intent', 'ok');
            $big['detail'] = str_repeat('d', 32768);
            fh12_audit($dir, $big);
        } catch (Throwable $e) { $caught = $e->getMessage(); }
        @unlink($filler);
        t($caught === 'prod_audit_unavailable', 'R1 короткая запись отвергнута с prod_audit_unavailable',
          'code=' . $caught . ' free=' . (int)$freeNow);
        $after = (string)file_get_contents($file);
        t($after === $before, 'R1 файл цепочки не изменён (обрезанной строки нет)');
        t(fh12_audit_chain_ok($dir), 'R1 цепочка audit цела после неудачной записи');
        // и после неудачной записи цепочка остаётся дописываемой
        fh12_audit($dir, fh12_result('upload', $lead, 'photos', 4242, 'op-3', 'photo3.jpg', 'intent', 'ok'));
        t(fh12_audit_chain_ok($dir), 'R1 цепочка audit цела после успешной записи следом');
    }

    /* ===================== R2: режим refresh по умолчанию ===================== */
    putenv('FH_REFRESH_MODE=stable');
    t(fh_sess_refresh_mode() === 'stable', 'R2 явный stable');
    putenv('FH_REFRESH_MODE=ROTATE');
    t(fh_sess_refresh_mode() === 'rotate', 'R2 регистр значения игнорируется');
    putenv('FH_REFRESH_MODE=typo-from-deploy');
    t(fh_sess_refresh_mode() === 'stable',
      'R2 нераспознанное значение даёт stable (а не rotate: клиент rotate не понимает)');
    putenv('FH_REFRESH_MODE');
    t(fh_sess_refresh_mode() === 'stable', 'R2 значение по умолчанию — stable');

    /* ===================== R3: признак extended и серверное время ===================== */
    $now = time();
    $claims = ['user_id' => 4242, 'account_id' => '12345678',
               'client_uuid' => '0b0832f6-d123-4123-9123-e73f2368330c', 'exp' => $now + 1800];
    $s = fh_sess_record_create($claims);
    $token = (string)$s['token'];
    $rec = fh_sess_read(fh_sess_path($token));
    $rec['expires'] = $now - 5;               // idle истёк, absolute жив
    fh_sess_write_atomic(fh_sess_path($token), $rec);
    // Прямая запись оставляет блокировку опубликованного inode в реестре —
    // снимаем её, иначе следующий refresh упрётся в собственную блокировку.
    fh_sess_lock_release_all();
    [$status, $body] = fh_sess_refresh_compute($token);
    t($status === 200, 'R3 refresh истёкшей по idle сессии успешен', (string)$status);
    t(($body['extended'] ?? null) === true, 'R3 признак extended=true при реальном продлении');
    t(isset($body['serverTime']) && is_int($body['serverTime']) && $body['serverTime'] >= $now,
      'R3 ответ содержит серверное время');
    t(($body['absoluteExpires'] ?? 0) === (int)$rec['absolute_expires'],
      'R3 абсолютный срок не сдвигается');

    // «ранний» refresh: сессия ещё жива — признак extended=false, expires прежний
    [$status2, $body2] = fh_sess_refresh_compute($token);
    t($status2 === 200, 'R3 повторный refresh успешен', (string)$status2);
    t(($body2['extended'] ?? null) === false, 'R3 «ранний» refresh помечен extended=false');

    /* ===================== R4: ограниченный скан дедупликации ===================== */
    $tmp = sys_get_temp_dir() . '/fh-review-tel-' . bin2hex(random_bytes(4)) . '.jsonl';
    $fh = fopen($tmp, 'c+');
    for ($i = 0; $i < 200; $i++) {
        fwrite($fh, json_encode(['event_id' => sprintf('%08d-0000-4000-8000-000000000000', $i),
            'account_id' => '1', 'operator_id' => '2']) . "\n");
    }
    fflush($fh);
    $all = fh_telemetry_existing_event_ids($fh, 0);
    $bounded = fh_telemetry_existing_event_ids($fh, 4096);
    fclose($fh);
    @unlink($tmp);
    t(count($all) === 200, 'R4 полный скан видит все события', (string)count($all));
    t(count($bounded) > 0 && count($bounded) < 200, 'R4 ограниченный скан читает только хвост',
      (string)count($bounded));
    t(count($bounded) <= 60, 'R4 объём ограниченного скана предсказуем', (string)count($bounded));

    /* ===================== R5: allowlist признаков телеметрии ===================== */
    $features = fh_telemetry_feature_allowlist();
    foreach (['auth', 'upload', 'files', 'trash', 'calls', 'preview', 'widget', 'rename', 'restore'] as $f) {
        t(isset($features[$f]), 'R5 признак ' . $f . ' допустим');
    }

    echo "PASS: review fixes regression suite\n";
} finally {
    foreach (glob($stateBase . '/*/*') ?: [] as $f) { @unlink($f); }
    foreach (glob($stateBase . '/*') ?: [] as $f) { @unlink($f); @rmdir($f); }
    @rmdir($stateBase);
}
