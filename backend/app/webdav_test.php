<?php
declare(strict_types=1);
// Central remote I/O boundary for Stage08. Host and path root are compile-time fixed.
const FH08_HOST = 'https://webdav.cloud.mail.ru';
const FH08_ROOT = '/CRM/ivan0082';
const FH08_LEAD = '34228264';
const FH08_WRITE_ACK = 'I_AUTHORIZE_STAGE08_IVAN0082_ONLY';
const FH08_DIRS = ['passport'=>'1_passport','tickets'=>'2_tickets','photos'=>'3_photos',
                   'other'=>'4_other','calls'=>'5_calls','report'=>'6_Otchet','ai'=>'7_AI'];
function fh08_require_lead(string $lead): void {
    if ($lead !== FH08_LEAD || getenv('FILEHUB_TEST_LEAD_ID') !== FH08_LEAD) {
        respond(403, ['error'=>'test_lead_only']);
    }
}
function fh08_name(mixed $v): bool {
    return is_string($v) && preg_match('/^fh08-[a-f0-9]{16}-(?:a|b)\.txt$/D', $v) === 1;
}
function fh08_verify_write(): void {
    if (getenv('FILEHUB_DAV_MODE') !== 'test_crm_ivan0082'
        || getenv('FILEHUB_STAGE08_WRITE_ACK') !== FH08_WRITE_ACK) {
        respond(503, ['error'=>'test_writes_not_authorized']);
    }
}
function fh08_dav(string $method, string $path, array $extra=[], ?string $body=null): array {
    if (getenv('FILEHUB_DAV_MODE') !== 'test_crm_ivan0082'
        || getenv('FILEHUB_TEST_LEAD_ID') !== FH08_LEAD) {
        respond(503, ['error'=>'test_mode_not_configured']);
    }
    $allowedFolders = array_map(static fn($d)=>FH08_ROOT.'/'.$d, array_values(FH08_DIRS));
    $isFolder = in_array($path,$allowedFolders,true);
    $rel = substr($path,strlen(FH08_ROOT.'/4_other/'));
    $testFile = str_starts_with($path,FH08_ROOT.'/4_other/') && fh08_name($rel);
    $sample = ($path === FH08_ROOT.'/3_photos/filehub-test-ivan0082.txt');
    // Writing is permanently restricted to synthetic fh08-*.txt in 4_other.
    if ($method === 'PROPFIND') {
        if (!$isFolder || $body === null || strlen($body)>4096) respond(403,['error'=>'dav_path_rejected']);
    } elseif ($method === 'GET') {
        if (!$sample && !$testFile) respond(403,['error'=>'dav_path_rejected']);
    } elseif (in_array($method,['PUT','MOVE','DELETE'],true)) {
        fh08_verify_write();
        if (!$testFile) respond(403,['error'=>'dav_write_scope_rejected']);
        if ($method === 'PUT' && ($body === null || strlen($body)<1 || strlen($body)>131072)) respond(413,['error'=>'test_file_too_large']);
        if ($method === 'MOVE') {
            $dst = 'Destination: '.FH08_HOST.FH08_ROOT.'/4_other/';
            $dest = array_values(array_filter($extra,static fn($h)=>str_starts_with($h,'Destination: ')));
            if (count($dest)!==1 || !str_starts_with($dest[0],$dst) || !fh08_name(substr($dest[0],strlen($dst)))
                || !in_array('Overwrite: F',$extra,true)) respond(403,['error'=>'dav_move_destination_rejected']);
        }
    } else {
        respond(403,['error'=>'dav_method_rejected']);
    }
    $user=getenv('FILEHUB_DAV_USER') ?: '';
    $pass=getenv('FILEHUB_DAV_PASS') ?: '';
    if (!is_string($user) || !str_contains($user,'@') || !is_string($pass) || $pass==='') {
        respond(503,['error'=>'dav_credentials_missing']);
    }
    $headers=array_merge([
        'Authorization: Basic '.base64_encode($user.':'.$pass),
        'User-Agent: FileHubUnifiedRC01-IVAN0082/1.0',
    ], $extra);
    if ($body!==null) $headers[]='Content-Length: '.strlen($body);
    $opts=['http'=>[
        'method'=>$method, 'header'=>implode("\r\n",$headers)."\r\n", 'timeout'=>20,
        'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1,
    ], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]];
    if ($body!==null) $opts['http']['content']=$body;
    $ctx=stream_context_create($opts);
    $ret=@file_get_contents(FH08_HOST.$path,false,$ctx,0,1048577);
    $httpHeaders=$http_response_header ?? [];
    $status=0;
    foreach($httpHeaders as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})\b~i',$h,$m)) $status=(int)$m[1];
    }
    if ($ret===false) return [$status,null];
    if (strlen($ret)>1048576) return [502,null];
    return [$status,$ret];
}
