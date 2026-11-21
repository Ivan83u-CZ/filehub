<?php
declare(strict_types=1);
// Manual CLI-only test. Never contacted Mail.ru, no credentials used.
function respond(int $status, array $body): never {
    throw new RuntimeException('REJECTED:'.$status.':'.($body['error']??''));
}
require_once __DIR__.'/webdav_test.php';
putenv('FILEHUB_DAV_MODE=test_crm_ivan0082');
putenv('FILEHUB_TEST_LEAD_ID=900000000001');
putenv('FILEHUB_STAGE08_WRITE_ACK=I_AUTHORIZE_STAGE08_IVAN0082_ONLY');
$bad = [
 ['DELETE','/CRM'],
 ['DELETE','/CRM/ivan0082'],
 ['DELETE','/CRM/34186815/4_other/fh08-0123456789abcdef-a.txt'],
 ['PUT','/CRM/34186815/4_other/fh08-0123456789abcdef-a.txt'],
 ['PUT','/CRM/ivan0082/3_photos/fh08-0123456789abcdef-a.txt'],
 ['DELETE','/CRM/ivan0082/3_photos/filehub-test-ivan0082.txt'],
 ['DELETE','/CRM/ivan0082/4_other/summary.json'],
 ['DELETE','/CRM/ivan0082/4_other/../3_photos/filehub-test-ivan0082.txt'],
 ['MKCOL','/CRM/ivan0082/4_other/new'],
 ['MOVE','/CRM/ivan0082/4_other/fh08-0123456789abcdef-a.txt',[
     'Destination: '.FH08_HOST.'/CRM/34186815/4_other/fh08-0123456789abcdef-b.txt',
     'Overwrite: F',
 ]],
 ['MOVE','/CRM/ivan0082/4_other/fh08-0123456789abcdef-a.txt',[
     'Destination: '.FH08_HOST.'/CRM/ivan0082/4_other/summary.json',
     'Overwrite: F',
 ]],
 ['GET','/CRM/34186815/3_photos/patient.jpg'],
 ['PROPFIND','/CRM/34186815/3_photos'],
];
foreach($bad as $case) {
    try {
        fh08_dav($case[0],$case[1],$case[2]??[], $case[0]==='PUT'?'x':null);
        throw new RuntimeException('FAIL: accepted disallowed '.$case[0].' '.$case[1]);
    } catch(RuntimeException $e) {
        if (!str_starts_with($e->getMessage(),'REJECTED:')) throw $e;
    }
}
foreach (['../x.txt','patient.pdf','fh08-0123456789abcdef-x.txt', 'fh08-0123456789abcdef-a.txt/evil'] as $badName) {
    if (fh08_name($badName)) throw new RuntimeException('FAIL: invalid name accepted');
}
foreach (['fh08-0123456789abcdef-a.txt','fh08-0123456789abcdef-b.txt'] as $good) {
    if (!fh08_name($good)) throw new RuntimeException('FAIL: valid name rejected');
}
try { fh08_require_lead('34186815'); throw new RuntimeException('FAIL: real lead accepted'); }
catch (RuntimeException $e) { if (!str_starts_with($e->getMessage(),'REJECTED:')) throw $e; }
try { fh08_require_lead('33570433'); throw new RuntimeException('FAIL: real lead accepted'); }
catch (RuntimeException $e) { if (!str_starts_with($e->getMessage(),'REJECTED:')) throw $e; }
echo "PASS: 13 blocked DAV paths, dangerous methods and destinations; 6 name checks; 2 real lead guards. OFFLINE ONLY.\n";
