<?php
declare(strict_types=1);
/**
 * FileHub RC02 — R2 «client telemetry» — STAGING CANDIDATE (НЕ ВЫКАЧЕН В PROD).
 *
 * Приём диагностических событий виджета. Авторизация — ТОЛЬКО действующая
 * FileHub-сессия (X-FH-Session). JWT не принимается и не расходуется: причина
 * отсутствия события остаётся в серверном auth-журнале, клиент повторно /session
 * НЕ вызывает.
 *
 * ПРИВАТНОСТЬ (жёсткий allowlist, никакого «сырого» тела в журнале):
 *   - принимаются только перечисленные ниже поля; неизвестное поле => 400
 *   - никаких filename/URL/headers/JWT/token/stack/HTML/медицинских данных
 *   - для ошибок сохраняются numeric lead_id, category и client_time_ms;
 *     operator_id берётся из действующей FileHub-сессии, а сообщённый клиентом
 *     operator_id сверяется с ней
 *   - тело целиком НИКОГДА не попадает в журнал и не сохраняется
 *   - account_id/user_id берутся ТОЛЬКО из серверного контекста сессии,
 *     из payload не берутся
 *   - сырые записи: 14 дней, агрегаты: 30 дней, общий бюджет диска: 128 МиБ,
 *     при переполнении — stop-on-full (503), а не молчаливая потеря
 */
if (!defined('FH_TELEMETRY_MAX_BODY'))        define('FH_TELEMETRY_MAX_BODY', 8192);            // 8 КиБ на запрос
if (!defined('FH_TELEMETRY_MAX_EVENTS'))       define('FH_TELEMETRY_MAX_EVENTS', 10);             // 1..10 событий в батче
if (!defined('FH_TELEMETRY_MAX_PER_MIN'))      define('FH_TELEMETRY_MAX_PER_MIN', 30);            // запросов в минуту на актора
if (!defined('FH_TELEMETRY_RAW_RETENTION_DAYS')) define('FH_TELEMETRY_RAW_RETENTION_DAYS', 14);   // сырые записи, дней
if (!defined('FH_TELEMETRY_AGG_RETENTION_DAYS')) define('FH_TELEMETRY_AGG_RETENTION_DAYS', 30);   // агрегаты, дней
if (!defined('FH_TELEMETRY_DISK_BUDGET'))      define('FH_TELEMETRY_DISK_BUDGET', 134217728);     // 128 МиБ
if (!defined('FH_TELEMETRY_SCAN_MAX_BYTES'))    define('FH_TELEMETRY_SCAN_MAX_BYTES', 2097152);      // 2 МиБ на файл: предел скана дедупликации
if (!defined('FH_TELEMETRY_DIR'))              define('FH_TELEMETRY_DIR', '/var/www/ops10/telemetry');
/**
 * Каталог хранилища телеметрии задаётся КОНСТАНТОЙ (а не переменной окружения):
 * php-fpm работает с clear_env=yes, поэтому новая переменная потребовала бы
 * рестарта FPM. Каталог по умолчанию — примонтированный том, отдельный от web-root
 * и отдельный от JTI tmpfs.
 */
if (!defined('FH_TELEMETRY_DIR_DEFAULT')) define('FH_TELEMETRY_DIR_DEFAULT', FH_TELEMETRY_DIR);

/** Allowlist событий (совпадает с TELEMETRY_ALLOWED виджета 2.8.6). */
function fh_telemetry_event_allowlist(): array {
    return [
        'widget_render_failed' => 1, 'context_missing' => 1, 'session_attempt' => 1, 'session_issued' => 1,
        'session_failed' => 1, 'session_reauth_blocked' => 1, 'request_failed' => 1, 'request_timeout' => 1,
        'upload_verified' => 1, 'upload_ambiguous' => 1, 'upload_paused' => 1, 'list_failed' => 1,
        'trash_failed' => 1, 'calls_failed' => 1, 'thumb_failed' => 1, 'image_preview_failed' => 1,
    ];
}
/**
 * Allowlist признаков (feature).
 *
 * 'rename' и 'restore' добавлены вместе с виджетом 2.8.17: раньше виджет помечал
 * ВСЕ POST-запросы как 'upload', и по телеметрии нельзя было отличить
 * переименование от корзины. Значение 'upload' остаётся допустимым — старые
 * виджеты продолжают слать его.
 */
function fh_telemetry_feature_allowlist(): array {
    return ['auth' => 1, 'upload' => 1, 'files' => 1, 'trash' => 1, 'calls' => 1, 'preview' => 1, 'widget' => 1,
            'rename' => 1, 'restore' => 1];
}
/** Allowlist полей события. */
function fh_telemetry_field_allowlist(): array {
    return ['schema_version' => 1, 'widget_version' => 1, 'event' => 1, 'feature' => 1, 'event_id' => 1, 'error_code' => 1,
            'http_status' => 1, 'duration_ms' => 1, 'corr_id' => 1, 'queue_remaining' => 1,
            'operator_id' => 1, 'lead_id' => 1, 'category' => 1, 'client_time_ms' => 1];
}

/**
 * Строгая проверка одного события. Любое нарушение => исключение 'schema_invalid'.
 * Возвращает нормализованное событие (только allowlist-поля, приведённые к типам).
 */
function fh_telemetry_normalize_event(mixed $ev): array {
    if (!is_array($ev)) throw new RuntimeException('schema_invalid');
    foreach (array_keys($ev) as $k) {
        if (!is_string($k) || !isset(fh_telemetry_field_allowlist()[$k])) throw new RuntimeException('schema_invalid');
    }
    $sv = $ev['schema_version'] ?? null;
    if (!is_int($sv) || $sv !== 1) throw new RuntimeException('schema_invalid');
    $wv = $ev['widget_version'] ?? null;
    if (!is_string($wv) || !preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){1,2}$/D', $wv)) throw new RuntimeException('schema_invalid');
    $name = $ev['event'] ?? null;
    if (!is_string($name) || !isset(fh_telemetry_event_allowlist()[$name])) throw new RuntimeException('schema_invalid');
    $feat = $ev['feature'] ?? null;
    if (!is_string($feat) || !isset(fh_telemetry_feature_allowlist()[$feat])) throw new RuntimeException('schema_invalid');

    $out = ['schema_version' => 1, 'widget_version' => $wv, 'event' => $name, 'feature' => $feat];

    if (array_key_exists('event_id', $ev)) {
        $eventId = $ev['event_id'];
        if (!is_string($eventId) ||
            (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $eventId) !== 1 &&
             preg_match('/^evt_[a-z0-9]+_[a-z0-9]+_[a-z0-9]+$/D', $eventId) !== 1)) {
            throw new RuntimeException('schema_invalid');
        }
        $out['event_id'] = preg_match('/^evt_/D', $eventId) === 1 ? $eventId : strtolower($eventId);
    }

    if (array_key_exists('error_code', $ev)) {
        $ec = $ev['error_code'];
        if (!is_string($ec) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $ec)) throw new RuntimeException('schema_invalid');
        $out['error_code'] = $ec;
    }
    if (array_key_exists('http_status', $ev)) {
        $hs = $ev['http_status'];
        if (!is_int($hs) || $hs < 0 || $hs > 599) throw new RuntimeException('schema_invalid');
        $out['http_status'] = $hs;
    }
    if (array_key_exists('duration_ms', $ev)) {
        $dm = $ev['duration_ms'];
        if (!is_int($dm) || $dm < 0 || $dm > 600000) throw new RuntimeException('schema_invalid');
        $out['duration_ms'] = $dm;
    }
    if (array_key_exists('corr_id', $ev)) {
        $cid = $ev['corr_id'];
        if (!is_string($cid) || !fh_telemetry_valid_corr_id($cid)) throw new RuntimeException('schema_invalid');
        $out['corr_id'] = $cid;
    }
    if (array_key_exists('queue_remaining', $ev)) {
        $qr = $ev['queue_remaining'];
        if (!is_int($qr) || $qr < 0 || $qr > 100000) throw new RuntimeException('schema_invalid');
        $out['queue_remaining'] = $qr;
    }
    if (array_key_exists('operator_id', $ev)) {
        $operatorId = $ev['operator_id'];
        if (!is_string($operatorId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $operatorId)) {
            throw new RuntimeException('schema_invalid');
        }
        $out['operator_id'] = $operatorId;
    }
    if (array_key_exists('lead_id', $ev)) {
        $leadId = $ev['lead_id'];
        if (!is_string($leadId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $leadId)) {
            throw new RuntimeException('schema_invalid');
        }
        $out['lead_id'] = $leadId;
    }
    if (array_key_exists('category', $ev)) {
        $category = $ev['category'];
        if (!is_string($category) || !in_array($category,
            ['passport', 'tickets', 'photos', 'other', 'calls', 'report', 'ai'], true)) {
            throw new RuntimeException('schema_invalid');
        }
        $out['category'] = $category;
    }
    if (array_key_exists('client_time_ms', $ev)) {
        $clientTime = $ev['client_time_ms'];
        if (!is_int($clientTime) || $clientTime < 0 || $clientTime > 4102444800000) {
            throw new RuntimeException('schema_invalid');
        }
        $out['client_time_ms'] = $clientTime;
    }
    return $out;
}

/** A reported operator is accepted only when it matches the session identity. */
function fh_telemetry_assert_operator(array $event, int $operatorId): void {
    if (isset($event['operator_id']) && $event['operator_id'] !== (string)$operatorId) {
        throw new RuntimeException('schema_invalid');
    }
}

/** The caller holds LOCK_EX on the day's JSONL file while reading this index. */
function fh_telemetry_event_index_key(string $actor, string $eventId): string {
    return hash('sha256', $actor . "\n" . $eventId);
}

/**
 * Скан уже записанных event_id для дедупликации.
 *
 * Скан ОГРАНИЧЕН по объёму (FH_TELEMETRY_SCAN_MAX_BYTES на файл): без предела
 * каждый POST /client-events перечитывал дневной лог целиком, поэтому поток
 * телеметрии (а тем более его лавина) съедал I/O интерактивного пула FPM
 * (2 воркера) и замедлял /files. За пределами окна повтор того же события может
 * быть принят как новое — для аналитической телеметрии это безвредно.
 */
function fh_telemetry_existing_event_ids($fd,int $maxBytes = 0): array {
    $ids = [];
    if (!is_resource($fd)) return $ids;
    if ($maxBytes > 0) {
        $st = @fstat($fd);
        $size = (is_array($st) && isset($st['size'])) ? (int)$st['size'] : 0;
        if ($size > $maxBytes) {
            if (@fseek($fd, $size - $maxBytes, SEEK_SET) !== 0) return $ids;
            @fgets($fd);   // первая строка окна может быть обрезанной — пропускаем
        } elseif (!@rewind($fd)) {
            return $ids;
        }
    } elseif (!@rewind($fd)) {
        return $ids;
    }
    while (($line = fgets($fd)) !== false) {
        $row = json_decode($line, true);
        if (is_array($row) && is_string($row['event_id'] ?? null)) {
            $actor = (string)($row['account_id'] ?? '') . ':' . (string)($row['operator_id'] ?? '');
            $ids[fh_telemetry_event_index_key($actor, $row['event_id'])] = true;
        }
    }
    return $ids;
}

/** Filter replayed event ids while the caller holds the JSONL file lock. */
function fh_telemetry_filter_fresh_rows(array $rows, array &$seen, string $actor): array {
    $fresh = [];
    foreach ($rows as $row) {
        $eventId = $row['event']['event_id'] ?? null;
        $indexKey = is_string($eventId) ? fh_telemetry_event_index_key($actor, $eventId) : '';
        if ($indexKey !== '' && isset($seen[$indexKey])) continue;
        if ($indexKey !== '') $seen[$indexKey] = true;
        $fresh[] = $row;
    }
    return $fresh;
}

/** Names are mapped from the authenticated amoCRM user id, never from the payload. */
function fh_telemetry_operator_name(int $operatorId): ?string {
    return [
        1287411 => 'Иван',
        10108470 => 'Юля',
        10467934 => 'Сергей',
        11455242 => 'Залина',
        12202974 => 'Оксана',
    ][$operatorId] ?? null;
}

/** corr_id: UUID v4 либо короткий неперсональный идентификатор. */
function fh_telemetry_valid_corr_id(string $cid): bool {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $cid) === 1) return true;
    return preg_match('/^[A-Za-z0-9_-]{7,64}$/D', $cid) === 1;
}

/**
 * Проверка «сырого» тела на запрещённые фрагменты: JWT, URL, HTML, stack-подобное.
 * Тело не логируется и не сохраняется — проверка только по памяти.
 */
function fh_telemetry_assert_no_secrets(string $raw): void {
    $forbidden = ['eyJ', 'Bearer ', 'http://', 'https://', '<', '>', '\\', 'data:', 'file:', '-----BEGIN'];
    foreach ($forbidden as $needle) {
        if (stripos($raw, $needle) !== false) throw new RuntimeException('schema_invalid');
    }
}

/** Корневой каталог хранилища телеметрии (вне web-root). */
function fh_telemetry_root(): string {
    $dir = (string)FH_TELEMETRY_DIR_DEFAULT; // Existing persistent /var/www/ops10 bind-mount; no recreate.
    $dir = rtrim(trim($dir), '/');
    if ($dir === '' || str_contains($dir, '..') || str_contains($dir, "\0")) throw new RuntimeException('writer_unavailable');
    return $dir;
}

/** Проверка доступности хранилища (создаёт подкаталоги при первом обращении). */
function fh_telemetry_ensure_dirs(): string {
    $root = fh_telemetry_root();
    foreach ([$root, $root . '/raw', $root . '/agg', $root . '/rate'] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) throw new RuntimeException('writer_unavailable');
    }
    if (!is_writable($root . '/raw') || !is_writable($root . '/agg')) throw new RuntimeException('writer_unavailable');
    return $root;
}

/** Суммарный размер хранилища (байт). */
function fh_telemetry_dir_size(string $root): int {
    $total = 0;
    foreach (['raw', 'agg'] as $sub) {
        $dir = $root . '/' . $sub;
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $sz = @filesize($dir . '/' . $name);
            if (is_int($sz)) $total += $sz;
        }
    }
    return $total;
}

/** Удаление сырых записей старше срока и агрегатов старше срока. */
function fh_telemetry_sweep(string $root, int $now): void {
    $rawCut = $now - (FH_TELEMETRY_RAW_RETENTION_DAYS * 86400);
    $aggCut = $now - (FH_TELEMETRY_AGG_RETENTION_DAYS * 86400);
    foreach (@scandir($root . '/raw') ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $p = $root . '/raw/' . $name;
        $mt = @filemtime($p);
        if (is_int($mt) && $mt < $rawCut) @unlink($p);
    }
    foreach (@scandir($root . '/agg') ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.agg.lock') continue;
        $p = $root . '/agg/' . $name;
        $mt = @filemtime($p);
        if (is_int($mt) && $mt < $aggCut) @unlink($p);
    }
    // Очистка счётчиков rate-limiter старше 5 минут.
    foreach (@scandir($root . '/rate') ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $p = $root . '/rate/' . $name;
        $mt = @filemtime($p);
        if (is_int($mt) && $mt < $now - 300) @unlink($p);
    }
}

/**
 * Ограничение частоты: не более FH_TELEMETRY_MAX_PER_MIN запросов в минуту на актора.
 * Актор = серверный account_id/user_id (не из payload).
 * @return bool true — можно принимать, false — превышен лимит
 */
function fh_telemetry_rate_guard(string $root, string $actor): bool {
    $file = $root . '/rate/' . hash('sha256', $actor) . '.json';
    $fd = @fopen($file, 'c+');
    if ($fd === false) return true;                 // при недоступности счётчика — не блокируем работу
    $allow = true;
    if (@flock($fd, LOCK_EX)) {
        $raw = @stream_get_contents($fd);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $minute = (int)floor(time() / 60);
        $count  = 0;
        if (is_array($data) && (int)($data['minute'] ?? -1) === $minute) $count = (int)($data['count'] ?? 0);
        if ($count >= FH_TELEMETRY_MAX_PER_MIN) {
            $allow = false;
        } else {
            $count++;
            $payload = json_encode(['minute' => $minute, 'count' => $count], JSON_THROW_ON_ERROR);
            @ftruncate($fd, 0);
            @rewind($fd);
            @fwrite($fd, $payload);
            @fflush($fd);
        }
        @flock($fd, LOCK_UN);
    }
    @fclose($fd);
    return $allow;
}

/** Прирост дневного агрегата (счётчики по event/error_code/http_status). */
function fh_telemetry_bump_aggregate(string $root, string $day, array $event, int $httpStatus): void {
    $file = $root . '/agg/agg-' . $day . '.json';
    $lock = $root . '/agg/.agg.lock';
    $lf = @fopen($lock, 'c');
    if ($lf === false) return;
    if (!@flock($lf, LOCK_EX)) { @fclose($lf); return; }
    $data = [];
    $raw = @file_get_contents($file);
    if (is_string($raw) && $raw !== '') { $j = json_decode($raw, true); if (is_array($j)) $data = $j; }
    $ev = (string)$event['event'];
    $data['events'] = ($data['events'] ?? []); $data['events'][$ev] = (int)($data['events'][$ev] ?? 0) + 1;
    if (isset($event['error_code'])) {
        $ec = (string)$event['error_code'];
        $data['error_codes'] = ($data['error_codes'] ?? []); $data['error_codes'][$ec] = (int)($data['error_codes'][$ec] ?? 0) + 1;
    }
    $st = (string)$httpStatus;
    $data['http_status'] = ($data['http_status'] ?? []); $data['http_status'][$st] = (int)($data['http_status'][$st] ?? 0) + 1;
    $data['updated_at'] = time();
    $tmp = $root . '/agg/.agg.tmp.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== false) {
        @chmod($tmp, 0640);
        @rename($tmp, $file);
    }
    @flock($lf, LOCK_UN);
    @fclose($lf);
}

/**
 * Обработчик POST /wp-json/czfh/v1/client-events.
 * Ответ: 204 (принято) / 400 / 401 / 413 / 429 / 503.
 */
function fh_telemetry_handle(): never {
    $token = $_SERVER['HTTP_X_FH_SESSION'] ?? '';
    if (!is_string($token) || $token === '') {
        fh_telemetry_auth_log('session_missing');
        respond(401, ['error' => 'session_required']);
    }
    // Сессия проверяется ДО разбора тела: без действующей сессии событие не принимается.
    try { $identity = fh_test_session_validate($token); }
    catch (Throwable $e) {
        fh_telemetry_auth_log('session_invalid');
        respond(401, ['error' => $e->getMessage() === 'session_expired' ? 'session_expired' : 'session_invalid']);
    }
    $actor = (string)($identity['account_id'] ?? '') . ':' . (string)($identity['user_id'] ?? '');

    $raw = @file_get_contents('php://input', false, null, 0, FH_TELEMETRY_MAX_BODY + 1);
    if (!is_string($raw)) { respond(400, ['error' => 'schema_invalid']); }
    if (strlen($raw) > FH_TELEMETRY_MAX_BODY) respond(413, ['error' => 'payload_too_large']);
    try { fh_telemetry_assert_no_secrets($raw); }
    catch (Throwable) { respond(400, ['error' => 'schema_invalid']); }
    try { $payload = json_decode($raw, true, 12, JSON_THROW_ON_ERROR); }
    catch (Throwable) { respond(400, ['error' => 'schema_invalid']); }
    if (!is_array($payload)) respond(400, ['error' => 'schema_invalid']);
    $events = $payload['events'] ?? null;
    if (!is_array($events) || $events === [] || count($events) > FH_TELEMETRY_MAX_EVENTS) {
        respond(400, ['error' => 'schema_invalid']);
    }
    // В конверте допускаются только 'schema_version' и 'events'.
    foreach (array_keys($payload) as $k) {
        if ($k !== 'events' && $k !== 'schema_version') respond(400, ['error' => 'schema_invalid']);
    }
    if (isset($payload['schema_version']) && (!is_int($payload['schema_version']) || $payload['schema_version'] !== 1)) {
        respond(400, ['error' => 'schema_invalid']);
    }

    $normalized = [];
    foreach ($events as $ev) {
        try {
            $event = fh_telemetry_normalize_event($ev);
            fh_telemetry_assert_operator($event, (int)($identity['user_id'] ?? 0));
            $normalized[] = $event;
        }
        catch (Throwable) { respond(400, ['error' => 'schema_invalid']); }
    }

    try { $root = fh_telemetry_ensure_dirs(); }
    catch (Throwable) { respond(503, ['error' => 'writer_unavailable']); }
    if (!fh_telemetry_rate_guard($root, $actor)) {
        header('Retry-After: 60');
        respond(429, ['error' => 'rate_limited']);
    }
    if (fh_telemetry_dir_size($root) > FH_TELEMETRY_DISK_BUDGET) respond(503, ['error' => 'store_full']);

    $now = time();
    $day = gmdate('Y-m-d', $now);
    $file = $root . '/raw/events-' . $day . '.jsonl';
    $fd = @fopen($file, 'c+');
    if ($fd === false) respond(503, ['error' => 'writer_unavailable']);
    $previousFd = null;
    $previousDay = gmdate('Y-m-d', $now - 86400);
    foreach ($normalized as $ev) {
        $clientTime = $ev['client_time_ms'] ?? null;
        if (is_int($clientTime) && gmdate('Y-m-d', (int)floor($clientTime / 1000)) === $previousDay) {
            $previousPath = $root . '/raw/events-' . $previousDay . '.jsonl';
            if (is_file($previousPath)) {
                $previousFd = @fopen($previousPath, 'r');
                if ($previousFd === false) { @fclose($fd); respond(503, ['error' => 'writer_unavailable']); }
            }
            break;
        }
    }
    $requestId = isset($_SERVER['HTTP_X_REQUEST_ID']) && is_string($_SERVER['HTTP_X_REQUEST_ID'])
        ? substr($_SERVER['HTTP_X_REQUEST_ID'], 0, 64) : fh_telemetry_new_request_id();
    $corr = null;
    $rawCorr = $_SERVER['HTTP_X_CORRELATION_ID'] ?? '';
    if (is_string($rawCorr) && fh_telemetry_valid_corr_id($rawCorr)) $corr = $rawCorr;

    $rows = [];
    foreach ($normalized as $ev) {
        $row = $ev;
        $row['received_at'] = $now;
        $row['request_id']  = $requestId;
        $row['account_id']  = (string)($identity['account_id'] ?? '');
        $row['user_id']     = (int)($identity['user_id'] ?? 0);
        $row['operator_id'] = (int)($identity['user_id'] ?? 0);
        $operatorName = fh_telemetry_operator_name((int)($identity['user_id'] ?? 0));
        if ($operatorName !== null) $row['operator_name'] = $operatorName;
        $row['session_gen'] = (int)($identity['generation'] ?? 0);
        if ($corr !== null && !isset($row['corr_id'])) $row['corr_id'] = $corr;
        $rows[] = ['event' => $ev, 'line' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    }
    $ok = true;
    $freshEvents = [];
    $previousSeen = [];
    if ($previousFd !== null) {
        if (@flock($previousFd, LOCK_SH)) $previousSeen = fh_telemetry_existing_event_ids($previousFd, FH_TELEMETRY_SCAN_MAX_BYTES);
        else $ok = false;
    }
    if ($ok && @flock($fd, LOCK_EX)) {
        $seen = $previousSeen + fh_telemetry_existing_event_ids($fd, FH_TELEMETRY_SCAN_MAX_BYTES);
        $lines = [];
        $freshRows = fh_telemetry_filter_fresh_rows($rows, $seen, $actor);
        foreach ($freshRows as $row) {
            $lines[] = $row['line'];
            $freshEvents[] = $row['event'];
        }
        if ($lines && fseek($fd, 0, SEEK_END) !== 0) $ok = false;
        foreach ($lines as $line) {
            if (!$ok || @fwrite($fd, $line . "\n") !== strlen($line) + 1) { $ok = false; break; }
        }
        if ($ok && $lines && !@fflush($fd)) { $ok = false; }
        // R2 (аудит 12.11.2026): результат fsync ПРОВЕРЯЕТСЯ. Молчаливая потеря
        // durability при ENOSPC/EIO недопустима: клиент должен получить 503 и решить,
        // отправлять ли событие повторно (операции с файлами при этом не страдают).
        if ($ok && $lines && function_exists('fsync') && !@fsync($fd)) { $ok = false; }
        @flock($fd, LOCK_UN);
    } else { $ok = false; }
    @fclose($fd);
    if ($previousFd !== null) {
        @flock($previousFd, LOCK_UN);
        @fclose($previousFd);
    }
    if (!$ok) respond(503, ['error' => 'writer_unavailable']);

    foreach ($freshEvents as $ev) {
        fh_telemetry_bump_aggregate($root, $day, $ev, (int)($ev['http_status'] ?? 0));
    }
    if (random_int(1, 50) === 1) fh_telemetry_sweep($root, $now);

    http_response_code(204);
    exit;
}

/** Идентификатор запроса сервера (если заголовок не задан nginx). */
function fh_telemetry_new_request_id(): string {
    try { $b = random_bytes(16); }
    catch (Throwable) { $b = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()); }
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/** Причина отказа остаётся в серверном auth-журнале (без тела и без токена). */
function fh_telemetry_auth_log(string $reason): void {
    if (function_exists('fh12_log')) fh12_log('auth', 0, '', '', 'denied', 0, 0, '', '', 'telemetry_' . $reason);
}
