<?php
declare(strict_types=1);
/** Lead ACL unit test; no amoCRM, WebDAV or Docker calls. */
putenv('FILEHUB_AMO_ACCOUNT_ID=12345678');
putenv('FILEHUB_LEAD_ACL_FILE');

// auth.php normally provides this in the API; the ACL unit runs in isolation.
function fh_cap_secret(): string { return 'test-only-cap-secret'; }

require_once __DIR__.'/../app/fh12_prod.php';
function fh_acl_t(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL: '.$message);}

$identity=['user_id'=>42,'account_id'=>'12345678','lead_id'=>'*'];
$exact=['user_id'=>42,'account_id'=>'12345678','lead_id'=>'99999999'];

// An exact lead-scoped session is sufficient and must not require the account-wide manifest.
putenv('FILEHUB_LEAD_ACL_MODE=enforce');
fh_acl_t(fh_lead_acl_check($exact,'99999999')===true,
    'exact lead session works when the account-wide manifest is absent');
$missing=false;
try { fh_lead_acl_check($identity,'99999999'); }
catch (RuntimeException $e) { $missing=$e->getMessage()==='lead_acl_unavailable'; }
fh_acl_t($missing,'account-wide enforce without manifest fails closed');

// A typo must not silently disable enforcement.
putenv('FILEHUB_LEAD_ACL_MODE=enforc');
$invalid=false;
try { fh_lead_acl_check($identity,'99999999'); }
catch (RuntimeException $e) { $invalid=$e->getMessage()==='lead_acl_mode_invalid'; }
fh_acl_t($invalid,'invalid ACL mode fails closed');

$file=tempnam(sys_get_temp_dir(),'fh-acl-');
if($file===false)throw new RuntimeException('temp file failed');
file_put_contents($file,json_encode([
    'version'=>1,
    'accounts'=>['12345678'=>['users'=>['42'=>['33942619']]]]
],JSON_THROW_ON_ERROR));
putenv('FILEHUB_LEAD_ACL_FILE='.$file);

putenv('FILEHUB_LEAD_ACL_MODE=shadow');
fh_acl_t(fh_lead_acl_check($identity,'33942619')===true,'shadow permits an allowed lead');
fh_acl_t(fh_lead_acl_check($identity,'99999999')===true,'shadow does not break availability');

putenv('FILEHUB_LEAD_ACL_MODE=enforce');
fh_acl_t(fh_lead_acl_check($identity,'33942619')===true,'enforce permits a manifest lead');
fh_acl_t(fh_lead_acl_check($identity,'99999999')===false,'enforce denies an unlisted lead');

// Permission edits must be visible to long-running PHP-FPM workers.
file_put_contents($file,json_encode([
    'version'=>1,
    'accounts'=>['12345678'=>['users'=>['42'=>['33942619','77777777']]]]
],JSON_THROW_ON_ERROR));
touch($file,time()+2);
clearstatcache(true,$file);
fh_acl_t(fh_lead_acl_check($identity,'77777777')===true,'replaced manifest is reloaded without PHP restart');

fh_acl_t(fh_lead_acl_check($exact,'99999999')===true,
    'non-account-wide session still authorizes its exact lead');
putenv('FILEHUB_LEAD_ACL_MODE=off');
fh_acl_t(fh_lead_acl_check($identity,'99999999')===true,'off preserves legacy behavior');

@unlink($file);
echo "PASS: lead ACL shadow/enforce/exact-session cases\n";
