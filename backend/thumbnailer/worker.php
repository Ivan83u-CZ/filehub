<?php
declare(strict_types=1);
/**
 * FileHub thumbnail conversion worker.
 *
 * Internal-only service. It lives on the compose network, publishes no host port and
 * holds no credentials: it never talks to Mail.ru and never sees the WebDAV password.
 * The main application streams the original bytes here and gets a resized image back.
 *
 * Why a separate service instead of GD inside the PHP-FPM container: the production
 * PHP image has no GD/Imagick, and adding one means rebuilding and recreating the
 * PHP container. The PHP container keeps its JTI markers and live sessions in a tmpfs,
 * so a recreate would destroy them. A sidecar keeps the fix off the critical path.
 *
 * Limits enforced here (defence in depth - the caller enforces the same bounds):
 *   - input body        : max 20 MiB
 *   - pixels after decode: max 16 MP (decompression-bomb guard, checked on the header
 *                           only, BEFORE any pixel buffer is allocated)
 *   - single side       : max 12000 px
 *   - output            : max 1 MiB
 */
const TW_MAX_INPUT=20971520;      // 20 MiB
const TW_MAX_PIXELS=16000000;     // 16 MP
const TW_MAX_SIDE=12000;          // px
const TW_MAX_OUT=1048576;         // 1 MiB
const TW_MAX_DIM=1024;            // requested box, px

function tw_fail(int $status,string $code):never {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error'=>$code]);
    exit;
}

/** Shared secret between the application and the worker (file, not env: the PHP container
 *  must not be recreated, and an env change would force exactly that). */
function tw_token():string {
    static $t=null;
    if($t!==null)return $t;
    $f=getenv('FILEHUB_THUMB_TOKEN_FILE');
    if(!is_string($f)||$f==='')$f='/var/www/thumbs/.thumbd_token';
    $raw=@file_get_contents($f);
    if(!is_string($raw)||$raw==='')$raw='';
    $t=trim($raw);
    return $t;
}

$method=$_SERVER['REQUEST_METHOD']??'';
if($method!=='POST')tw_fail(405,'method_denied');
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
if($path!=='/thumb')tw_fail(404,'not_found');

$expected=tw_token();
if($expected==='')tw_fail(503,'worker_misconfigured');
$sent=(string)($_SERVER['HTTP_X_FH_THUMB_TOKEN']??'');
if($sent===''||!hash_equals($expected,$sent))tw_fail(403,'worker_token_rejected');

$w=(int)($_GET['w']??260);
$h=(int)($_GET['h']??160);
$fmt=(string)($_GET['fmt']??'jpg');
if($w<1||$w>TW_MAX_DIM||$h<1||$h>TW_MAX_DIM)tw_fail(400,'invalid_size');
if(!in_array($fmt,['jpg','png','webp'],true))tw_fail(400,'invalid_format');

$declared=(int)($_SERVER['CONTENT_LENGTH']??0);
if($declared<1)tw_fail(400,'empty_body');
if($declared>TW_MAX_INPUT)tw_fail(413,'input_too_large');

// The body goes to disk first: a 20 MiB original must never be held in memory twice.
$in=@fopen('php://input','rb');
if(!$in)tw_fail(400,'body_unreadable');
$tmp=tempnam(sys_get_temp_dir(),'tw');
if($tmp===false){fclose($in);tw_fail(500,'temp_unavailable');}
$out=@fopen($tmp,'wb');
if(!$out){fclose($in);@unlink($tmp);tw_fail(500,'temp_unavailable');}
$total=stream_copy_to_stream($in,$out,TW_MAX_INPUT+1);
fclose($in);fclose($out);
if($total===false||$total<1){@unlink($tmp);tw_fail(400,'body_unreadable');}
if($total>TW_MAX_INPUT){@unlink($tmp);tw_fail(413,'input_too_large');}

// Header-only probe: no pixel buffer is allocated here, so a hostile header cannot
// make this process allocate memory before the bomb check has run.
$info=@getimagesize($tmp);
if(!is_array($info)||!isset($info[0],$info[1],$info[2])){@unlink($tmp);tw_fail(415,'unsupported_format');}
$sw=(int)$info[0];$sh=(int)$info[1];$type=(int)$info[2];
if($sw<1||$sh<1){@unlink($tmp);tw_fail(415,'unsupported_format');}
if($sw>TW_MAX_SIDE||$sh>TW_MAX_SIDE){@unlink($tmp);tw_fail(413,'image_too_large');}
if($sw*$sh>TW_MAX_PIXELS){@unlink($tmp);tw_fail(413,'image_too_large');}

$img=false;
switch($type){
    case IMAGETYPE_JPEG:$img=@imagecreatefromjpeg($tmp);break;
    case IMAGETYPE_PNG:$img=@imagecreatefrompng($tmp);break;
    case IMAGETYPE_WEBP:$img=@imagecreatefromwebp($tmp);break;
    case IMAGETYPE_GIF:$img=@imagecreatefromgif($tmp);break;
    default:@unlink($tmp);tw_fail(415,'unsupported_format');
}
@unlink($tmp);
if($img===false)tw_fail(415,'decode_failed');

// Fit inside the box, preserve the aspect ratio, never upscale.
$ratio=min($w/(float)$sw,$h/(float)$sh,1.0);
$nw=max(1,(int)round($sw*$ratio));
$nh=max(1,(int)round($sh*$ratio));

$dst=imagecreatetruecolor($nw,$nh);
if($dst===false)tw_fail(500,'canvas_failed');
imagealphablending($dst,false);
imagesavealpha($dst,true);
$transparent=imagecolorallocatealpha($dst,0,0,0,127);
imagefilledrectangle($dst,0,0,$nw-1,$nh-1,$transparent);
imagecopyresampled($dst,$img,0,0,0,0,$nw,$nh,$sw,$sh);
imagedestroy($img);

$outImg=$dst;
if($fmt==='jpg'){
    // JPEG has no alpha: flatten onto white so transparent PNGs do not turn black.
    $flat=imagecreatetruecolor($nw,$nh);
    if($flat===false)tw_fail(500,'canvas_failed');
    $white=imagecolorallocate($flat,255,255,255);
    imagefilledrectangle($flat,0,0,$nw-1,$nh-1,$white);
    imagealphablending($flat,true);
    imagecopy($flat,$dst,0,0,0,0,$nw,$nh);
    imagedestroy($dst);
    $outImg=$flat;
}

ob_start();
$ok=false;
if($fmt==='jpg'){$ok=imagejpeg($outImg,null,82);}
elseif($fmt==='png'){imagesavealpha($outImg,true);$ok=imagepng($outImg,null,6);}
else{$ok=imagewebp($outImg,null,80);}
$bytes=ob_get_clean();
imagedestroy($outImg);
if($ok!==true||!is_string($bytes)||$bytes==='')tw_fail(500,'encode_failed');
if(strlen($bytes)>TW_MAX_OUT)tw_fail(500,'output_too_large');

$types=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
http_response_code(200);
header('Content-Type: '.$types[$fmt]);
header('Content-Length: '.strlen($bytes));
header('Cache-Control: no-store');
header('X-Src-Width: '.$sw);
header('X-Src-Height: '.$sh);
header('X-Out-Width: '.$nw);
header('X-Out-Height: '.$nh);
header('X-Image-Format: '.$fmt);
echo $bytes;
