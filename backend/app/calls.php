<?php
declare(strict_types=1);
// Stage04 /calls_feed: equivalent legacy response shape; explicitly NO MKCOL or writes.
// api.php has already validated route/method and X-API-KEY.
$leadId = $_GET['leadId'] ?? '';
if (!is_string($leadId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $leadId)) {
    respond(400, ['error'=>'invalid_lead_id']);
}
$mode = getenv('FILEHUB_DAV_MODE') ?: 'fixture';
if ($mode === 'test_crm_ivan0082') {
    require_once __DIR__.'/webdav_test.php';
    fh08_require_lead($leadId);
    respond(200, [
        'ok'=>true, 'leadId'=>$leadId, 'summaryMeta'=>null,
        'summaryError'=>'summary_missing', 'summaryFile'=>null,
        'timeline'=>[], 'orphanAudio'=>[],
    ]);
}

if (!in_array($mode, ['fixture', 'read_only_mail', 'prod_mail'], true)) {
    respond(503, ['error'=>'invalid_webdav_mode']);
}
// FH12 production identity and account scope have already been validated by
// fh12_dispatch() before including this legacy response-shape parser.
// The former FILEHUB_TEST_LEAD_ID gate applied only to the FH11 synthetic canary
// and incorrectly blocked all ordinary leads with HTTP 403.
if ($mode === 'read_only_mail') respond(403, ['error'=>'real_patient_mode_disabled_in_rc01']);
if ($mode === 'prod_mail') {
    $user = getenv('FILEHUB_DAV_USER') ?: '';
    $pass = getenv('FILEHUB_DAV_PASS') ?: '';
    if ($user === '' || $pass === '') respond(503, ['error'=>'mail_credentials_not_configured']);
}
$folder = '/CRM/' . $leadId . '/5_calls';
/**
 * Read-only WebDAV access for the calls feed.
 * The stream wrapper (file_get_contents + stream context) produced false "no answer"
 * results while Mail.ru was slow, which surfaced to the user as HTTP 502 on the feed.
 * curl with a bounded number of attempts removes that failure mode.
 * Only GET and PROPFIND are used here: the feed never writes.
 */
function fh_calls_request(string $method,string $url,?string $body,array $headers,int $timeout,int $maxBytes,int $attempts):array {
    $status=0;
    for($try=1;$try<=$attempts;$try++){
        $payload='';$tooLarge=false;$status=0;
        $ch=curl_init($url);
        if($ch===false)return [0,null];
        $options=[
            CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>min(15,$timeout),
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$payload,&$tooLarge,$maxBytes):int {
                if(strlen($payload)+strlen($chunk)>$maxBytes){$tooLarge=true;return 0;}
                $payload.=$chunk;return strlen($chunk);
            },
        ];
        if($body!==null)$options[CURLOPT_POSTFIELDS]=$body;
        if(!curl_setopt_array($ch,$options)){curl_close($ch);return [0,null];}
        $ok=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if($tooLarge)return [$status,null];
        // A definitive answer (2xx/3xx/4xx) is returned as is. A transport failure or a 5xx
        // means "not known yet": retry, because these calls are read-only.
        if($ok!==false&&$status!==0&&$status<500)return [$status,$payload];
        if($try<$attempts)usleep(400000*$try);
    }
    return [$status,null];
}
if ($mode === 'fixture') {
    if ($leadId === '123') {
        $xml = @file_get_contents('/var/www/fixtures/propfind_calls.xml');
        if ($xml === false) respond(503, ['error'=>'fixture_missing']);
    } else {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"></d:multistatus>';
    }
} else {
    $requestBody = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getcontenttype/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>';
    // Exact host comes from the FH12 host resolver; in production it is the fixed Mail.ru host.
    $callsHost = function_exists('fh12_host') ? fh12_host() : 'https://webdav.cloud.mail.ru';
    [$status,$xml] = fh_calls_request('PROPFIND', $callsHost . $folder, $requestBody, [
        'Authorization: Basic ' . base64_encode($user . ':' . $pass),
        'Depth: 1',
        'Content-Type: application/xml',
    ], 12, 1048576, 2);
    if ($status === 404) {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"></d:multistatus>';
    } elseif ($status !== 207 || !is_string($xml)) {
        respond(502, ['error'=>'mail_webdav_unavailable', 'upstream_status'=>$status]);
    }
}
if (!is_string($xml) || strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false) {
    respond(502, ['error'=>'invalid_webdav_xml']);
}
if (!class_exists('DOMDocument')) respond(503, ['error'=>'php_dom_not_available']);
libxml_use_internal_errors(true);
$doc = new DOMDocument();
if (!$doc->loadXML($xml, LIBXML_NONET)) respond(502, ['error'=>'invalid_webdav_xml']);
$xp = new DOMXPath($doc);
$xp->registerNamespace('d', 'DAV:');
$items = [];
foreach ($xp->query('//d:response') as $node) {
    $href = $xp->evaluate('string(d:href)', $node);
    if (!$href) continue;
    $parsed = parse_url($href, PHP_URL_PATH);
    if (!is_string($parsed)) continue;
    $decoded = rawurldecode($parsed);
    $prefix = $folder . '/';
    if (!str_starts_with($decoded, $prefix)) continue;
    $name = substr($decoded, strlen($prefix));
    if ($name === '' || str_contains($name, '/') || str_contains($name, "\0") || $name === '.' || $name === '..') continue;
    $props = $xp->query('d:propstat[contains(d:status," 200 ")]/d:prop', $node);
    if (!$props || !$props->length) continue;
    $prop = $props->item(0);
    if ($xp->evaluate('boolean(d:resourcetype/d:collection)', $prop)) continue;
    $length = $xp->evaluate('string(d:getcontentlength)', $prop);
    $items[] = [
        'name'=>$name,
        'size'=>ctype_digit($length) ? (int)$length : null,
        'contentType'=>$xp->evaluate('string(d:getcontenttype)', $prop),
        'lastmod'=>$xp->evaluate('string(d:getlastmodified)', $prop),
    ];
}

$summaryItem = null;
$mediaItems = [];
foreach ($items as $item) {
    $name = $item['name'];
    if (strcasecmp($name, 'summary.json') === 0) {
        $summaryItem = $item;
        continue;
    }
    if (preg_match('/\.(mp3|wav|ogg|m4a|amr|aac|flac|opus|mp4|m4v|webm)$/i', $name)) {
        $mediaItems[] = $item;
    }
}
usort($mediaItems, static function(array $a, array $b): int {
    $ta = strtotime($a['lastmod'] ?? '') ?: 0;
    $tb = strtotime($b['lastmod'] ?? '') ?: 0;
    return $ta === $tb ? strcmp(($b['name'] ?? ''), ($a['name'] ?? '')) : ($ta < $tb ? 1 : -1);
});

$summary = null;
$summaryError = null;
if ($summaryItem !== null) {
    if ($mode === 'fixture') {
        $summaryRaw = @file_get_contents('/var/www/fixtures/summary_calls.json');
        $ok = is_string($summaryRaw);
    } else {
        // Exact filename comes from 5_calls PROPFIND listing; host from the FH12 host resolver.
        $callsHost = function_exists('fh12_host') ? fh12_host() : 'https://webdav.cloud.mail.ru';
        $url = $callsHost . $folder . '/' . rawurlencode($summaryItem['name']);
        [$status,$summaryRaw] = fh_calls_request('GET', $url, null, [
            'Authorization: Basic ' . base64_encode($user . ':' . $pass),
        ], 18, 2097152, 2);
        $ok = $status === 200 && is_string($summaryRaw) && strlen($summaryRaw) <= 2097152;
    }
    if (!$ok) {
        $summaryError = 'summary_fetch_failed';
    } else {
        $decoded = json_decode($summaryRaw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $summaryError = 'invalid_summary_json';
        } else {
            $summary = $decoded;
        }
    }
} else {
    $summaryError = 'summary_missing';
}

$timeline = [];
$summaryMeta = null;
$matchedAudioNames = [];
if (is_array($summary)) {
    $previousLeadIds = $summary['previous_lead_ids'] ?? [];
    $mergedFromLeadIds = $summary['merged_from_lead_ids'] ?? [];
    $summaryMeta = [
        'updatedAt'=>$summary['updated_at'] ?? '',
        'callsCount'=>(int)($summary['calls_count'] ?? 0),
        'firstCallAt'=>$summary['first_call_at'] ?? '',
        'lastCallAt'=>$summary['last_call_at'] ?? '',
        'previousLeadIds'=>is_array($previousLeadIds) ? array_values($previousLeadIds) : [],
        'mergedFromLeadIds'=>is_array($mergedFromLeadIds) ? array_values($mergedFromLeadIds) : [],
    ];
    $calls = $summary['calls'] ?? [];
    if (!is_array($calls)) $calls = [];
    foreach ($calls as $call) {
        if (!is_array($call)) continue;
        $uniq = (string)($call['uniq'] ?? '');
        $audio = null;
        if ($uniq !== '') {
            foreach ($mediaItems as $item) {
                if (strpos($item['name'], $uniq) !== false) {
                    $audio = $item;
                    $matchedAudioNames[$item['name']] = true;
                    break;
                }
            }
        }
        $timeline[] = [
            'uniq'=>$uniq,
            'callAt'=>$call['call_at'] ?? '',
            'operatorName'=>$call['operator_name'] ?? '',
            'amoStageId'=>(string)($call['amo_stage_id'] ?? ''),
            'amoStageName'=>$call['amo_stage_name'] ?? '',
            'summary'=>$call['summary'] ?? '',
            'audio'=>$audio !== null ? fh_attach_links($audio,$leadId,'calls',$fhUserId) : null,
        ];
    }
    usort($timeline, static function(array $a, array $b): int {
        $ta = strtotime($a['callAt'] ?? '') ?: 0;
        $tb = strtotime($b['callAt'] ?? '') ?: 0;
        return $ta === $tb ? strcmp($b['uniq'], $a['uniq']) : ($ta < $tb ? 1 : -1);
    });
}
$orphans = [];
foreach ($mediaItems as $item) {
    if (!isset($matchedAudioNames[$item['name']])) $orphans[] = fh_attach_links($item,$leadId,'calls',$fhUserId);
}
// Audit the completed fetch without logging patient text, filenames or contents.
if ($mode === 'prod_mail' && function_exists('fh12_log')) {
    fh12_log('calls', (int)$fhUserId, $leadId, 'calls',
        $summaryError ?? 'ok', 207, count($timeline) + count($orphans));
}
respond(200, [
    'ok'=>true,
    'leadId'=>$leadId,
    'summaryMeta'=>$summaryMeta,
    'summaryError'=>$summaryError,
    'summaryFile'=>$summaryItem !== null ? [
        'name'=>$summaryItem['name'],
        'size'=>$summaryItem['size'],
        'lastmod'=>$summaryItem['lastmod'],
    ] : null,
    'timeline'=>$timeline,
    'orphanAudio'=>$orphans,
]);
