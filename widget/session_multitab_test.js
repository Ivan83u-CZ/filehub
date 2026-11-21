'use strict';
/**
 * Multi-tab regressions:
 * - repeated peer discovery inside one bounded window;
 * - transport failures use a short backoff, confirmed denials use five minutes;
 * - a stuck auth-lock holder cannot block a tab indefinitely.
 */
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

let now = 1_800_000_000_000;
class TestDate extends Date { static now() { return now; } }

async function main() {
  // A: five discovery probes fit inside the bounded 5000 ms wait.
  {
    let posted = 0;
    const cleared = [];
    const timers = [];
    const windowStub = {
      crypto:{randomUUID:() => '11111111-1111-4111-8111-111111111111'},
      BroadcastChannel:function(){}
    };
    const channel = {postMessage(){posted++;}};
    let resolved;
    const request = new Function('window','ensurePeerChannel','peerChannel','peerPending',
      'FH_PEER_REQUEST_WAIT_MS','FH_PEER_REQUEST_OFFSETS_MS','setTimeout','clearTimeout','Date',
      `return (${extractFn('requestLivePeerSession')});`)(
      windowStub, () => true, channel, null, 5000, [0,700,1600,3000,4400],
      (fn, delay) => { timers.push({fn,delay}); return timers.length; },
      id => cleared.push(id), TestDate);
    request('a1:u42').then(value => { resolved = value; });
    assert.equal(posted, 1, 'first discovery probe is immediate');
    for (let i = 0; i < 4; i++) {
      const retry = timers.find(t => t.delay < 5000 && !t.used);
      assert.ok(retry, 'a retry probe is scheduled');
      retry.used = true;
      retry.fn();
    }
    assert.equal(posted, 5, 'peer discovery retries instead of relying on one broadcast');
    const timeout = timers.find(t => t.delay === 5000 && !t.used);
    assert.ok(timeout, 'overall discovery remains bounded at 5000 ms');
    timeout.used = true;
    timeout.fn();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(resolved, null, 'bounded discovery resolves without a peer');
    assert.ok(cleared.length >= 1, 'overall timeout cancels the pending retry timer');
  }

  // Client retry margin and the backend refresh floor must not drift apart.
  assert.match(source, /FH_REFRESH_RETRY_MS\s*=\s*35000/);
  const refreshBackend = fs.readFileSync(path.join(__dirname, '../backend/app/fh_session_refresh.php'), 'utf8');
  assert.match(refreshBackend, /FH_SESSION_REFRESH_MIN_GAP_S[^\n]*35/);

  // B: transport failure and a confirmed denial have different cooldowns.
  {
    const map = new Map();
    const localStorage = {
      getItem:k => map.has(k) ? map.get(k) : null,
      setItem:(k,v) => map.set(k,String(v))
    };
    const allowed = new Function('window','storageKeyFor','Date','FH_REAUTH_RETRY_COOLDOWN_MS',
      'FH_REAUTH_TRANSPORT_RETRY_MS', `return (${extractFn('authRetryAllowed')});`)(
      {localStorage}, c => 'guard:' + c, TestDate, 5*60*1000, 20*1000);
    map.set('guard:a1:u42', JSON.stringify({ts:now, id:'x', outcome:'transport'}));
    now += 19_000;
    assert.equal(allowed('a1:u42'), false, 'transport failure uses a short cooldown first');
    now += 2_000;
    assert.equal(allowed('a1:u42'), true, 'transport failure recovers after about 20 seconds');
    map.set('guard:a1:u42', JSON.stringify({ts:now, id:'y', outcome:'failed'}));
    now += 60_000;
    assert.equal(allowed('a1:u42'), false, 'confirmed denial keeps the five-minute cooldown');
    map.set('guard:a1:u42', JSON.stringify({ts:now, id:'z', outcome:'transport', nextRetryAt:now+5000}));
    assert.equal(allowed('a1:u42'), false, 'explicit nextRetryAt wins');
    now += 6_000;
    assert.equal(allowed('a1:u42'), true, 'retry is allowed after nextRetryAt');
  }

  // C: a stuck lock holder is aborted and converted to a readable auth error.
  {
    const navigatorStub = {locks:{request(name, options) {
      return new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => {
          const err = new Error('aborted'); err.name = 'AbortError'; reject(err);
        }, {once:true});
      });
    }}};
    const fhError = (code) => Object.assign(new Error(code), {code});
    const lock = new Function('navigator','fhError','FH_AUTH_LOCK_WAIT_MS','AbortController',
      'setTimeout','clearTimeout', `return (${extractFn('underAuthLock')});`)(
      navigatorStub, fhError, 5, AbortController, setTimeout, clearTimeout);
    await lock('a1:u42', () => Promise.resolve('never')).then(
      () => { throw new Error('expected lock timeout'); },
      err => assert.equal(err.code, 'auth_lock_timeout', 'lock timeout has a specific code'));
  }

  console.log('PASS: peer discovery retries within a bounded 5 s window');
  console.log('PASS: transport retry and confirmed-denial cooldowns are separated');
  console.log('PASS: a stuck auth lock is bounded and surfaces auth_lock_timeout');
}

main().catch(err => { console.error('FAIL:', (err && err.message) || err); process.exit(1); });
