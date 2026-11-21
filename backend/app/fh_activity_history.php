<?php
declare(strict_types=1);
/**
 * FileHub activity history — staging candidate, server-derived actions only.
 * NOT live. No hard DELETE, no browser-authored events, no new Docker mounts.
 * Local durable intent/verified outbox -> scoped immutable-per-id Mail.ru WebDAV objects.
 * Historical ops prior to this module cannot recover plaintext filenames from the hashed FH12 log.
 */
if (!defined('FH_ACT_QUEUE_ROOT')) define('FH_ACT_QUEUE_ROOT','/var/www/ops10/activity-queue');
if (!defined('FH_ACT_CLOUD_FOLDER')) define('FH_ACT_CLOUD_FOLDER','.filehub-actions');
if (!defined('FH_ACT_HMAC_KEY_FILE')) define('FH_ACT_HMAC_KEY_FILE','/var/www/ops10/activity-key-v1');
/**
 * R2.1 — lifecycle of a local activity record.
 *
 *   intent    — the operation was started, the outcome is not known yet.
 *   verified  — the remote effect is CONFIRMED and the audit chain agrees. Only this
 *               state is ever published to the cloud.
 *   aborted   — the remote effect is CONFIRMED ABSENT: the operation did not happen.
 *               Closed locally, never published as a successful event.
 *   unresolved— the outcome could NOT be established (timeout, storage error, or the
 *               remote object no longer matches). Kept open on purpose: an unknown
 *               outcome is never turned into a successful event.
 */
if (!defined('FH_ACT_ST_INTENT'))     define('FH_ACT_ST_INTENT','intent');
if (!defined('FH_ACT_ST_VERIFIED'))   define('FH_ACT_ST_VERIFIED','verified');
if (!defined('FH_ACT_ST_ABORTED'))    define('FH_ACT_ST_ABORTED','aborted');
if (!defined('FH_ACT_ST_UNRESOLVED')) define('FH_ACT_ST_UNRESOLVED','unresolved');
/** States that are still open: they are counted in pending_count. */
if (!defined('FH_ACT_OPEN_STATES')) define('FH_ACT_OPEN_STATES',[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED,FH_ACT_ST_VERIFIED]);
/** Age before an orphan (a record whose pending marker is gone) may be reconciled.
 *  It must exceed the longest in-flight WebDAV operation, otherwise an operation that is
 *  still running could be classified as "did not happen" while it is about to succeed. */
if (!defined('FH_ACT_ORPHAN_GRACE')) define('FH_ACT_ORPHAN_GRACE',300);
/** Protected server journal for integrity violations (append-only, 0600, outside web-root). */
if (!defined('FH_ACT_INTEGRITY_LOG')) define('FH_ACT_INTEGRITY_LOG','/var/www/ops10/activity-integrity.jsonl');
/** Retention of closed local records (days). Cloud objects are NOT deleted by this module. */
if (!defined('FH_ACT_RETENTION_DAYS')) define('FH_ACT_RETENTION_DAYS',30);

/** A durable, separate audit-signing key. Never reuse short-lived media link keys. */
function fh_act_secret():string {
    static $key=null;
    if($key!==null)return $key;
    $path=FH_ACT_HMAC_KEY_FILE;
    if(!is_file($path)||is_link($path))throw new RuntimeException('activity_key_unavailable');
    $raw=@file_get_contents($path,false,null,0,67);
    if(!is_string($raw)||preg_match('/^[a-f0-9]{64}\n?$/Di',$raw)!==1)throw new RuntimeException('activity_key_unavailable');
    $key=hex2bin(trim($raw));
    if(!is_string($key))throw new RuntimeException('activity_key_unavailable');
    return $key;
}
function fh_act_dir(string $lead): string {
    return '/CRM/'.fh12_lead($lead).'/'.FH_ACT_CLOUD_FOLDER;
}
function fh_act_valid_id(string $id):string {
    if(preg_match('/^[a-f0-9]{16}$/D',$id)!==1)throw new RuntimeException('activity_invalid_id');
    return $id;
}
function fh_act_queue_dir(string $lead):string {
    $lead=fh12_lead($lead);
    $root=FH_ACT_QUEUE_ROOT;
    if(str_contains($root,'..')||is_link($root))throw new RuntimeException('activity_local_unavailable');
    if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('activity_local_unavailable');
    $dir=$root.'/'.hash('sha256',$lead);
    if(!is_dir($dir)&&!@mkdir($dir,0700)&&!is_dir($dir))throw new RuntimeException('activity_local_unavailable');
    if(is_link($dir))throw new RuntimeException('activity_local_unavailable');
    return $dir;
}
function fh_act_queue_file(string $lead,string $id):string {
    return fh_act_queue_dir($lead).'/'.fh_act_valid_id($id).'.json';
}
function fh_act_write_atomic(string $target,array $event):void {
    if(is_link($target))throw new RuntimeException('activity_local_unavailable');
    $raw=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($raw)>4096)throw new RuntimeException('activity_payload_too_large');
    $tmp=dirname($target).'/.act-'.bin2hex(random_bytes(16)).'.tmp';
    $fp=@fopen($tmp,'x');if(!$fp)throw new RuntimeException('activity_local_unavailable');
    try{
        @chmod($tmp,0600);
        $total=0;$len=strlen($raw);
        while($total<$len){$n=@fwrite($fp,substr($raw,$total));if(!is_int($n)||$n<=0)throw new RuntimeException('activity_local_unavailable');$total+=$n;}
        if(!@fflush($fp)||!@fsync($fp))throw new RuntimeException('activity_local_unavailable');
    } finally {fclose($fp);}
    if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('activity_local_unavailable');}
}
function fh_act_prepare(string $lead,int $actor,string $action,string $category,string $name,string $newName,string $id,string $davDest='',string $expectedHash=''):void {
    if(!in_array($action,['upload','rename','trash','restore'],true))throw new RuntimeException('activity_invalid_action');
    $p=fh_act_queue_file($lead,$id);
    if(file_exists($p))throw new RuntimeException('activity_duplicate_id');
    $row=[
       'schema'=>1,'lead_id'=>fh12_lead($lead),'operation_id'=>fh_act_valid_id($id),
       'time'=>gmdate('Y-m-d\TH:i:s\Z'),'actor_id'=>max(1,$actor),
       'action'=>$action,'category'=>fh12_cat($category),'file_name'=>fh12_name($name),
       'new_name'=>$action==='rename'?fh12_name($newName):'', 'status'=>FH_ACT_ST_INTENT,
       'prepared_at'=>time(),
    ];
    // R2.1: the remote destination and the expected content hash are stored WITH the
    // intent, so that a record whose pending marker is already gone can still be
    // reconciled against the real remote state. Without them the outcome of a crashed
    // operation could not be established and the record would stay open forever.
    if($davDest!=='')$row['dav_dest']=fh12_name(basename($davDest));
    if($expectedHash!==''&&preg_match('/^[a-f0-9]{64}$/D',$expectedHash)===1)$row['expected_sha256']=$expectedHash;
    fh_act_write_atomic($p,$row);
}
function fh_act_commit(string $lead,string $id):void {
    $path=fh_act_queue_file($lead,$id);
    $raw=@file_get_contents($path,false,null,0,4097);
    if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('activity_local_unavailable');
    $row=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if(!is_array($row)||($row['lead_id']??'')!==$lead||($row['operation_id']??'')!==$id)throw new RuntimeException('activity_local_unavailable');
    if(($row['status']??'')==='verified')return;
    if(($row['status']??'')!=='intent')throw new RuntimeException('activity_local_unavailable');
    $row['status']='verified';
    fh_act_write_atomic($path,$row);
}
/**
 * R2.1 — read one local record. Returns null when the record is absent or unreadable.
 */
function fh_act_record_read(string $lead,string $id):?array {
    try {
        $path=fh_act_queue_file($lead,$id);
        if(!is_file($path))return null;
        $raw=@file_get_contents($path,false,null,0,4097);
        if(!is_string($raw)||strlen($raw)>4096)return null;
        $row=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
        if(!is_array($row))return null;
        if(($row['lead_id']??'')!==$lead||($row['operation_id']??'')!==$id)return null;
        return $row;
    } catch(Throwable) { return null; }
}
/**
 * R2.1 — protected server journal for integrity violations.
 *
 * Two destinations: the protected file outside the web-root (append-only, 0600) and the
 * R1 server journal (fh12_log). Neither of them receives a file name, a lead id or a
 * token - only the operation id, which is not personal data.
 */
function fh_act_integrity_log(string $lead,string $opId,string $reason):void {
    $reason=preg_replace('/[^a-z0-9_-]/','_',substr(strtolower($reason),0,32));
    $row=['time'=>gmdate('Y-m-d\TH:i:s\Z'),'op_id'=>preg_replace('/[^a-f0-9]/','',substr($opId,0,32)),
          'lead'=>function_exists('fh12_hash')?fh12_hash($lead):'','reason'=>$reason];
    $line=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $fh=@fopen(FH_ACT_INTEGRITY_LOG,'a');
    if($fh!==false){@chmod(FH_ACT_INTEGRITY_LOG,0600);@flock($fh,LOCK_EX);@fwrite($fh,$line."\n");@fflush($fh);@flock($fh,LOCK_UN);@fclose($fh);}
    if(function_exists('fh12_log'))fh12_log('error',0,$lead,'','activity_integrity_violation',0,0,$opId,'',$reason);
}
/**
 * R2.1 — promote a local record to 'verified'.
 *
 * The promotion is allowed ONLY when BOTH conditions hold:
 *   1) the remote effect is confirmed (the caller established it against the real DAV),
 *   2) the audit chain is intact and already carries the 'verified' phase for this id.
 * If the audit does not agree, the record stays open: an unverified operation is never
 * published as a successful event.
 */
function fh_act_promote_verified(string $lead,string $id,string $auditDir=''):bool {
    $row=fh_act_record_read($lead,$id);
    if($row===null)return false;
    $status=(string)($row['status']??'');
    if($status===FH_ACT_ST_VERIFIED)return true;
    if(!in_array($status,[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED],true))return false;
    try {
        if($auditDir==='')$auditDir=fh12_state_root($lead);
        if(!function_exists('fh12_audit_chain_ok')||!fh12_audit_chain_ok($auditDir)){
            fh_act_integrity_log($lead,$id,'audit_chain_broken');
            return false;
        }
        if(!function_exists('fh12_phase_exists')||!fh12_phase_exists($auditDir,$id,'verified')){
            fh_act_integrity_log($lead,$id,'audit_phase_missing');
            return false;
        }
    } catch(Throwable) {
        fh_act_integrity_log($lead,$id,'audit_unavailable');
        return false;
    }
    $row['status']=FH_ACT_ST_VERIFIED;
    $row['verified_at']=time();
    try { fh_act_write_atomic(fh_act_queue_file($lead,$id),$row); }
    catch(Throwable) { fh_act_integrity_log($lead,$id,'promote_write_failed'); return false; }
    return true;
}
/**
 * R2.1 — close a record as 'aborted': the remote effect is confirmed ABSENT, so the
 * operation did not happen. Such a record is never published to the cloud.
 */
function fh_act_close_aborted(string $lead,string $id):bool {
    $row=fh_act_record_read($lead,$id);
    if($row===null)return false;
    if(!in_array((string)($row['status']??''),[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED],true))return false;
    $row['status']=FH_ACT_ST_ABORTED;
    $row['aborted_at']=time();
    try { fh_act_write_atomic(fh_act_queue_file($lead,$id),$row); }
    catch(Throwable) { return false; }
    return true;
}
/**
 * R2.1 — mark a record as 'unresolved': the outcome could not be established.
 * The record stays open on purpose and is counted in pending_count, so the gap is
 * visible to the operator instead of being silently presented as a complete history.
 */
function fh_act_mark_unresolved(string $lead,string $id,string $reason):bool {
    $row=fh_act_record_read($lead,$id);
    if($row===null||!in_array($row['status']??'',[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED],true))return false;
    $attempts=min(8,(int)($row['reconcile_attempts']??0)+1);
    $row['status']=FH_ACT_ST_UNRESOLVED;
    $row['unresolved_reason']=preg_replace('/[^a-z0-9_-]/','_',substr(strtolower($reason),0,32));
    $row['unresolved_at']=time();
    $row['reconcile_attempts']=$attempts;
    $row['reconcile_after']=time()+min(3600,300*2**($attempts-1));
    try { fh_act_write_atomic(fh_act_queue_file($lead,$id),$row); }
    catch(Throwable) { return false; }
    fh_act_integrity_log($lead,$id,'outcome_undetermined');
    return true;
}
/**
 * R2.1 — establish the real outcome of an operation whose local record is still open.
 *
 * Returns 'verified' | 'aborted' | 'unresolved'. The decision is made ONLY against the
 * real remote state: the destination object is listed and its content hash is compared
 * with the hash that was recorded with the intent.
 */
function fh_act_outcome(string $lead,array $row,?int $stopAt=null):string {
    $id=fh_act_valid_id($row['operation_id']);
    $dir=fh12_state_root($lead);
    if(!fh12_audit_chain_ok($dir))return FH_ACT_ST_UNRESOLVED;
    foreach([fh12_pending_file($dir,fh12_cat($row['category'])),fh12_pending_legacy($dir)] as $pending){
        if(!fh12_pending_is_ours($pending,$id))continue;
        return match(fh12_resolve_pending($dir,$pending,$lead,(int)$row['actor_id'],stopAt:$stopAt)){
            'committed'=>FH_ACT_ST_VERIFIED,
            'aborted'=>FH_ACT_ST_ABORTED,
            default=>FH_ACT_ST_UNRESOLVED,
        };
    }
    if(fh12_phase_exists($dir,$id,'verified'))return FH_ACT_ST_VERIFIED;
    if(fh12_phase_exists($dir,$id,'cancelled'))return FH_ACT_ST_ABORTED;
    return FH_ACT_ST_UNRESOLVED;
}
/**
 * R2.1 — reconcile records whose pending marker is already gone (orphans).
 *
 * Such a record is the residue of a process death between the WebDAV operation and the
 * local commit. The outcome is established against the real remote state and the audit
 * chain; nothing is ever published without both checks.
 */
function fh_act_reconcile_orphans(string $lead,int $max=5,?int $stopAt=null):array {
    $out=['scanned'=>0,'verified'=>0,'aborted'=>0,'unresolved'=>0];
    try { $dir=fh_act_queue_dir($lead); } catch(Throwable) { return $out; }
    $now=time();
    foreach(glob($dir.'/*.json')?:[] as $file){
        if($out['scanned']>=$max||($stopAt!==null&&hrtime(true)>=$stopAt-1000000000))break;
        $id=basename($file,'.json');
        if(!preg_match('/^[a-f0-9]{16}$/D',$id))continue;
        $row=fh_act_record_read($lead,$id);
        if($row===null)continue;
        $status=(string)($row['status']??'');
        if(!in_array($status,[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED],true))continue;
        $age=$now-(int)($row['prepared_at']??0);
        if($age<FH_ACT_ORPHAN_GRACE||(int)($row['reconcile_after']??0)>$now)continue;
        try { $lock=fh12_lock_lead(fh12_state_root($lead),0); }
        catch(Throwable) { break; }
        try {
            $row=fh_act_record_read($lead,$id);
            if($row===null||!in_array($row['status']??'',[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED],true))continue;
            $out['scanned']++;
            try { $outcome=fh_act_outcome($lead,$row,$stopAt); }
            catch(Throwable) { $outcome=FH_ACT_ST_UNRESOLVED; }
            if($outcome===FH_ACT_ST_VERIFIED&&fh_act_promote_verified($lead,$id))$out['verified']++;
            elseif($outcome===FH_ACT_ST_ABORTED&&fh_act_close_aborted($lead,$id))$out['aborted']++;
            else {
                fh_act_mark_unresolved($lead,$id,'remote_state_unknown');
                $out['unresolved']++;
            }
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }
    return $out;
}
/**
 * R2.1 — number of events that are still open for this lead: an intent, an unresolved
 * outcome, or a verified record that is not in the cloud yet.
 */
function fh_act_pending_count(string $lead):int {
    $n=0;
    try { $dir=fh_act_queue_dir($lead); } catch(Throwable) { return 0; }
    foreach(glob($dir.'/*.json')?:[] as $file){
        $raw=@file_get_contents($file,false,null,0,4097);
        if(!is_string($raw))continue;
        $r=json_decode($raw,true);
        if(!is_array($r))continue;
        if(in_array((string)($r['status']??''),FH_ACT_OPEN_STATES,true))$n++;
    }
    return $n;
}
/** WebDAV client with narrow paths and operations. Never forward client-provided URLs. */
function fh_act_cloud_request(string $method,string $path,?string $body=null,array $extra=[],int $max=1048576):array {
    if(!in_array($method,['GET','PROPFIND','MKCOL','PUT'],true))throw new RuntimeException('activity_method_denied');
    if(!preg_match('~^/CRM/[1-9][0-9]{0,17}/\.filehub-actions(?:/[0-9]{4}-[0-9]{2}(?:/[0-9]{14}-[a-f0-9]{16}\.json)?)?$~D',$path))throw new RuntimeException('activity_scope_denied');
    if(!fh12_on()&&!fh12_test_mode())throw new RuntimeException('activity_mode_denied');
    // Test seam is only active with the existing local-only FH12_TEST_MODE guard.
    if(fh12_test_mode() && is_callable($GLOBALS['fh_activity_dav_mock']??null))
        return ($GLOBALS['fh_activity_dav_mock'])($method,$path,$body,$extra);
    if(!function_exists('curl_init'))throw new RuntimeException('activity_cloud_unavailable');
    $headers=['Authorization: Basic '.base64_encode(fh_env_required('FILEHUB_DAV_USER').':'.fh_env_required('FILEHUB_DAV_PASS')),
              'User-Agent: FileHub-Activity/1','Accept: application/json'];
    foreach($extra as $h){if(!is_string($h)||preg_match('/[\r\n]/',$h))throw new RuntimeException('activity_invalid_header');$headers[]=$h;}
    $raw='';$tooLarge=false;
    $ch=curl_init(fh12_host().fh12_encode_path($path));if(!$ch)throw new RuntimeException('activity_cloud_unavailable');
    try{
        $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
         CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,
         CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,
         CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
         CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$raw,&$tooLarge,$max):int {
            if(strlen($raw)+strlen($chunk)>$max){$tooLarge=true;return 0;}
            $raw.=$chunk;return strlen($chunk);
         }];
        if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
        if(!curl_setopt_array($ch,$opts))throw new RuntimeException('activity_cloud_unavailable');
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($tooLarge)throw new RuntimeException('activity_cloud_response_large');
        return [$ok===false?0:$status,$ok===false?null:$raw];
    }finally{curl_close($ch);}
}
function fh_act_list_dir(string $dir):array {
    [$status,$body]=fh_act_cloud_request('PROPFIND',$dir,FH12_XML,['Depth: 1','Content-Type: application/xml'],1048576);
    if($status===404)return [];
    if($status!==207||!is_string($body))throw new RuntimeException('activity_cloud_unavailable');
    return fh12_inventory($body,$dir);
}
function fh_act_ensure_dir(string $dir):void {
    [$status,]=fh_act_cloud_request('MKCOL',$dir,null,[],1024);
    if(!in_array($status,[200,201,405],true))throw new RuntimeException('activity_cloud_unavailable');
    [$probe, $raw]=fh_act_cloud_request('PROPFIND',$dir,FH12_XML,['Depth: 0','Content-Type: application/xml'],8192);
    if($probe!==207||!is_string($raw))throw new RuntimeException('activity_cloud_unavailable');
    fh12_inventory($raw,$dir);
}
function fh_act_cloud_event(array $record):array {
    // Exclude lead_id and local commit status from public-to-operators cloud payload.
    $event=[
      'schema'=>1,'operation_id'=>(string)$record['operation_id'],'time'=>(string)$record['time'],
      'actor_id'=>(int)$record['actor_id'],'action'=>(string)$record['action'],
      'category'=>(string)$record['category'],'file_name'=>(string)$record['file_name'],
      'new_name'=>(string)($record['new_name']??'')
    ];
    $json=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $lead=fh12_lead($record['lead_id']);
    return ['key_id'=>'v1','event'=>$event,'sig'=>hash_hmac('sha256','filehub-action-v1\n'.$json,fh_act_secret()),
        'lead_sig'=>hash_hmac('sha256',"filehub-action-v2\n".$lead."\n".$json,fh_act_secret())];
}
function fh_act_verify_event(string $raw,string $lead,bool $requireBinding=false):array {
    if(strlen($raw)>8192||strlen($raw)<10)throw new RuntimeException('activity_cloud_corrupt');
    $doc=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if(!is_array($doc)||($doc['key_id']??null)!=='v1'||!is_array($doc['event']??null)||!is_string($doc['sig']??null))throw new RuntimeException('activity_cloud_corrupt');
    $e=$doc['event'];
    if(($e['schema']??null)!==1||!is_int($e['actor_id']??null)||($e['actor_id']??0)<=0||
       !in_array($e['action']??null,['upload','rename','trash','restore'],true))throw new RuntimeException('activity_cloud_corrupt');
    fh_act_valid_id($e['operation_id']??'');fh12_cat($e['category']??'');fh12_name($e['file_name']??'');
    if(($e['action']??'')==='rename')fh12_name($e['new_name']??'');
    $json=json_encode($e,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $expected=hash_hmac('sha256','filehub-action-v1\n'.$json,fh_act_secret());
    if(!hash_equals($expected,$doc['sig']))throw new RuntimeException('activity_cloud_corrupt');
    if($requireBinding||array_key_exists('lead_sig',$doc)){
        $expected=hash_hmac('sha256',"filehub-action-v2\n".$lead."\n".$json,fh_act_secret());
        if(!is_string($doc['lead_sig']??null)||!hash_equals($expected,$doc['lead_sig']))
            throw new RuntimeException('activity_cloud_corrupt');
    }
    return $e;
}
function fh_act_publication_dir(string $lead):string {
    return fh_act_queue_dir($lead).'/published';
}
function fh_act_flush_one(string $lead,string $id):bool {
    try {
        $p=fh_act_queue_file($lead,$id);
        if(!is_file($p))return false; // Never claim cloud success when no staged verified event exists.
        $raw=@file_get_contents($p,false,null,0,4097);
        if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('activity_local_unavailable');
        $r=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['lead_id']??'')!==$lead||($r['operation_id']??'')!==$id||($r['status']??'')!=='verified')return false;
        $base=fh_act_dir($lead);$month=substr((string)$r['time'],0,7);
        if(preg_match('/^[0-9]{4}-[0-9]{2}$/D',$month)!==1)throw new RuntimeException('activity_invalid_time');
        $mPath=$base.'/'.$month;
        fh_act_ensure_dir($base);fh_act_ensure_dir($mPath);
        $stamp=preg_replace('/[^0-9]/','',substr((string)$r['time'],0,19));
        $path=$mPath.'/'.$stamp.'-'.$id.'.json';
        $cloud=fh_act_cloud_event($r);
        $json=json_encode($cloud,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($json)>8192)throw new RuntimeException('activity_payload_too_large');
        [$status,]=fh_act_cloud_request('PUT',$path,$json,['If-None-Match: *','Content-Type: application/json'],1024);
        if(!in_array($status,[200,201,204,412,0],true))throw new RuntimeException('activity_cloud_unavailable');
        // Always verify GET after PUT: response 0 may mean a successful but lost acknowledgement.
        [$get,$back]=fh_act_cloud_request('GET',$path,null,[],8192);
        if($get!==200||!is_string($back))throw new RuntimeException('activity_cloud_unconfirmed');
        $verified=fh_act_verify_event($back,$lead);
        $expected=$cloud['event'];
        if($verified!==$expected)throw new RuntimeException('activity_cloud_conflict');
        $backDoc=json_decode($back,true,12,JSON_THROW_ON_ERROR);
        $receipts=fh_act_publication_dir($lead).'/'.$month;
        if(!is_dir($receipts)&&!@mkdir($receipts,0700,true)&&!is_dir($receipts))
            throw new RuntimeException('activity_receipt_unavailable');
        fh_act_write_atomic($receipts.'/'.basename($path),['operation_id'=>$id,'bound'=>isset($backDoc['lead_sig'])]);
        @unlink($p); // Best effort. Retaining verified spool is safe: next flush is idempotent.
        return true;
    } catch(Throwable) {
        // Never log a patient's filename, a bearer, a raw exception or a WebDAV URL.
        if(function_exists('fh12_log'))fh12_log('error',0,$lead,'','activity_sync_pending');
        return false;
    }
}
function fh_act_flush_some(string $lead,int $max=3):int {
    $dir=fh_act_queue_dir($lead);$count=0;
    $list=glob($dir.'/????????????????.json')?:[];
    foreach($list as $p){
        if($count>=$max)break;
        $id=basename($p,'.json');
        if(!preg_match('/^[a-f0-9]{16}$/D',$id))continue;
        $count++;
        fh_act_flush_one($lead,$id);
    }
    return $count;
}
/**
 * Read bounded, HMAC-verified history from Mail.ru Cloud for exactly one lead.
 *
 * R2.1: every object is processed INDEPENDENTLY. A single unreadable or tampered object
 * must not make the whole history of the deal unreadable, and it must never be presented
 * as a complete history: the unreadable objects are counted (skipped_count), the response
 * is marked partial and the integrity violation is written to the protected server journal.
 */
function fh_act_list(string $lead,int $limit=30):array {
    $lead=fh12_lead($lead);$limit=max(1,min(30,$limit));
    // Do not block the read endpoint by attempting WebDAV writes. A separate worker
    // reconciles durable local outbox entries; verified cloud entries are read here.
    $base=fh_act_dir($lead);
    $months=[];
    $partial=false; $skipped=0;
    try {
        $listing=fh_act_list_dir($base);
        foreach($listing as $name=>$item){
            if(($item['collection']??false)&&preg_match('/^[0-9]{4}-[0-9]{2}$/D',$name))$months[]=$name;
        }
        foreach(glob(fh_act_publication_dir($lead).'/*',GLOB_ONLYDIR)?:[] as $receiptMonth){
            $month=basename($receiptMonth);
            if(!in_array($month,$months,true))$months[]=$month;
        }
    } catch(Throwable $e) {
        // The root of the history cannot be enumerated: the answer is incomplete, and it
        // is reported as incomplete. It is NOT reported as an empty history.
        fh_act_integrity_log($lead,'','history_root_unreadable');
        $months=[];$partial=true;$skipped++;
    }
    rsort($months,SORT_STRING);$rows=[];$deadline=microtime(true)+8.0;
    foreach($months as $month){
        if(microtime(true)>$deadline){$partial=true;break;}
        $dir=$base.'/'.$month;
        $items=[];
        try {
            $files=fh_act_list_dir($dir);
            foreach($files as $name=>$item){
                if(!($item['collection']??true)&&preg_match('/^[0-9]{14}-[a-f0-9]{16}\.json$/D',$name))$items[]=$name;
            }
            $present=array_fill_keys($items,true);
            foreach(glob(fh_act_publication_dir($lead).'/'.$month.'/*.json')?:[] as $receipt){
                $name=basename($receipt);
                if(isset($present[$name]))continue;
                $partial=true;$skipped++;
                fh_act_integrity_log($lead,substr($name,15,16),'history_object_missing');
            }
        } catch(Throwable $e) {
            fh_act_integrity_log($lead,'','history_month_unreadable');
            $partial=true;$skipped++;continue;
        }
        rsort($items,SORT_STRING);
        foreach($items as $name){
            if(count($rows)>$limit)break;
            if(microtime(true)>$deadline){$partial=true;break;}
            // --- one object, one verdict: a failure here never aborts the whole answer ---
            try {
                [$status,$raw]=fh_act_cloud_request('GET',$dir.'/'.$name,null,[],8192);
                if($status!==200||!is_string($raw))throw new RuntimeException('activity_cloud_unavailable');
                $receipt=@file_get_contents(fh_act_publication_dir($lead).'/'.$month.'/'.$name,false,null,0,512);
                $receiptData=is_string($receipt)?json_decode($receipt,true):null;
                $e=fh_act_verify_event($raw,$lead,($receiptData['bound']??false)===true);
                if(substr(preg_replace('/[^0-9]/','',(string)$e['time']),0,14)!==substr($name,0,14)||
                   ($e['operation_id']??'')!==substr($name,15,16))throw new RuntimeException('activity_cloud_corrupt');
                $rows[]=$e;
            } catch(Throwable $e) {
                $skipped++;$partial=true;
                $opId=preg_match('/^[a-f0-9]{16}$/D',substr($name,15,16))===1?substr($name,15,16):'';
                fh_act_integrity_log($lead,$opId,'history_object_unreadable');
            }
        }
        if(count($rows)>$limit)break;
    }
    $hasMore=count($rows)>$limit;
    if($hasMore){
        // Read one additional verified event as proof that the response was truncated.
        $rows=array_slice($rows,0,$limit);
    }
    // A local event that is not in Mail.ru yet is not silently presented as stored in cloud.
    $pending=fh_act_pending_count($lead);
    return ['events'=>$rows,'cloud_status'=>$pending>0?'delayed':'ok','pending_count'=>$pending,
            'partial'=>$partial,'skipped_count'=>$skipped,'has_more'=>$hasMore];
}
