from pathlib import Path
import json,re
root=Path(__file__).resolve().parent.parent
s=(root/'widget/script.js').read_text()
assert 'self.$authorizedAjax' in s
assert 'X-API-KEY' not in s
assert "'/calls_feed?leadId='" in s
for old in ['backend_url','/rename','/upload','/files?leadId=','/file?leadId=']:
    assert old in s,old
assert 'FILEHUB_DAV_MODE' in (root/'app/api.php').read_text()
assert 'test_crm_ivan0082' in (root/'app/api.php').read_text()
assert 'FILEHUB_ALLOWED_CLIENT_SERIAL' not in (root/'app/api.php').read_text()
assert 'FH_CLIENT_SERIAL' not in (root/'nginx/default.conf').read_text()
assert 'X-Auth-Token' in (root/'nginx/default.conf').read_text()
assert 'api_key' not in json.loads((root/'widget/manifest.json').read_text())['settings']
assert not any((root/'pki').glob('*.key'))
assert not (root/'.env').exists()
print('PASS: widget contract, amo auth, synthetic guard, no cert private keys or .env')
