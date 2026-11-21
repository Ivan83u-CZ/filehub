'use strict';
/** Edge 429 classification and idempotent-GET retry regressions. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'script.js'), 'utf8');
function extractFn(name) {
  const marker = 'function ' + name + '(';
  const i = source.indexOf(marker);
  if (i < 0) throw new Error('function not found: ' + name);
  let depth = 0, started = false;
  for (let j = i; j < source.length; j++) {
    if (source[j] === '{') { depth++; started = true; }
    else if (source[j] === '}' && started && --depth === 0) return source.slice(i, j + 1);
  }
  throw new Error('unbalanced function: ' + name);
}
function fhError(code, status, ambiguous) {
  return Object.assign(new Error(code), {code, status:status||0, ambiguous:!!ambiguous});
}

async function main() {
  // A: edge 429 keeps its machine-readable code and Retry-After.
  {
    const fetchWithDeadline = new Function('fetch','telemetry','fhError','AbortController',
      'setTimeout','clearTimeout','Date', `return (${extractFn('fetchWithDeadline')});`)(
      () => Promise.resolve({ok:false, status:429,
        headers:{get:n => n==='Retry-After' ? '2' : null},
        json:() => Promise.resolve({error:'gateway_rate_limited'})}),
      () => {}, fhError, AbortController, setTimeout, clearTimeout, Date);
    await fetchWithDeadline('https://example.test/api/files', {}, 'GET', 'files', 'c1', {}).then(
      () => { throw new Error('expected 429'); },
      err => {
        assert.equal(err.code, 'gateway_rate_limited', 'edge code is preserved');
        assert.equal(err.retryAfterMs, 2000, 'Retry-After is converted to milliseconds');
      });
  }

  function runAmo(method) {
    let calls = 0;
    const state = {canary:false, production:true, contextKey:'a1:u42', backend:{base:'https://example.test/api'}};
    const amoRequest = new Function('state','destroyed','resolveContext','contextIsCurrent','captureTelemetryContext',
      'telemetryFeature','newCorrId','fetchWithDeadline','ensureCanarySession','refreshCanarySession',
      'authStateFor','clearRefreshTimer','clearStoredSession','fhError','CANARY_API','telemetry','setTimeout',
      `return (${extractFn('amoRequest')});`)(
      state, false, () => 'a1:u42', () => true, () => ({operator_id:'42'}), () => 'files', () => 'c1',
      () => { calls++; return calls===1
        ? Promise.reject(fhError('gateway_rate_limited',429,false))
        : Promise.resolve([]); },
      () => Promise.resolve('x'.repeat(64)), () => Promise.reject(fhError('refresh',401)),
      () => ({token:null,expires:0,absoluteExpires:0}), () => {}, () => {}, fhError,
      'https://example.test/api', () => {}, fn => { fn(); return 1; });
    const url = method==='POST' ? 'https://example.test/api/upload' : 'https://example.test/api/files';
    return amoRequest(url, {method}).then(value => ({value,calls}));
  }

  // B: one safe GET retry after a 429.
  {
    const out = await runAmo('GET');
    assert.equal(out.calls, 2, 'GET is retried once after a rate limit');
    assert.deepEqual(out.value, [], 'GET retry returns the successful result');
  }

  // C: a write is never replayed automatically.
  {
    const out = await runAmo('POST').then(
      () => { throw new Error('expected write failure'); },
      err => ({err}));
    assert.equal(out.err.code, 'gateway_rate_limited', 'POST error is surfaced');
  }

  console.log('PASS: edge 429 is classified and exposes Retry-After');
  console.log('PASS: only idempotent GET is retried after 429');
}

main().catch(err => { console.error('FAIL:', (err && err.message) || err); process.exit(1); });
