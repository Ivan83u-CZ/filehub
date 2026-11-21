<?php
declare(strict_types=1);
/** RC02 auth module. No amoCRM secrets may be embedded in source, JS or logs. */
/**
 * FIX-1: the accepted JTI-marker format and the "write in progress" window are taken
 * verbatim from the approved JTI collector jti_gc.php v1.5, so that the application and
 * the collector agree on what a valid marker is:
 *   jti_gc.php v1.5: PAT_EXP        = '/^[0-9]{9,11}$/D'   (applied to trim($raw))
 *   jti_gc.php v1.5: FRESH_WINDOW_S = 300
 * The collector is NOT modified; these two values only mirror its rules.
 */
if (!defined('FH_JTI_EXP_PATTERN')) define('FH_JTI_EXP_PATTERN','/^[0-9]{9,11}$/D');
if (!defined('FH_JTI_FRESH_WINDOW_S')) define('FH_JTI_FRESH_WINDOW_S',300);
function fh_b64url_decode(string $data): string|false {
    if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $data) !== 1) return false;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
}
function fh_b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function fh_env_required(string $key): string {
    $s = getenv($key);
    if (!is_string($s) || $s === '' || str_starts_with($s, 'REPLACE_')) {
        throw new RuntimeException('missing_configuration');
    }
    return $s;
}
function fh_jwt_validate(string $jwt, ?int $now = null): array {
    $now ??= time();
    if (strlen($jwt) > 8192 || substr_count($jwt, '.') !== 2) throw new RuntimeException('invalid_jwt');
    [$h64,$p64,$s64] = explode('.', $jwt);
    $h = fh_b64url_decode($h64); $p = fh_b64url_decode($p64); $sig = fh_b64url_decode($s64);
    if ($h === false || $p === false || $sig === false || strlen($sig)!==32) throw new RuntimeException('invalid_jwt');
    $header = json_decode($h,true,16,JSON_THROW_ON_ERROR);
    $payload = json_decode($p,true,24,JSON_THROW_ON_ERROR);
    if (!is_array($header) || ($header['alg']??'') !== 'HS256' || !is_array($payload)) throw new RuntimeException('invalid_jwt');
    if (isset($header['crit']) || isset($header['jku']) || isset($header['jwk']) || isset($header['kid'])) throw new RuntimeException('invalid_header');
    $expected = hash_hmac('sha256',$h64.'.'.$p64,fh_env_required('FILEHUB_AMO_CLIENT_SECRET'),true);
    if (!hash_equals($expected,$sig)) throw new RuntimeException('invalid_signature');
    foreach (['iat','nbf','exp','account_id','user_id'] as $k) {
        if (!isset($payload[$k]) || !is_int($payload[$k])) throw new RuntimeException('invalid_claim_'.$k);
    }
    $iat=$payload['iat'];$nbf=$payload['nbf'];$exp=$payload['exp'];
    if ($iat>$now+30 || $nbf>$now+30 || $exp<=$now-30 || $exp<=$iat || $exp-$iat>3600) throw new RuntimeException('invalid_time');
    if ($payload['user_id']<=0 || $payload['account_id']<=0) throw new RuntimeException('invalid_identity');
    foreach (['iss'=>'FILEHUB_AMO_ISSUER','aud'=>'FILEHUB_AMO_AUDIENCE','client_uuid'=>'FILEHUB_AMO_CLIENT_UUID'] as $claim=>$env) {
        $value=$payload[$claim]??null;
        if (!is_string($value) || !hash_equals(fh_env_required($env),$value)) throw new RuntimeException('invalid_claim_'.$claim);
    }
    if ((string)$payload['account_id']!==fh_env_required('FILEHUB_AMO_ACCOUNT_ID')) throw new RuntimeException('invalid_account');
    $jti=$payload['jti']??null;
    if (!is_string($jti) || preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$jti)!==1) throw new RuntimeException('invalid_jti');
    return $payload;
}
/** Filesystem-based one-time jti for single-host staging ONLY; prod requires shared atomic store. */
function fh_claim_jti(array $claims): bool {
    $base = fh_env_required('FILEHUB_JTI_DIR');
    if (!is_dir($base) || !is_writable($base)) throw new RuntimeException('jti_store_unavailable');
    $file=$base.'/'.hash('sha256',(string)$claims['account_id'].'|'.$claims['jti']);
    $fd=@fopen($file,'x');
    if ($fd===false) {
        // FIX-1: fopen('x') fails for several DIFFERENT reasons and the previous code
        // mapped all of them to 'jwt_replayed':
        //   (1) the one-time marker exists and holds a valid timestamp -> a genuine replay;
        //   (2) the marker exists but holds no parsable timestamp and is FRESH
        //       (<= FH_JTI_FRESH_WINDOW_S) -> the marker content is INVALID.
        //       A concurrent write in progress is the most likely explanation, but the
        //       age alone does NOT prove it (a short failed write looks the same), so
        //       the category is deliberately neutral: 'jti_marker_invalid_recent';
        //   (3) the marker exists but holds no parsable timestamp and is STALE
        //       (> FH_JTI_FRESH_WINDOW_S) -> a leftover of a failed write (ENOSPC);
        //   (4) the marker exists but cannot be read -> unreadable, never a replay;
        //   (5) the marker could not be created at all -> storage fault.
        // Only case (1) may be reported as a replay. The marker is only READ here and
        // never rewritten: the on-disk format stays a bare unix timestamp.
        clearstatcache(true,$file);
        if (!is_file($file)) throw new RuntimeException('jti_store_unavailable');
        $raw=@file_get_contents($file);                       // FULL content, no truncation
        if (!is_string($raw)) throw new RuntimeException('jti_marker_unreadable');
        if (preg_match(FH_JTI_EXP_PATTERN,trim($raw))===1) return false;   // genuine replay
        $mt=@filemtime($file);
        if ($mt===false) throw new RuntimeException('jti_marker_unreadable');
        if ((time()-(int)$mt)<=FH_JTI_FRESH_WINDOW_S) throw new RuntimeException('jti_marker_invalid_recent');
        throw new RuntimeException('jti_marker_corrupt');
    }
    @chmod($file,0600);
    $expiry=(string)$claims['exp'];
    $written=@fwrite($fd,$expiry)===strlen($expiry)&&@fflush($fd)&&@fsync($fd);
    fclose($fd);
    if(!$written)throw new RuntimeException('jti_store_unavailable');
    return true;
}
function fh_authenticated_user(string $jwt): array {
    $claims=fh_jwt_validate($jwt);
    if (!fh_claim_jti($claims)) throw new RuntimeException('jwt_replayed');
    return $claims;
}
/** Session bridge for the one synthetic canary lead only. Never used for writes. */
function fh_test_session_file(string $token): string {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('invalid_session');
    $base=fh_env_required('FILEHUB_JTI_DIR');
    if (!is_dir($base)) throw new RuntimeException('session_store_unavailable');
    return $base.'/session-'.hash('sha256',$token);
}
function fh_test_session_create(array $claims,?int $now=null): array {
    $now ??= time();
    if (!in_array(getenv('FILEHUB_DAV_MODE'),['test_crm_ivan0082','prod_mail'],true)) throw new RuntimeException('session_mode_denied');
    $exp=min($now+600,(int)($claims['exp']??0));
    if ($exp <= $now) throw new RuntimeException('session_expired');
    $token=bin2hex(random_bytes(32));
    $file=fh_test_session_file($token);
    $payload=[
        'user_id'=>(int)$claims['user_id'],
        'account_id'=>(string)$claims['account_id'],
        'client_uuid'=>(string)$claims['client_uuid'],
        'lead_id'=>getenv('FILEHUB_DAV_MODE')==='prod_mail' ? '*' : fh_env_required('FILEHUB_TEST_LEAD_ID'),
        'expires'=>$exp,
    ];
    $fd=@fopen($file,'x');
    if ($fd===false) throw new RuntimeException('session_store_unavailable');
    $data=json_encode($payload,JSON_THROW_ON_ERROR);
    if (fwrite($fd,$data)!==strlen($data)) {
        fclose($fd); @unlink($file); throw new RuntimeException('session_store_unavailable');
    }
    fclose($fd); @chmod($file,0600);
    return ['token'=>$token,'expires'=>$exp];
}
function fh_test_session_validate(string $token,?int $now=null): array {
    $now ??= time();
    if (!in_array(getenv('FILEHUB_DAV_MODE'),['test_crm_ivan0082','prod_mail'],true)) throw new RuntimeException('session_mode_denied');
    $file=fh_test_session_file($token);
    $raw=@file_get_contents($file,false,null,0,1024);
    if (!is_string($raw) || $raw==='') throw new RuntimeException('invalid_session');
    $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_int($data['expires']??null) || ($data['expires']??0)<=$now) {
        throw new RuntimeException('session_expired');
    }
    if (!is_int($data['user_id']??null) || $data['user_id']<=0) throw new RuntimeException('invalid_session');
    if (!is_string($data['account_id']??null) || !hash_equals(fh_env_required('FILEHUB_AMO_ACCOUNT_ID'),$data['account_id'])) throw new RuntimeException('invalid_session');
    if (!is_string($data['client_uuid']??null) || !hash_equals(fh_env_required('FILEHUB_AMO_CLIENT_UUID'),$data['client_uuid'])) throw new RuntimeException('invalid_session');
    if (!is_string($data['lead_id']??null) || !hash_equals(getenv('FILEHUB_DAV_MODE')==='prod_mail' ? '*' : fh_env_required('FILEHUB_TEST_LEAD_ID'),$data['lead_id'])) throw new RuntimeException('invalid_session');
    return $data;
}
function fh_cap_secret(): string {
    $hex=fh_env_required('FILEHUB_LINK_HMAC_KEY');
    if (preg_match('/^[a-f0-9]{64}$/iD',$hex)!==1) throw new RuntimeException('invalid_link_key');
    return hex2bin($hex);
}
function fh_cap_canonical(string $route,array $q): string {
    if (!in_array($route,['file','thumb'],true)) throw new RuntimeException('invalid_route');
    $keys=['leadId','category','name','download','w','h','exp','uid','nonce'];
    $parts=['fh-cap-v1',$route];
    foreach ($keys as $k) { $v=$q[$k]??''; if (!is_string($v)) throw new RuntimeException('invalid_cap_param'); $parts[]=$v; }
    return implode("\n",$parts);
}
function fh_cap_params(string $route,string $lead,string $category,string $name,int $uid,string $variant='view',?int $now=null): array {
    $now ??= time();
    if (!preg_match('/^[1-9][0-9]{0,17}$/D',$lead) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$uid)) throw new RuntimeException('invalid_cap_identity');
    if (!in_array($category,['photos','tickets','report','calls','passport','other','ai'],true)) throw new RuntimeException('invalid_category');
    if ($name==='' || strlen($name)>255 || preg_match('/[\x00-\x1f\x7f\/\\\\]/',$name) || $name==='.' || $name==='..' || str_contains($name,'..')) throw new RuntimeException('invalid_name');
    $q=['leadId'=>$lead,'category'=>$category,'name'=>$name,'download'=>($variant==='download'?'1':'0'),'w'=>($route==='thumb'?'260':''),'h'=>($route==='thumb'?'160':''),'exp'=>(string)($now+600),'uid'=>(string)$uid,'nonce'=>bin2hex(random_bytes(12))];
    $q['sig']=fh_b64url_encode(hash_hmac('sha256',fh_cap_canonical($route,$q),fh_cap_secret(),true));
    return $q;
}
function fh_cap_valid(string $route,array $q,?int $now=null): bool {
    $now??=time();
    foreach (['leadId','category','name','download','w','h','exp','uid','nonce','sig'] as $k) if (!isset($q[$k]) || !is_string($q[$k])) return false;
    if (!ctype_digit($q['exp']) || (int)$q['exp']<$now || (int)$q['exp']>$now+900) return false;
    if (!preg_match('/^[a-f0-9]{24}$/D',$q['nonce'])) return false;
    if (!preg_match('/^[1-9][0-9]{0,17}$/D',$q['uid'])) return false;
    if (!preg_match('/^[1-9][0-9]{0,17}$/D',$q['leadId'])) return false;
    if (!in_array($q['category'],['photos','tickets','report','calls','passport','other','ai'],true)) return false;
    if ($q['download']!=='0' && $q['download']!=='1') return false;
    if ($route==='thumb') { if ($q['download']!=='0' || $q['w']!=='260' || $q['h']!=='160') return false; }
    else { if ($q['w']!=='' || $q['h']!=='') return false; }
    if ($q['name']==='' || strlen($q['name'])>255 || preg_match('/[\x00-\x1f\x7f\/\\\\]/',$q['name']) || str_contains($q['name'],'..')) return false;
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$q['sig'])) return false;
    try { $expected=fh_b64url_encode(hash_hmac('sha256',fh_cap_canonical($route,$q),fh_cap_secret(),true)); }
    catch (Throwable) { return false; }
    return hash_equals($expected,$q['sig']);
}
function fh_public_url(string $route,array $params): string {
    $base=rtrim(fh_env_required('FILEHUB_PUBLIC_API_URL'),'/');
    $u=parse_url($base);
    if (!is_array($u) || ($u['scheme']??'')!=='https' || !isset($u['host']) || !str_ends_with($base,'/wp-json/czfh/v1')) throw new RuntimeException('invalid_public_url');
    return $base.'/'.$route.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
function fh_attach_links(array $item,string $lead,string $category,int $uid): array {
    $name=$item['name']??'';
    if (!is_string($name) || $name==='') return $item;
    $item['url']=fh_public_url('file',fh_cap_params('file',$lead,$category,$name,$uid));
    $item['downloadUrl']=fh_public_url('file',fh_cap_params('file',$lead,$category,$name,$uid,'download'));
    // Миниатюра выдаётся для любой разрешённой категории, а не только для photos:
    // виджет показывает карточку файла одинаково во всех вкладках.
    if (fh12_thumb_format($name,(string)($item['contentType']??''))!==null) {
        $item['thumbUrl']=fh_public_url('thumb',fh_cap_params('thumb',$lead,$category,$name,$uid));
    }
    return $item;
}
/**
 * Формат миниатюры для файла: 'jpg' | 'png' | 'webp' | null.
 *
 * null означает «миниатюра невозможна»: клиент не получает thumbUrl и не показывает
 * битую картинку. Поддерживаются JPEG, PNG и WebP; GIF читается и отдаётся как JPEG.
 * Расширение имени — основной признак, content-type используется только когда
 * расширение ничего не говорит (файл сохранён без осмысленного расширения).
 */
function fh12_thumb_format(?string $name,?string $contentType): ?string {
    $ext=is_string($name)?strtolower((string)pathinfo($name,PATHINFO_EXTENSION)):'';
    $byExt=['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'jpg'];
    if($ext!==''&&isset($byExt[$ext]))return $byExt[$ext];
    $ct='';
    if(is_string($contentType)&&$contentType!==''){
        $ct=strtolower(trim(explode(';',$contentType)[0]));
    }
    $byCt=['image/jpeg'=>'jpg','image/pjpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'jpg'];
    if($ct!==''&&isset($byCt[$ct]))return $byCt[$ct];
    return null;
}
