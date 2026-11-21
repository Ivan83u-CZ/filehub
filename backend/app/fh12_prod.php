<?php
declare(strict_types=1);
/**
 * FH12 production bridge: account authentication is mandatory. Per-lead access is
 * checked through fh_lead_acl_check(); the default shadow mode records the denies
 * that enforce mode would return, while enforce requires a read-only ACL manifest.
 * All Mail.ru paths remain under /CRM/<numeric lead>/<known category>.
 * NO hard DELETE. Soft trash remains in each category.
 * Does not alter FH11 synthetic module or files.
 */
const FH12_HOST='https://webdav.cloud.mail.ru';
const FH12_PENDING_GRACE=900;      // сек: устаревший pending-маркер старше этого порога запускает самовосстановление
const FH12_RECOVER_ATTEMPTS=3;      // ограниченные повторы при проверке неоднозначной операции
const FH12_RECOVER_BACKOFF_MS=400;  // пауза между попытками восстановления
const FH12_RECHECK_INTERVAL=900;     // сек: минимальный интервал между полными перепроверками удалённого состояния
const FH12_RESOLVE_BUDGET=60;        // seconds for background reconciliation
const FH12_REQUEST_RESOLVE_BUDGET=3;
const FH12_WRITE_LOCK_WAIT_MS=1000;
const FH12_PROBE_TIMEOUT=5;          // сек: таймаут дешёвой проверки наличия внутри интервала (одна попытка)
const FH12_AUDIT_SEGMENT_MAX=1048576;// байт: предел размера активного сегмента audit-цепочки (1 МиБ)
const FH12_AUDIT_FILE_MAX=67108864;  // байт: абсолютный предел файла сегмента (защита от повреждения)
const FH12_DIRS=['passport'=>'1_passport','tickets'=>'2_tickets','photos'=>'3_photos','other'=>'4_other','calls'=>'5_calls','report'=>'6_Otchet','ai'=>'7_AI'];
const FH12_TRASH='.filehub-trash';
const FH12_MAX_UPLOAD=20971520; // 20 MiB. PHP multipart uploads are stored on disk; WebDAV PUT is streamed.
const FH12_XML='<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/><d:getcontenttype/><d:getlastmodified/></d:prop></d:propfind>';
// R2.2: модуль серверных миниатюр. Ставится ПЕРВЫМ, роутер подключает его последним:
// отсутствующий модуль не роняет приложение, а маршрут /thumb отвечает 404.
if(is_file(__DIR__.'/fh12_thumb.php'))require_once __DIR__.'/fh12_thumb.php';

function fh12_on():bool {return getenv('FILEHUB_DAV_MODE')==='prod_mail';}
/**
 * Test seam. It can only ever redirect traffic to the local machine and to a local state
 * tree: the endpoint must be a loopback address, so a stray environment variable can never
 * move patient data to another server. Without FH12_TEST_MODE=1 nothing here is active.
 */
function fh12_test_mode():bool {
    if(getenv('FH12_TEST_MODE')!=='1')return false;
    $h=getenv('FH12_DAV_HOST');
    if(!is_string($h)||$h==='')return false;
    if(preg_match('~^https?://(127\.0\.0\.1|localhost|\[::1\])(:[0-9]{1,5})?$~D',$h)!==1)return false;
    $b=getenv('FH12_STATE_BASE');
    if(!is_string($b)||$b===''||str_contains($b,'..')||str_contains($b,"\0"))return false;
    return true;
}
/** Host of the WebDAV endpoint. In production the value is hardcoded and cannot be overridden. */
function fh12_host():string {
    if(fh12_test_mode())return rtrim((string)getenv('FH12_DAV_HOST'),'/');
    return FH12_HOST;
}
/** Root of the per-lead state tree. Same prod-immunity rule as fh12_host(). */
function fh12_state_base():string {
    if(fh12_test_mode())return rtrim((string)getenv('FH12_STATE_BASE'),'/');
    return '/var/www/ops10/prod';
}
function fh12_write_on():bool {
    if(fh12_test_mode())return true;
    return fh12_on() && getenv('FILEHUB_PROD_WRITES')==='1' && getenv('FILEHUB_PROD_ACK')==='I_ACCEPT_FH12_ACCOUNT_WIDE_WRITES';
}
/**
 * Per-lead authorization seam.
 *
 * Default is shadow: the current production session carries lead_id='*', so the
 * server cannot prove that an operator may open this particular lead. Shadow mode
 * records what enforcement WOULD deny while preserving availability. Turning on
 * 'enforce' requires FILEHUB_LEAD_ACL_FILE; a missing/unreadable source fails
 * closed with 503 instead of silently reverting to account-wide access.
 *
 * The ACL file is a non-secret read-only manifest:
 *   {"version":1,"accounts":{"<account>":{"users":{"<user>":["<lead>", ...]}}}}
 */
function fh_lead_acl_mode():string {
    $raw=getenv('FILEHUB_LEAD_ACL_MODE');
    if($raw===false||$raw==='')return 'shadow';
    $m=is_string($raw)?strtolower(trim($raw)):'';
    if(!in_array($m,['off','shadow','enforce'],true))
        throw new RuntimeException('lead_acl_mode_invalid');
    return $m;
}
/**
 * Shadow denials are diagnostic, not an audit trail: without sampling one card
 * with a dozen thumbnails can write a dozen identical ACL rows per second.
 * FILEHUB_LEAD_ACL_LOG_SAMPLE_PCT=0 disables shadow rows; 100 keeps all rows.
 */
function fh_lead_acl_log_sample(string $reason,int $uid,string $account,string $lead):bool {
    $raw=getenv('FILEHUB_LEAD_ACL_LOG_SAMPLE_PCT');
    $pct=is_string($raw)&&ctype_digit($raw)?(int)$raw:10;
    if($pct<=0)return false;
    if($pct>=100)return true;
    return (crc32($account.'|'.$uid.'|'.$lead.'|'.$reason)&0x7fffffff)%100<$pct;
}
/** Logging is best-effort: an unavailable logger must not change the ACL decision. */
function fh_lead_acl_try_log(int $uid,string $lead,string $result,string $reason):void {
    try{fh12_log('auth',$uid,$lead,'',$result,403,0,'','',$reason);}catch(Throwable){}
}
function fh_lead_acl_load():?array {
    static $loaded=false,$loadedPath='',$loadedSig='',$manifest=null;
    $file=getenv('FILEHUB_LEAD_ACL_FILE');
    $path=is_string($file)?$file:'';
    $sig='';
    if($path!==''&&!str_contains($path,"\0")){
        // A permission edit must take effect without restarting PHP-FPM.
        // mtime+size+inode is a cheap cache key for an atomic replacement.
        clearstatcache(true,$path);
        $st=@stat($path);
        if(is_array($st))$sig=(string)$st['mtime'].':'.(string)$st['size'].':'.(string)$st['ino'];
    }
    if($loaded&&$loadedPath===$path&&$loadedSig===$sig)return $manifest;
    $loaded=true;
    $loadedPath=$path;
    $loadedSig=$sig;
    $manifest=null;
    if(!is_string($file)||$file===''||str_contains($file,"\0"))return null;
    if(!is_file($file)||!is_readable($file))throw new RuntimeException('lead_acl_unavailable');
    $raw=@file_get_contents($file,false,null,0,524289);
    if(!is_string($raw)||strlen($raw)>524288)throw new RuntimeException('lead_acl_unavailable');
    try{$manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}
    catch(Throwable){throw new RuntimeException('lead_acl_unavailable');}
    if(!is_array($manifest)||($manifest['version']??null)!==1||!is_array($manifest['accounts']??null))
        throw new RuntimeException('lead_acl_unavailable');
    return $manifest;
}
function fh_lead_acl_has(array $manifest,string $account,string $user,string $lead):bool {
    $users=$manifest['accounts'][$account]['users']??null;
    if(!is_array($users))return false;
    $leads=$users[$user]??null;
    if(!is_array($leads))return false;
    foreach($leads as $allowed){
        if(is_string($allowed)&&preg_match('/^[1-9][0-9]{0,17}$/D',$allowed)===1&&$allowed===$lead)return true;
    }
    return false;
}
function fh_lead_acl_check(array $identity,string $leadId):bool {
    $mode=fh_lead_acl_mode();
    if($mode==='off')return true;
    $uid=(int)($identity['user_id']??0);
    $account=(string)($identity['account_id']??'');
    if($uid<=0||$account===''||preg_match('/^[1-9][0-9]{0,17}$/D',$leadId)!==1){
        if($mode==='enforce')return false;
        if(fh_lead_acl_log_sample('invalid_identity',$uid,$account,$leadId))
            fh_lead_acl_try_log($uid,$leadId,'acl_would_deny','invalid_identity');
        return true;
    }
    $sessionLead=(string)($identity['lead_id']??'');
    $allowed=$sessionLead!==''&&$sessionLead!=='*'&&hash_equals($sessionLead,$leadId);
    // A session scoped to this exact lead is already an authorization proof; an
    // unrelated account-wide manifest must not make it unavailable.
    if($allowed)return true;
    try{
        $manifest=fh_lead_acl_load();
        if($manifest===null&&$mode==='enforce')throw new RuntimeException('lead_acl_unavailable');
        if($manifest!==null&&fh_lead_acl_has($manifest,$account,(string)$uid,$leadId))$allowed=true;
        if(!$allowed&&$mode==='enforce'){
            fh_lead_acl_try_log($uid,$leadId,'lead_acl_denied','enforce');
            return false;
        }
        if(!$allowed&&$mode==='shadow'&&fh_lead_acl_log_sample('shadow',$uid,$account,$leadId))
            fh_lead_acl_try_log($uid,$leadId,'acl_would_deny','shadow');
    }catch(Throwable $e){
        if($mode==='enforce')throw new RuntimeException('lead_acl_unavailable');
        if(fh_lead_acl_log_sample('acl_source_unavailable',$uid,$account,$leadId))
            fh_lead_acl_try_log($uid,$leadId,'acl_would_deny','acl_source_unavailable');
    }
    return true; // shadow only
}
function fh12_assert_lead_access(?array $identity,string $lead):void {
    $id=$identity??['user_id'=>0,'account_id'=>(string)getenv('FILEHUB_AMO_ACCOUNT_ID')];
    if(!fh_lead_acl_check($id,$lead))throw new RuntimeException('lead_acl_denied');
}
function fh12_lead(mixed $v):string {if(!is_string($v)||!preg_match('/^[1-9][0-9]{0,17}$/D',$v))throw new RuntimeException('invalid_lead');return $v;}
function fh12_cat(mixed $v):string {if(!is_string($v)||!isset(FH12_DIRS[$v]))throw new RuntimeException('invalid_category');return $v;}
function fh12_name(mixed $v):string {
    if(!is_string($v)||preg_match('//u',$v)!==1||strlen($v)<1||strlen($v)>220||$v==='.'||$v==='..'||str_contains($v,'..')||
       str_contains($v,'/')||str_contains($v,'\\')||preg_match('/[\x00-\x1f\x7f]/',$v)||
       $v===FH12_TRASH||str_starts_with($v,'.filehub-'))throw new RuntimeException('invalid_filename');
    return $v;
}
function fh12_dir(string $lead,string $cat,bool $trash=false):string {
    $path='/CRM/'.fh12_lead($lead).'/'.FH12_DIRS[fh12_cat($cat)];
    return $trash?$path.'/'.FH12_TRASH:$path;
}
function fh12_file(string $lead,string $cat,string $name,bool $trash=false):string {return fh12_dir($lead,$cat,$trash).'/'.fh12_name($name);}
function fh12_encode_path(string $path):string {return implode('/',array_map('rawurlencode',explode('/', $path)));}
function fh12_hash(string $value):string {return substr(hash_hmac('sha256',$value,fh_cap_secret()),0,24);}
function fh12_log(string $action,int $actor,string $lead='',string $category='',string $result='',int $status=0,int $bytes=0,string $id='',string $name='',string $reason='',string $corr=''):void {
    $allowed=['request','auth','list','read','upload','rename','trash','restore','mkdir','calls','recover','error'];
    if(!in_array($action,$allowed,true))$action='error';
    $row=['tag'=>'FH12','time'=>gmdate('Y-m-d\TH:i:s\Z'),'id'=>preg_replace('/[^a-f0-9]/','',substr($id,0,32)),
       'action'=>$action,'actor'=>max(0,$actor),'lead'=>$lead!==''?fh12_hash($lead):'',
       'category'=>isset(FH12_DIRS[$category])?$category:'','file'=>$name!==''?fh12_hash($name):'',
       'result'=>preg_replace('/[^a-z0-9_-]/','_',substr(strtolower($result),0,60)),
       'upstream'=>$status,'bytes'=>max(0,$bytes)];
    // FIX-1: optional diagnostic category. The field is appended only when it is filled in,
    // so every pre-existing log line stays byte-identical. Only whitelist values are accepted.
    if($reason!=='')$row['reason']=preg_replace('/[^a-z0-9_-]/','_',substr(strtolower($reason),0,32));
    // R1/R2: optional correlation id. It is a non-personal identifier supplied by the client
    // (X-Correlation-ID) and is validated against a strict pattern before it is logged;
    // it never contains user data, a token, a lead id or a file name.
    if($corr!=='')$row['corr']=fh12_corr_id((string)$corr);
    error_log('[FH12] '.json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
/**
 * R1/R2: строгая проверка correlation id (UUID v4 либо короткий неперсональный id).
 * Всё, что не проходит, отбрасывается молча — в журнал попадает только пустое значение.
 */
function fh12_corr_id(string $raw):string{
    if($raw===''||strlen($raw)>64)return '';
    if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$raw)===1)return strtolower($raw);
    if(preg_match('/^[A-Za-z0-9_-]{7,64}$/D',$raw)===1)return $raw;
    return '';
}
/** R1/R2: correlation id из заголовка клиента (пусто, если невалиден). */
function fh12_request_corr():string{
    $raw=$_SERVER['HTTP_X_CORRELATION_ID']??'';
    return is_string($raw)?fh12_corr_id($raw):'';
}
/**
 * FIX-1: closed whitelist mapping of an authentication failure to a diagnostic category.
 * The raw exception text is never returned, logged or exposed: only one of the fixed
 * category strings below can leave this function.
 *
 * This function deliberately lives in fh12_prod.php and NOT in auth.php: it is the only
 * cross-file dependency of the patch, and keeping it here makes every mixed-version
 * state (new auth.php + old fh12_prod.php and old auth.php + new fh12_prod.php) free of
 * undefined-function fatals. See the deployment plan, section 8.
 */
function fh_auth_failure_reason(string $message): string {
    $exact=[
        'jwt_replayed'=>'jwt_replayed',
        'invalid_time'=>'invalid_time',
        'invalid_signature'=>'invalid_signature',
        'invalid_jwt'=>'invalid_jwt',
        'invalid_header'=>'invalid_header',
        'invalid_identity'=>'invalid_identity',
        'invalid_account'=>'invalid_account',
        'invalid_jti'=>'invalid_jti',
        'session_expired'=>'session_expired',
        'session_store_unavailable'=>'session_store_unavailable',
        'session_mode_denied'=>'session_mode_denied',
        'jti_store_unavailable'=>'jti_store_unavailable',
        'jti_marker_corrupt'=>'jti_marker_corrupt',
        'jti_marker_invalid_recent'=>'jti_marker_invalid_recent',
        'jti_marker_unreadable'=>'jti_marker_unreadable',
    ];
    if (isset($exact[$message])) return $exact[$message];
    if (str_starts_with($message,'invalid_claim_')) return 'invalid_claim';
    return 'other_denied';
}
/** Prevent future API callers from widening the WebDAV filesystem scope by accident. */
function fh12_remote_scope_ok(string $method,string $path,array $headers=[],?string $body=null):bool {
    if($path==='/CRM')return in_array($method,['PROPFIND','MKCOL'],true);
    if(preg_match('~^/CRM/[1-9][0-9]{0,17}$~D',$path))return in_array($method,['PROPFIND','MKCOL'],true);
    $prefix='~^/CRM/([1-9][0-9]{0,17})/(1_passport|2_tickets|3_photos|4_other|5_calls|6_Otchet|7_AI)(?:/(\.filehub-trash))?(?:/(.+))?$~uD';
    if(preg_match($prefix,$path,$m)!==1)return false;
    $lead=$m[1];$category=$m[2];$trashed=isset($m[3]) && $m[3]===FH12_TRASH;
    $name=$m[4]??null;
    if($name===null){return in_array($method,['PROPFIND','MKCOL'],true);}
    try {fh12_name($name);}catch(Throwable){return false;}
    if($method==='GET')return $body===null;
    if($method==='PUT')return !$trashed && $body!==null && in_array('If-None-Match: *',$headers,true);
    if($method!=='MOVE'||$body!==null||!in_array('Overwrite: F',$headers,true))return false;
    $dest=array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Destination: ')));
    if(count($dest)!==1)return false;
    $dst=substr($dest[0],strlen('Destination: '));
    if(!str_starts_with($dst,fh12_host().'/CRM/'))return false;
    $decodedPath=rawurldecode(substr($dst,strlen(fh12_host())));
    if(str_contains($dst,'?')||str_contains($dst,'#'))return false;
    if(preg_match($prefix,$decodedPath,$dm)!==1)return false;
    if($dm[1]!==$lead||$dm[2]!==$category||!isset($dm[4]))return false;
    try {fh12_name($dm[4]);}catch(Throwable){return false;}
    $dstTrash=isset($dm[3])&&$dm[3]===FH12_TRASH;
    // Rename inside live, or trash/restore without changing name.
    if($dstTrash===$trashed){return !$trashed && $name!==$dm[4];}
    return $name===$dm[4];
}

function fh12_dav(string $method,string $path,array $extra=[],?string $body=null,int $limit=1048576,?int $timeoutOverride=null,?int $attemptsOverride=null):array {
    if(!in_array($method,['GET','PROPFIND','MKCOL','PUT','MOVE'],true))throw new RuntimeException('dav_method_denied');
    if(!fh12_on()&&!fh12_test_mode()&&!(getenv('FH12_PREFLIGHT')==='READ_ONLY' && in_array($method,['GET','PROPFIND'],true)))throw new RuntimeException('dav_mode_denied');
    if(!fh12_remote_scope_ok($method,$path,$extra,$body))throw new RuntimeException('dav_scope_denied');
    // Guard all paths, even if called by future routes. No absolute URL, no traversal.
    if(!preg_match('~^/CRM(?:/[1-9][0-9]{0,17}(?:/(?:1_passport|2_tickets|3_photos|4_other|5_calls|6_Otchet|7_AI)(?:/.*)?)?)?$~D',$path)||
       str_contains($path,'..')||str_contains($path,'\\')||str_contains($path,"\0"))throw new RuntimeException('dav_path_denied');
    $user=getenv('FILEHUB_DAV_USER');$pass=getenv('FILEHUB_DAV_PASS');
    if(!is_string($user)||!str_contains($user,'@')||!is_string($pass)||$pass==='')throw new RuntimeException('dav_credentials_missing');
    $headers=['Authorization: Basic '.base64_encode($user.':'.$pass),'User-Agent: FileHub-FH12/1.0'];
    foreach($extra as $h) {if(!is_string($h)||preg_match('/[\r\n]/',$h))throw new RuntimeException('invalid_dav_header');$headers[]=$h;}
    // curl sets Content-Length itself for a request body; an explicit header would duplicate it.
    // Only idempotent methods are retried: a retry of MOVE could turn a completed move into a 404.
    // Policy: no answer at all -> up to 3 attempts; 5xx -> one extra attempt;
    // any definitive status (2xx/3xx/4xx) -> returned immediately.
    // Metadata requests get a shorter per-attempt timeout: the measured latency of Mail.ru is
    // 0.1-0.2 s, so 15 s is a wide margin, and the total stays well below the timeouts of the
    // amoCRM AJAX layer - the server must answer before the client gives up.
    $attempts=$attemptsOverride??(in_array($method,['GET','PROPFIND','MKCOL'],true)?3:1);
    $timeout=$timeoutOverride??(($method==='PROPFIND'||$method==='MKCOL')?15:35);
    if($timeout<1)$timeout=1;
    if($attempts<1)$attempts=1;
    $status=0;$raw=null;
    for($try=1;$try<=$attempts;$try++){
        [$status,$raw]=fh12_dav_once($method,$path,$headers,$body,$limit,$timeout);
        if($status!==0&&$status<500)return [$status,$raw];
        if($status>=500&&$try>=2)return [$status,$raw];
        if($try<$attempts)usleep(400000);
    }
    return [$status,$raw];
}
/**
 * Single WebDAV round trip over curl.
 * The previous transport (file_get_contents + stream context) produced false "no answer"
 * statuses under load; curl was measured at 0.09-0.22 s for the same request.
 * Returns [http status, body|null]; status 0 means "no HTTP response at all".
 */
function fh12_dav_once(string $method,string $path,array $headers,?string $body,int $limit,int $timeout=35):array {
    if(!function_exists('curl_init'))throw new RuntimeException('dav_transport_unavailable');
    $ch=curl_init(fh12_host().fh12_encode_path($path));
    if(!$ch)throw new RuntimeException('dav_transport_unavailable');
    $raw='';$tooLarge=false;
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
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw,&$tooLarge,$limit):int {
            if(strlen($raw)+strlen($chunk)>$limit){$tooLarge=true;return 0;}
            $raw.=$chunk;return strlen($chunk);
        },
    ];
    if($body!==null)$options[CURLOPT_POSTFIELDS]=$body;
    if(!curl_setopt_array($ch,$options)){curl_close($ch);throw new RuntimeException('dav_transport_unavailable');}
    $ok=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($tooLarge)throw new RuntimeException('dav_response_too_large');
    if($ok===false)return [$status===0?0:$status,null];
    if($status===0)return [0,null];
    return [$status,$raw];
}
/** Streaming, create-only WebDAV PUT. Never hold the patient's entire upload in PHP memory. */
function fh12_put_stream(string $path,string $tempFile,int $size):int {
    if(!fh12_write_on())throw new RuntimeException('prod_writes_disabled');
    if($size<1||$size>FH12_MAX_UPLOAD)throw new RuntimeException('upload_too_large');
    if(!is_uploaded_file($tempFile)||!is_readable($tempFile)||is_link($tempFile)||filesize($tempFile)!==$size)
        throw new RuntimeException('invalid_upload');
    if(!fh12_remote_scope_ok('PUT',$path,['If-None-Match: *'],''))
        throw new RuntimeException('dav_scope_denied');
    if(!function_exists('curl_init'))throw new RuntimeException('upload_streaming_unavailable');
    $u=fh_env_required('FILEHUB_DAV_USER');$p=fh_env_required('FILEHUB_DAV_PASS');
    $stream=@fopen($tempFile,'rb');if(!$stream)throw new RuntimeException('upload_temp_unavailable');
    $ch=curl_init(fh12_host().fh12_encode_path($path));
    if(!$ch){fclose($stream);throw new RuntimeException('upload_streaming_unavailable');}
    $response='';
    try {
        $options=[
            CURLOPT_UPLOAD=>true,
            CURLOPT_CUSTOMREQUEST=>'PUT',
            CURLOPT_INFILE=>$stream,
            CURLOPT_INFILESIZE=>$size,
            CURLOPT_HTTPHEADER=>['If-None-Match: *','Content-Type: application/octet-stream','Expect:'],
            CURLOPT_USERPWD=>$u.':'.$p,
            CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_USERAGENT=>'FileHub-FH12/stream-50MiB',
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>240,
            CURLOPT_LOW_SPEED_LIMIT=>1024,
            CURLOPT_LOW_SPEED_TIME=>60,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int {
                if(strlen($response)+strlen($chunk)>4096)return 0;
                $response.=$chunk;return strlen($chunk);
            },
        ];
        if(!curl_setopt_array($ch,$options))throw new RuntimeException('upload_streaming_unavailable');
        $ok=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        // Any broken connection is ambiguous. Verify remotely; NEVER retry the PUT.
        return $ok===false?0:$status;
    } finally {curl_close($ch);fclose($stream);}
}

function fh12_inventory(string $xml,string $dir):array {
    if(strlen($xml)>1048576||strlen($xml)<20||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new RuntimeException('dav_xml_rejected');
    $old=libxml_use_internal_errors(true);
    try {
        $doc=new DOMDocument();
        if(!$doc->loadXML($xml,LIBXML_NONET)||$doc->documentElement?->localName!=='multistatus'||$doc->documentElement?->namespaceURI!=='DAV:')throw new RuntimeException('dav_xml_invalid');
        $xp=new DOMXPath($doc);$xp->registerNamespace('d','DAV:');
        $nodes=$xp->query('/d:multistatus/d:response');
        if(!$nodes||$nodes->length<1)throw new RuntimeException('dav_xml_empty');
        $items=[];$rootFound=false;
        foreach($nodes as $node){
            $hrefs=$xp->query('d:href',$node);
            if(!$hrefs||$hrefs->length!==1)throw new RuntimeException('dav_href_invalid');
            $href=trim($hrefs->item(0)->textContent);
            if($href===''||str_contains($href,'?')||str_contains($href,'#')||str_contains($href,'\\'))throw new RuntimeException('dav_href_invalid');
            $urlHost=parse_url($href,PHP_URL_HOST);
            if($urlHost!==null){
                // The allowed origin is the resolved WebDAV endpoint (production host or, in the
                // loopback test seam, the local mock) - not a hardcoded literal: a hardcoded host
                // made every absolute href of a non-production endpoint look foreign.
                $hostParts=parse_url(fh12_host());
                $allowedHost=(string)($hostParts['host']??'');
                $allowedScheme=(string)($hostParts['scheme']??'https');
                $allowedPort=$hostParts['port']??null;
                $hrefPort=parse_url($href,PHP_URL_PORT);
                if($allowedHost===''
                    ||$urlHost!==$allowedHost
                    ||parse_url($href,PHP_URL_SCHEME)!==$allowedScheme
                    ||($allowedPort!==null&&$hrefPort!==$allowedPort))
                    throw new RuntimeException('dav_foreign_host');
            }
            $encoded=parse_url($href,PHP_URL_PATH);if(!is_string($encoded))throw new RuntimeException('dav_href_invalid');
            $path=rawurldecode($encoded);
            if(str_contains($path,'//')||str_contains($path,'\\')||str_contains($path,"\0")||preg_match('~/(?:\.|\.\.)/(?:|$)~',$path))throw new RuntimeException('dav_href_traversal');
            $norm=rtrim($path,'/');
            if($norm===$dir){
                if($rootFound)throw new RuntimeException('dav_root_duplicate');
                $rootProps=$xp->query('d:propstat[contains(d:status," 200 ")]/d:prop',$node);
                if(!$rootProps||!$rootProps->length||!$xp->evaluate('boolean(d:resourcetype/d:collection)',$rootProps->item(0)))throw new RuntimeException('dav_root_not_directory');
                $rootFound=true;continue;
            }
            if(!str_starts_with($norm,$dir.'/'))throw new RuntimeException('dav_outside_directory');
            $name=substr($norm,strlen($dir)+1);
            if($name===''||str_contains($name,'/')||array_key_exists($name,$items))throw new RuntimeException('dav_duplicate_or_nested');
            $props=$xp->query('d:propstat[contains(d:status," 200 ")]/d:prop',$node);
            if(!$props||!$props->length)throw new RuntimeException('dav_prop_missing');$p=$props->item(0);
            $items[$name]=['name'=>$name,'collection'=>(bool)$xp->evaluate('boolean(d:resourcetype/d:collection)',$p),
               'size'=>ctype_digit($len=$xp->evaluate('string(d:getcontentlength)',$p))?(int)$len:null,
               'contentType'=>$xp->evaluate('string(d:getcontenttype)',$p),
               'lastmod'=>$xp->evaluate('string(d:getlastmodified)',$p)];
        }
        if(!$rootFound)throw new RuntimeException('dav_root_missing');
        return $items;
    } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
}
function fh12_list_dir(string $dir,bool $allowMissing=true,?int $timeout=null,?int $attempts=null):array {
    [$status,$xml]=fh12_dav('PROPFIND',$dir,['Depth: 1','Content-Type: application/xml'],FH12_XML,1048576,$timeout,$attempts);
    if($status===404&&$allowMissing)return [];
    if($status!==207||!is_string($xml))throw new RuntimeException('dav_list_unconfirmed_'.$status);
    return fh12_inventory($xml,$dir);
}
function fh12_read(string $path,int $max=FH12_MAX_UPLOAD):string {
    [$status,$body]=fh12_dav('GET',$path,[],null,$max);
    if($status!==200||!is_string($body))throw new RuntimeException('dav_read_unconfirmed_'.$status);
    return $body;
}
function fh12_collection_exists(string $path):bool {
    [$status,$xml]=fh12_dav('PROPFIND',$path,['Depth: 0','Content-Type: application/xml'],FH12_XML,4096);
    if($status===404)return false;
    if($status!==207||!is_string($xml))throw new RuntimeException('directory_probe_failed_'.$status);
    fh12_inventory($xml,$path); // Must have the exact requested root, not a different resource.
    return true;
}
function fh12_mkdir(string $path):void {
    [$status,]=fh12_dav('MKCOL',$path,[],null,1024);
    if(!in_array($status,[0,200,201,405],true))throw new RuntimeException('mkdir_unconfirmed_'.$status);
    if(!fh12_collection_exists($path))throw new RuntimeException('mkdir_unverified_'.$status);
}
function fh12_ensure_dir(string $lead,string $cat):void {
    $lead=fh12_lead($lead);$cat=fh12_cat($cat);
    if(!fh12_collection_exists('/CRM'))throw new RuntimeException('crm_root_missing');
    if(!fh12_collection_exists('/CRM/'.$lead))fh12_mkdir('/CRM/'.$lead);
    $path=fh12_dir($lead,$cat);
    if(!fh12_collection_exists($path))fh12_mkdir($path);
}
function fh12_remote_hash(string $path,int $timeout=180):array {
    $u=fh_env_required('FILEHUB_DAV_USER');$p=fh_env_required('FILEHUB_DAV_PASS');
    if(!function_exists('curl_init'))throw new RuntimeException('remote_hash_unavailable');
    $hash=hash_init('sha256');$len=0;$tooLarge=false;
    $ch=curl_init(fh12_host().fh12_encode_path($path));
    if(!$ch)throw new RuntimeException('remote_hash_unavailable');
    $options=[
        CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode($u.':'.$p),'User-Agent: FileHub-FH12/1.0'],
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>min(15,$timeout),
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_LOW_SPEED_LIMIT=>1024,
        CURLOPT_LOW_SPEED_TIME=>60,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
        CURLOPT_NOSIGNAL=>true,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use($hash,&$len,&$tooLarge):int {
            $len+=strlen($chunk);
            if($len>1073741824){$tooLarge=true;return 0;}
            hash_update($hash,$chunk);return strlen($chunk);
        },
    ];
    if(!curl_setopt_array($ch,$options)){curl_close($ch);throw new RuntimeException('remote_hash_unavailable');}
    $ok=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($tooLarge)throw new RuntimeException('remote_hash_too_large');
    if($ok===false||$status!==200)throw new RuntimeException('remote_hash_unconfirmed_'.$status);
    if($len<1)throw new RuntimeException('remote_hash_empty');
    return [hash_final($hash),$len];
}
function fh12_state_root(string $lead):string {
    $base=fh12_state_base();
    if(!is_dir($base)||is_link($base))throw new RuntimeException('prod_audit_unavailable');
    $dir=$base.'/'.hash('sha256',fh12_lead($lead));
    if(!is_dir($dir) && !@mkdir($dir,0700) && !is_dir($dir))throw new RuntimeException('prod_audit_unavailable');
    if(is_link($dir))throw new RuntimeException('prod_state_symlink');
    foreach(['.lock','audit.jsonl'] as $file){
        if(is_link($dir.'/'.$file))throw new RuntimeException('prod_state_symlink');
        if(!file_exists($dir.'/'.$file)){
            $fd=@fopen($dir.'/'.$file,'x');
            if($fd){fclose($fd);chmod($dir.'/'.$file,0600);}
            elseif(!is_file($dir.'/'.$file))throw new RuntimeException('prod_state_unavailable');
        }
    }
    return $dir;
}
/** Pending marker of one category. One lead may have independent operations in different categories. */
function fh12_pending_file(string $dir,string $cat):string { return $dir.'/pending.'.$cat.'.json'; }
/** Legacy lead-level marker written before the hotfix. */
function fh12_pending_legacy(string $dir):string { return $dir.'/pending.json'; }
/**
 * Category key of a pending marker, derived from the marker itself.
 * The destination path always encodes the category directory, so the marker is the only
 * authoritative source - the category of the request that happens to resolve it is not.
 * '' when it cannot be established.
 */
function fh12_marker_category(array $pj):string {
    $dest=is_string($pj['destination']??null)?$pj['destination']:'';
    if($dest!==''&&preg_match('~^/CRM/[1-9][0-9]{0,17}/([^/]+)~',$dest,$m)===1){
        $key=array_search($m[1],FH12_DIRS,true);
        if(is_string($key))return $key;
    }
    $cat=is_string($pj['category']??null)?$pj['category']:'';
    return isset(FH12_DIRS[$cat])?$cat:'';
}
/** Category the pending marker belongs to; '' when it cannot be established (=> block conservatively). */
function fh12_pending_category(string $pendingFile):string {
    $raw=@file_get_contents($pendingFile);
    if(!is_string($raw))return '';
    $pj=json_decode($raw,true);
    if(!is_array($pj))return '';
    return fh12_marker_category($pj);
}
/* ================================ PENDING-ATOMIC ================================
 * The pending marker of an operation is the lock of its category: while it exists, the
 * category is blocked. The previous implementation published the marker with
 * fopen($file,'x') and then wrote the JSON with an unchecked fwrite(). That made an
 * EMPTY or TRUNCATED marker visible to every other request, and such a marker could not
 * be repaired by the normal resolver (it returns 'blocked' for unparsable content), so a
 * single failed local write blocked the category of that lead for good.
 *
 * The marker is now published atomically:
 *   PREPARE -> WRITE_ALL -> FLUSH/FSYNC -> VERIFY -> PUBLISH_NO_CLOBBER -> CLEANUP
 * The document is written into a staged file, verified by reading it back, and only then
 * published with link(), which is an atomic create-if-absent and never overwrites an
 * existing marker. The staged file lives in the SAME directory as the live marker, so it
 * is on the same mount instance and link() cannot fail with EXDEV.
 */

/** Grammar of a staged (not yet published) pending file. */
const FH12_PENDING_TMP_RE='/^\.fh12-stage-[0-9a-f]{32}\.tmp$/D';
/** A staged file older than this is considered orphaned and may be swept. */
const FH12_PENDING_TMP_MAX_AGE=3600;
/** Upper bound of the files removed by one sweep. */
const FH12_PENDING_TMP_SWEEP_MAX=8;

/**
 * Name of the staged file. The name is not "pending*.json", so no collector and no
 * reconciliation tool can mistake it for an active marker.
 */
function fh12_pending_tmp(string $dir):string {
    return $dir.'/.fh12-stage-'.bin2hex(random_bytes(16)).'.tmp';
}

/**
 * Remove the staged file of THIS request only.
 * The identity (device + inode) captured at creation is verified first and the file must
 * be a regular file (no symlink, no directory), so a file that was replaced in the
 * meantime is never removed. After link() the staged name and the published marker are
 * two links to the same inode: unlinking the staged name leaves the published marker.
 */
function fh12_pending_drop(string $path,?array $own):bool {
    if(!is_array($own))return false;
    clearstatcache(true,$path);
    $st=@lstat($path);
    if(!is_array($st))return false;
    if(($st['mode'] & 0170000)!==0100000)return false;
    if(($st['dev']??-1)!==($own['dev']??-2)||($st['ino']??-1)!==($own['ino']??-2))return false;
    return @unlink($path);
}

/**
 * Sweep orphaned staged files of one lead: only the exact name grammar, only regular
 * files, only older than $maxAge, at most $max per call. Nothing else is ever touched.
 */
function fh12_pending_sweep(string $dir,int $maxAge=FH12_PENDING_TMP_MAX_AGE,int $max=FH12_PENDING_TMP_SWEEP_MAX):int {
    $removed=0;$now=time();
    $entries=@scandir($dir);
    if(!is_array($entries))return 0;
    foreach($entries as $name){
        if($removed>=$max)break;
        if(preg_match(FH12_PENDING_TMP_RE,$name)!==1)continue;
        $path=$dir.'/'.$name;
        $st=@lstat($path);
        if(!is_array($st)||($st['mode'] & 0170000)!==0100000)continue;
        if($now-(int)$st['mtime']<$maxAge)continue;
        if(@unlink($path))$removed++;
    }
    return $removed;
}

/**
 * Schema of a pending marker. The fields are unchanged, so a marker written by the
 * previous version stays valid and readable, and a marker of the new version is
 * indistinguishable for the resolver and for the operator tooling.
 */
function fh12_pending_valid(array $pj):bool {
    foreach(['id','action','destination','expectedSha256','time'] as $k)
        if(!is_string($pj[$k]??null)||$pj[$k]==='')return false;
    // The source may be empty (an upload has no remote source object), but it must be a string.
    if(!is_string($pj['source']??null))return false;
    if(!in_array($pj['action'],['upload','rename','trash','restore'],true))return false;
    if(!is_int($pj['actor']??null))return false;
    if(!preg_match('/^[0-9a-f]{16}$/D',$pj['id']))return false;
    if(!preg_match('/^[0-9a-f]{64}$/D',$pj['expectedSha256']))return false;
    // The destination always names the remote object. The source is empty for an upload
    // (there is no remote object before it) and names the remote object for the other
    // actions. This is the format the previous version wrote, so old markers stay valid.
    if(!str_starts_with($pj['destination'],'/CRM/'))return false;
    if($pj['action']==='upload'){ if($pj['source']!=='')return false; }
    elseif(!str_starts_with($pj['source'],'/CRM/'))return false;
    return true;
}

/**
 * Publish the pending marker of one category atomically.
 *
 * @return array{0:string,1:string} [published|exists|failed, code]
 *   published - the marker is visible and complete
 *   exists    - another operation holds the marker; it is NOT overwritten
 *   failed    - nothing was published; the category is not blocked
 */
function fh12_pending_publish(string $dir,string $cat,array $pending,array $hooks=[]):array {
    $target=fh12_pending_file($dir,$cat);
    try{$json=json_encode($pending,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    catch(Throwable $e){return ['failed','pending_write_failed'];}
    $tmp=fh12_pending_tmp($dir);
    $fd=@fopen($tmp,'x');
    if($fd===false)return ['failed','pending_create_failed'];
    $own=@fstat($fd);
    $len=strlen($json);$written=0;$fault='';
    while($written<$len){
        $bytes=substr($json,$written);$reported=null;
        // Test seam: a hook may write fewer bytes than it reports (a torn write).
        if(isset($hooks['write'])&&is_callable($hooks['write']))$bytes=(string)$hooks['write']($bytes,$reported);
        $n=@fwrite($fd,$bytes);
        $count=($reported===null)?$n:$reported;
        if($count===false||$count===0){$fault='pending_write_failed';break;}
        $written+=$count;
    }
    if($fault===''&&!@fflush($fd))$fault='pending_write_failed';
    if($fault===''&&function_exists('fsync')&&!@fsync($fd))$fault='pending_write_failed';
    @fclose($fd);
    if($fault===''){
        // Test seam: a hook may damage the staged file right before the verification.
        if(isset($hooks['corrupt'])&&is_callable($hooks['corrupt']))$hooks['corrupt']($tmp);
        // VERIFY: the bytes on disk must be exactly the intended document and the document
        // must satisfy the schema. This is what makes a truncated write unpublishable.
        $back=@file_get_contents($tmp);
        if(!is_string($back)||$back!==$json)$fault='pending_write_failed';
        else{
            $dec=json_decode($back,true);
            if(!is_array($dec)||!fh12_pending_valid($dec))$fault='pending_write_failed';
        }
    }
    if($fault!==''){fh12_pending_drop($tmp,$own);return ['failed',$fault];}
    @chmod($tmp,0600);
    // Test seam: the point between the verified staged file and its publication. A crash
    // here leaves an orphaned staged file, which the sweep has to remove later.
    if(isset($hooks['after_stage'])&&is_callable($hooks['after_stage']))$hooks['after_stage']();
    // PUBLISH_NO_CLOBBER: link() fails with EEXIST and never replaces an existing marker.
    if(@link($tmp,$target)===false){
        $exists=@file_exists($target);
        fh12_pending_drop($tmp,$own);
        return $exists?['exists','pending_create_failed']:['failed','pending_write_failed'];
    }
    // Test seam: the point between the successful publication and the cleanup of the
    // staged name. A crash here leaves the published marker and an orphaned staged name
    // that are two links to the SAME inode.
    if(isset($hooks['after_link'])&&is_callable($hooks['after_link']))$hooks['after_link']();
    // CLEANUP: only the staged name is removed; the published marker stays.
    fh12_pending_drop($tmp,$own);
    return ['published',''];
}

/** Is this marker OUR operation? The operation id inside the document is the only proof. */
function fh12_pending_is_ours(string $file,string $id):bool {
    if($id==='')return false;
    $raw=@file_get_contents($file);
    if(!is_string($raw))return false;
    $pj=json_decode($raw,true);
    if(!is_array($pj))return false;
    return ($pj['id']??'')===$id;
}

/**
 * Integrity of the WHOLE audit chain.
 *
 * The chain starts at the genesis (seq=1, prev=64 zeros) and continues through the
 * archived segments (oldest first) into the active one.
 *
 * The previous implementation took the tail of the MOST RECENT archive as the starting
 * point and then walked the segments again from the OLDEST one. After the first rotation
 * the first record of the oldest archive did not match that seq/prev, so a perfectly
 * intact chain was reported as broken - and a broken chain means that a marker is never
 * released and a lead stays blocked.
 *
 * The archives are only renamed by the rotation and never deleted, so the oldest existing
 * segment always starts at the genesis. A missing or unreadable segment is a broken chain.
 */
function fh12_audit_chain_ok(string $dir):bool {
    try{
        $prev=str_repeat('0',64);$seq=1;
        foreach(fh12_audit_segments($dir) as $file){
            if(!is_file($file))return false;      // a missing segment breaks the chain
            $raw=@file_get_contents($file);
            if(!is_string($raw))return false;     // an unreadable segment breaks the chain
            foreach(explode("\n",rtrim($raw,"\n")) as $line){
                if($line==='')continue;
                $row=json_decode($line,true);
                if(!is_array($row)||($row['seq']??null)!==$seq||($row['prev']??null)!==$prev||!is_array($row['event']??null))return false;
                $payload=json_encode($row['event'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $h=hash('sha256',$prev."\n".$payload);
                if(!hash_equals($h,(string)($row['hash']??'')))return false;
                $prev=$h;$seq++;
            }
        }
        return true;
    }catch(Throwable $e){return false;}
}

/**
 * A published marker without an audit intent proves that the remote operation never
 * started: the intent is written strictly BEFORE the WebDAV call (fh12_mutate) - and it
 * was so in the previous version as well. Such a marker is released here instead of
 * blocking the category for good. The conclusion is drawn only when the audit chain is
 * intact, because an unreadable chain must not be read as "there is no intent".
 *
 * The terminal record is written BEFORE the marker is removed. If it does not get into
 * the chain, the marker stays: releasing it would erase the only trace of the operation.
 *
 * Returns true when the marker was released.
 */
function fh12_cancel_prepared(string $dir,string $pendingFile,string $id,string $action,string $lead,string $cat,int $actor,string $name):bool {
    if(!fh12_pending_is_ours($pendingFile,$id))return false;
    if(fh12_phase_exists($dir,$id,'intent'))return false;
    if(!fh12_audit_chain_ok($dir))return false;
    if(!fh12_audit_terminal($dir,fh12_result($action,$lead,$cat,$actor,$id,$name,'cancelled','prepared_without_intent')))return false;
    return @unlink($pendingFile);
}
/* ============================== /PENDING-ATOMIC ============================== */

/**
 * Files of the audit chain of one lead, oldest segment first.
 * audit.jsonl   - active segment (appended to)
 * audit.N.jsonl - archived segments, N=1 is the most recent archive
 * The chain is continuous across the segments: seq and prev continue without a break.
 */
function fh12_audit_segments(string $dir):array {
    $archives=[];
    foreach(glob($dir.'/audit.*.jsonl')?:[] as $p){
        if(preg_match('/audit\.(\d+)\.jsonl$/',$p,$m)===1)$archives[(int)$m[1]]=$p;
    }
    ksort($archives);                       // 1 = the most recent archive
    $chain=array_reverse(array_values($archives));   // oldest segment first
    $chain[]=$dir.'/audit.jsonl';            // the active segment is always the newest
    return $chain;
}
/**
 * Tail of the newest archived segment: [hash of its last record, its seq].
 * The newest archive is the one with the SMALLEST number (audit.1 is the most recent, a larger
 * number is older). The file with the smallest number that actually exists is used: a missing
 * audit.1.jsonl must not reset the chain base and freeze the lead with prod_audit_corrupt.
 */
function fh12_audit_archive_tail(string $dir):array {
    $p='';$best=null;
    foreach(glob($dir.'/audit.*.jsonl')?:[] as $f){
        if(preg_match('/audit\.(\d+)\.jsonl$/',$f,$m)===1){
            $n=(int)$m[1];
            if($best===null||$n<$best){$best=$n;$p=$f;}
        }
    }
    if($p===''||!is_file($p))return [str_repeat('0',64),0];
    $raw=@file_get_contents($p);
    if(!is_string($raw)||$raw==='')return [str_repeat('0',64),0];
    $lines=explode("\n",rtrim($raw,"\n"));
    for($i=count($lines)-1;$i>=0;$i--){
        if($lines[$i]==='')continue;
        $row=json_decode($lines[$i],true);
        if(!is_array($row)||!isset($row['hash'],$row['seq']))return [str_repeat('0',64),0];
        return [(string)$row['hash'],(int)$row['seq']];
    }
    return [str_repeat('0',64),0];
}
/**
 * Undo the renames of a failed rotation. The list is undone in reverse order, so a freed name
 * is never occupied again while the previous step is being restored.
 * Returns true when every segment is back in place.
 */
function fh12_audit_rotate_rollback(string $dir,array $moved):bool {
    $ok=true;
    foreach(array_reverse($moved) as $m){
        if(!is_file($m[0]))continue;
        if(file_exists($m[1])){$ok=false;continue;}   // the original name is occupied - the segment stays shifted
        if(!@rename($m[0],$m[1]))$ok=false;
    }
    return $ok;
}
/**
 * Move the active segment into the archive and start a new one.
 * The chain continues: the first record of the new segment points at the tail of the archived one.
 *
 * Every step is verified. A failure rolls the whole rotation back and is REPORTED, but never
 * thrown: a failed rotation must not make the lead unusable. The record is written into the
 * current segment instead, the chain stays continuous, and the anomaly is reported separately
 * (log + a distinct code in the response detail). Throwing here was worse than the silent
 * failure it replaced: the pending marker can only be released by a record in this very chain,
 * so an exception froze the lead with no way out.
 *
 * Returns '' on success, otherwise the failure code.
 */
function fh12_audit_rotate(string $dir):string {
    $max=0;
    foreach(glob($dir.'/audit.*.jsonl')?:[] as $p)
        if(preg_match('/audit\.(\d+)\.jsonl$/',$p,$m)===1)$max=max($max,(int)$m[1]);
    $active=$dir.'/audit.jsonl';
    $moved=[];                       // [from, to] of every rename already done
    // The archive number grows with age, so the names are freed in descending order.
    for($n=$max;$n>=1;$n--){
        $src=$dir.'/audit.'.$n.'.jsonl';
        if(!is_file($src))continue;
        $dst=$dir.'/audit.'.($n+1).'.jsonl';
        if(file_exists($dst))return fh12_audit_rotate_failed($dir,$moved,'prod_audit_rotate_conflict');
        if(!@rename($src,$dst))return fh12_audit_rotate_failed($dir,$moved,'prod_audit_rotate_unavailable');
        $moved[]=[$dst,$src];
    }
    // The active segment becomes the most recent archive.
    if(is_file($active)){
        if(file_exists($dir.'/audit.1.jsonl'))return fh12_audit_rotate_failed($dir,$moved,'prod_audit_rotate_conflict');
        if(!@rename($active,$dir.'/audit.1.jsonl'))return fh12_audit_rotate_failed($dir,$moved,'prod_audit_rotate_unavailable');
        $moved[]=[$dir.'/audit.1.jsonl',$active];
    }
    // The new active segment.
    $fp=@fopen($active,'xb');
    if(!$fp)return fh12_audit_rotate_failed($dir,$moved,'prod_audit_rotate_unavailable');
    fclose($fp);@chmod($active,0600);
    return '';
}
/**
 * Undo a failed rotation and name the failure. A failed rollback is the most serious case:
 * the segments stay shifted, the chain has to be repaired by hand - it is reported separately.
 */
function fh12_audit_rotate_failed(string $dir,array $moved,string $code):string {
    return fh12_audit_rotate_rollback($dir,$moved)?$code:'prod_audit_rollback_failed';
}
/**
 * Is there a record with this operation id and phase in the chain?
 * The active segment is checked first - an operation that is being resolved is almost always
 * in it, so the common path does not walk the whole history.
 */
function fh12_phase_exists(string $dir,string $id,string $phase):bool {
    return fh12_phase_time($dir,$id,$phase)>0;
}
/** Time of the record with this operation id and phase; 0 when there is none. */
function fh12_phase_time(string $dir,string $id,string $phase):int {
    $chain=array_reverse(fh12_audit_segments($dir));   // newest segment first
    foreach($chain as $file){
        if(!is_file($file))continue;
        $raw=@file_get_contents($file);
        if(!is_string($raw)||$raw==='')continue;
        $lines=explode("\n",rtrim($raw,"\n"));
        for($i=count($lines)-1;$i>=0;$i--){
            if($lines[$i]==='')continue;
            $row=json_decode($lines[$i],true);
            if(!is_array($row)||!is_array($row['event']??null))continue;
            if(($row['event']['id']??'')!==$id||($row['event']['phase']??'')!==$phase)continue;
            $t=is_string($row['event']['time']??null)?strtotime($row['event']['time']):0;
            return $t>0?$t:1;
        }
    }
    return 0;
}
/**
 * A degraded audit chain is not a reason to freeze the lead: the record is written into the
 * current segment and the anomaly is reported here - in the log and in the response detail.
 */
$GLOBALS['fh12_audit_degraded']=[];
function fh12_audit_degraded(string $code,array $event=[]):void {
    $GLOBALS['fh12_audit_degraded'][$code]=true;
    fh12_log('error',(int)($event['actor']??0),(string)($event['lead']??''),(string)($event['category']??''),
             $code,0,0,(string)($event['id']??''),'');
}
/** Distinct codes of the anomalies of this request, for the response detail. */
function fh12_audit_degraded_codes():array {
    return array_keys($GLOBALS['fh12_audit_degraded']??[]);
}
/**
 * Terminal record of a resolved operation. Its failure must not block the release of the
 * marker: the remote state is already established, and a marker that cannot be released
 * freezes the lead. The missing record is reported and is visible to the checker.
 */
/**
 * Terminal record of an operation.
 *
 * Returns true when the record was REALLY written into the chain.
 *
 * The pending marker can only be released by a record in this very chain. A terminal
 * record that was swallowed (a broken chain, a full disk, a read-only filesystem) must
 * therefore NOT be followed by the removal of the marker: the operation would be
 * released without a trace and the lead would look like an unfinished operation for
 * good. The caller keeps the marker and the category stays blocked - the operator gets
 * the degraded code in the response detail.
 */
function fh12_audit_terminal(string $dir,array $event):bool {
    // Test seam: simulate a terminal record that does not get into the chain.
    $hooks=(array)($GLOBALS['fh12_pending_hooks']??[]);
    if(isset($hooks['audit_terminal_fail'])&&is_callable($hooks['audit_terminal_fail'])
        &&$hooks['audit_terminal_fail']($event)){
        fh12_audit_degraded('prod_audit_terminal_not_written',$event);
        return false;
    }
    try {
        fh12_audit($dir,$event);
        return true;
    } catch(Throwable $e) {
        fh12_audit_degraded($e->getMessage(),$event);
        return false;
    }
}
function fh12_audit(string $dir,array $event):void {
    $file=$dir.'/audit.jsonl';$raw=@file_get_contents($file);
    if(!is_string($raw))throw new RuntimeException('prod_audit_unavailable');
    if(strlen($raw)>FH12_AUDIT_FILE_MAX)throw new RuntimeException('prod_audit_corrupt');
    // A file written by another tool may lack the final newline. The record itself is still
    // valid (the per-record validation below proves it), so the file is normalized on append
    // instead of being refused - otherwise one missing byte would freeze the whole lead.
    $needsSeparator=($raw!==''&&!str_ends_with($raw,"\n"));
    // The chain continues from the archived segment; seq and prev must continue without a break.
    [$basePrev,$baseSeq]=fh12_audit_archive_tail($dir);
    $prev=$basePrev;$seq=$baseSeq+1;
    if($raw!=='')foreach(explode("\n",rtrim($raw,"\n")) as $line){
        $row=json_decode($line,true);if(!is_array($row)||($row['seq']??null)!==$seq||($row['prev']??null)!==$prev||!is_array($row['event']??null))throw new RuntimeException('prod_audit_corrupt');
        $payload=json_encode($row['event'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $expect=hash('sha256',$prev."\n".$payload);
        if(!hash_equals($expect,(string)($row['hash']??'')))throw new RuntimeException('prod_audit_corrupt');
        $prev=$expect;$seq++;
    }
    $payload=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $line=json_encode(['seq'=>$seq,'prev'=>$prev,'event'=>$event,'hash'=>hash('sha256',$prev."\n".$payload)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    // The active segment must not grow without bound: it is archived and a new one is started.
    // The chain continues seamlessly - the new segment starts from the tail of the archived one.
    if($raw!==''&&strlen($raw)+strlen($line)>FH12_AUDIT_SEGMENT_MAX){
        $rot=fh12_audit_rotate($dir);
        if($rot!==''){
            // The rotation failed. The lead must stay usable: the record goes into the current
            // segment, so the chain stays continuous, and the anomaly is reported - a distinct
            // code in the response detail and a log entry. An exception here used to freeze the
            // lead for good: the pending marker can only be released by a record in this chain.
            fh12_audit_degraded($rot,$event);
        } else {
            $raw='';$needsSeparator=false;
        }
    }
    // Запись цепочки делается под исключительной блокировкой файла: два
    // параллельных append'а посчитали бы один и тот же seq и сломали цепочку.
    // Ожидание ограничено: воркер FPM не должен зависать в ожидании блокировки.
    $fp=@fopen($file,'ab');if(!$fp)throw new RuntimeException('prod_audit_unavailable');
    $locked=false;$deadline=hrtime(true)+2000000000;
    do {
        if(@flock($fp,LOCK_EX|LOCK_NB)){$locked=true;break;}
        if(hrtime(true)>=$deadline)break;
        usleep(20000);
    } while(true);
    if(!$locked){@fclose($fp);throw new RuntimeException('prod_audit_unavailable');}
    // Размер файла ДО записи: при короткой записи (ENOSPC/EIO) именно на неё
    // приходится откатываться, иначе в цепочке остаётся обрезанная строка.
    $st=@fstat($fp);$before=(is_array($st)&&isset($st['size']))?(int)$st['size']:strlen($raw);
    $ok=true;$written=0;
    if($needsSeparator){
        $n=@fwrite($fp,"\n");$ok=($n===1);$written+=($n===false?0:$n);
        if($ok)$ok=@fflush($fp);
    }
    if($ok){
        $n=@fwrite($fp,$line);$ok=($n===strlen($line));$written+=($n===false?0:$n);
        if($ok)$ok=@fflush($fp);
    }
    if($ok&&function_exists('fsync'))$ok=@fsync($fp);
    if(!$ok){
        // Откат: в файле ровно наша (обрезанная) запись — убираем её целиком.
        // Без отката обрезанная строка навсегда ломает цепочку лида:
        // fh12_audit_chain_ok() -> false, fh12_resolve_pending() -> 'blocked',
        // все мутации по лиду падают prod_audit_corrupt без автовосстановления.
        $st2=@fstat($fp);$now=(is_array($st2)&&isset($st2['size']))?(int)$st2['size']:-1;
        if($now>=0&&$now===$before+$written){
            @ftruncate($fp,$before);
            @fflush($fp);
        }
        if($locked)@flock($fp,LOCK_UN);
        @fclose($fp);
        throw new RuntimeException('prod_audit_unavailable');
    }
    if($locked)@flock($fp,LOCK_UN);
    fclose($fp);
}
function fh12_result(string $action,string $lead,string $cat,int $actor,string $id,string $name,string $phase,string $status=''):array {
    return ['time'=>gmdate('c'),'actor'=>$actor,'action'=>$action,'lead'=>fh12_hash($lead),'category'=>$cat,'name'=>fh12_hash($name),
      'id'=>$id,'phase'=>$phase,'reason'=>substr($status,0,60)];
}
function fh12_protect_name(string $raw):string {
    $raw=fh12_name($raw);$ext=pathinfo($raw,PATHINFO_EXTENSION);
    $stem=$ext!==''?substr($raw,0,-strlen($ext)-1):$raw;
    $suffix='__'.bin2hex(random_bytes(6));
    $maxStem=220-strlen($suffix)-($ext!==''?strlen($ext)+1:0);
    if($maxStem<1)throw new RuntimeException('invalid_filename');
    // Do not cut a multibyte UTF-8 character in half.
    while(strlen($stem)>$maxStem){$stem=preg_replace('/.$/us','',$stem);if(!is_string($stem)||$stem==='')throw new RuntimeException('invalid_filename');}
    return fh12_name($stem.$suffix.($ext!==''?'.'.$ext:''));
}
/** @return resource */
function fh12_lock_lead(string $dir,int $waitMs=FH12_WRITE_LOCK_WAIT_MS) {
    $lock=@fopen($dir.'/.lock','r+');
    if(!$lock)throw new RuntimeException('prod_lock_failed');
    $deadline=hrtime(true)+$waitMs*1000000;
    do {
        $wouldBlock=0;
        if(@flock($lock,LOCK_EX|LOCK_NB,$wouldBlock))return $lock;
        if(!$wouldBlock)break;
        $remaining=$deadline-hrtime(true);
        if($remaining<1000)break;
        usleep((int)min(25000,intdiv($remaining,1000)));
    } while(hrtime(true)<$deadline);
    fclose($lock);
    throw new RuntimeException($wouldBlock?'write_busy':'prod_lock_failed');
}

function fh12_mutate(string $action,string $lead,string $cat,string $name,string $newName,string $body,int $actor,?string $uploadTemp=null,int $uploadSize=0):array {
    if(!fh12_write_on())throw new RuntimeException('prod_writes_disabled');
    $dir=fh12_state_root($lead);$lock=fh12_lock_lead($dir);
    $pendingPath='';$opId='';$expectedHash='';$dest='';$dstName=$name;$fileBytes=0;$intentLogged=false;
    try {
        fh12_guard_pending($dir,$lead,$cat,$actor);
        $parent=fh12_list_dir(fh12_dir($lead,$cat));
        $trash=fh12_list_dir(fh12_dir($lead,$cat,true));
        if(isset($parent[FH12_TRASH])&&!$parent[FH12_TRASH]['collection'])throw new RuntimeException('trash_name_conflict');
        $source='';$dest='';
        if($action==='upload') {
            if(isset($parent[$name])||isset($trash[$name]))throw new RuntimeException('filename_conflict');
            if($uploadTemp===null||$uploadSize<1||$uploadSize>FH12_MAX_UPLOAD||!is_uploaded_file($uploadTemp))
                throw new RuntimeException('invalid_upload');
            $dest=fh12_file($lead,$cat,$name);
        } elseif($action==='restore') {
            if(!isset($trash[$name])||$trash[$name]['collection'])throw new RuntimeException('trash_missing');
            if(isset($parent[$name]))throw new RuntimeException('filename_conflict');
            $source=fh12_file($lead,$cat,$name,true);$dest=fh12_file($lead,$cat,$name);

        } else {
            if(!isset($parent[$name])||$parent[$name]['collection'])throw new RuntimeException('file_missing');
            $source=fh12_file($lead,$cat,$name);
            if($action==='rename'){
                $newName=fh12_name($newName);if($name===$newName||isset($parent[$newName])||isset($trash[$newName]))throw new RuntimeException('filename_conflict');
                $dest=fh12_file($lead,$cat,$newName);
            } elseif($action==='trash') {
                if(isset($trash[$name]))throw new RuntimeException('filename_conflict');
                $dest=fh12_file($lead,$cat,$name,true);
            } else throw new RuntimeException('invalid_action');

        }
        if($action==='upload') {
            if(filesize((string)$uploadTemp)!==$uploadSize)throw new RuntimeException('invalid_upload');
            $expectedHash=hash_file('sha256',(string)$uploadTemp);
            if(!is_string($expectedHash))throw new RuntimeException('invalid_upload');
            $fileBytes=$uploadSize;
        } else {[$expectedHash,$fileBytes]=fh12_remote_hash($source);}
        $dstName=$action==='rename'?$newName:$name;
        $id=bin2hex(random_bytes(8));$opId=$id;
        $event=fh12_result($action,$lead,$cat,$actor,$id,$name,'intent');
        // Exact source/destination are stored only while pending, never emitted to stderr.
        $pending=['id'=>$id,'action'=>$action,'source'=>$source,'destination'=>$dest,'expectedSha256'=>$expectedHash,'actor'=>$actor,'time'=>gmdate('c')];
        $pendingPath=fh12_pending_file($dir,$cat);
        // PENDING-ATOMIC: the marker is staged, verified and then published atomically.
        // A partially written marker can never become visible, so a failed local write
        // can no longer block the category with a corrupt file. The error codes are the
        // ones the previous implementation used, so the HTTP contract does not change.
        fh12_pending_sweep($dir);
        // Test seam: fault-injection points. The hooks are empty in production, so the
        // behaviour of the application is not changed by their presence.
        $hooks=(array)($GLOBALS['fh12_pending_hooks']??[]);
        if(isset($hooks['before_publish'])&&is_callable($hooks['before_publish']))$hooks['before_publish']();
        [$pstate,$pcode]=fh12_pending_publish($dir,$cat,$pending,$hooks);
        if($pstate==='published'&&isset($hooks['after_publish'])&&is_callable($hooks['after_publish']))$hooks['after_publish']();
        if($pstate==='exists')throw new RuntimeException('pending_create_failed');
        if($pstate!=='published')throw new RuntimeException($pcode);
        fh12_audit($dir,$event);
        $intentLogged=true;
        if(function_exists('fh_act_prepare'))fh_act_prepare($lead,$actor,$action,$cat,$name,$newName,$id,$dest,$expectedHash);
        // Test seam: the point between the audit intent and the remote operation.
        if(isset($hooks['after_intent'])&&is_callable($hooks['after_intent']))$hooks['after_intent']();
        if($action==='trash'&&!isset($parent[FH12_TRASH])) {
            fh12_mkdir(fh12_dir($lead,$cat,true));
        }
        if($action==='upload')$status=fh12_put_stream($dest,(string)$uploadTemp,$uploadSize);
        else [$status,]=fh12_dav('MOVE',$source,['Destination: '.fh12_host().fh12_encode_path($dest),'Overwrite: F'],null,1024);
        // Test seam: the point right after the remote operation returned.
        if(isset($hooks['after_dav'])&&is_callable($hooks['after_dav']))$hooks['after_dav']();
        // Never blindly retry an ambiguous request. 0 is allowed to proceed to read-only verification.
        if(!in_array($status,[0,200,201,204],true)){
            // A definite upstream refusal means the object was not created: there is nothing to
            // reconcile, so the marker is dropped instead of blocking the lead forever. The chain
            // still gets a terminal record - an 'intent' without a closing phase is exactly the
            // state the reconciliation tooling reports as an unfinished operation.
            if($status>=400&&$status<500&&!in_array($status,[409,412],true)){
                if(!fh12_phase_exists($dir,$id,'cancelled'))fh12_audit($dir,fh12_result($action,$lead,$cat,$actor,$id,$name,'cancelled','upstream_rejected_4xx'));
                @unlink($pendingPath);$pendingPath='';
                throw new RuntimeException('dav_write_rejected_'.$status);
            }
            throw new RuntimeException('dav_write_unconfirmed_'.$status);
        }
        $after=fh12_list_dir(fh12_dir($lead,$cat),false);
        $afterTrash=fh12_list_dir(fh12_dir($lead,$cat,true));
        $inTrash=$action==='trash';
        $target=$inTrash?$afterTrash:$after;
        $before=$action==='restore'?$afterTrash:$after;
        if(!isset($target[$dstName])||$target[$dstName]['collection']||($action!=='upload'&&isset($before[$name])))throw new RuntimeException('dav_effect_ambiguous');
        [$actualHash,$actualBytes]=fh12_remote_hash($dest);
        if(!hash_equals($expectedHash,$actualHash)||$fileBytes!==$actualBytes)throw new RuntimeException('dav_content_mismatch');
        fh12_invalidate_operation_thumbs($lead,$cat,$name,$dstName);
        fh12_audit($dir,fh12_result($action,$lead,$cat,$actor,$id,$name,'verified'));
        if(!@unlink($pendingPath))throw new RuntimeException('pending_cleanup_failed');
        $pendingPath='';
        // Test seam: the point between the release of the pending marker and the local
        // activity commit. A process death here leaves an ORPHANED activity record:
        // the remote operation is done, the pending marker is gone, the local record is
        // still 'intent'. The orphan reconciler of fh_activity_history.php closes it.
        if(isset($hooks['after_unlink'])&&is_callable($hooks['after_unlink']))$hooks['after_unlink']();
        if(function_exists('fh_act_promote_verified')){
            // R2.1: the record is promoted to 'verified' only after the audit chain
            // confirms the operation. A failure here leaves the record open and the
            // event unpublished - an unconfirmed event never becomes a successful one.
            if(!fh_act_promote_verified($lead,$id,$dir))fh12_log('error',$actor,$lead,$cat,'activity_commit_pending',0,0,$id);
        } elseif(function_exists('fh_act_commit')){
            try{fh_act_commit($lead,$id);}
            catch(Throwable){fh12_log('error',$actor,$lead,$cat,'activity_commit_pending',0,0,$id);}
        }
        fh12_log($action,$actor,$lead,$cat,'verified',$status,$fileBytes,$id,$name);
        $out=['ok'=>true,'name'=>$dstName,'action'=>$action,'id'=>$id];
        // A degraded audit chain is reported to the caller with a distinct code: the operation
        // itself succeeded, but the chain of the lead needs the attention of an operator.
        $degraded=fh12_audit_degraded_codes();
        if($degraded)$out['detail']=implode(',',$degraded);
        return $out;
    } catch(Throwable $e) {
        // Self-healing: the operation may have reached Mail.ru even though the confirmation
        // failed. Resolve the marker against the remote state instead of leaving the lead blocked.
        if($pendingPath!==''&&is_file($pendingPath)&&$opId!==''&&$intentLogged){
            $resolved=fh12_resolve_pending($dir,$pendingPath,$lead,$actor,FH12_REQUEST_RESOLVE_BUDGET);
            if($resolved==='committed'){
                // R2.1: the same promotion rule as in the success path - the remote effect
                // is confirmed by the resolver AND the audit chain must agree.
                if(function_exists('fh_act_promote_verified')){
                    try{if(!fh_act_promote_verified($lead,$opId,$dir))fh12_log('error',$actor,$lead,$cat,'activity_recovered_pending',0,0,$opId);}
                    catch(Throwable){fh12_log('error',$actor,$lead,$cat,'activity_recovered_pending',0,0,$opId);}
                } elseif(function_exists('fh_act_commit')){
                    try{fh_act_commit($lead,$opId);}
                    catch(Throwable){fh12_log('error',$actor,$lead,$cat,'activity_recovered_pending',0,0,$opId);}
                }
                fh12_log($action,$actor,$lead,$cat,'verified_recovered',0,$fileBytes,$opId,$name);
                return ['ok'=>true,'name'=>$dstName,'action'=>$action,'id'=>$opId,'recovered'=>true];
            }
            if($resolved==='aborted'){
                // The remote effect is confirmed ABSENT: the operation did not happen.
                // The local activity record is closed accordingly and is never published.
                if($opId!==''&&function_exists('fh_act_close_aborted')){
                    try{fh_act_close_aborted($lead,$opId);}
                    catch(Throwable){fh12_log('error',$actor,$lead,$cat,'activity_recovery_unconfirmed',0,0,$opId);}
                }
                fh12_log($action,$actor,$lead,$cat,'aborted_remote_absent',0,0,$opId,$name);
            }
        } elseif($pendingPath!==''&&$opId!==''&&!$intentLogged&&is_file($pendingPath)){
            // PENDING-ATOMIC: our marker was published, but the audit intent was not
            // written. The WebDAV call happens strictly after the intent, so no remote
            // side effect can exist. The marker is released here - otherwise the category
            // would stay blocked for good after a failed audit write.
            if(fh12_cancel_prepared($dir,$pendingPath,$opId,$action,$lead,$cat,$actor,$name)){
                fh12_log($action,$actor,$lead,$cat,'cancelled_prepared_without_intent',0,0,$opId,$name);
                $pendingPath='';
            }
        }
        fh12_log('error',$actor,$lead,$cat,$e->getMessage(),0,0,'',$name);
        throw $e;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
/**
 * Block the category while an operation of that category is unfinished.
 * A marker older than FH12_PENDING_GRACE is not trusted blindly: it is resolved against the
 * remote state first, so an orphaned marker (process death, container restart) cannot keep a
 * lead blocked forever. Markers of other categories never block this one.
 */
function fh12_guard_pending(string $dir,string $lead,string $cat,int $actor):void {
    $blocker='';
    $legacy=fh12_pending_legacy($dir);
    if(is_file($legacy)||is_link($legacy)){
        $legacyCat=fh12_pending_category($legacy);
        if($legacyCat===''||$legacyCat===$cat)$blocker=$legacy;   // unknown category -> block conservatively
    }
    if($blocker===''){
        $own=fh12_pending_file($dir,$cat);
        if(is_file($own)||is_link($own))$blocker=$own;
    }
    if($blocker==='')return;
    $age=time()-(int)@filemtime($blocker);
    if($age>FH12_PENDING_GRACE){
        // R2.1: the operation id is read BEFORE the resolver, because the resolver
        // releases the marker. Without it the activity record of the recovered operation
        // would stay in the state 'intent' forever and the operation would silently
        // disappear from the activity history.
        $resolvedOpId='';
        $raw=@file_get_contents($blocker);
        if(is_string($raw)){$pj=json_decode($raw,true);if(is_array($pj)&&is_string($pj['id']??null))$resolvedOpId=$pj['id'];}
        $resolved=fh12_resolve_pending($dir,$blocker,$lead,$actor,FH12_REQUEST_RESOLVE_BUDGET);
        if($resolved==='committed'||$resolved==='aborted'){
            if($resolvedOpId!==''&&function_exists('fh_act_promote_verified')){
                // The remote effect is confirmed by the resolver; the record is promoted
                // only if the audit chain agrees as well. Otherwise it stays open.
                try{
                    if($resolved==='committed'){
                        if(!fh_act_promote_verified($lead,$resolvedOpId,$dir))fh12_log('error',$actor,$lead,$cat,'activity_recovery_unconfirmed',0,0,$resolvedOpId);
                    } else {
                        // The remote effect is confirmed ABSENT: the operation did not
                        // happen. The record is closed as 'aborted' and is never published.
                        fh_act_close_aborted($lead,$resolvedOpId);
                    }
                }catch(Throwable){fh12_log('error',$actor,$lead,$cat,'activity_recovery_unconfirmed',0,0,$resolvedOpId);}
            }
            fh12_log('recover',$actor,$lead,$cat,$resolved,0,0,'','');
            return;
        }
    }
    throw new RuntimeException('reconciliation_required');
}
/**
 * Resolve one pending marker against the remote state.
 * committed - the object exists and matches expectedSha256: the operation is completed and
 *             the marker is released, the audit chain gets a "verified" record.
 * aborted   - the object is provably absent: the operation did not happen, the marker is
 *             released, the audit chain gets a "cancelled" record.
 * blocked   - the state cannot be established: the marker is kept, a human must look at it.
 *
 * The category of the record is taken from the marker itself, never from the request that
 * happens to resolve it. Every phase is written at most once per operation id, so repeated
 * resolution attempts cannot grow the audit chain without bound.
 */
function fh12_invalidate_operation_thumbs(string $lead,string $cat,string $sourceName,string $targetName):void {
    if(!function_exists('fh12_thumb_invalidate'))return;
    try {
        fh12_thumb_invalidate($lead,$cat,$sourceName);
        if($targetName!==$sourceName)fh12_thumb_invalidate($lead,$cat,$targetName);
    } catch(Throwable) {}
}

function fh12_resolve_pending(string $dir,string $pendingFile,string $lead,int $actor,int $budget=FH12_RESOLVE_BUDGET,?int $stopAt=null):string {
    $raw=@file_get_contents($pendingFile);
    if(!is_string($raw))return 'blocked';
    $pj=json_decode($raw,true);
    if(!is_array($pj))return 'blocked';
    $id=is_string($pj['id']??null)?$pj['id']:'';
    $action=is_string($pj['action']??null)?$pj['action']:'';
    $source=is_string($pj['source']??null)?$pj['source']:'';
    $dest=is_string($pj['destination']??null)?$pj['destination']:'';
    $expected=is_string($pj['expectedSha256']??null)?$pj['expectedSha256']:'';
    $opActor=is_int($pj['actor']??null)?$pj['actor']:$actor;
    // The category belongs to the operation, not to the request that resolves it.
    $opCat=fh12_marker_category($pj);
    if($opCat==='')$opCat='unknown';
    if($id===''||$dest===''||$expected==='')return 'blocked';
    if(!in_array($action,['upload','rename','trash','restore'],true))return 'blocked';
    if(!fh12_pending_valid($pj)||!str_starts_with($dest,'/CRM/'.$lead.'/')||
       ($source!==''&&!str_starts_with($source,'/CRM/'.$lead.'/')))return 'blocked';
    $name=basename($source!==''?$source:$dest);
    if(!fh12_audit_chain_ok($dir))return 'blocked';
    foreach(['verified'=>'committed','cancelled'=>'aborted'] as $phase=>$outcome){
        if(!fh12_phase_exists($dir,$id,$phase))continue;
        if($outcome==='committed')fh12_invalidate_operation_thumbs($lead,$opCat,$name,basename($dest));
        return @unlink($pendingFile)?$outcome:'blocked';
    }
    // PENDING-ATOMIC: a marker without an audit intent proves that the remote operation
    // was never started (the intent is written before the WebDAV call). Such a marker is
    // released here instead of blocking the category for good - but only when the audit
    // chain is intact, because an unreadable chain must not be read as "no intent".
    if(!fh12_phase_exists($dir,$id,'intent')){
        // The terminal record is the proof that releases the marker. If it does not get
        // into the chain, the marker stays - the operation is not released without a trace.
        if(!fh12_audit_terminal($dir,fh12_result($action,$lead,$opCat,$opActor,$id,$name,'cancelled','prepared_without_intent')))
            return 'blocked';
        return @unlink($pendingFile)?'aborted':'blocked';
    }
    // A full re-verification re-reads the remote object (PROPFIND + a full GET for the hash).
    // After an unresolved attempt the expensive part is repeated at most once per interval, so a
    // user retrying does not pull the same file from the storage on every click. The cheap
    // listing is always done: it proves the absence of the object, and an absent object must
    // release the lead at once instead of after the whole interval.
    //
    // The cheap listing is not free: it is a network round trip on the request path, under the
    // per-lead lock, while the FPM pool has two workers. Inside the interval it therefore gets
    // ONE attempt with a short timeout - there is nothing to retry, the full verification below
    // is where retries matter. The whole resolution has a time budget, so a degraded storage
    // cannot hold a worker for minutes.
    $lastTry=fh12_phase_time($dir,$id,'unconfirmed');
    $skipHash=$budget<FH12_RESOLVE_BUDGET&&$lastTry>0&&(time()-$lastTry)<FH12_RECHECK_INTERVAL;
    $state='unknown';
    $parentDir=dirname($dest);$baseName=basename($dest);
    $deadline=hrtime(true)+$budget*1000000000;
    if($stopAt!==null)$deadline=min($deadline,$stopAt);
    $remaining=static fn():int => (int)floor(($deadline-hrtime(true))/1000000000);
    $attempts=$skipHash?1:FH12_RECOVER_ATTEMPTS;
    for($attempt=1;$attempt<=$attempts;$attempt++){
        $left=$remaining();
        if($left<1)break;
        $tmo=min($skipHash?FH12_PROBE_TIMEOUT:15,$left);
        try {
            $items=fh12_list_dir($parentDir,true,$tmo,1);
            $target=$items[$baseName]??null;
            if($action!=='upload'){
                $sourceDir=dirname($source);
                if($sourceDir===$parentDir)$sourceItems=$items;
                else{
                    if($remaining()<1)break;
                    $sourceItems=fh12_list_dir($sourceDir,true,min(15,$remaining()),1);
                }
                $original=$sourceItems[basename($source)]??null;
                if($original!==null){
                    if($target===null&&!$original['collection']&&$remaining()>0&&!$skipHash){
                        [$originalHash,]=fh12_remote_hash($source,$remaining());
                        if(hash_equals($expected,$originalHash))$state='absent';
                    }
                    break;
                }
                if($target===null)break;
            } elseif($target===null){$state='absent';break;}
            if($target['collection']||$skipHash||$remaining()<1)break;
            [$actual,]=fh12_remote_hash($dest,$remaining());
            $state=hash_equals($expected,$actual)?'match':'mismatch';
            break;
        } catch(Throwable $e) {
            $state='unknown';
        }
        if($attempt<$attempts&&$remaining()>0)usleep(FH12_RECOVER_BACKOFF_MS*1000);
    }
    if($state==='match'){
        fh12_invalidate_operation_thumbs($lead,$opCat,$name,basename($dest));
        if(!fh12_phase_exists($dir,$id,'verified')){
            if(!fh12_audit_terminal($dir,fh12_result($action,$lead,$opCat,$opActor,$id,$name,'verified','recovered_remote_hash_match')))
                return 'blocked';
        }
        return @unlink($pendingFile)?'committed':'blocked';
    }
    if($state==='absent'&&$budget>=FH12_RESOLVE_BUDGET){
        if(!fh12_phase_exists($dir,$id,'cancelled')){
            if(!fh12_audit_terminal($dir,fh12_result($action,$lead,$opCat,$opActor,$id,$name,'cancelled','recovered_remote_object_absent')))
                return 'blocked';
        }
        return @unlink($pendingFile)?'aborted':'blocked';
    }
    // An unresolved marker must not write a new record on every attempt: the guard calls the
    // resolver on every request, so an unbounded write here would grow the audit chain until
    // fh12_audit() refuses it (>4 MiB) - and then neither the app nor the ops tool could ever
    // release the marker again. One 'unconfirmed' record per operation id is enough.
    if($budget===FH12_RESOLVE_BUDGET&&!fh12_phase_exists($dir,$id,'unconfirmed')){
        $reason=$state==='mismatch'?'remote_hash_mismatch':'remote_state_unknown';
        fh12_audit($dir,fh12_result($action,$lead,$opCat,$opActor,$id,$name,'unconfirmed',$reason));
    }
    return 'blocked';
}
function fh12_http_error(Throwable $e,int $uid=0,string $lead='',string $cat=''):never {
    $msg=$e->getMessage();$status=match($msg) {
        'invalid_lead','invalid_category','invalid_filename','invalid_action','invalid_payload','invalid_upload','invalid_name'=>400,
        'filename_conflict','reconciliation_required','trash_name_conflict','write_busy'=>409,
        'file_missing','trash_missing'=>404,
        'upload_too_large'=>413,
        'lead_acl_denied'=>403,
        'lead_acl_mode_invalid'=>503,
        'lead_acl_unavailable'=>503,
        'prod_writes_disabled'=>503,
        default=>502,
    };
    fh12_log('error',$uid,$lead,$cat,$msg);
    $body=['error'=>$msg==='prod_writes_disabled'?$msg:($status===502?'upstream_or_storage_error':$msg)];
    if($msg==='reconciliation_required')
        $body['detail']='Another operation of this category is still being reconciled. The file list is authoritative: check it before uploading again.';
    // An anomaly of the audit chain is reported with its own code: the operator has to see that
    // the chain of the lead is degraded, not a generic storage error.
    if(str_starts_with($msg,'prod_audit_'))$body['detail']=$msg;
    $degraded=fh12_audit_degraded_codes();
    if($degraded&&!isset($body['detail']))$body['detail']=implode(',',$degraded);
    respond($status,$body);
}
function fh12_session():never {
    $jwt=$_SERVER['HTTP_X_AUTH_TOKEN']??'';
    // FIX-1: the missing-token branch produced no log line at all, so a 401 from it was
    // invisible. The HTTP response is unchanged; only a diagnostic event is added.
    if(!is_string($jwt)||$jwt===''){fh12_log('auth',0,'','','denied',0,0,'','','token_missing');respond(401,['error'=>'amo_token_required']);}
    // FIX-1: the failure reason is classified through a closed whitelist. The original
    // exception text is never logged, so no JWT, JTI, session token or claim value can leak.
    try {
        // R1: extended session record (issued_at/absolute_expires/generation). The R1 module
        // is optional: without it the legacy record is used and the session simply cannot
        // be refreshed (mixed-version state stays safe, no fatal).
        if(function_exists('fh_sess_record_create')){$s=fh_sess_record_create(fh_authenticated_user($jwt));}
        else{$claims=fh_authenticated_user($jwt);$s=fh_test_session_create($claims);}
    }
    catch(Throwable $e){fh12_log('auth',0,'','','denied',0,0,'','',fh_auth_failure_reason($e->getMessage()),fh12_request_corr());respond(401,['error'=>'amo_session_denied']);}
    fh12_log('auth',(int)($s['user_id']??0),'','','session_issued',0,0,'','','',fh12_request_corr());
    $body=['sessionToken'=>$s['token'],'expires'=>$s['expires']];
    // R1: additive field — the hard deadline of the session. Widget 2.8.5/2.8.6 ignore it.
    if(isset($s['absolute_expires']))$body['absoluteExpires']=(int)$s['absolute_expires'];
    // Время сервера: клиент сверяет с ним верхнюю границу абсолютного срока.
    // Без этого часы браузера, отстающие больше чем на десять секунд, отвергали
    // бы СВЕЖУЮ сессию — уже после расходования одноразового JWT amoCRM.
    $body['serverTime']=time();
    respond(200,$body);
}
function fh12_dispatch(string $route,string $method):never {
    if(!fh12_on())respond(503,['error'=>'prod_mode_not_enabled']);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if($origin!=='' && $origin!=='https://chinazub.amocrm.ru')respond(403,['error'=>'origin_denied']);
    if($route==='session'&&$method==='POST')fh12_session();
    // R1: продление действующей сессии. Только X-FH-Session, без X-Auth-Token,
    // без повторного fh_claim_jti(), без WebDAV-операций и без изменения audit/pending.
    if($route==='session/refresh'&&$method==='POST'){
        if(!function_exists('fh_sess_refresh_handle'))respond(404,['error'=>'route_not_available']);
        fh_sess_refresh_handle();
    }
    if($route==='client-events'&&$method==='POST'){
        if(!function_exists('fh_telemetry_handle'))respond(404,['error'=>'route_not_available']);
        fh_telemetry_handle();
    }
    if($route==='fh11')respond(404,['error'=>'synthetic_route_disabled_in_prod']);
    if($route==='ping'){fh12_log('request',0,'','','ping',0,0,'','','',fh12_request_corr());respond(200,['pong'=>true,'mode'=>'prod_mail','writes'=>fh12_write_on()]);}
    if($route==='file'&&$method==='DELETE')respond(403,['error'=>'hard_delete_disabled']);
    // Activity endpoint is read-only; the server derives identity from the FileHub session.

    $signed=($route==='file'||$route==='thumb')&&$method==='GET';$uid=0;$identity=null;
    // R1/R2: correlation id клиента (безопасный, неперсональный идентификатор) попадает
    // в серверный журнал и связывает обычный API-запрос с диагностикой виджета.
    $corr=fh12_request_corr();
    if($signed){
        if(!fh_cap_valid($route,$_GET)){fh12_log('auth',0,'','','media_denied',0,0,'','','',$corr);respond(403,['error'=>'expired_or_invalid_media_link']);}
        $uid=(int)$_GET['uid'];
        $identity=['user_id'=>$uid,'account_id'=>(string)getenv('FILEHUB_AMO_ACCOUNT_ID')];
    }
    else {
        $token=$_SERVER['HTTP_X_FH_SESSION']??'';
        if(!is_string($token)||$token===''){fh12_log('auth',0,'','','session_missing',0,0,'','','',$corr);respond(401,['error'=>'session_required']);}
        try {$identity=fh_test_session_validate($token);$uid=(int)$identity['user_id'];}
        catch(Throwable $e){
            // Различаем «сессию ещё можно продлить» и «сессия мертва».
            // Раньше всё схлопывалось в session_invalid, и клиент не мог понять,
            // что делать: продлить сессию (/session/refresh) или переавторизоваться
            // (новый amoCRM-JWT). Из-за этого клиент считал это «Ошибкой подключения
            // к бэкенду», хотя запрос не уходил в сеть вовсе.
            $reason=$e->getMessage();
            if($reason==='session_expired'){
                $abs=0;$revoked=false;$replaced=false;
                if(function_exists('fh_sess_path')&&function_exists('fh_sess_read')){
                    try{$rec=fh_sess_read(fh_sess_path($token));
                        if(isset($rec['absolute_expires']))$abs=(int)$rec['absolute_expires'];
                        if(!empty($rec['revoked']))$revoked=true;
                        if(!empty($rec['replaced_by']))$replaced=true;
                    }catch(Throwable){}
                }
                // Токен вытеснен ротацией: он не «продлеваемый» — refresh на нём
                // отвечает 409 session_refresh_conflict. Раньше tombstone сохранял
                // прежний absolute_expires, и клиент получал противоречивый ответ:
                // диспетчер обещал продление, а refresh отказывал.
                if($revoked||$replaced){
                    fh12_log('auth',0,'','auth','session_refresh_conflict',0,0,'','','',$corr);
                    respond(409,['error'=>'session_refresh_conflict']);
                }
                if($abs>0&&$abs<=time()){
                    fh12_log('auth',0,'','','session_absolute_expired',0,0,'','','',$corr);
                    respond(401,['error'=>'session_absolute_expired','absoluteExpires'=>$abs]);
                }
                if($abs<=0){
                    // Запись без absolute_expires (legacy): продлить её нельзя.
                    fh12_log('auth',0,'','','session_invalid',0,0,'','','',$corr);
                    respond(401,['error'=>'session_invalid']);
                }
                fh12_log('auth',0,'','','session_refreshable',0,0,'','','',$corr);
                respond(401,['error'=>'session_refreshable','absoluteExpires'=>$abs]);
            }
            fh12_log('auth',0,'','','session_invalid',0,0,'','','',$corr);
            respond(401,['error'=>'session_invalid']);
        }
    }
    $lead='';$cat='';
    // Correlation id пишется в журнал только когда клиент его прислал: без него
    // объём и формат боевого журнала не меняются (байт в байт).
    if($corr!=='')fh12_log('request',$uid,'','dispatch',$route,0,0,'','','',$corr);
    try {
        if(in_array($route,['files','file','thumb','calls_feed','trash','activity'],true)&&$method==='GET'){
            $lead=fh12_lead($_GET['leadId']??null);
            $cat=fh12_cat($route==='calls_feed'?'calls':($_GET['category']??'other'));
            fh12_assert_lead_access($identity,$lead);
        }
        if($route==='activity'&&$method==='GET'){
            if(!function_exists('fh_act_list'))respond(404,['error'=>'activity_not_available']);
            $limit=filter_var($_GET['limit']??30,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>30]]);
            if($limit===false)respond(400,['error'=>'invalid_limit']);
            respond(200,fh_act_list($lead,(int)$limit));
        }
        if($route==='files'||($route==='trash'&&$method==='GET')) {
            $trash=$route==='trash';$items=fh12_list_dir(fh12_dir($lead,$cat,$trash));$out=[];
            foreach($items as $name=>$item){if($item['collection']||$name===FH12_TRASH)continue;
                try{fh12_name($name);}catch(Throwable){continue;}
                unset($item['collection']);
                $out[]=$trash?$item:fh_attach_links($item,$lead,$cat,$uid);
            }
            usort($out,static fn($a,$b)=>strcmp($a['name'],$b['name']));
            fh12_log('list',$uid,$lead,$cat,'ok',207,count($out));respond(200,$out);
        }
        if(($route==='file'||$route==='thumb')&&$method==='GET'){
            $name=fh12_name($_GET['name']??null);
            fh12_stream_media($lead,$cat,$name,$route==='thumb');
        }
        if($route==='calls_feed'&&$method==='GET'){
            // Run old summary parser against the same remote folder; no writes.
            $fhUserId=$uid;fh12_log('calls',$uid,$lead,'calls','start');
            require __DIR__.'/calls.php';
        }
        if(in_array($route,['upload','rename','trash','restore'],true)&&$method==='POST'){
            if(!fh12_write_on())respond(503,['error'=>'prod_writes_disabled']);
            if($route==='upload'){
                $posted=$_SERVER['CONTENT_LENGTH']??'';
                if(is_string($posted)&&ctype_digit($posted)&&(float)$posted>21*1048576)
                    throw new RuntimeException('upload_too_large');
                $lead=fh12_lead($_POST['leadId']??null);$cat=fh12_cat($_POST['category']??null);
                fh12_assert_lead_access($identity,$lead);
                $f=$_FILES['file']??null;
                if(!is_array($f))throw new RuntimeException('invalid_upload');
                if(in_array($f['error']??null,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new RuntimeException('upload_too_large');
                if(($f['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($f['tmp_name']??'')))throw new RuntimeException('invalid_upload');
                $size=$f['size']??null;if(!is_int($size)||$size<1||$size>FH12_MAX_UPLOAD)throw new RuntimeException('upload_too_large');
                $name=fh12_protect_name((string)$f['name']);
                fh12_ensure_dir($lead,$cat);
                $out=fh12_mutate('upload',$lead,$cat,$name,'','',$uid,(string)$f['tmp_name'],$size);
                if(function_exists('fh_act_prepare'))$out['activity_status']='pending';
                respond(200,$out);
            }
            $raw=file_get_contents('php://input',false,null,0,8193);
            if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('invalid_payload');
            $p=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($p))throw new RuntimeException('invalid_payload');
            $lead=fh12_lead($p['leadId']??null);$cat=fh12_cat($p['category']??null);
            fh12_assert_lead_access($identity,$lead);
            $name=fh12_name($p['name']??null);
            $action=$route==='trash'?'trash':($route==='restore'?'restore':'rename');
            $newName=$action==='rename'?fh12_name($p['new_name']??null):'';
            $out=fh12_mutate($action,$lead,$cat,$name,$newName,'',$uid);
            if(function_exists('fh_act_prepare'))$out['activity_status']='pending';
            respond(200,$out);
        }
        respond(503,['error'=>'route_not_migrated','route'=>$route]);
    } catch(Throwable $e){fh12_http_error($e,$uid,$lead,$cat);}
}
function fh12_stream_media(string $lead,string $cat,string $name,bool $thumb):never {
    // Настоящая миниатюра: файл уменьшается на сервере. Раньше параметр $thumb
    // игнорировался и наружу уходил оригинал целиком - CSS-уменьшение не сокращало
    // объём загрузки. Оригинал по маршруту /file не меняется (полноэкранный просмотр).
    if($thumb){
        if(!function_exists('fh12_serve_thumb'))respond(404,['error'=>'thumb_not_available']);
        fh12_serve_thumb($lead,$cat,$name);
    }
    $path=fh12_file($lead,$cat,$name);
    $range=$_SERVER['HTTP_RANGE']??'';
    if(!is_string($range)||($range!==''&&!preg_match('/^bytes=\d*-\d*$/D',$range)))respond(416,['error'=>'invalid_range']);
    $headers=['Authorization: Basic '.base64_encode(fh_env_required('FILEHUB_DAV_USER').':'.fh_env_required('FILEHUB_DAV_PASS')),
              'User-Agent: FileHub-FH12/1.0'];
    if($range!=='')$headers[]='Range: '.$range;
    $context=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers)."\r\n",'timeout'=>60,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $stream=@fopen(fh12_host().fh12_encode_path($path),'rb',false,$context);
    if(!$stream)respond(502,['error'=>'webdav_media_unavailable']);
    $meta=stream_get_meta_data($stream);$status=0;$contentLength=null;$contentRange=null;
    foreach(($meta['wrapper_data']??[]) as $h){
        if(preg_match('~^HTTP/\S+\s+(\d{3})~i',$h,$m))$status=(int)$m[1];
        if(preg_match('/^Content-Length:\s*(\d+)/i',$h,$m))$contentLength=(int)$m[1];
        if(preg_match('/^Content-Range:\s*(bytes\s+[\d*-]+\/[\d*]+)/i',$h,$m))$contentRange=$m[1];
    }
    if(!in_array($status,[200,206],true)){fclose($stream);respond($status===404?404:502,['error'=>'webdav_media_unavailable']);}
    // Safe inline formats only. All others are forced as download (prevents HTML/SVG scripts).
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $types=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
       'webp'=>'image/webp','gif'=>'image/gif','mp3'=>'audio/mpeg','wav'=>'audio/wav','m4a'=>'audio/mp4',
       'ogg'=>'audio/ogg','mp4'=>'video/mp4','webm'=>'video/webm','txt'=>'text/plain; charset=utf-8'];
    $mime=$types[$ext]??'application/octet-stream';
    $inline=isset($types[$ext]) && in_array($ext,['pdf','jpg','jpeg','png','webp','gif','mp3','wav','m4a','ogg','mp4','webm','txt'],true);
    if(($_GET['download']??'')==='1'||!$inline)$inline=false;
    header_remove('Content-Type');http_response_code($status);
    header('Content-Type: '.$mime);header('Accept-Ranges: bytes');
    header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="file"; filename*=UTF-8\'\''.rawurlencode($name));
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    if($contentLength!==null)header('Content-Length: '.$contentLength);
    if($status===206&&$contentRange!==null)header('Content-Range: '.$contentRange);
    fh12_log('read',(int)($_GET['uid']??0),$lead,$cat,'ok',$status,$contentLength??0,'',$name);
    while(!feof($stream)){$buf=fread($stream,65536);if($buf===false)break;echo $buf;flush();}
    fclose($stream);exit;
}
