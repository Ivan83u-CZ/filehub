<?php
declare(strict_types=1);
/**
 * FH12 server-side thumbnails.
 *
 * The /thumb route used to stream the ORIGINAL file through WebDAV and relied on CSS to
 * shrink it, so the client downloaded the full 10 MiB original for every thumbnail.
 * This module produces a real resized JPEG/PNG/WebP, at most 260x160, on the server.
 *
 * Design constraints:
 *  - The PHP-FPM container has no GD/Imagick, and adding one would mean recreating the
 *    container. The container keeps JTI markers and live sessions in a tmpfs, so a
 *    recreate would destroy them. Conversion therefore runs in a separate sidecar
 *    service on the compose network; this module never recreates the PHP container.
 *  - The thumb cache lives OUTSIDE the public web root and is bounded in bytes and in
 *    file count. The cache key contains the file identity and the file version; it
 *    never contains a token, a signature or a signed URL.
 *  - The original is never served in place of a thumbnail. Any failure ends with a
 *    placeholder image.
 */
const FH12_THUMB_W=260;                     // ширина миниатюры (закреплена подписью ссылки)
const FH12_THUMB_H=160;                     // высота миниатюры (закреплена подписью ссылки)
const FH12_THUMB_MAX_INPUT=20971520;        // 20 МиБ: предел размера исходного изображения
const FH12_THUMB_CACHE_DIR='/var/www/ops10/thumbs';        // вне web-root: наружу отдаётся только /var/www/html
const FH12_THUMB_CACHE_MAX_BYTES=268435456; // 256 МиБ: жёсткий предел объёма дискового кеша
const FH12_THUMB_CACHE_MAX_FILES=4000;      // жёсткий предел числа файлов кеша
const FH12_THUMB_CACHE_MAX_FILE=1048576;    // 1 МиБ: предел одного файла кеша
const FH12_THUMB_FRESH_S=30;
const FH12_THUMB_WORKER_URL='http://thumbnailer:8081/thumb'; // сервис конвертации во внутренней сети compose
const FH12_THUMB_TOKEN_FILE='.thumbd_token';// общий секрет приложения и конвертера (файл, не env)
const FH12_THUMB_TMP_MAX_AGE=3600;          // сек: осиротевшие временные файлы удаляются

/** Заглушка 260x160 PNG (1.2 КБ): нейтральная карточка вместо миниатюры. */
const FH12_THUMB_PLACEHOLDER_B64='iVBORw0KGgoAAAANSUhEUgAAAQQAAACgCAIAAABlkIURAAAEe0lEQVR42u3dMWocQRAFUN3/ao59gHHk0OFiHNhgJbvMjHZ3uruqux50/qCqvmBA0v/49n3zPO/f+zACz7sLw+33n97vx89f/98AC4d7iRsaBsvDZebGhcHycMm5QWGwPFx+bkQYLA83Bdc9DJaHm4XrGwbLw03EdQyD5eHm4nqFwfJw03FdwmB5uBm59mGwPNykXOMwWB5uXq5lGEwTNzXXLAymiZudaxMG08QtwDUIg2ni1uCuhsE0cctwl8JgmriVuPfDYJq4xbg3w2CauPW4d8JgmrgluZfDYJq4VbnXwmCauIW5F8Jgmri1uWfDYJq45bmnwmCauArc12EwTVwR7oswmCauDncWBtPEleIOw2CauGrcfhhME1eQ2wmDaeJqco9hME1cWe4uDKaJq8zthME0cTW5xzCYJq4sN7rTzfJwaTkFhzjcJ6fgEIdTcIjD3XMKDnE4BYc4nIJDHO6m4BCHO+EUHOJwncNgebjpOAWHOFy3MFgeblJOwSEO1yEMpombmlNwiMM1DYNp4hbgFBzicI3C0KQe4vn38AuGvR8ukBsfvOCCQ7eC6xGGKQsO3QqueRhmLTh0K7i2YZi44NCt4BqGYe6CQ7eCaxWG6QsO3QquSRhWKDh0K7jrYVik4NCt4C6GYZ2CQ7eCuxKGpQoO3Qru7TCsVnDoVnDvhWHBgkO3gnsjDGsWHLoV3KthWLbg0K3gXgrDygWHbgX3fBiqFBy6Fdx5GKoUHLoV3HkYqhQcuhXceRiqFBy6Fdx5GAoVHLoV3EkYahUcuhXcl9ywn9HBBYduBZckCfEFh24FlyQJ8QWHbgWXJAm38IJDt4I74qK+YMMKDt0K7oiL+oINKzh0K7gjrlzBoVvBHXHlCg7dCu6IK1dw6FZwR1y5gkO3gjviyhUcuhXcxf+btE7BoVvBDQjDHAWHbgXXOwzTFBy6FVzXMMxUcOhWcP3CoODQaS7CKTh0K7gt9gtWwSEuHafg0K3gttgvWAWHuHScgkO3gttiv2AVHOLScQoO3Qpui/2CVXCIS8cpOHQruC32C1bBIS4dp+DQreC22C9YBYe4dFy5gkMcLg+3HwbTxBXkdsJgmria3GMYTBNXlrsLg2niKnM7YTBNXE3uMQymiSvLdf+X9JaHm4UbHQbLw6XlhobB8nCZuXFhsDxccm5QGCwPl58bEQbLw03BdQ+D5eFm4YILDnG4PFxwwSEOl4cLLjjE4fJwwQWHOFweLrjgEIfLwwUXHOJwebjggkMcLg8XXHCIw+XhggsOcbg8XHDBIQ6XhwsuOMTh8nDBBYc4XB4uuOAQh8vDBRcc4nB5uOCCQxwuDxdccIjD5eGCCw5xuDxccMEhDpeHCy44xOHycMEFhzhcHi644BCHy8MFFxzicHm44IJDHC4Pp+AQhzsNg2niCnIKDnG4gzCYJq4sp+AQhzsOg2nianIKDnG4y3/pZpq4xTgFhzjcJ6fgEIdTcIjD3XMKDnE4BYc4nIJDHO6m4BCHO+EUHOJwe2HwPE8YPO/z/QVdlhivUw5W1QAAAABJRU5ErkJggg==';

/**
 * Формат миниатюры: 'jpg' | 'png' | 'webp' | null (миниатюра невозможна).
 * Дублирует fh12_thumb_format() из auth.php: модуль миниатюр должен работать и без
 * загруженного auth.php (например, в диагностическом сценарии).
 */
function fh12_thumb_fmt(?string $name,?string $contentType):?string {
    $ext=is_string($name)?strtolower((string)pathinfo($name,PATHINFO_EXTENSION)):'';
    $byExt=['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'jpg'];
    if($ext!==''&&isset($byExt[$ext]))return $byExt[$ext];
    $ct='';
    if(is_string($contentType)&&$contentType!=='')$ct=strtolower(trim(explode(';',$contentType)[0]));
    $byCt=['image/jpeg'=>'jpg','image/pjpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'jpg'];
    if($ct!==''&&isset($byCt[$ct]))return $byCt[$ct];
    return null;
}
/** Каталог кеша. В production путь фиксирован и не берётся из окружения. */
function fh12_thumb_cache_dir():string {
    if(fh12_test_mode()){
        $v=getenv('FH12_THUMB_CACHE_DIR');
        if(is_string($v)&&$v!==''&&!str_contains($v,'..')&&!str_contains($v,"\0"))return rtrim($v,'/');
    }
    return FH12_THUMB_CACHE_DIR;
}
function fh12_thumb_cache_ensure():void {
    $dir=fh12_thumb_cache_dir();
    if(is_dir($dir))return;
    if(!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('thumb_cache_unavailable');
    @chmod($dir,0700);
}
/**
 * Ключ кеша = HMAC(идентификатор файла + версия файла).
 * Идентификатор: лид + категория + имя. Версия: ETag либо Last-Modified + размер.
 * Токены, подписи, nonce и параметры подписанного URL в ключ НЕ входят.
 * Ключ подписи ссылок используется как соль, чтобы имя файла нельзя было подобрать
 * перебором по каталогу кеша.
 */
function fh12_thumb_cache_key(string $lead,string $cat,string $name,string $version):string {
    return hash_hmac('sha256',"fh-thumb-v1\n".$lead."\n".$cat."\n".$name."\n".$version,fh_cap_secret());
}
function fh12_thumb_cache_path(string $key,string $fmt):string {
    return fh12_thumb_cache_dir().'/'.$key.'.'.$fmt;
}
function fh12_thumb_cache_read(string $path):?string {
    if(!is_file($path)||!is_readable($path))return null;
    $sz=@filesize($path);
    if($sz===false||$sz<1||$sz>FH12_THUMB_CACHE_MAX_FILE)return null;
    $b=@file_get_contents($path);
    return (is_string($b)&&$b!=='')?$b:null;
}
/** Запись в кеш атомарно (временный файл + rename): читатель никогда не видит полузапись. */
function fh12_thumb_cache_store(string $path,string $bytes):void {
    if(strlen($bytes)<1||strlen($bytes)>FH12_THUMB_CACHE_MAX_FILE)throw new RuntimeException('thumb_output_invalid');
    $dir=fh12_thumb_cache_dir();
    $tmp=$dir.'/.tmp-'.bin2hex(random_bytes(8));
    if(@file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes)){@unlink($tmp);throw new RuntimeException('thumb_cache_write_failed');}
    @chmod($tmp,0640);
    if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('thumb_cache_write_failed');}
    fh12_thumb_cache_gc();
}
/**
 * Ограничение кеша: суммарный объём и число файлов. Вытесняются самые старые.
 * Временные и служебные файлы в подсчёт не входят.
 */
function fh12_thumb_cache_gc():void {
    $dir=fh12_thumb_cache_dir();
    $lock=@fopen($dir.'/.gc.lock','c');
    if($lock===false)return;
    if(!@flock($lock,LOCK_EX|LOCK_NB)){@fclose($lock);return;}
    try {
        $entries=@scandir($dir);
        if(!is_array($entries))return;
        $files=[];$bytes=0;$now=time();
        foreach($entries as $e){
            if($e==='.'||$e==='..')continue;
            $p=$dir.'/'.$e;
            if(str_starts_with($e,'.tmp-')||str_starts_with($e,'.part-')){
                $mt=@filemtime($p);
                if($mt!==false&&($now-$mt)>FH12_THUMB_TMP_MAX_AGE)@unlink($p); // осиротевший временный файл
                continue;
            }
            if($e[0]==='.')continue;
            if(!is_file($p))continue;
            $sz=@filesize($p);if($sz===false)$sz=0;
            $mt=@filemtime($p);if($mt===false)$mt=0;
            $files[]=['p'=>$p,'s'=>$sz,'m'=>$mt];
            $bytes+=$sz;
        }
        $count=count($files);
        if($count<=FH12_THUMB_CACHE_MAX_FILES&&$bytes<=FH12_THUMB_CACHE_MAX_BYTES)return;
        usort($files,static fn($a,$b)=>$a['m']<=>$b['m']);
        foreach($files as $f){
            if($count<=FH12_THUMB_CACHE_MAX_FILES&&$bytes<=FH12_THUMB_CACHE_MAX_BYTES)break;
            if(@unlink($f['p'])){$bytes-=$f['s'];$count--;}
        }
    } finally { @flock($lock,LOCK_UN); @fclose($lock); }
}
/** Адрес сервиса конвертации. Переопределение из окружения разрешено только для
 *  приватных адресов (docker-сеть, loopback): данные пациента не могут уйти наружу. */
function fh12_thumb_worker_url():string {
    $u=getenv('FILEHUB_THUMB_WORKER');
    if(!is_string($u)||$u==='')return FH12_THUMB_WORKER_URL;
    $p=parse_url($u);
    if(!is_array($p)||($p['scheme']??'')!=='http')throw new RuntimeException('thumb_worker_unavailable');
    $host=(string)($p['host']??'');
    $private=($host==='localhost'||$host==='127.0.0.1'||preg_match('/^10\./',$host)===1
        ||preg_match('/^192\.168\./',$host)===1||preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./',$host)===1);
    if(!$private)throw new RuntimeException('thumb_worker_unavailable');
    return $u;
}
/** Общий секрет приложения и сервиса конвертации (файл, не переменная окружения:
 *  переменная окружения потребовала бы пересоздания PHP-контейнера). */
function fh12_thumb_token():string {
    static $t=null;
    if($t!==null)return $t;
    $raw=@file_get_contents(fh12_thumb_cache_dir().'/'.FH12_THUMB_TOKEN_FILE);
    $t=is_string($raw)?trim($raw):'';
    return $t;
}
/**
 * Версия файла на Mail.ru. Запрашивается ОДИН байт (Range: bytes=0-0), поэтому
 * оригинал не скачивается. Версия: ETag, иначе Last-Modified + размер.
 * Возвращает null, если ответ не даёт пригодной версии (тогда кеш не используется).
 */
function fh12_thumb_probe(string $path):?array {
    if(!function_exists('curl_init'))return null;
    $u=fh_env_required('FILEHUB_DAV_USER');$p=fh_env_required('FILEHUB_DAV_PASS');
    $ch=curl_init(fh12_host().fh12_encode_path($path));
    if(!$ch)return null;
    $headers=[];
    $options=[
        CURLOPT_CUSTOMREQUEST=>'GET',
        CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode($u.':'.$p),'User-Agent: FileHub-FH12/1.0','Range: bytes=0-0'],
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_NOSIGNAL=>true,
        CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$headers):int {
            $headers[]=$line;return strlen($line);
        },
        // Если сервер проигнорирует Range и начнёт отдавать весь файл - обрываем сразу.
        CURLOPT_WRITEFUNCTION=>static function($h,string $chunk):int {return 0;},
    ];
    if(!curl_setopt_array($ch,$options)){curl_close($ch);return null;}
    @curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $etag='';$lastmod='';$len=null;$type='';
    foreach($headers as $line){
        if(preg_match('/^ETag:\s*(.+)$/i',$line,$m))$etag=trim($m[1]);
        elseif(preg_match('/^Last-Modified:\s*(.+)$/i',$line,$m))$lastmod=trim($m[1]);
        elseif(preg_match('/^Content-Length:\s*(\d+)/i',$line,$m))$len=(int)$m[1];
        elseif(preg_match('/^Content-Type:\s*([^;\r\n]+)/i',$line,$m))$type=trim($m[1]);
    }
    $all=implode("\n",$headers);
    if(preg_match('/^bytes\s+\d+-\d+\/(\d+)/mi',$all,$m))$len=(int)$m[1];
    $version='';
    if($etag!=='')$version='etag:'.$etag;
    elseif($lastmod!=='')$version='lm:'.$lastmod.'|len:'.($len??0);
    return ['version'=>$version,'length'=>$len,'type'=>$type,'status'=>$status];
}
/**
 * Версия файла по метаданным каталога (PROPFIND). Запасной источник версии: нужен,
 * когда GET не вернул ETag/Last-Modified. Метаданные каталога не меняют объём
 * загрузки оригинала. Каталог - тот же, в котором лежит файл.
 */
function fh12_thumb_version_meta(string $lead,string $cat,string $name):string {
    try{$items=fh12_list_dir(fh12_dir($lead,$cat));}
    catch(Throwable){return '';}
    $it=$items[$name]??null;
    if(!is_array($it))return '';
    $lm=(string)($it['lastmod']??'');
    $sz=$it['size']??null;
    if($lm===''&&$sz===null)return '';
    return 'meta:'.$lm.'|len:'.($sz??0);
}
/**
 * Скачивание оригинала во временный файл с жёстким пределом размера.
 * Оригинал не попадает в память PHP. Возвращает null при сбое транспорта.
 */
function fh12_thumb_download(string $path,string $tmpFile,int $max):?array {
    if(!function_exists('curl_init'))return null;
    $u=fh_env_required('FILEHUB_DAV_USER');$p=fh_env_required('FILEHUB_DAV_PASS');
    $ch=curl_init(fh12_host().fh12_encode_path($path));
    if(!$ch)return null;
    $fh=@fopen($tmpFile,'wb');
    if($fh===false){curl_close($ch);return null;}
    $total=0;$tooLarge=false;$headers=[];
    $options=[
        CURLOPT_CUSTOMREQUEST=>'GET',
        CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode($u.':'.$p),'User-Agent: FileHub-FH12/1.0'],
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_LOW_SPEED_LIMIT=>1024,
        CURLOPT_LOW_SPEED_TIME=>60,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
        CURLOPT_NOSIGNAL=>true,
        CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$headers):int {
            $headers[]=$line;return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use($fh,&$total,&$tooLarge,$max):int {
            $total+=strlen($chunk);
            if($total>$max){$tooLarge=true;return 0;} // обрыв: предел размера превышен
            $w=@fwrite($fh,$chunk);
            if($w===false)return 0;
            return $w;
        },
    ];
    if(!curl_setopt_array($ch,$options)){fclose($fh);curl_close($ch);return null;}
    @curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);
    if($tooLarge)return ['too_large'=>true,'status'=>$status,'bytes'=>$total,'version'=>'','type'=>''];
    if($status!==200)return null;
    if($total<1)return null;
    $etag='';$lastmod='';$type='';
    foreach($headers as $line){
        if(preg_match('/^ETag:\s*(.+)$/i',$line,$m))$etag=trim($m[1]);
        elseif(preg_match('/^Last-Modified:\s*(.+)$/i',$line,$m))$lastmod=trim($m[1]);
        elseif(preg_match('/^Content-Type:\s*([^;\r\n]+)/i',$line,$m))$type=trim($m[1]);
    }
    $version='';
    if($etag!=='')$version='etag:'.$etag;
    elseif($lastmod!=='')$version='lm:'.$lastmod.'|len:'.$total;
    return ['too_large'=>false,'status'=>$status,'bytes'=>$total,'version'=>$version,'type'=>$type];
}
/** Конвертация оригинала в миниатюру. Файл отправляется потоком: байты не копируются в память PHP. */
function fh12_thumb_convert(string $srcFile,string $fmt):?string {
    if(!function_exists('curl_init'))return null;
    $token=fh12_thumb_token();
    if($token==='')return null;
    $url=fh12_thumb_worker_url().'?w='.FH12_THUMB_W.'&h='.FH12_THUMB_H.'&fmt='.rawurlencode($fmt);
    $fh=@fopen($srcFile,'rb');
    if($fh===false)return null;
    $size=@filesize($srcFile);
    if($size===false||$size<1){fclose($fh);return null;}
    $ch=curl_init($url);
    if(!$ch){fclose($fh);return null;}
    $options=[
        CURLOPT_UPLOAD=>true,
        CURLOPT_CUSTOMREQUEST=>'POST',
        CURLOPT_INFILE=>$fh,
        CURLOPT_INFILESIZE=>$size,
        CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream','X-FH-Thumb-Token: '.$token,'Expect:'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>120,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_NOSIGNAL=>true,
    ];
    if(!curl_setopt_array($ch,$options)){fclose($fh);curl_close($ch);return null;}
    $body=@curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);
    if($status!==200||!is_string($body)||$body==='')return null;
    if(strlen($body)>FH12_THUMB_CACHE_MAX_FILE)return null;
    return $body;
}
/** Заглушка: показывается вместо миниатюры при неподдерживаемом формате и при ошибке
 *  обработки. Оригинал при этом НЕ скачивается и НЕ отдаётся клиенту. */
function fh12_thumb_placeholder():string {
    static $b=null;
    if($b!==null)return $b;
    $b=base64_decode(FH12_THUMB_PLACEHOLDER_B64,true);
    return is_string($b)&&$b!==''?$b:'';
}
/** Ответ с миниатюрой. */
function fh12_thumb_respond(string $bytes,string $fmt,string $status):never {
    $types=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    header_remove('Content-Type');
    http_response_code(200);
    header('Content-Type: '.($types[$fmt]??'image/png'));
    header('Content-Length: '.strlen($bytes));
    header('Content-Disposition: inline');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-FH-Thumb: '.$status);
    echo $bytes;
    exit;
}
/** Ответ с заглушкой. Клиент никогда не получает оригинал вместо миниатюры. */
function fh12_thumb_respond_placeholder(int $uid,string $lead,string $cat,string $name,string $reason):never {
    $bytes=fh12_thumb_placeholder();
    if($bytes===''){respond(502,['error'=>'upstream_or_storage_error']);}
    fh12_log('error',$uid,$lead,$cat,'thumb_placeholder',200,strlen($bytes),'',$name,$reason);
    header_remove('Content-Type');
    http_response_code(200);
    header('Content-Type: image/png');
    header('Content-Length: '.strlen($bytes));
    header('Content-Disposition: inline');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-FH-Thumb: placeholder-'.$reason);
    echo $bytes;
    exit;
}
/**
 * Основной путь миниатюры: версия файла -> кеш -> (при промахе) скачивание оригинала,
 * конвертация и запись в кеш. Повторный запрос отдаёт готовую миниатюру и НЕ скачивает
 * оригинал заново.
 */
function fh12_thumb_fresh_path(string $lead,string $cat,string $name):string {
    return fh12_thumb_cache_dir().'/'.fh12_thumb_cache_key($lead,$cat,$name,'fresh').'.fresh';
}
function fh12_thumb_invalidate(string $lead,string $cat,string $name):void {
    $path=fh12_thumb_fresh_path($lead,$cat,$name);
    @unlink($path);
}
function fh12_thumb_fresh_read(string $path):?array {
    clearstatcache(true,$path);
    $mtime=@filemtime($path);
    if($mtime===false||time()-$mtime>=FH12_THUMB_FRESH_S)return null;
    $reference=@file_get_contents($path,false,null,0,80);
    if(!is_string($reference)||!preg_match('/^([a-f0-9]{64})\.(jpg|png|webp)$/D',$reference,$m))return null;
    $bytes=fh12_thumb_cache_read(fh12_thumb_cache_path($m[1],$m[2]));
    return $bytes===null?null:[$bytes,$m[2]];
}
function fh12_serve_thumb(string $lead,string $cat,string $name):never {
    $uid=(int)($_GET['uid']??0);
    $fresh=fh12_thumb_fresh_path($lead,$cat,$name);
    $hit=fh12_thumb_fresh_read($fresh);
    if($hit!==null){
        fh12_log('read',$uid,$lead,$cat,'thumb_cached',200,strlen($hit[0]),'',$name);
        fh12_thumb_respond($hit[0],$hit[1],'cached');
    }
    try{fh12_thumb_cache_ensure();}
    catch(Throwable){fh12_thumb_respond_placeholder($uid,$lead,$cat,$name,'cache_unavailable');}
    // Fixed stripes bound disk use. FPM closes the lock on exit.
    $lock=@fopen(fh12_thumb_cache_dir().'/.gen-'.substr(basename($fresh),0,2),'c');
    if(!$lock)fh12_thumb_respond_placeholder($uid,$lead,$cat,$name,'cache_unavailable');
    for($attempt=0;!flock($lock,LOCK_EX|LOCK_NB);$attempt++){
        if($attempt===10){
            header('Retry-After: 1');
            respond(503,['error'=>'thumb_generation_busy']);
        }
        usleep(25000);
    }
    $hit=fh12_thumb_fresh_read($fresh);
    if($hit!==null){
        fh12_log('read',$uid,$lead,$cat,'thumb_cached',200,strlen($hit[0]),'',$name);
        fh12_thumb_respond($hit[0],$hit[1],'cached');
    }
    // Reserve the reference using its own inode. A concurrent mutation can unlink it;
    // this request will then be unable to publish a reference by writing to that inode.
    $reference=@fopen($fresh,'w');
    $path=fh12_file($lead,$cat,$name);
    $probe=fh12_thumb_probe($path);
    $status=$probe['status']??0;
    if($status===404)respond(404,['error'=>'file_missing']);
    if($status!==0&&$status!==200&&$status!==206)
        respond(502,['error'=>'webdav_media_unavailable']);
    $fmt=fh12_thumb_fmt($name,$probe['type']??null);
    if($fmt===null)fh12_thumb_respond_placeholder($uid,$lead,$cat,$name,'unsupported_format');
    $version=(string)($probe['version']??'');
    if($version===''){
        // GET не дал ETag/Last-Modified (или ответ не дошёл) - берём версию из
        // метаданных каталога. Это тоже метаданные: оригинал не перекачивается.
        $version=fh12_thumb_version_meta($lead,$cat,$name);
    }
    $len=$probe['length']??null;
    if(is_int($len)&&$len>FH12_THUMB_MAX_INPUT)
        fh12_thumb_respond_placeholder($uid,$lead,$cat,$name,'source_too_large');
    $key='';
    if($version!==''){
        try{$key=fh12_thumb_cache_key($lead,$cat,$name,$version);}catch(Throwable){$key='';}
    }
    if($key!==''){
        $cached=fh12_thumb_cache_read(fh12_thumb_cache_path($key,$fmt));
        if($cached!==null){
            if($reference)fwrite($reference,$key.'.'.$fmt);
            fh12_log('read',$uid,$lead,$cat,'thumb_cached',200,strlen($cached),'',$name);
            fh12_thumb_respond($cached,$fmt,'cached');
        }
    }
    $tmp=@tempnam(fh12_thumb_cache_dir(),'.part-');
    if($tmp===false)fh12_thumb_respond_placeholder($uid,$lead,$cat,$name,'cache_unavailable');
    $out=null;$reason='';
    $dl=fh12_thumb_download($path,$tmp,FH12_THUMB_MAX_INPUT);
    if($dl===null)$reason='download_failed';
    elseif($dl['too_large'])$reason='source_too_large';
    else{
        $bytes=fh12_thumb_convert($tmp,$fmt);
        if($bytes===null)$reason='conversion_failed';
        else{
            $out=$bytes;
            // Версия могла прийти из заголовков самого GET (если проба Range не дала ETag).
            $ver=(string)($dl['version']??'');
            if($ver==='')$ver=$version;
            if($ver!==''){
                try{
                    $k=fh12_thumb_cache_key($lead,$cat,$name,$ver);
                    fh12_thumb_cache_store(fh12_thumb_cache_path($k,$fmt),$bytes);
                    if($reference)fwrite($reference,$k.'.'.$fmt);
                }catch(Throwable){/* кеш недоступен - отдаём миниатюру без записи */}
            }
        }
    }
    @unlink($tmp); // временный оригинал удаляется ДО ответа: exit не выполняет finally
    if($out===null)fh12_thumb_respond_placeholder($uid,$lead,$cat,$name,$reason!==''?$reason:'conversion_failed');
    fh12_log('read',$uid,$lead,$cat,'thumb_generated',200,strlen($out),'',$name);
    fh12_thumb_respond($out,$fmt,'generated');
}
