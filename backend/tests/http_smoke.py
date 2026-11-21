#!/usr/bin/env python3
"""Local PHP built-in HTTP smoke; all credentials fake and no WebDAV writes."""
import base64, hashlib, hmac, json, os, pathlib, socket, subprocess, time, urllib.error, urllib.request, uuid
root=pathlib.Path(__file__).resolve().parent.parent
secret='test-only-amo-secret-not-a-real-credential'
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
jdir=root/'tests'/'_tmp_jti'
jdir.mkdir(exist_ok=True)
base_env={**os.environ,'FILEHUB_DAV_MODE':'test_crm_ivan0082','FILEHUB_AMO_CLIENT_SECRET':secret,'FILEHUB_AMO_ISSUER':'https://chinazub.amocrm.ru','FILEHUB_AMO_AUDIENCE':'https://filehub.example.test','FILEHUB_AMO_ACCOUNT_ID':'12345678','FILEHUB_AMO_CLIENT_UUID':'0b0832f6-d123-4123-9123-e73f2368330c','FILEHUB_JTI_DIR':str(jdir),'FILEHUB_LINK_HMAC_KEY':'a3'*32,'FILEHUB_PUBLIC_API_URL':'https://filehub.example.test/wp-json/czfh/v1'}
proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'app'),str(root/'tests'/'router.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=base_env)
def url64(data):return base64.urlsafe_b64encode(data).rstrip(b'=').decode()
def jwt(account=12345678,secret_arg=secret,exp_add=120):
    now=int(time.time()); h=url64(b'{"alg":"HS256","typ":"JWT"}')
    body={'iss':'https://chinazub.amocrm.ru','aud':'https://filehub.example.test','iat':now,'nbf':now,'exp':now+exp_add,'account_id':account,'user_id':324,'client_uuid':'0b0832f6-d123-4123-9123-e73f2368330c','jti':str(uuid.uuid4())}
    p=url64(json.dumps(body,separators=(',',':')).encode()); return f'{h}.{p}.'+url64(hmac.new(secret_arg.encode(),f'{h}.{p}'.encode(),hashlib.sha256).digest())
def req(path, token=None):
    headers={'X-Auth-Token':token} if token else {}
    r=urllib.request.Request(f'http://127.0.0.1:{port}'+path,headers=headers)
    try:
        with urllib.request.urlopen(r,timeout=5) as res:return res.status
    except urllib.error.HTTPError as e:return e.code
try:
    time.sleep(.3)
    tests=[('health',req('/_health'),200),('missing token',req('/wp-json/czfh/v1/ping'),401)]
    same=jwt();tests.append(('valid JWT',req('/wp-json/czfh/v1/ping',same),200))
    tests.append(('replay',req('/wp-json/czfh/v1/ping',same),401))
    tests.append(('bad signature',req('/wp-json/czfh/v1/ping',jwt(secret_arg='fake')),401))
    tests.append(('foreign account',req('/wp-json/czfh/v1/ping',jwt(account=999)),401))
    tests.append(('real lead denied',req('/wp-json/czfh/v1/files?leadId=34186815&category=photos',jwt()),403))
    tests.append(('unsigned media denied',req('/wp-json/czfh/v1/file?leadId=34228264&category=photos&name=filehub-test-ivan0082.txt'),403))
    for name,got,expected in tests:
        print(f'{name:24} HTTP {got} (expected {expected})')
        if got!=expected: raise RuntimeError('FAIL '+name)
    print('PASS: RC02 HTTP auth smoke; no Mail.ru requests / writes.')
finally:
    proc.terminate()
    try:proc.wait(timeout=3)
    except subprocess.TimeoutExpired:proc.kill()
    for f in jdir.glob('*'):f.unlink()
    jdir.rmdir()
