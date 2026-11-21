<?php
declare(strict_types=1);
// Unified FileHub RC02: amoCRM auth + signed links. Synthetic-only, NEVER patient data.
// RC02 does NOT use mTLS: amoCRM JWT exchanges for a short synthetic session.
// Media uses signed, time-limited bearer capability URLs; not approved for patient data.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * R1/R2: идентификатор запроса. Edge передаёт собственный X-Request-ID; сохраняем
 * его, чтобы access-log edge и backend-журнал можно было сопоставить. Если заголовка
 * нет или он некорректен, генерируем новый UUID.
 */
function fh_request_id(): string {
    static $id = null;
    if ($id !== null) return $id;
    $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if (is_string($incoming) && preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/Di', $incoming) === 1) {
        $id = strtolower($incoming);
        return $id;
    }
    try { $b = random_bytes(16); }
    catch (Throwable) { $b = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()); }
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    $id = substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    return $id;
}
$fqRequestId = fh_request_id();
header('X-Request-ID: '.$fqRequestId);
$_SERVER['HTTP_X_REQUEST_ID'] = $fqRequestId;

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$prefix = '/wp-json/czfh/v1/';
if (!is_string($path) || !str_starts_with($path, $prefix)) {
    respond(404, ['error' => 'not_found']);
}
$route = substr($path, strlen($prefix));
$methods = [
    'ping' => ['GET'], 'session' => ['POST'], 'files' => ['GET'], 'file' => ['GET','DELETE'],
    'thumb' => ['GET'], 'upload' => ['POST'], 'rename' => ['POST'],
    'trash' => ['GET','POST'], 'restore' => ['POST'],
    'calls_feed' => ['GET'], 'proxy_save_call' => ['POST'],
    'ai_analyze' => ['POST'], 'ai_report' => ['GET'], 'ai_test' => ['POST'],
    'operator_card' => ['GET'], 'operator_card_diag' => ['GET'],
    'operator_card_publish_local' => ['POST'],
    'operator_card_wazzup_snapshot' => ['POST'], 'fh11' => ['GET','POST'],
    // R1: продление действующей сессии (без повторного расходования JWT/JTI).
    'session/refresh' => ['POST'], 'client-events' => ['POST'], 'activity' => ['GET'],
];
if (!array_key_exists($route, $methods)) {
    respond(404, ['error' => 'not_found']);
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, $methods[$route], true)) {
    header('Allow: ' . implode(', ', $methods[$route]));
    respond(405, ['error' => 'method_not_allowed']);
}
// Native amoCRM auth. A browser JS API key is NOT accepted as identity.
require_once __DIR__.'/auth.php';
// R1 module. Optional by design: if the file is absent the new route answers 404 and no
// fatal occurs, so a mixed-version state (new router + missing module) stays safe.
if (is_file(__DIR__.'/fh_session_refresh.php')) require_once __DIR__.'/fh_session_refresh.php';
if (is_file(__DIR__.'/fh_client_events.php')) require_once __DIR__.'/fh_client_events.php';
if (is_file(__DIR__.'/fh_activity_history.php')) require_once __DIR__.'/fh_activity_history.php';
// FH12 production gateway is opt-in. FH11 remains unchanged in test mode.
if (getenv('FILEHUB_DAV_MODE') === 'prod_mail') {
    require_once __DIR__.'/fh12_prod.php';
    fh12_dispatch($route,$method);
}
// R1 in non-prod dispatch modes (test_crm_ivan0082): same handler, same codes.
if ($route === 'session/refresh' && $method === 'POST') {
    if (!function_exists('fh_sess_refresh_handle')) respond(404, ['error' => 'route_not_available']);
    fh_sess_refresh_handle();
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $origin !== 'https://chinazub.amocrm.ru') respond(403,['error'=>'origin_denied']);
if (getenv('FILEHUB_DAV_MODE') !== 'test_crm_ivan0082') respond(503,['error'=>'synthetic_only']);
// A disposable amoCRM JWT is consumed once, only when exchanging for a synthetic read-only session.
if ($route === 'session') {
    $jwt=$_SERVER['HTTP_X_AUTH_TOKEN']??'';
    if (!is_string($jwt) || $jwt==='') respond(401,['error'=>'amo_token_required']);
    try {
        $claims=fh_authenticated_user($jwt);
        $session=fh_test_session_create($claims);
    } catch (Throwable $e) {
        error_log('[FH-SESSION] '.(in_array($e->getMessage(),[
            'jwt_replayed','invalid_signature','invalid_claim_aud',
            'invalid_claim_client_uuid','session_store_unavailable','session_expired',
        ],true)?$e->getMessage():'auth_failed'));
        respond(401,['error'=>'amo_session_denied']);
    }
    respond(200,['sessionToken'=>$session['token'],'expires'=>$session['expires']]);
}
$mediaGet = ($route === 'file' && $method === 'GET') || ($route === 'thumb' && $method === 'GET');
$fhUserId=0;
if ($mediaGet) {
    if (!fh_cap_valid($route,$_GET)) respond(403,['error'=>'invalid_or_expired_media_link']);
    // In RC02, media URL is a bearer capability. Login is checked when issuing it.
    $fhUserId=(int)$_GET['uid'];
} else {
    $sessionToken=$_SERVER['HTTP_X_FH_SESSION']??'';
    $fh09Mutation = ($method==='POST' && in_array($route,['upload','rename','trash','restore'],true));
    $fh11Mutation = ($route==='fh11' && $method==='POST');
    if (is_string($sessionToken) && $sessionToken!=='') {
        if ($fh11Mutation) {
            require_once __DIR__.'/fh11_webdav.php';
            if (!fh11_enabled()) respond(403,['error'=>'fh11_disabled']);
        } elseif ($fh09Mutation) {
            // Mutations require explicit server-side ack and a valid existing session.
            require_once __DIR__.'/fh09_scratch.php';
            if (!fh09_enabled()) respond(403,['error'=>'synthetic_ops_disabled']);
        } elseif ($method!=='GET' || !in_array($route,['files','calls_feed','ping','trash','fh11'],true)) {
            respond(403,['error'=>'session_read_only']);
        }
        try {
            $session=fh_test_session_validate($sessionToken);
            $fhUserId=$session['user_id'];
        } catch (Throwable) { respond(401,['error'=>'session_invalid']); }
    } else {
        // Do not allow a one-shot JWT to bypass the session-only write gate.
        if ($route==='fh11') respond(401,['error'=>'fh11_session_required']);
        if ($fh09Mutation || ($route==='file' && $method==='DELETE')) respond(403,['error'=>'test_write_requires_session']);
        // Backward-compatible one-shot JWT path. jti replay protection stays on.
        $jwt=$_SERVER['HTTP_X_AUTH_TOKEN']??'';
        if (!is_string($jwt) || $jwt==='') respond(401,['error'=>'amo_token_required']);
        try {
            $claims=fh_authenticated_user($jwt);
            $fhUserId=(int)$claims['user_id'];
        } catch (Throwable) { respond(401,['error'=>'amo_token_invalid']); }
    }
}
// TODO before production: active-user check via amoCRM API; atomic shared jti store;
// network-independent emergency revocation; audit; per-file large media streaming.
if ($route === 'ping') {
    respond(200, ['pong' => true, 'time' => gmdate('c')]);
}
if (($route === 'file' && $method === 'GET') || $route === 'thumb') {
    require __DIR__ . '/media.php';
}
if ($route==='fh11') {
    // Explicitly session-only, no one-shot JWT and no public fallback.
    require_once __DIR__.'/fh11_webdav.php';
    if (!fh11_enabled()) respond(403,['error'=>'fh11_disabled']);
    if ($method==='GET') {
        if (($_GET['leadId']??null)!=='34228264' || ($_GET['category']??null)!=='other') respond(403,['error'=>'fh11_scope_denied']);
        fh11_list((int)$fhUserId);
    }
    fh11_run((int)$fhUserId);
}
if ($route === 'calls_feed') {
    require __DIR__ . '/calls.php';
}
if ($route==='file' && $method==='DELETE') respond(403,['error'=>'hard_delete_disabled']);
if ($route==='trash' && $method==='GET') {
    require_once __DIR__.'/fh09_scratch.php';
    $lead=$_GET['leadId']??null;
    $category=$_GET['category']??null;
    fh09_validate_lead($lead);
    if ($category!=='other') respond(403,['error'=>'synthetic_other_only']);
    respond(200,fh09_items(true));
}
if ($method==='POST' && in_array($route,['upload','rename','trash','restore'],true)) {
    require_once __DIR__.'/fh09_scratch.php';
    fh09_operation($route,(int)$fhUserId);
}
if ($route !== 'files') {
    respond(503, ['error' => 'staging_only_not_implemented', 'route' => $route]);
}

// /files retains the legacy array and adds signed view/download/thumbnail URLs.
$leadId = $_GET['leadId'] ?? '';
$category = $_GET['category'] ?? 'other';
if (!is_string($leadId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $leadId)) {
    respond(400, ['error' => 'invalid_lead_id']);
}
$dirs = [
    'passport' => '1_passport', 'tickets' => '2_tickets',
    'photos' => '3_photos', 'other' => '4_other',
    'calls' => '5_calls', 'report' => '6_Otchet', 'ai' => '7_AI',
];
if (!is_string($category) || !array_key_exists($category, $dirs)) {
    respond(400, ['error' => 'invalid_category']);
}
$mode = getenv('FILEHUB_DAV_MODE') ?: 'fixture';
if ($mode === 'test_crm_ivan0082') {
    require_once __DIR__ . '/webdav_test.php';
    fh08_require_lead($leadId);
    $folder = '/CRM/ivan0082/' . $dirs[$category];
} else {
    $folder = '/CRM/' . $leadId . '/' . $dirs[$category];
}
if ($mode === 'fixture' && $category === 'other' && $leadId === '123') {
    $dir = getenv('FILEHUB_FIXTURE_SCRATCH') ?: '/var/www/scratch';
    if (!is_dir($dir)) respond(503, ['error' => 'fixture_scratch_unavailable']);
    $items = [];
    foreach ((scandir($dir) ?: []) as $name) {
        if (!is_string($name) || preg_match('/^fixture-[A-Za-z0-9_-]{1,64}\.txt$/D', $name) !== 1) continue;
        $path = $dir . '/' . $name;
        if (!is_file($path)) continue;
        $size = filesize($path);
        $mod = filemtime($path);
        $items[] = [
            'name' => $name,
            'size' => $size === false ? null : (int)$size,
            'contentType' => 'text/plain',
            'lastmod' => $mod === false ? '' : gmdate('D, d M Y H:i:s', $mod) . ' GMT',
        ];
    }
    usort($items, static fn($a, $b) => strcmp($a['name'], $b['name']));
    respond(200, array_map(static fn($it)=>fh_attach_links($it,$leadId,$category,$fhUserId),$items));
}
if ($mode === 'fixture') {
    // Fixture generated solely for lead 123/photos. No real customer data.
    if ($leadId !== '123' || $category !== 'photos') respond(200, []);
    $xml = file_get_contents('/var/www/fixtures/propfind.xml');
    if ($xml === false) respond(503, ['error' => 'fixture_missing']);
} elseif ($mode === 'test_crm_ivan0082') {
    [$status, $xml] = fh08_dav('PROPFIND', $folder, ['Depth: 1', 'Content-Type: application/xml'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getcontenttype/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>');
    if ($status !== 207 || !is_string($xml)) respond(502, ['error'=>'webdav_listing_failed', 'upstream_status'=>$status]);
    if (strlen($xml) > 1048576) respond(502, ['error'=>'webdav_listing_too_large']);
} elseif ($mode === 'read_only_mail') {
    respond(403, ['error' => 'real_patient_mode_disabled_in_rc01']);
} else {
    respond(503, ['error' => 'invalid_webdav_mode']);
}
if (!class_exists('DOMDocument')) respond(503, ['error' => 'php_dom_not_available']);
if (str_contains($xml, '<!DOCTYPE') || strlen($xml) > 1048576) respond(502, ['error' => 'invalid_webdav_xml']);
libxml_use_internal_errors(true);
$doc = new DOMDocument();
if (!$doc->loadXML($xml, LIBXML_NONET)) respond(502, ['error' => 'invalid_webdav_xml']);
$xp = new DOMXPath($doc);
$xp->registerNamespace('d', 'DAV:');
$items = [];
foreach ($xp->query('//d:response') as $node) {
    $href = $xp->evaluate('string(d:href)', $node);
    if ($href === '') continue;
    $p = parse_url($href, PHP_URL_PATH);
    if (!is_string($p)) continue;
    $decoded = rawurldecode($p);
    $base = $folder . '/';
    if (!str_starts_with($decoded, $base)) continue;
    $relative = substr($decoded, strlen($base));
    if ($relative === '' || str_contains($relative, '/') || str_contains($relative, "\0") || $relative === '.' || $relative === '..') continue;
    // Only successful property blocks count; ignore collection pseudo-files.
    $props = $xp->query('d:propstat[contains(d:status, " 200 ")]/d:prop', $node);
    if (!$props || $props->length === 0) continue;
    $prop = $props->item(0);
    if ($xp->evaluate('boolean(d:resourcetype/d:collection)', $prop)) continue;
    $sizeRaw = $xp->evaluate('string(d:getcontentlength)', $prop);
    $items[] = [
        'name' => $relative,
        'size' => ctype_digit($sizeRaw) ? (int)$sizeRaw : null,
        'contentType' => $xp->evaluate('string(d:getcontenttype)', $prop),
        'lastmod' => $xp->evaluate('string(d:getlastmodified)', $prop),
    ];
}
// Synthetic-only local media examples overlay. Never adds Mail.ru patient data.
if ($mode === 'test_crm_ivan0082') {
    require_once __DIR__.'/test_media_catalog.php';
    $items=array_merge($items, fh_media_test_items($category));
    if ($category==='other') {
        require_once __DIR__.'/fh09_scratch.php';
        $items=array_merge($items,fh09_items(false));
    }
}
respond(200, array_map(static fn($it)=>fh_attach_links($it,$leadId,$category,$fhUserId),$items));
