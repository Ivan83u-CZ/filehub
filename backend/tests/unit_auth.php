<?php
declare(strict_types=1);
require __DIR__.'/../app/auth.php';
function t(bool $b,string $name): void { if (!$b) {fwrite(STDERR,"FAIL: $name\n");exit(1);} echo "PASS: $name\n"; }
function mint(array $p,string $key,string $alg='HS256'): string {
 $head=fh_b64url_encode(json_encode(['alg'=>$alg,'typ'=>'JWT']));
 $body=fh_b64url_encode(json_encode($p));
 return "$head.$body.".fh_b64url_encode(hash_hmac('sha256',"$head.$body",$key,true));
}
function rejects(callable $test): bool {try{$test();return false;}catch(Throwable){return true;}}
$secret='only-for-unit-testing-value-very-long-not-real';
putenv('FILEHUB_AMO_CLIENT_SECRET='.$secret);
putenv('FILEHUB_AMO_ACCOUNT_ID=12345678');
putenv('FILEHUB_AMO_CLIENT_UUID=0b0832f6-d123-4123-9123-e73f2368330c');
putenv('FILEHUB_AMO_ISSUER=https://chinazub.amocrm.ru');
putenv('FILEHUB_AMO_AUDIENCE=https://filehub.example.test');
putenv('FILEHUB_LINK_HMAC_KEY='.str_repeat('a3',32));
putenv('FILEHUB_PUBLIC_API_URL=https://filehub.example.test/wp-json/czfh/v1');
$dir=sys_get_temp_dir().'/filehub-jti-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);putenv('FILEHUB_JTI_DIR='.$dir);
$now=time();
$c=['iss'=>'https://chinazub.amocrm.ru','aud'=>'https://filehub.example.test','iat'=>$now-2,'nbf'=>$now-2,'exp'=>$now+120,'account_id'=>12345678,'user_id'=>1111,'client_uuid'=>'0b0832f6-d123-4123-9123-e73f2368330c','jti'=>'d628f123-5123-473e-a123-ed123ef31f8f'];
$jwt=mint($c,$secret);
t(fh_jwt_validate($jwt)['user_id']===1111,'valid amoCRM JWT');
t(rejects(fn()=>fh_jwt_validate(substr($jwt,0,-2).'XX')),'tampered JWT rejected');
$bad=$c;$bad['account_id']=99;
t(rejects(fn()=>fh_jwt_validate(mint($bad,$secret))),'foreign account rejected');
$bad=$c;$bad['client_uuid']='wrong';
t(rejects(fn()=>fh_jwt_validate(mint($bad,$secret))),'other integration rejected');
$bad=$c;$bad['aud']='https://attacker.example';
t(rejects(fn()=>fh_jwt_validate(mint($bad,$secret))),'foreign audience rejected');
$bad=$c;$bad['iss']='https://other.amocrm.ru';
t(rejects(fn()=>fh_jwt_validate(mint($bad,$secret))),'foreign issuer rejected');
$bad=$c;$bad['exp']=$now-120;
t(rejects(fn()=>fh_jwt_validate(mint($bad,$secret))),'expired token rejected');
$bad=$c;$bad['exp']=$now+7200;
t(rejects(fn()=>fh_jwt_validate(mint($bad,$secret))),'excessive JWT lifetime rejected');
t(rejects(fn()=>fh_jwt_validate(mint($c,$secret,'none'))),'JWT alg none rejected');
t(fh_authenticated_user($jwt)['user_id']===1111,'first jti accepted');
t(rejects(fn()=>fh_authenticated_user($jwt)),'jti replay denied');
$q=fh_cap_params('file','34228264','photos','filehub-test-ivan0082.txt',1111,'view',$now);
t(fh_cap_valid('file',$q,$now),'signed media works');
$q2=$q;$q2['name']='other.txt';t(!fh_cap_valid('file',$q2,$now),'file swap blocked');
$q2=$q;$q2['download']='1';t(!fh_cap_valid('file',$q2,$now),'download escalation blocked');
t(!fh_cap_valid('thumb',$q,$now),'route swap blocked');
t(!fh_cap_valid('file',$q,$now+601),'expired media denied');
$q2=fh_cap_params('file','34228264','photos','filehub-test-ivan0082.txt',1111,'download',$now);
t(fh_cap_valid('file',$q2,$now) && $q2['download']==='1','separate signed download');
$item=fh_attach_links(['name'=>'filehub-test-ivan0082.txt','contentType'=>'text/plain'],'34228264','photos',1111);
t(str_starts_with($item['url'],'https://filehub.example.test/'),'link emitted with HTTPS');
t(str_contains($item['downloadUrl'],'download=1'),'download URL bound');
t(rejects(fn()=>fh_cap_params('file','34228264','photos','../secret',1111)),'path traversal rejected');
foreach(glob($dir.'/*') as $f) @unlink($f); @rmdir($dir);
echo "PASS: RC02 auth tests complete; no real amoCRM or Mail.ru requests.\n";
