'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const src = fs.readFileSync(path.join(__dirname, '../app/fh12_prod.php'), 'utf8');
assert.match(src, /function fh_lead_acl_check\(array \$identity,string \$leadId\):bool/);
assert.match(src, /FILEHUB_LEAD_ACL_MODE/);
assert.match(src, /acl_would_deny/);
assert.match(src, /lead_acl_denied/);
assert.match(src, /lead_acl_unavailable/);
assert.match(src, /lead_acl_mode_invalid/);
assert.match(src, /FILEHUB_LEAD_ACL_LOG_SAMPLE_PCT/);
for (const route of ['files','file','thumb','calls_feed','trash','activity']) {
  assert.ok(src.includes(`'${route}'`), `route present: ${route}`);
}
const checks = (src.match(/fh12_assert_lead_access\(\$identity,\$lead\)/g) || []).length;
assert.ok(checks >= 3, 'ACL assertion is present on generic GET, upload and write paths');
assert.ok(src.includes("'upload','rename','trash','restore'"));
assert.ok(!src.includes('NO per-lead ACL; account auth is mandatory'));
console.log(`PASS: ACL seam present; route assertions=${checks}`);
