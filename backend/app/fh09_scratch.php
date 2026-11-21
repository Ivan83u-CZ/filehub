<?php
declare(strict_types=1);
/** FileHub Stage10: synthetic persistent test operations and audited reversible trash ONLY. No patient data, no WebDAV writes. */
const FH09_LEAD='34228264';
const FH09_ACK='I_ACCEPT_SYNTHETIC_TMPFS_TEST_WRITES_ONLY';
const FH10_ACK='I_ACCEPT_SYNTHETIC_PERSISTENT_CANARY_ONLY';
const FH09_MARKER="FILEHUB_SYNTHETIC_ONLY_V1\nTest file ID: ";
function fh09_enabled(): bool {
    return getenv('FILEHUB_DAV_MODE')==='test_crm_ivan0082'
        && getenv('FILEHUB_TEST_LEAD_ID')===FH09_LEAD
        && getenv('FILEHUB_TEST_WRITE_ACK')===FH09_ACK
        && getenv('FILEHUB_OPS10_ACK')===FH10_ACK;
}
function fh09_validate_lead(mixed $lead): void {
    if (!is_string($lead) || $lead!==FH09_LEAD || !fh09_enabled()) {
        respond(403,['error'=>'synthetic_ops_only']);
    }
}
function fh09_parse_name(mixed $name): ?array {
    if (!is_string($name) || preg_match('/^fh09-([a-f0-9]{16})-([a-z][a-z0-9-]{0,23})\.txt$/D',$name,$m)!==1) return null;
    return [$m[1],$m[2]];
}
function fh09_dirs(): array {
    if (!fh09_enabled()) respond(403,['error'=>'synthetic_ops_only']);
    // Never fall back to tmpfs when persistent storage is missing: fail closed.
    $root='/var/www/ops10/fh09';
    // Offline harness only; variable is NOT exported to the live FPM worker.
    if (getenv('FILEHUB_OPS10_OFFLINE_TEST')==='1' && getenv('FILEHUB_OPS10_SCRATCH')!==false) {
        $root=(string)getenv('FILEHUB_OPS10_SCRATCH');
    }
    if (is_link($root) || !is_dir($root) || !is_writable($root)) respond(503,['error'=>'persistent_store_unavailable']);
    $live=$root.'/live'; $trash=$root.'/trash'; $audit=$root.'/audit';
    foreach ([$live,$trash,$audit] as $dir) {
        if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) respond(503,['error'=>'persistent_store_unavailable']);
    }
    if (is_link($root.'/.lock') || is_link($audit.'/events.jsonl')) respond(503,['error'=>'persistent_store_unavailable']);
    return [$root,$live,$trash];
}
function fh09_items(bool $trashed=false): array {
    if (!fh09_enabled()) return [];
    [, $live, $trash]=fh09_dirs();
    $dir=$trashed?$trash:$live;
    $results=[];
    foreach (scandir($dir) ?: [] as $name) {
        if (fh09_parse_name($name)===null) continue;
        $file=$dir.'/'.$name;
        if (!is_file($file) || is_link($file)) continue;
        $size=filesize($file);
        if (!is_int($size) || $size>8192) continue;
        $mtime=filemtime($file);
        $results[]=['name'=>$name,'size'=>$size,'contentType'=>'text/plain',
            'lastmod'=>is_int($mtime)?gmdate('D, d M Y H:i:s',$mtime).' GMT':'',
            'isSyntheticExample'=>true,'isTrash'=>$trashed];
    }
    usort($results,static fn($a,$b)=>strcmp($a['name'],$b['name']));
    return $results;
}
function fh09_read_media(string $name): never {
    $parts=fh09_parse_name($name);
    if ($parts===null) respond(403,['error'=>'synthetic_name_only']);
    [, $live, ]=fh09_dirs();
    $file=$live.'/'.$name;
    if (!is_file($file) || is_link($file)) respond(404,['error'=>'test_file_not_found']);
    $data=@file_get_contents($file);
    if (!is_string($data) || strlen($data)>8192 || !hash_equals(FH09_MARKER.$parts[0]."\n",$data)) {
        respond(503,['error'=>'scratch_invalid']);
    }
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.strlen($data));
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$name.'"');
    http_response_code(200);
    echo $data;
    exit;
}
function fh09_json_body(): array {
    $body=file_get_contents('php://input');
    if (!is_string($body) || strlen($body)>1024) respond(400,['error'=>'invalid_json']);
    $payload=json_decode($body,true);
    if (!is_array($payload)) respond(400,['error'=>'invalid_json']);
    return $payload;
}
/** Bounded, hash-chained staging audit. Does not replace independent production audit. */
function fh10_audit_append(string $root, int $actor, string $action, string $name, ?string $newName=null): bool {
    $file=$root.'/audit/events.jsonl';
    if (is_link($file) || !is_dir(dirname($file))) return false;
    $prior=@file_get_contents($file);
    if ($prior===false && is_file($file)) return false;
    $prior=is_string($prior)?$prior:'';
    if (strlen($prior)>1048576) return false; // fail closed; no silent log truncation
    $prev=str_repeat('0',64);
    $seq=1;
    if ($prior!=='') {
        if (!str_ends_with($prior,"\n")) return false;
        foreach (explode("\n",rtrim($prior,"\n")) as $line) {
            $old=json_decode($line,true);
            if (!is_array($old) || ($old['seq']??null)!==$seq || ($old['prev']??null)!==$prev
                || !is_array($old['event']??null) || !is_string($old['hash']??null)) return false;
            $payload=json_encode($old['event'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if (!is_string($payload) || !hash_equals($old['hash'],hash('sha256',$prev."\n".$payload))) return false;
            $prev=$old['hash']; $seq++;
        }
    }
    $event=['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'actor'=>$actor,'action'=>$action,'name'=>$name];
    if ($newName!==null) $event['new_name']=$newName;
    $serialized=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($serialized)) return false;
    $record=json_encode(['seq'=>$seq,'prev'=>$prev,'event'=>$event,
        'hash'=>hash('sha256',$prev."\n".$serialized)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    if (!is_string($record)) return false;
    $handle=@fopen($file,'ab');
    if ($handle===false) return false;
    $written=@fwrite($handle,$record);
    $ok=$written===strlen($record) && @fflush($handle);
    if ($ok && function_exists('fsync')) $ok=@fsync($handle);
    fclose($handle);
    return $ok;
}

function fh09_operation(string $route, int $actor): never {
    if (!fh09_enabled()) respond(403,['error'=>'synthetic_ops_disabled']);
    if ($actor < 1) respond(403,['error'=>'invalid_actor']);
    if (($_SERVER['HTTP_ORIGIN']??'')!=='https://chinazub.amocrm.ru') respond(403,['error'=>'origin_required_for_write']);
    $data=$route==='upload'?$_POST:fh09_json_body();
    fh09_validate_lead($data['leadId']??null);
    if (($data['category']??null)!=='other') respond(403,['error'=>'synthetic_other_only']);
    [$root,$live,$trash]=fh09_dirs();
    $lock=@fopen($root.'/.lock','c');
    if ($lock===false || !flock($lock,LOCK_EX)) respond(503,['error'=>'lock_unavailable']);
    @chmod($root.'/.lock',0600);
    // All mutations serialized; audit chain checked again at commit.
    if ($route==='upload') {
        $upload=$_FILES['file']??null;
        if (!is_array($upload) || ($upload['error']??null)!==UPLOAD_ERR_OK) respond(400,['error'=>'invalid_upload']);
        $name=$upload['name']??null;
        $parsed=fh09_parse_name($name);
        if ($parsed===null || $parsed[1]!=='demo') respond(400,['error'=>'invalid_test_name']);
        $size=$upload['size']??null;
        if (!is_int($size) || $size<1 || $size>256) respond(413,['error'=>'test_file_too_large']);
        $tmp=$upload['tmp_name']??null;
        if (!is_string($tmp) || !is_uploaded_file($tmp)) respond(400,['error'=>'invalid_upload']);
        $content=@file_get_contents($tmp);
        if (!is_string($content) || strlen($content)!==$size || !hash_equals(FH09_MARKER.$parsed[0]."\n",$content)) {
            respond(400,['error'=>'only_generated_synthetic_content']);
        }
        if (count(fh09_items(false))+count(fh09_items(true))>=20) respond(429,['error'=>'synthetic_quota_exceeded']);
        if (file_exists($live.'/'.$name) || is_link($live.'/'.$name) || file_exists($trash.'/'.$name) || is_link($trash.'/'.$name)) respond(409,['error'=>'file_already_exists']);
        $stage=@tempnam($live,'.fh09-');
        if ($stage===false) respond(503,['error'=>'scratch_unavailable']);
        if (@file_put_contents($stage,$content,LOCK_EX)!==strlen($content)) { @unlink($stage); respond(503,['error'=>'scratch_write_failed']); }
        @chmod($stage,0600);
        if (!@rename($stage,$live.'/'.$name)) { @unlink($stage); respond(503,['error'=>'scratch_write_failed']); }
        if (!fh10_audit_append($root,$actor,'upload',$name)) { @unlink($live.'/'.$name); respond(503,['error'=>'audit_failed']); }
        respond(201,['ok'=>true,'name'=>$name]);
    }
    $name=$data['name']??null;
    $parts=fh09_parse_name($name);
    if ($parts===null) respond(400,['error'=>'invalid_test_name']);
    if ($route==='rename') {
        $new=$data['new_name']??null;
        $newParts=fh09_parse_name($new);
        if ($newParts===null || $newParts[0]!==$parts[0]) respond(400,['error'=>'invalid_new_name']);
        if ($new===$name) respond(400,['error'=>'same_name']);
        if (!is_file($live.'/'.$name) || is_link($live.'/'.$name)) respond(404,['error'=>'file_not_found']);
        if (file_exists($live.'/'.$new) || is_link($live.'/'.$new) || file_exists($trash.'/'.$new) || is_link($trash.'/'.$new)) respond(409,['error'=>'file_already_exists']);
        if (!@rename($live.'/'.$name,$live.'/'.$new)) respond(503,['error'=>'rename_failed']);
        if (!fh10_audit_append($root,$actor,'rename',$name,$new)) { @rename($live.'/'.$new,$live.'/'.$name); respond(503,['error'=>'audit_failed']); }
        respond(200,['ok'=>true,'name'=>$new]);
    }
    if ($route==='trash') {
        if (!is_file($live.'/'.$name) || is_link($live.'/'.$name)) respond(404,['error'=>'file_not_found']);
        if (file_exists($trash.'/'.$name) || is_link($trash.'/'.$name)) respond(409,['error'=>'trash_collision']);
        if (!@rename($live.'/'.$name,$trash.'/'.$name)) respond(503,['error'=>'trash_failed']);
        if (!fh10_audit_append($root,$actor,'trash',$name)) { @rename($trash.'/'.$name,$live.'/'.$name); respond(503,['error'=>'audit_failed']); }
        respond(200,['ok'=>true,'trashed'=>true]);
    }
    if ($route==='restore') {
        if (!is_file($trash.'/'.$name) || is_link($trash.'/'.$name)) respond(404,['error'=>'trash_file_not_found']);
        if (file_exists($live.'/'.$name) || is_link($live.'/'.$name)) respond(409,['error'=>'restore_collision']);
        if (!@rename($trash.'/'.$name,$live.'/'.$name)) respond(503,['error'=>'restore_failed']);
        if (!fh10_audit_append($root,$actor,'restore',$name)) { @rename($live.'/'.$name,$trash.'/'.$name); respond(503,['error'=>'audit_failed']); }
        respond(200,['ok'=>true,'restored'=>true]);
    }
    respond(405,['error'=>'method_not_allowed']);
}
