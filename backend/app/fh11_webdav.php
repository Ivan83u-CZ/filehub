<?php
declare(strict_types=1);
/**
 * FileHub FH11 synthetic-only Mail.ru WebDAV canary.
 * No arbitrary uploads, no DELETE, no patient data, no general amoCRM ACL.
 * Names are generated from 128-bit random IDs, payloads have an exact marker.
 * This module must never be enabled for other leads or real patient files.
 */
const FH11_LEAD = '34228264';
const FH11_BASE = '/CRM/ivan0082/4_other';
const FH11_TRASH_NAME = 'fh11-trash';
const FH11_TRASH = FH11_BASE.'/'.FH11_TRASH_NAME;
const FH11_ORIGIN = 'https://chinazub.amocrm.ru';
const FH11_HOST = 'https://webdav.cloud.mail.ru';
const FH11_ACK = 'I_ACCEPT_FH11_SYNTHETIC_MAILRU_CANARY_ONLY';
const FH11_UUID = '4109c136-6e73-42a9-9b71-1518249cd2f7';
const FH11_MARKER = "FILEHUB_SYNTHETIC_ONLY_FH11\nTest file ID: ";
const FH11_PROPFIND = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/></d:prop></d:propfind>';

function fh11_enabled(): bool {
    return getenv('FILEHUB_DAV_MODE') === 'test_crm_ivan0082'
        && getenv('FILEHUB_TEST_LEAD_ID') === FH11_LEAD
        && getenv('FILEHUB_AMO_CLIENT_UUID') === FH11_UUID
        && getenv('FILEHUB_OPS10_ACK') === 'I_ACCEPT_SYNTHETIC_PERSISTENT_CANARY_ONLY'
        && getenv('FILEHUB_FH11_ACK') === FH11_ACK
        && (getenv('FILEHUB_STAGE08_WRITE_ACK') === false || getenv('FILEHUB_STAGE08_WRITE_ACK') === '');
}
function fh11_parse_name(mixed $v): ?array {
    if (!is_string($v) || preg_match('/^fh11-([a-f0-9]{32})-([a-z][a-z0-9-]{0,19})\.txt$/D', $v, $m) !== 1) return null;
    return [$m[1],$m[2]];
}
function fh11_make_name(string $id,string $slug): string {
    if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !preg_match('/^[a-z][a-z0-9-]{0,19}$/D',$slug)) {
        throw new RuntimeException('invalid_synthetic_name');
    }
    return 'fh11-'.$id.'-'.$slug.'.txt';
}
function fh11_body(string $id): string {
    return FH11_MARKER.$id."\nNo patient data.\n";
}
function fh11_body_valid(string $name,?string $content): bool {
    $parts=fh11_parse_name($name);
    return $parts!==null && is_string($content) && hash_equals(fh11_body($parts[0]), $content);
}
function fh11_path(string $name,bool $trash=false): string {
    if (fh11_parse_name($name)===null) throw new RuntimeException('invalid_synthetic_name');
    return ($trash ? FH11_TRASH : FH11_BASE).'/'.$name;
}
/** Validates exact DAV response inventory; fails closed on ambiguous href/duplicates. */
function fh11_inventory(string $xml,string $directory): array {
    if (!in_array($directory,[FH11_BASE,FH11_TRASH],true)) throw new RuntimeException('directory_not_allowed');
    if (strlen($xml)<20 || strlen($xml)>1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) throw new RuntimeException('invalid_xml_size_or_dtd');
    if (!class_exists('DOMDocument')) throw new RuntimeException('dom_missing');
    $old=libxml_use_internal_errors(true);
    try {
        $dom=new DOMDocument();
        if (!$dom->loadXML($xml,LIBXML_NONET) || $dom->documentElement?->namespaceURI!=='DAV:' || $dom->documentElement?->localName!=='multistatus') throw new RuntimeException('invalid_dav_xml');
        $xp=new DOMXPath($dom);$xp->registerNamespace('d','DAV:');
        $responses=$xp->query('/d:multistatus/d:response');
        if (!$responses || $responses->length<1) throw new RuntimeException('empty_dav_xml');
        $rootFound=false;$items=[];
        foreach ($responses as $response) {
            $hrefs=$xp->query('d:href',$response);
            if (!$hrefs || $hrefs->length!==1) throw new RuntimeException('invalid_href_count');
            $href=trim($hrefs->item(0)->textContent);
            if ($href==='' || str_contains($href,'?') || str_contains($href,'#') || str_contains($href,'\\')) throw new RuntimeException('unsafe_href');
            $host=parse_url($href,PHP_URL_HOST);$scheme=parse_url($href,PHP_URL_SCHEME);
            if ($host!==null && ($host!=='webdav.cloud.mail.ru' || $scheme!=='https')) throw new RuntimeException('foreign_href');
            $path=parse_url($href,PHP_URL_PATH);
            if (!is_string($path) || $path==='') throw new RuntimeException('invalid_href_path');
            $decoded=rawurldecode($path);
            if (str_contains($decoded,'\\') || str_contains($decoded,"\0") || str_contains($decoded,'//') || preg_match('~/(?:\.|\.\.)/(?:|$)~',$decoded)) throw new RuntimeException('unsafe_decoded_path');
            $norm=rtrim($decoded,'/');
            if ($norm===$directory) {if ($rootFound) throw new RuntimeException('duplicate_root'); $rootFound=true; continue;}
            if (!str_starts_with($norm,$directory.'/')) throw new RuntimeException('out_of_scope_dav_entry');
            $name=substr($norm,strlen($directory)+1);
            if ($name==='' || strlen($name)>255 || str_contains($name,'/') || isset($items[$name])) throw new RuntimeException('invalid_or_duplicate_child');
            $props=$xp->query('d:propstat[contains(d:status," 200 ")]/d:prop',$response);
            if (!$props || $props->length<1) throw new RuntimeException('missing_successful_dav_props');
            $collection=(bool)$xp->evaluate('boolean(d:resourcetype/d:collection)',$props->item(0));
            $items[$name]=['collection'=>$collection];
        }
        if (!$rootFound) throw new RuntimeException('missing_directory_root');
        return $items;
    } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
}
/** Strict internal method and exact path allowlist; never calls generic fh08 write helper. */
function fh11_request_allowed(string $method,string $path,array $headers=[],?string $body=null): bool {
    if ($method==='PROPFIND') return in_array($path,[FH11_BASE,FH11_TRASH],true) && is_string($body) && strlen($body)<=4096;
    if ($method==='MKCOL') return $path===FH11_TRASH && $body===null;
    $trash=str_starts_with($path,FH11_TRASH.'/');
    $folder=$trash?FH11_TRASH:FH11_BASE;
    if (!str_starts_with($path,$folder.'/')) return false;
    $name=substr($path,strlen($folder)+1);
    if (fh11_parse_name($name)===null) return false;
    if ($method==='GET') return $body===null;
    if ($method==='PUT') {
        return !$trash && is_string($body) && fh11_body_valid($name,$body)
          && in_array('If-None-Match: *',$headers,true);
    }
    if ($method!=='MOVE' || $body!==null || !in_array('Overwrite: F',$headers,true)) return false;
    $dest=array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Destination: ')));
    if (count($dest)!==1) return false;
    $dst=substr($dest[0],strlen('Destination: '));
    // Rename within live, trash into dedicated collection, or restore to live.
    $folders=$trash ? [FH11_BASE] : [FH11_BASE,FH11_TRASH];
    $sourceId=fh11_parse_name($name)[0];
    foreach ($folders as $dir) {
        $prefix=FH11_HOST.$dir.'/';
        if (!str_starts_with($dst,$prefix)) continue;
        $destName=substr($dst,strlen($prefix));
        $destParts=fh11_parse_name($destName);
        if ($destParts===null || $destParts[0]!==$sourceId) continue;
        if ($dir!==$folder && $destName!==$name) continue; // trash/restore keep original filename
        if ($dir===$folder && $destName===$name) continue; // rename must change name
        return true;
    }
    return false;
}
function fh11_dav(string $method,string $path,array $headers=[],?string $body=null): array {
    if (!fh11_enabled() || !fh11_request_allowed($method,$path,$headers,$body)) throw new RuntimeException('dav_scope_denied');
    $user=getenv('FILEHUB_DAV_USER');$pass=getenv('FILEHUB_DAV_PASS');
    if (!is_string($user) || !str_contains($user,'@') || !is_string($pass) || $pass==='') throw new RuntimeException('dav_credentials_missing');
    $headers=array_merge(['Authorization: Basic '.base64_encode($user.':'.$pass),'User-Agent: FileHub-FH11-SyntheticCanary/1.0'],$headers);
    if ($body!==null) $headers[]='Content-Length: '.strlen($body);
    $opts=['http'=>['method'=>$method,'header'=>implode("\r\n",$headers)."\r\n",'timeout'=>20,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1],
      'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]];
    if ($body!==null) $opts['http']['content']=$body;
    $context=stream_context_create($opts);
    $result=@file_get_contents(FH11_HOST.$path,false,$context,0,1048577);
    $received=$http_response_header??[];$status=0;
    foreach ($received as $h) if (preg_match('~^HTTP/\S+\s+(\d{3})\b~i',$h,$m)) $status=(int)$m[1];
    if ($result===false) return [$status,null];
    if (strlen($result)>1048576) return [502,null];
    return [$status,$result];
}
function fh11_listing(string $directory): array {
    [$status,$xml]=fh11_dav('PROPFIND',$directory,['Depth: 1','Content-Type: application/xml'],FH11_PROPFIND);
    if ($status!==207 || !is_string($xml)) throw new RuntimeException('dav_listing_failed_'.$status);
    return fh11_inventory($xml,$directory);
}
function fh11_snapshot(): array {
    $parent=fh11_listing(FH11_BASE);
    if (isset($parent[FH11_TRASH_NAME]) && !$parent[FH11_TRASH_NAME]['collection']) throw new RuntimeException('trash_not_directory');
    $trash=isset($parent[FH11_TRASH_NAME]) ? fh11_listing(FH11_TRASH) : [];
    // Trash is reserved. Unknown items indicate an unsafe external change.
    foreach ($trash as $name=>$props) if (fh11_parse_name($name)===null || $props['collection']) throw new RuntimeException('unknown_item_in_reserved_trash');
    return [$parent,$trash];
}
function fh11_existing(string $name,bool $trashed): void {
    [$status,$data]=fh11_dav('GET',fh11_path($name,$trashed));
    if ($status!==200 || !fh11_body_valid($name,$data)) throw new RuntimeException('source_content_not_verified_'.$status);
}
/**
 * WebDAV may complete an operation while its HTTP response is lost (status 0).
 * Status 0 is NOT proof of success. It permits one read-only postcondition check;
 * every non-zero unexpected code remains fail-closed with pending intact.
 * Return true only when the response was ambiguous and must be reconciled.
 */
function fh11_status_needs_verification(string $method,int $status): bool {
    $accepted = $method === 'MKCOL' ? [200,201] : [200,201,204];
    if (!in_array($method,['MKCOL','PUT','MOVE'],true)) throw new RuntimeException('unexpected_write_method');
    if (in_array($status,$accepted,true)) return false;
    if ($status === 0) return true;
    throw new RuntimeException(strtolower($method).'_unconfirmed_'.$status);
}
/** Pure check: no I/O and no side effects; content is verified separately. */
function fh11_verified_postcondition(string $action,string $name,string $newName,array $parent,array $trash): array {
    if (!in_array($action,['create','rename','trash','restore'],true) || fh11_parse_name($name)===null) throw new RuntimeException('invalid_action');
    $destName=$action==='rename'?$newName:$name;
    if (fh11_parse_name($destName)===null) throw new RuntimeException('invalid_synthetic_name');
    if ($action==='rename' && fh11_parse_name($destName)[0]!==fh11_parse_name($name)[0]) throw new RuntimeException('cross_id_move');
    $destTrashed=$action==='trash';
    $destInventory=$destTrashed?$trash:$parent;
    if (!isset($destInventory[$destName]) || ($destInventory[$destName]['collection']??true)) throw new RuntimeException('destination_unverified');
    if ($action!=='create') {
        $srcInventory=$action==='restore'?$trash:$parent;
        if (isset($srcInventory[$name])) throw new RuntimeException('source_still_present');
    }
    return [$destName,$destTrashed];
}

/** Injectable read-only verification: allows testing HTTP-0 outcomes without network writes. */
function fh11_verify_remote_effect(string $action,string $name,string $newName,callable $snapshot,callable $verifyContent): array {
    [$parent,$trash]=$snapshot();
    if (!is_array($parent) || !is_array($trash)) throw new RuntimeException('invalid_dav_snapshot');
    [$destName,$destTrashed]=fh11_verified_postcondition($action,$name,$newName,$parent,$trash);
    $verifyContent($destName,$destTrashed);
    return [$destName,$destTrashed];
}

function fh11_store(): string {
    $root='/var/www/ops10/fh11';
    if (is_link($root) || !is_dir($root) || !is_writable($root) || !is_file($root.'/.lock') || is_link($root.'/.lock')) throw new RuntimeException('state_unavailable');
    $audit=$root.'/audit';
    if (!is_dir($audit) || is_link($audit) || !is_writable($audit) || !is_file($audit.'/events.jsonl') || is_link($audit.'/events.jsonl')) throw new RuntimeException('audit_unavailable');
    return $root;
}
function fh11_audit(string $root,array $event): void {
    $path=$root.'/audit/events.jsonl';
    $raw=@file_get_contents($path);
    if ($raw===false && is_file($path)) throw new RuntimeException('audit_read_failed');
    $raw=is_string($raw)?$raw:'';
    if (strlen($raw)>1048576 || ($raw!=='' && !str_ends_with($raw,"\n"))) throw new RuntimeException('audit_corrupt');
    $prev=str_repeat('0',64);$seq=1;
    if ($raw!=='') foreach (explode("\n",rtrim($raw,"\n")) as $line) {
        $old=json_decode($line,true);
        if (!is_array($old) || ($old['seq']??null)!==$seq || ($old['prev']??null)!==$prev || !is_array($old['event']??null) || !is_string($old['hash']??null)) throw new RuntimeException('audit_corrupt');
        $json=json_encode($old['event'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $want=hash('sha256',$prev."\n".$json);
        if (!hash_equals($want,$old['hash'])) throw new RuntimeException('audit_corrupt');
        $prev=$old['hash'];$seq++;
    };
    $data=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $line=json_encode(['seq'=>$seq,'prev'=>$prev,'event'=>$event,'hash'=>hash('sha256',$prev."\n".$data)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $f=@fopen($path,'ab');
    if ($f===false) throw new RuntimeException('audit_write_failed');
    $ok=@fwrite($f,$line)===strlen($line) && @fflush($f);
    if ($ok && function_exists('fsync')) $ok=@fsync($f);
    fclose($f);
    if (!$ok) throw new RuntimeException('audit_write_failed');
}
function fh11_pending(string $root): bool {
    return file_exists($root.'/pending.json') || is_link($root.'/pending.json');
}
function fh11_begin(string $root,array $event): void {
    if (fh11_pending($root)) throw new RuntimeException('reconciliation_required');
    $pending=$root.'/pending.json';
    $data=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $f=@fopen($pending,'x');
    if ($f===false) throw new RuntimeException('pending_write_failed');
    $ok=@fwrite($f,$data)===strlen($data) && @fflush($f);
    if ($ok && function_exists('fsync')) $ok=@fsync($f);
    fclose($f);
    @chmod($pending,0600);
    if (!$ok) throw new RuntimeException('pending_write_failed');
    fh11_audit($root,array_merge($event,['phase'=>'intent']));
}
function fh11_commit(string $root,array $event): void {
    fh11_audit($root,array_merge($event,['phase'=>'verified']));
    if (!@unlink($root.'/pending.json')) throw new RuntimeException('pending_clear_failed');
}
function fh11_lock(string $root) {
    $f=@fopen($root.'/.lock','r+');
    if (!$f || !@flock($f,LOCK_EX)) throw new RuntimeException('lock_unavailable');
    return $f;
}
function fh11_safe_http_failure(Throwable $e): never {
    $code=$e->getMessage();
    $map=['reconciliation_required'=>409,'synthetic_quota_exceeded'=>429,'filename_conflict'=>409,'source_missing'=>404,'trash_item_missing'=>404,'invalid_synthetic_name'=>400,'invalid_slug'=>400,'invalid_action'=>400,'invalid_payload'=>400,'invalid_state'=>409];
    $status=$map[$code]??503;
    error_log('[FH11] '.preg_replace('/[^a-zA-Z0-9_-]/','_',substr($code,0,100)));
    respond($status,['error'=>$code]);
}
function fh11_response_item(string $name,int $actor): array {
    $item=['name'=>$name,'contentType'=>'text/plain','size'=>strlen(fh11_body(fh11_parse_name($name)[0])),'isSyntheticExample'=>true,'storage'=>'mailru-test'];
    return fh_attach_links($item,FH11_LEAD,'other',$actor);
}
function fh11_list(int $actor): never {
    if (!fh11_enabled()) respond(403,['error'=>'fh11_disabled']);
    try {
        $root=fh11_store();
        [$parent,$trash]=fh11_snapshot();
        $live=[];$removed=[];
        foreach ($parent as $name=>$info) if (fh11_parse_name($name)!==null && !$info['collection']) $live[]=fh11_response_item($name,$actor);
        foreach ($trash as $name=>$info) $removed[]=['name'=>$name,'isSyntheticExample'=>true];
        usort($live,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        usort($removed,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        respond(200,['files'=>$live,'trash'=>$removed,'pending'=>fh11_pending($root),'mode'=>'synthetic_mailru_only']);
    } catch(Throwable $e) { fh11_safe_http_failure($e); }
}
function fh11_run(int $actor): never {
    if (!fh11_enabled() || $actor<1 || ($_SERVER['HTTP_ORIGIN']??'')!==FH11_ORIGIN || empty($_SERVER['HTTP_X_FH_SESSION'])) respond(403,['error'=>'fh11_write_denied']);
    $input=@file_get_contents('php://input',false,null,0,1025);
    if (!is_string($input) || strlen($input)>1024) respond(400,['error'=>'invalid_payload']);
    try {$data=json_decode($input,true,8,JSON_THROW_ON_ERROR);} catch(Throwable) {respond(400,['error'=>'invalid_payload']);}
    if (!is_array($data) || ($data['leadId']??null)!==FH11_LEAD || ($data['category']??null)!=='other') respond(403,['error'=>'fh11_scope_denied']);
    $action=$data['action']??null;
    if (!in_array($action,['create','rename','trash','restore'],true)) respond(400,['error'=>'invalid_action']);
    try {
        $root=fh11_store();$lock=fh11_lock($root);
        if (fh11_pending($root)) throw new RuntimeException('reconciliation_required');
        [$parent,$trash]=fh11_snapshot();
        $name=$data['name']??null;$newName='';$source='';$destination='';$content='';
        if ($action==='create') {
            $count=0;
            foreach ($parent as $n=>$item) if (fh11_parse_name($n)!==null) $count++;
            $count+=count($trash);
            if ($count>=20) throw new RuntimeException('synthetic_quota_exceeded');
            $name=fh11_make_name(bin2hex(random_bytes(16)),'demo');
            if (isset($parent[$name]) || isset($trash[$name])) throw new RuntimeException('filename_conflict');
            $source='';$destination=fh11_path($name);$content=fh11_body(fh11_parse_name($name)[0]);
        } else {
            if (fh11_parse_name($name)===null) throw new RuntimeException('invalid_synthetic_name');
            if ($action==='restore') {
                if (!isset($trash[$name])) throw new RuntimeException('trash_item_missing');
                if (isset($parent[$name])) throw new RuntimeException('filename_conflict');
                $source=fh11_path($name,true);$destination=fh11_path($name);
                fh11_existing($name,true);
            } else {
                if (!isset($parent[$name]) || $parent[$name]['collection']) throw new RuntimeException('source_missing');
                fh11_existing($name,false);
                $source=fh11_path($name);
                if ($action==='trash') {
                    if (isset($trash[$name])) throw new RuntimeException('filename_conflict');
                    $destination=fh11_path($name,true);
                } else {
                    $slug=$data['new_slug']??null;
                    if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9-]{0,19}$/D',$slug)) throw new RuntimeException('invalid_slug');
                    $newName=fh11_make_name(fh11_parse_name($name)[0],$slug);
                    if ($newName===$name || isset($parent[$newName]) || isset($trash[$newName])) throw new RuntimeException('filename_conflict');
                    $destination=fh11_path($newName);
                }
            }
        }
        $event=['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'actor'=>$actor,'action'=>$action,'from'=>$source,'to'=>$destination,'name'=>$name,'id'=>bin2hex(random_bytes(8))];
        fh11_begin($root,$event);
        // Never resend PUT/MOVE/MKCOL after HTTP 0; reconcile through read-only DAV requests.
        $ambiguousWrite=false;
        if ($action==='create') {
            [$status,]=fh11_dav('PUT',$destination,['Content-Type: text/plain; charset=utf-8','If-None-Match: *'],$content);
            $ambiguousWrite=fh11_status_needs_verification('PUT',$status);
        } else {
            if ($action==='trash' && !isset($parent[FH11_TRASH_NAME])) {
                [$created,]=fh11_dav('MKCOL',FH11_TRASH);
                $ambiguousMkcol=fh11_status_needs_verification('MKCOL',$created);
                // Even when MKCOL has HTTP 0, continue ONLY if read-only DAV confirms the directory.
                [$parent,$trash]=fh11_snapshot();
                if (!isset($parent[FH11_TRASH_NAME]) || !$parent[FH11_TRASH_NAME]['collection']) throw new RuntimeException('trash_mkcol_unverified');
                if ($ambiguousMkcol) error_log('[FH11] MKCOL status 0 directory verified before MOVE');
            }
            [$status,]=fh11_dav('MOVE',$source,['Destination: '.FH11_HOST.$destination,'Overwrite: F']);
            $ambiguousWrite=fh11_status_needs_verification('MOVE',$status);
        }
        // A status code, including 2xx, is never sufficient. Re-read BOTH folders and the
        // exact synthetic contents. Missing/duplicate/unknown state retains pending.json.
        [$destName,$dstTrash]=fh11_verify_remote_effect(
            $action,$name,$newName,
            static fn(): array => fh11_snapshot(),
            static function (string $verifiedName,bool $isTrash): void { fh11_existing($verifiedName,$isTrash); }
        );
        fh11_commit($root,$event);
        if ($ambiguousWrite) error_log('[FH11] '.strtoupper($action==='create'?'PUT':'MOVE').' status 0 verified by PROPFIND and GET; audit committed');
        respond(200,['ok'=>true,'name'=>$destName,'action'=>$action]);
    } catch(Throwable $e) {fh11_safe_http_failure($e);}
}
function fh11_media(string $name): never {
    if (!fh11_enabled() || fh11_parse_name($name)===null) respond(403,['error'=>'synthetic_only']);
    try {[$status,$content]=fh11_dav('GET',fh11_path($name));
        if ($status!==200 || !fh11_body_valid($name,$content)) throw new RuntimeException('fh11_media_unverified');
    } catch(Throwable $e) {fh11_safe_http_failure($e);}
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: '.strlen($content));
    header('Cache-Control: private, no-store');
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$name.'"');
    http_response_code(200);echo $content;exit;
}
