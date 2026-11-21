'use strict';
/** Static contracts for the review fixes that PHP/browser runners cannot cover here. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '../..');
const widget = fs.readFileSync(path.join(root, 'widget/script.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'backend/app/api.php'), 'utf8');
const acl = fs.readFileSync(path.join(root, 'backend/app/fh12_prod.php'), 'utf8');
const edge = fs.readFileSync(path.join(root, 'deploy/nginx-filehub.proposed.conf'), 'utf8');
const apply = fs.readFileSync(path.join(root, 'deploy/apply-backend.sh'), 'utf8');
const rollback = fs.readFileSync(path.join(root, 'deploy/rollback-backend.sh'), 'utf8');
const proxy = fs.readFileSync(path.join(root, 'deploy/filehub-proxy.inc'), 'utf8');

assert.match(api, /HTTP_X_REQUEST_ID/);
assert.match(api, /\$id\s*=\s*strtolower\(\$incoming\)/);
assert.match(api, /header\('X-Request-ID: '\.\$fqRequestId\)/);
assert.match(proxy, /proxy_set_header X-Request-ID \$request_id/);
assert.match(edge, /upstream_status=\$upstream_status/);
assert.match(edge, /zone=filehub_auth:1m\s+rate=10r\/s/);
assert.match(edge, /limit_req zone=filehub_auth burst=30 nodelay/);
assert.match(edge, /add_header Retry-After 5 always/);
assert.match(edge, /location @filehub_rate_limited[\s\S]*add_header X-Content-Type-Options nosniff always/);
assert.match(widget, /options\.direct/);
assert.match(widget, /czfh_telemetry_quarantine_v1/);
assert.match(widget, /quarantineTelemetryBatch/);
assert.match(acl, /lead_acl_mode_invalid/);
assert.match(acl, /if\(\$allowed\)return true/);
assert.match(acl, /loadedSig/);
assert.match(apply, /FILES=\([^\n]*\bapi\.php\b/);
assert.match(rollback, /for f in [^\n]*\bapi\.php\b/);
assert.match(apply, /install -m 0644 -o root -g root/);
assert.match(apply, /docker exec -u "\$PHP_USER"/);

console.log('PASS: review fixes static contracts');
