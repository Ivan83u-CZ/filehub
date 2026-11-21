<?php
declare(strict_types=1);
/**
 * FileHub RC02 — R1 «session refresh» — STAGING CANDIDATE (НЕ ВЫКАЧЕН В PROD).
 *
 * Назначение: продлить ДЕЙСТВУЮЩУЮ FileHub-сессию без повторного расходования
 * одноразового amoCRM-JWT/JTI. Модуль самодостаточен: fh12_prod.php подключает его
 * только при наличии файла, поэтому смешанное состояние (новый диспетчер без модуля)
 * не даёт fatal.
 *
 * ХРАНИЛИЩЕ (формат совместим с утверждённым сборщиком jti_gc.php):
 *   <FILEHUB_JTI_DIR>/session-<sha256(bearer)>   — JSON-запись сессии
 *   - имя файла остаётся 'session-[0-9a-f]{64}'  (PAT_SESSION в jti_gc.php)
 *   - JSON всегда содержит целое 'expires' > 0   (gc_expiry в jti_gc.php)
 *   - НИ ОДНОГО нового шаблона имени файла в каталог JTI не добавляется
 *     (блокировки — flock на самом файле сессии, а не отдельные .lock-файлы)
 *   - временные файлы записи тоже имеют вид 'session-[0-9a-f]{64}' и содержат
 *     валидный JSON, поэтому для GC они выглядят как обычные сессии
 *
 * АТОМАРНОСТЬ ЗАПИСИ: полная запись во временный файл того же mount → fflush+fsync →
 * чтение и сверка содержимого → atomic rename. Сбой на любом шаге оставляет
 * предыдущее валидное состояние (torn JSON невозможен).
 *
 * ЭКСКЛЮЗИВНОСТЬ (аудит 12.11.2026, P0 — stale-inode locking):
 *   flock привязан к INODE, а atomic rename подменяет файл по пути новым inode.
 *   Если держать только блокировку старого inode, второй процесс успешно
 *   заблокирует НОВЫЙ inode, не дождавшись освобождения первого, — эксклюзивность
 *   критической секции нарушается (возможны два успешных обновления одного
 *   поколения и обход rate limit).
 *   Протокол после исправления:
 *     1) блокируется ТЕКУЩИЙ inode файла сессии (fh_sess_lock);
 *     2) сразу после захвата сверяется fstat($fd) с актуальным stat($path) по
 *        dev/ino; при расхождении дескриптор освобождается, путь переоткрывается,
 *        попытка повторяется в пределах FH_SESSION_LOCK_WAIT_MS (дескриптор мог
 *        «протухнуть», пока мы ждали блокировку);
 *     3) временный файл записи БЛОКИРУЕТСЯ ДО rename — после rename путь
 *        указывает на этот inode, и любой открывший путь позже блокируется на нём;
 *     4) в течение критической секции удерживаются обе блокировки (старый inode и
 *        новый); освобождаются обе в finally;
 *     5) перед чтением и перед записью выполняется повторная сверка inode.
 *   Новых шаблонов имён файлов в каталог JTI при этом НЕ добавляется.
 *
 * ТЕСТОВАЯ ЗАДЕРЖКА: функция fh_sess_test_delay() работает ТОЛЬКО если константа
 * FH_SESS_TEST_DELAY_US определена тестовым harness'ом ДО подключения модуля.
 * В production такая константа не определена — ветка не выполняется.
 *
 * РЕЖИМЫ (решение владельца R1 фиксируется при выкате, значение по умолчанию —
 * 'rotate'; оба режима реализованы и покрыты тестами):
 *   rotate — новый случайный bearer, старый токен закрывается немедленно
 *   stable — bearer не меняется, продлевается только 'expires'
 *
 * АБСОЛЮТНЫЙ СРОК: absolute_expires = min(jwt.exp, issued_at + FH_SESSION_ABSOLUTE_TTL_S).
 * Исходный jwt.exp при refresh НЕ сдвигается. Сессии, созданные старым кодом
 * (без 'generation'/'absolute_expires'), НЕ продлеваются.
 */
if (!defined('FH_SESSION_IDLE_TTL_S'))        define('FH_SESSION_IDLE_TTL_S', 600);      // idle TTL сессии, с
if (!defined('FH_SESSION_ABSOLUTE_TTL_S'))     define('FH_SESSION_ABSOLUTE_TTL_S', 1800);   // абсолютный потолок, с
if (!defined('FH_SESSION_REFRESH_MAX_PER_MIN'))define('FH_SESSION_REFRESH_MAX_PER_MIN', 2);  // refresh на одну сессию в минуту
if (!defined('FH_SESSION_REFRESH_MIN_GAP_S'))  define('FH_SESSION_REFRESH_MIN_GAP_S', 35);   // минимальный интервал между refresh
if (!defined('FH_SESSION_REFRESH_WINDOW_S')) define('FH_SESSION_REFRESH_WINDOW_S', 120);
if (!defined('FH_SESSION_LOCK_WAIT_MS'))       define('FH_SESSION_LOCK_WAIT_MS', 3000);     // ожидание flock, мс
if (!defined('FH_SESSION_RECORD_MAX_BYTES'))   define('FH_SESSION_RECORD_MAX_BYTES', 4096); // предел размера записи сессии
/**
 * РЕЖИМ ПРОДЛЕНИЯ, ЗАФИКСИРОВАННЫЙ ВЛАДЕЛЬЦЕМ (решение R1 от 12.11.2026): 'stable'.
 *
 * 'stable' — bearer НЕ меняется, продлевается только 'expires' (idle TTL),
 *            абсолютный потолок 'absolute_expires' НЕ сдвигается.
 *            Принятый риск: окно жизни украденного bearer растёт с 10 до 30 минут.
 * 'rotate' — новый bearer на каждый refresh, старый немедленно закрыт.
 *            Включается заменой одной константы в этом файле (без рестартов).
 *
 * Режим задаётся КОНСТАНТОЙ, а не переменной окружения: php-fpm работает с
 * clear_env=yes, поэтому новая переменная окружения потребовала бы рестарта/перезагрузки
 * FPM. Переменная окружения FH_REFRESH_MODE (если она всё же есть) имеет приоритет —
 * это используется только на стенде.
 */
if (!defined('FH_REFRESH_MODE_DEFAULT')) define('FH_REFRESH_MODE_DEFAULT', 'stable');

/**
 * Режим продления. Белый список — ровно два значения.
 *
 * Любое НЕРАСПОЗНАННОЕ значение трактуется как 'stable': режим 'rotate' выдаёт
 * новый bearer и немедленно отзывает старый, а клиент (виджет) требует
 * res.sessionToken === oldToken и такой ответ отвергает как session_invalid.
 * Опечатка в переменной окружения при старом правиле ('rotate' по умолчанию)
 * ломала бы прод-сессии каждые ~10 минут, поэтому fail-safe направлен в
 * сторону режима, который умеет понимать клиент.
 */
function fh_sess_refresh_mode(): string {
    $m = getenv('FH_REFRESH_MODE');
    if (!is_string($m) || trim($m) === '') $m = (string)FH_REFRESH_MODE_DEFAULT;
    $m = strtolower(trim($m));
    return in_array($m, ['rotate', 'stable'], true) ? $m : 'stable';
}

/** Каталог хранения сессий и JTI. Отсутствие/недоступность = fail closed. */
function fh_sess_dir(): string {
    $base = getenv('FILEHUB_JTI_DIR');
    if (!is_string($base) || $base === '') throw new RuntimeException('session_store_unavailable');
    $base = rtrim($base, '/');
    if (!is_dir($base) || !is_writable($base)) throw new RuntimeException('session_store_unavailable');
    return $base;
}

/** Путь записи сессии по bearer-токену. Формат токена (64 hex) НЕ меняется. */
function fh_sess_path(string $token): string {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('session_invalid');
    return fh_sess_dir() . '/session-' . hash('sha256', $token);
}

/**
 * Тестовая задержка (только стенд).
 *
 * Активна ИСКЛЮЧИТЕЛЬНО если константа FH_SESS_TEST_DELAY_US была определена до
 * подключения модуля (тестовый harness). В production такая константа не
 * определена — ветка не выполняется, поведение и производительность не меняются.
 * Нужна, чтобы при проверке конкурентности детерминированно расширять окно гонки
 * между lock / read / prepare / rename / unlock.
 */
if (!function_exists('fh_sess_test_delay')) {
    function fh_sess_test_delay(string $point): void {
        if (!defined('FH_SESS_TEST_DELAY_US')) return;
        $us = (int)constant('FH_SESS_TEST_DELAY_US');
        if ($us > 0) usleep($us);
    }
}

/**
 * Совпадает ли дескриптор с текущим файлом по пути?
 *
 * flock привязан к INODE, а не к пути. Atomic rename заменяет файл по пути новым
 * inode, поэтому дескриптор, открытый ДО rename, указывает уже не на текущий файл.
 * Проверка fstat($fd) против stat($path) по dev/ino позволяет это обнаружить.
 */
function fh_sess_same_inode($fd, string $path): bool {
    $fs = @fstat($fd);
    if (!is_array($fs) || !isset($fs['dev'], $fs['ino'])) return false;
    $st = @stat($path);
    if (!is_array($st) || !isset($st['dev'], $st['ino'])) return false;
    return ((int)$fs['dev'] === (int)$st['dev']) && ((int)$fs['ino'] === (int)$st['ino']);
}

/**
 * Реестр удерживаемых блокировок критической секции.
 *
 * Блокировка должна удерживаться ДО конца критической секции (lock -> read ->
 * write -> публикация), и в её ходе удерживаются ДВА дескриптора: старый inode
 * (текущий файл) и новый (временный файл, который станет текущим после rename).
 * Реестр освобождается целиком в finally вызывающего кода.
 */
function fh_sess_lock_push($fd): void {
    if (!isset($GLOBALS['fh_sess_locks']) || !is_array($GLOBALS['fh_sess_locks'])) {
        $GLOBALS['fh_sess_locks'] = [];
    }
    $GLOBALS['fh_sess_locks'][] = $fd;
}

/** Освобождение всех блокировок критической секции (в обратном порядке захвата). */
function fh_sess_lock_release_all(): void {
    if (!isset($GLOBALS['fh_sess_locks']) || !is_array($GLOBALS['fh_sess_locks'])) return;
    foreach (array_reverse($GLOBALS['fh_sess_locks']) as $fd) {
        if (is_resource($fd)) { @flock($fd, LOCK_UN); @fclose($fd); }
    }
    $GLOBALS['fh_sess_locks'] = [];
}

/**
 * Подготовка атомарной записи: полная запись во временный файл того же mount,
 * fflush+fsync, чтение и сверка содержимого. Возвращает путь временного файла.
 * Имя временного файла соответствует PAT_SESSION сборщика GC.
 *
 * ВАЖНО (аудит 12.11.2026): временный файл БЛОКИРУЕТСЯ до того, как он станет
 * текущим. Именно это устраняет stale-inode locking: после rename путь указывает
 * на этот inode, и любой процесс, открывший путь позже, блокируется на нём —
 * до тех пор, пока писатель не завершит критическую секцию.
 */
function fh_sess_prepare(array $payload): string {
    $data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!is_string($data) || $data === '' || strlen($data) > FH_SESSION_RECORD_MAX_BYTES) {
        throw new RuntimeException('session_store_unavailable');
    }
    $tmp = fh_sess_dir() . '/session-' . bin2hex(random_bytes(32));
    $fd = @fopen($tmp, 'x');
    if ($fd === false) throw new RuntimeException('session_store_unavailable');
    $ok = false;
    $locked = false;
    try {
        if (fwrite($fd, $data) !== strlen($data)) throw new RuntimeException('session_store_unavailable');
        if (!fflush($fd)) throw new RuntimeException('session_store_unavailable');
        // R1 (аудит 12.11.2026): результат fsync ПРОВЕРЯЕТСЯ. Раньше вызов был
        // «@fsync($fd)» без проверки — молчаливая потеря durability при ENOSPC/EIO:
        // данные могли остаться только в кэше страниц, а запись считалась успешной.
        // Теперь сбой fsync = fail closed: старое валидное состояние остаётся
        // (временный файл удаляется в finally), клиент получает session_store_unavailable.
        if (function_exists('fsync') && !@fsync($fd)) throw new RuntimeException('session_store_unavailable');
        @chmod($tmp, 0600);
        $rb = @file_get_contents($tmp, false, null, 0, FH_SESSION_RECORD_MAX_BYTES);
        if (!is_string($rb) || $rb !== $data) throw new RuntimeException('session_store_unavailable');
        // Блокируем НОВЫЙ inode до rename: блокировка переживает переименование,
        // потому что flock привязан к inode, а не к пути.
        if (!@flock($fd, LOCK_EX | LOCK_NB)) throw new RuntimeException('session_store_unavailable');
        $locked = true;
        fh_sess_lock_push($fd);   // удерживается до конца критической секции
        $ok = true;
        return $tmp;
    } finally {
        if (!$ok) {
            if ($locked) { @flock($fd, LOCK_UN); }
            if (is_resource($fd)) { @fclose($fd); }
            @unlink($tmp);
        }
        // при $ok дескриптор остаётся открытым и заблокированным (регистр)
    }
}

/** Фиксация подготовленной записи: атомарное переименование на целевой путь. */
function fh_sess_commit(string $tmp, string $path): void {
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('session_store_unavailable');
    }
    @chmod($path, 0600);
}

/** Запись состояния целиком (prepare + commit). */
function fh_sess_write_atomic(string $path, array $payload): void {
    $tmp = fh_sess_prepare($payload);
    try {
        fh_sess_commit($tmp, $path);
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
}

/** Чтение записи сессии по пути (содержимое всегда проверяется заново после блокировки). */
function fh_sess_read(string $path): array {
    $raw = @file_get_contents($path, false, null, 0, FH_SESSION_RECORD_MAX_BYTES);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('session_invalid');
    try { $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new RuntimeException('session_invalid'); }
    if (!is_array($data)) throw new RuntimeException('session_invalid');
    return $data;
}

/**
 * Исключительная блокировка записи сессии.
 *
 * Возвращает дескриптор, заблокированный по текущему inode файла. Ожидание
 * ограничено: при занятом файле ответ — закрытый код конфликта, а не бесконечное
 * ожидание (FPM-пул из 4 воркеров).
 *
 * Аудит 12.11.2026 (P0, stale-inode locking): flock привязан к inode, а atomic
 * rename заменяет файл по пути новым inode. Поэтому:
 *   1) после захвата блокировки сверяется fstat($fd) с актуальным stat($path) по
 *      dev/ino — дескриптор может оказаться «протухшим» (файл успели заменить,
 *      пока мы ждали блокировку);
 *   2) при несовпадении дескриптор освобождается, путь переоткрывается, попытка
 *      повторяется в пределах общего бюджета ожидания.
 * @return resource
 */
function fh_sess_lock(string $path) {
    $deadline = microtime(true) + (FH_SESSION_LOCK_WAIT_MS / 1000);
    $attempts = 0;
    while (true) {
        $fd = @fopen($path, 'r+');
        if ($fd === false) throw new RuntimeException('session_invalid');
        $locked = false;
        try {
            while (true) {
                if (@flock($fd, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                if (microtime(true) >= $deadline) { @fclose($fd); throw new RuntimeException('session_refresh_conflict'); }
                usleep(25000);
            }
            // Дескриптор мог «протухнуть»: путь уже указывает на другой inode.
            if (!fh_sess_same_inode($fd, $path)) {
                $attempts++;
                @flock($fd, LOCK_UN); @fclose($fd);
                if ($attempts > 64 || microtime(true) >= $deadline) {
                    throw new RuntimeException('session_refresh_conflict');
                }
                continue;   // переоткрыть текущий путь и повторить
            }
        } catch (Throwable $e) {
            if (is_resource($fd)) { @flock($fd, LOCK_UN); @fclose($fd); }
            throw $e;
        }
        // Успех: дескриптор остаётся открытым и заблокированным до конца
        // критической секции (регистр освобождается в finally вызывающего кода).
        fh_sess_lock_push($fd);
        fh_sess_test_delay('after_lock');
        return $fd;
    }
}

/** Серверная проверка принадлежности записи ожидаемому аккаунту/клиенту. */
function fh_sess_assert_identity(array $rec): void {
    if (!is_int($rec['user_id'] ?? null) || $rec['user_id'] <= 0) throw new RuntimeException('session_invalid');
    if (!is_string($rec['account_id'] ?? null) || !hash_equals(fh_env_required('FILEHUB_AMO_ACCOUNT_ID'), $rec['account_id'])) {
        throw new RuntimeException('session_invalid');
    }
    if (!is_string($rec['client_uuid'] ?? null) || !hash_equals(fh_env_required('FILEHUB_AMO_CLIENT_UUID'), $rec['client_uuid'])) {
        throw new RuntimeException('session_invalid');
    }
}

/**
 * Создание новой сессии с расширенной записью (поколение 1).
 * Формат совместим с fh_test_session_validate(): прежние поля не меняются,
 * добавляются issued_at/absolute_expires/generation/revoked/session_id/token_hash.
 * @return array{token:string,expires:int,absolute_expires:int}
 */
function fh_sess_record_create(array $claims): array {
    $now = time();
    if (!in_array(getenv('FILEHUB_DAV_MODE'), ['test_crm_ivan0082', 'prod_mail'], true)) {
        throw new RuntimeException('session_mode_denied');
    }
    $jwtExp = (int)($claims['exp'] ?? 0);
    if ($jwtExp <= $now) throw new RuntimeException('session_expired');
    $exp = min($now + FH_SESSION_IDLE_TTL_S, $jwtExp);
    $absolute = min($jwtExp, $now + FH_SESSION_ABSOLUTE_TTL_S);
    if ($exp <= $now || $absolute <= $now) throw new RuntimeException('session_expired');
    $token = bin2hex(random_bytes(32));
    $rec = [
        'user_id'         => (int)$claims['user_id'],
        'account_id'      => (string)$claims['account_id'],
        'client_uuid'     => (string)$claims['client_uuid'],
        'lead_id'         => getenv('FILEHUB_DAV_MODE') === 'prod_mail' ? '*' : fh_env_required('FILEHUB_TEST_LEAD_ID'),
        'expires'         => $exp,
        'issued_at'       => $now,
        'absolute_expires'=> $absolute,
        'generation'      => 1,
        'revoked'         => false,
        'session_id'      => bin2hex(random_bytes(16)),
        'token_hash'      => hash('sha256', $token),
        'refresh_count'   => 0,
        'last_refresh_at' => 0,
        'refresh_stamps'  => [],
    ];
    fh_sess_write_atomic(fh_sess_path($token), $rec);
    // Критическая секция создания завершена: блокировка опубликованного inode
    // снимается сразу (иначе она держалась бы до конца запроса).
    fh_sess_lock_release_all();
    return ['token' => $token, 'expires' => $exp, 'absolute_expires' => $absolute, 'user_id' => (int)$claims['user_id']];
}

/**
 * Проверка тела refresh по строке (вынесено для тестируемости).
 *
 * Допустимо ТОЛЬКО пустое тело или пустой объект {}. Любые claims отклоняются.
 *
 * Аудит 12.11.2026 (P2): json_decode($raw, true) превращает и {}, и [] в пустой
 * PHP-массив, поэтому проверка '$p !== []' принимала JSON-массив [] вопреки
 * контракту. Теперь объект декодируется БЕЗ assoc и проверяется как stdClass:
 * пустой объект {} принимается, массив [] — нет.
 */
function fh_sess_assert_body_string(string $raw): void {
    if (trim($raw) === '') return;
    if (strlen($raw) > 4096) throw new RuntimeException('payload_too_large');
    try { $p = json_decode($raw, false, 4, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new RuntimeException('invalid_payload'); }
    if (!($p instanceof stdClass)) throw new RuntimeException('invalid_payload');
    if ((array)$p !== []) throw new RuntimeException('invalid_payload');
}

/** Тело refresh: пусто или строго {}. Любые claims не принимаются. */
function fh_sess_assert_empty_body(): void {
    $raw = @file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($raw)) $raw = '';
    fh_sess_assert_body_string($raw);
}

/** Проверка лимита частоты refresh для одной сессии. */
function fh_sess_rate_guard(array $rec, int $now): void {
    $stamps = [];
    foreach (($rec['refresh_stamps'] ?? []) as $t) { if (is_int($t)) $stamps[] = $t; }
    $stamps = array_values(array_filter($stamps, static fn(int $t): bool => $t > $now - 60));
    if (count($stamps) >= FH_SESSION_REFRESH_MAX_PER_MIN) throw new RuntimeException('session_refresh_rate_limited');
    $last = (int)($rec['last_refresh_at'] ?? 0);
    if ($last > 0 && ($now - $last) < FH_SESSION_REFRESH_MIN_GAP_S) throw new RuntimeException('session_refresh_rate_limited');
}

/**
 * Вычисление результата refresh. Выполняется ПОД блокировкой, но НЕ вызывает
 * respond()/exit: ответ формируется после явного освобождения блокировки.
 * @return array{0:int,1:array<string,mixed>}
 */
function fh_sess_refresh_compute(string $token): array {
    try { $path = fh_sess_path($token); }
    catch (Throwable) { return [401, ['error' => 'session_invalid']]; }

    $fd = null;
    try {
        try { $fd = fh_sess_lock($path); }
        catch (Throwable $e) {
            if ($e->getMessage() === 'session_refresh_conflict') return [409, ['error' => 'session_refresh_conflict']];
            return [401, ['error' => 'session_invalid']];
        }
        // Повторная сверка inode перед чтением: путь и заблокированный дескриптор
        // обязаны указывать на один и тот же файл (аудит 12.11.2026, P0).
        if (!fh_sess_same_inode($fd, $path)) {
            return [409, ['error' => 'session_refresh_conflict']];
        }
        // Содержимое перечитывается ПОСЛЕ блокировки: это единственный источник истины.
        if (!is_file($path)) return [401, ['error' => 'session_expired']];
        try { $rec = fh_sess_read($path); }
        catch (Throwable) { return [401, ['error' => 'session_invalid']]; }
        fh_sess_test_delay('after_read');

        if (($rec['token_hash'] ?? null) !== null && !hash_equals((string)$rec['token_hash'], hash('sha256', $token))) {
            return [401, ['error' => 'session_invalid']];
        }
        if (!empty($rec['revoked'])) {
            // Токен вытеснен ротацией: клиент должен синхронизироваться с новой вкладкой.
            if (!empty($rec['replaced_by'])) return [409, ['error' => 'session_refresh_conflict']];
            return [401, ['error' => 'session_revoked']];
        }
        $now = time();
        // Порядок проверок: АБСОЛЮТНЫЙ дедлайн важнее idle-дедлайна.
        // Сессия, у которой истёк idle (expires), но ещё жив абсолютный срок,
        // ПРОДЛЕЖАЕТСЯ, а не отвергается. Раньше опоздавший refresh (например
        // из-за троттлинга таймеров в фоновой вкладке) отвечал 401 session_expired
        // и убивал сессию, хотя продлить её ещё было можно. Клиент после такого
        // 401 не мог ни продлить сессию, ни переавторизоваться (JWT amoCRM уже
        // израсходован) и показывал «Ошибка подключения к бэкенду».
        if (!is_int($rec['absolute_expires'] ?? null)) return [403, ['error' => 'session_legacy_not_extendable']];
        if ($rec['absolute_expires'] <= $now) return [401, ['error' => 'session_absolute_expired']];
        if (!is_int($rec['expires'] ?? null)) return [401, ['error' => 'session_invalid']];
        // idle истёк, но absolute жив: продлеваем (newExp считается ниже)
        if ($rec['expires'] <= $now) $rec['expires'] = $now;
        if (!is_int($rec['generation'] ?? null) || $rec['generation'] < 1) return [403, ['error' => 'session_legacy_not_extendable']];
        try { fh_sess_assert_identity($rec); }
        catch (Throwable) { return [401, ['error' => 'session_invalid']]; }
        $mode = fh_sess_refresh_mode();
        if ($mode === 'stable' && ($rec['expires'] === $rec['absolute_expires'] ||
            $rec['expires'] > $now + FH_SESSION_REFRESH_WINDOW_S)) {
            // Ответ без продления: клиент обязан считать 'expires' фактическим
            // (признак extended=false снимает неоднозначность 200-ответа).
            return [200, ['sessionToken' => $token, 'expires' => $rec['expires'],
                'absoluteExpires' => $rec['absolute_expires'], 'extended' => false,
                'serverTime' => $now]];
        }
        try { fh_sess_rate_guard($rec, $now); }
        catch (Throwable) { return [429, ['error' => 'session_refresh_rate_limited']]; }

        $newExp = min($now + FH_SESSION_IDLE_TTL_S, (int)$rec['absolute_expires']);
        if ($newExp <= $now) return [401, ['error' => 'session_absolute_expired']];

        $stamps = [];
        foreach (($rec['refresh_stamps'] ?? []) as $t) { if (is_int($t) && $t > $now - 60) $stamps[] = $t; }
        $stamps[] = $now;

        if ($mode === 'stable') {
            $newRec = $rec;
            $newRec['expires']         = $newExp;
            $newRec['generation']      = (int)$rec['generation'] + 1;
            $newRec['last_refresh_at'] = $now;
            $newRec['refresh_count']   = (int)($rec['refresh_count'] ?? 0) + 1;
            $newRec['refresh_stamps']  = array_slice($stamps, -5);
            fh_sess_test_delay('before_write');
            fh_sess_write_atomic($path, $newRec);
            fh_sess_test_delay('after_write');
            return [200, [
                'sessionToken'    => $token,
                'expires'         => $newExp,
                'absoluteExpires' => (int)$rec['absolute_expires'],
                'extended'        => true,
                'serverTime'      => $now,
            ]];
        }

        // --- rotate -------------------------------------------------------------
        $newToken = bin2hex(random_bytes(32));
        $newPath  = fh_sess_path($newToken);
        $newRec = $rec;
        $newRec['token_hash']      = hash('sha256', $newToken);
        $newRec['expires']         = $newExp;
        $newRec['generation']      = (int)$rec['generation'] + 1;
        $newRec['last_refresh_at'] = $now;
        $newRec['refresh_count']   = (int)($rec['refresh_count'] ?? 0) + 1;
        $newRec['refresh_stamps']  = array_slice($stamps, -5);
        $newRec['revoked']         = false;
        unset($newRec['replaced_by']);

        // 1) новая запись готовится и ПРОВЕРЯЕТСЯ, но ещё не видна;
        // 2) старый токен закрывается атомарной заменой (tombstone);
        // 3) новая запись публикуется атомарной заменой.
        // Порядок 2->3 гарантирует: в любой момент активен НЕ БОЛЕЕ ОДНОГО токена.
        // Сбой между 2 и 3 = fail closed (сессия закрыта, нужна новая авторизация),
        // но НЕ два активных токена и НЕ torn-файл.
        $newTmp  = fh_sess_prepare($newRec);
        $tomb = $rec;
        $tomb['revoked']     = true;
        $tomb['revoked_at']  = $now;
        $tomb['expires']     = $now;              // целое > 0: GC удалит tombstone через 60 с
        $tomb['generation']  = (int)$rec['generation'] + 1;
        $tomb['replaced_by'] = hash('sha256', $newToken);
        $tombTmp = fh_sess_prepare($tomb);
        fh_sess_test_delay('before_write');
        fh_sess_commit($tombTmp, $path);            // старый bearer закрыт немедленно
        fh_sess_test_delay('after_write');
        fh_sess_commit($newTmp, $newPath);          // новый bearer активен
        return [200, [
            'sessionToken'    => $newToken,
            'expires'         => $newExp,
            'absoluteExpires' => (int)$rec['absolute_expires'],
            'extended'        => true,
            'serverTime'      => $now,
        ]];
    } catch (Throwable $e) {
        $m = $e->getMessage();
        $code = in_array($m, ['session_store_unavailable', 'session_refresh_conflict', 'session_invalid',
                              'session_expired', 'session_absolute_expired', 'session_revoked',
                              'session_refresh_rate_limited'], true) ? $m : 'session_store_unavailable';
        $status = match ($code) {
            'session_store_unavailable'      => 503,
            'session_refresh_conflict'       => 409,
            'session_refresh_rate_limited'   => 429,
            default                          => 401,
        };
        return [$status, ['error' => $code]];
    } finally {
        // Освобождаются ВСЕ удерживаемые блокировки критической секции: старый inode
        // (файл, который мы перезаписали) и новый (файл, который его заменил).
        // Порядок обратный захвату: сначала новый inode, затем старый.
        fh_sess_lock_release_all();
        if (is_resource($fd)) { @flock($fd, LOCK_UN); @fclose($fd); }
    }
}

/**
 * Обработчик POST /wp-json/czfh/v1/session/refresh.
 * Никаких WebDAV-операций, никаких изменений audit/pending/operation-state,
 * никакого повторного fh_claim_jti().
 */
function fh_sess_refresh_handle(): never {
    $token = $_SERVER['HTTP_X_FH_SESSION'] ?? '';
    if (!is_string($token) || $token === '') respond(401, ['error' => 'session_required']);
    try { fh_sess_assert_empty_body(); }
    catch (Throwable $e) {
        $m = $e->getMessage();
        respond($m === 'payload_too_large' ? 413 : 400, ['error' => $m === 'payload_too_large' ? 'payload_too_large' : 'invalid_payload']);
    }
    [$status, $body] = fh_sess_refresh_compute($token);
    if ($status === 429) header('Retry-After: 35');
    respond($status, $body);
}
