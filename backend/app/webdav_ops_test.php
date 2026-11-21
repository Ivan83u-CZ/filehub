<?php
declare(strict_types=1);
require_once __DIR__.'/webdav_test.php';
fh08_verify_write();
$payload=[];
$lead=(string)($_POST['leadId'] ?? $_GET['leadId'] ?? '');
$category=(string)($_POST['category'] ?? $_GET['category'] ?? '');
if ($route==='rename') {
    $raw=file_get_contents('php://input');
    $payload=is_string($raw)?json_decode($raw,true):null;
    if (!is_array($payload)) respond(400,['error'=>'invalid_json']);
    $lead=(string)($payload['leadId']??'');
    $category=(string)($payload['category']??'');
}
if (!preg_match('/^[1-9][0-9]{0,17}$/D',$lead)) respond(400,['error'=>'invalid_lead_id']);
fh08_require_lead($lead);
if ($category!=='other') respond(403,['error'=>'test_write_category_only']);
$prefix=FH08_ROOT.'/4_other/';
if ($route==='upload') {
    $upload=$_FILES['file']??null;
    if (!is_array($upload)) respond(400,['error'=>'no_file']);
    $name=$upload['name']??null;
    if (!fh08_name($name)) respond(400,['error'=>'invalid_test_name']);
    if (($upload['error']??null)!==UPLOAD_ERR_OK) respond(400,['error'=>'upload_failed']);
    $size=$upload['size']??null;
    if (!is_int($size) || $size<1 || $size>131072) respond(413,['error'=>'test_file_too_large']);
    $tmp=$upload['tmp_name']??null;
    if (!is_string($tmp) || !is_uploaded_file($tmp)) respond(400,['error'=>'invalid_upload']);
    $body=@file_get_contents($tmp);
    if (!is_string($body) || strlen($body)!==$size) respond(503,['error'=>'failed_to_read_upload']);
    // Guard against overwriting an existing file. If-None-Match is second defense.
    [$prior,] = fh08_dav('GET',$prefix.$name);
    if ($prior===200) respond(409,['error'=>'file_already_exists']);
    if ($prior!==404) respond(502,['error'=>'dav_preflight_failed','upstream_status'=>$prior]);
    [$status,] = fh08_dav('PUT',$prefix.$name,['Content-Type: text/plain; charset=utf-8','If-None-Match: *'],$body);
    if (in_array($status,[409,412],true)) respond(409,['error'=>'file_already_exists']);
    if (!in_array($status,[200,201,204],true)) respond(502,['error'=>'dav_put_failed','upstream_status'=>$status]);
    respond(200,['ok'=>true]);
}
if ($route==='rename') {
    $old=$payload['name']??null; $new=$payload['new_name']??null;
    if (!fh08_name($old) || !fh08_name($new)) respond(400,['error'=>'invalid_test_name']);
    if ($old===$new) respond(400,['error'=>'same_name']);
    [$destStatus,]=fh08_dav('GET',$prefix.$new);
    if ($destStatus===200) respond(409,['error'=>'file_already_exists']);
    if ($destStatus!==404) respond(502,['error'=>'dav_preflight_failed','upstream_status'=>$destStatus]);
    [$status,]=fh08_dav('MOVE',$prefix.$old,[
        'Destination: '.FH08_HOST.$prefix.$new,
        'Overwrite: F',
    ]);
    if ($status===404) respond(404,['error'=>'file_not_found']);
    if (in_array($status,[409,412],true)) respond(409,['error'=>'file_already_exists']);
    if (!in_array($status,[200,201,204],true)) respond(502,['error'=>'dav_move_failed','upstream_status'=>$status]);
    respond(200,['ok'=>true]);
}
if ($route==='file' && $method==='DELETE') {
    $name=$_GET['name']??null;
    if (!fh08_name($name)) respond(400,['error'=>'invalid_test_name']);
    [$status,]=fh08_dav('DELETE',$prefix.$name);
    if ($status===404) respond(404,['error'=>'file_not_found']);
    if (!in_array($status,[200,204],true)) respond(502,['error'=>'dav_delete_failed','upstream_status'=>$status]);
    respond(200,['ok'=>true]);
}
respond(503,['error'=>'test_operation_not_supported']);
