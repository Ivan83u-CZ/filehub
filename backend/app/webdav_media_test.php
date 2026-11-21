<?php
declare(strict_types=1);
// Authenticated media: synthetic local fixtures and restricted synthetic text.
// Stage09 synthetic-only ephemeral TXT media; signed capability was checked by api.php.
require_once __DIR__.'/fh09_scratch.php';
if ($route==='file' && ($_GET['category']??'')==='other'
    && fh09_parse_name($_GET['name']??null)!==null) {
    require_once __DIR__.'/webdav_test.php';
    fh08_require_lead((string)($_GET['leadId']??''));
    fh09_read_media((string)$_GET['name']);
}
// FH11: signed capabilities checked by api.php; read only exact synthetic Mail.ru TXT.
if ($route==='file' && ($_GET['category']??'')==='other') {
    require_once __DIR__.'/fh11_webdav.php';
    if (fh11_parse_name($_GET['name']??null)!==null) {
        if (($_GET['leadId']??null)!==FH11_LEAD) respond(403,['error'=>'fh11_lead_denied']);
        fh11_media((string)$_GET['name']);
    }
}
// New stage: read-only synthetic media fixture before any WebDAV access.
require_once __DIR__.'/test_media_catalog.php';
$testLead=$_GET['leadId']??'';
$testCat=$_GET['category']??'';
$testName=$_GET['name']??'';
if (is_string($testLead) && is_string($testCat) && is_string($testName)
    && fh_media_test_entry($testCat, $testName)!==null) {
    require_once __DIR__.'/webdav_test.php';
    fh08_require_lead($testLead);
    $entry=fh_media_test_entry($testCat, $testName);
    $fileName=$route==='thumb' ? $entry[1] : $testName;
    if (!is_string($fileName) || $fileName==='') respond(415,['error'=>'no_test_thumbnail']);
    // For thumbnails, only one predefined image miniature is served, no arbitrary resize.
    if ($route==='thumb' && ($testCat!=='photos' || ($_GET['w']??'')!=='260' || ($_GET['h']??'')!=='160')) {
        respond(400,['error'=>'invalid_thumb_request']);
    }
    $file=fh_media_test_path($fileName);
    $size=is_file($file) ? filesize($file) : false;
    if (!is_int($size) || $size<1 || $size>2097152 || !is_readable($file)) {
        respond(503,['error'=>'synthetic_fixture_missing']);
    }
    // Single-range GET, including suffix and open-ended forms. Never delegates to Mail.ru.
    $start=0; $end=$size-1;
    $range=$_SERVER['HTTP_RANGE']??'';
    if (!is_string($range)) respond(416,['error'=>'invalid_range']);
    if ($range!=='') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]==='' && $m[2]==='')) {
            header('Content-Range: bytes */'.$size);
            respond(416,['error'=>'invalid_range']);
        }
        if ($m[1]==='') {
            $suffix=(int)$m[2];
            if ($suffix<1) { header('Content-Range: bytes */'.$size); respond(416,['error'=>'invalid_range']); }
            $start=max(0,$size-$suffix);
        } else {
            $start=(int)$m[1];
            if ($m[2]!=='') $end=min($size-1,(int)$m[2]);
        }
        if ($start>=$size || $start>$end) {
            header('Content-Range: bytes */'.$size);
            respond(416,['error'=>'invalid_range']);
        }
    }
    $fh=@fopen($file,'rb');
    if ($fh===false) respond(503,['error'=>'synthetic_fixture_unreadable']);
    if (fseek($fh,$start,SEEK_SET)!==0) { fclose($fh); respond(503,['error'=>'synthetic_fixture_seek_failed']); }
    $mime=$route==='thumb' ? 'image/png' : $entry[0];
    header_remove('Content-Type');
    http_response_code($range!=='' ? 206 : 200);
    header('Content-Type: '.$mime);
    header('Content-Disposition: '.($route==='file' && ($_GET['download']??'')==='1' ? 'attachment' : 'inline').'; filename="'.$fileName.'"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.($end-$start+1));
    if ($range!=='') header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    $remaining=$end-$start+1;
    while ($remaining>0 && !feof($fh)) {
        $chunk=fread($fh,min(16384,$remaining));
        if (!is_string($chunk) || $chunk==='') break;
        echo $chunk;
        $remaining-=strlen($chunk);
    }
    fclose($fh);
    exit;
}
require_once __DIR__.'/webdav_test.php';
$lead=$_GET['leadId']??'';
$category=$_GET['category']??'';
$name=$_GET['name']??'';
if (!is_string($lead) || !preg_match('/^[1-9][0-9]{0,17}$/D',$lead)) respond(400,['error'=>'invalid_lead_id']);
fh08_require_lead($lead);
if ($route==='thumb') respond(403,['error'=>'test_thumbnails_disabled']);
if ($category==='other' && fh08_name($name)) $path=FH08_ROOT.'/4_other/'.$name;
elseif ($category==='photos' && $name==='filehub-test-ivan0082.txt') $path=FH08_ROOT.'/3_photos/filehub-test-ivan0082.txt';
else respond(403,['error'=>'synthetic_text_only']);
[$status,$data]=fh08_dav('GET',$path);
if ($status===404) respond(404,['error'=>'test_file_not_found']);
if ($status!==200 || !is_string($data) || strlen($data)>131072) respond(502,['error'=>'test_dav_read_failed','upstream_status'=>$status]);
header_remove('Content-Type');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.strlen($data));
header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.basename($path).'"');
http_response_code(200);
echo $data;
exit;
