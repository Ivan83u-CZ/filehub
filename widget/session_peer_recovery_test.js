'use strict';
/**
 * Multi-card / multi-tab regression:
 * - a tab with a live session answers peer discovery even when its widget state is
 *   temporarily stale or amoCRM constants are unavailable;
 * - an "available" notification wakes a tab that has no local session;
 * - background/visible recovery adopts a peer token without spending a new JWT;
 * - peer discovery remains bounded and retries inside that window.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'script.js'), 'utf8');
function extractFn(name) {
  const marker = 'function ' + name + '(';
  const start = source.indexOf(marker);
  if (start < 0) throw new Error('function not found: ' + name);
  let depth = 0, started = false;
  for (let index = start; index < source.length; index++) {
    if (source[index] === '{') { depth++; started = true; }
    else if (source[index] === '}' && started && --depth === 0) return source.slice(start, index + 1);
  }
  throw new Error('unbalanced function: ' + name);
}

let now = 1_800_000_000_000;
class TestDate extends Date { static now() { return now; } }

async function main() {
  assert.match(source, /FH_PEER_REQUEST_WAIT_MS\s*=\s*5000/);
  assert.match(source, /FH_PEER_REQUEST_OFFSETS_MS\s*=\s*\[0,\s*700,\s*1600,\s*3000,\s*4400\]/);

  // A temporary null from amoCRM constants must not discard a valid cached context.
  {
    const contextIsCurrent = new Function('destroyed','state','resolveContext',
      `return (${extractFn('contextIsCurrent')});`)(false, { contextKey: 'a1:u42' }, () => null);
    assert.equal(contextIsCurrent('a1:u42'), true, 'transient null context keeps the cached validated context');
    const changed = new Function('destroyed','state','resolveContext',
      `return (${extractFn('contextIsCurrent')});`)(false, { contextKey: 'a1:u42' }, () => 'a1:u99');
    assert.equal(changed('a1:u42'), false, 'a real account/user change still invalidates the old context');
  }

  // A: donor-side answer does not depend on this widget instance's stale state.
  {
    class FakeChannel {
      constructor(name) { this.name = name; this.sent = []; FakeChannel.last = this; }
      postMessage(message) { this.sent.push(message); }
      close() {}
    }
    const live = { token: 'a'.repeat(64), expires: Math.floor(now / 1000) + 600,
      absoluteExpires: Math.floor(now / 1000) + 1800 };
    let liveEntry = live;
    let recoveryCalls = 0;
    const stubs = {
      window: { BroadcastChannel: FakeChannel },
      state: { contextKey: 'a1:u999' },
      resolveContext: () => null,
      knownCanarySession: () => liveEntry,
      peerSessionLive: (entry, at) => !!(entry.token && entry.expires > at && entry.absoluteExpires > at),
      saveCanarySession: () => { throw new Error('recipient path not used'); },
      serverNowS: () => Math.floor(now / 1000),
      schedulePeerRecovery: () => { recoveryCalls++; },
      closePeerChannel: () => {},
      peerPending: null,
      peerChannel: null,
      peerContext: null,
      destroyed: false,
      Date: TestDate
    };
    const names = Object.keys(stubs);
    const ensurePeerChannel = new Function(...names,
      `return (${extractFn('ensurePeerChannel')});`)(...names.map(name => stubs[name]));
    const context = 'a1:u42';
    assert.equal(ensurePeerChannel(context), true);
    const nonce = '11111111-1111-4111-8111-111111111111';
    FakeChannel.last.onmessage({ data: { kind: 'request', nonce } });
    const offer = FakeChannel.last.sent.find(message => message.kind === 'offer');
    assert.ok(offer, 'a donor answers even when resolveContext() is temporarily null');
    assert.equal(offer.nonce, nonce, 'offer is bound to the request nonce');
    assert.equal(offer.token, live.token, 'offer carries the live tab-scoped token');
    assert.equal(offer.serverTime, Math.floor(now / 1000), 'offer includes server time for clock-safe adoption');

    liveEntry = { token: null, expires: 0, absoluteExpires: 0 };
    FakeChannel.last.onmessage({ data: { kind: 'available' } });
    assert.equal(recoveryCalls, 1, 'an availability notification wakes a tab without a session');
  }

  // B: a visible blocked tab adopts a peer session and refreshes its current view.
  {
    const telemetryRows = [];
    let refreshes = 0;
    const peerToken = 'b'.repeat(64);
    const stubs = {
      destroyed: false,
      state: { contextKey: 'a1:u42' },
      resolveContext: () => 'a1:u42',
      contextIsCurrent: () => true,
      navigator: { onLine: true },
      Date: TestDate,
      peerSessionLive: () => false,
      knownCanarySession: () => ({ token: null, expires: 0, absoluteExpires: 0 }),
      requestLivePeerSession: () => Promise.resolve(peerToken),
      telemetry: (event, details) => telemetryRows.push({ event, details }),
      captureTelemetryContext: context => ({ operator_id: '42', lead_id: '7', category: 'photos', context }),
      authErrorVisible: true,
      refreshCurrentCategory: () => { refreshes++; },
      schedulePeerRecovery: () => { throw new Error('must not retry after success'); },
      peerRecoveryDelay: 10000,
      FH_PEER_RECOVERY_MIN_MS: 10000,
      FH_PEER_RECOVERY_MAX_MS: 60000,
      Math
    };
    const names = Object.keys(stubs);
    const probePeerSession = new Function(...names,
      `return (${extractFn('probePeerSession')});`)(...names.map(name => stubs[name]));
    probePeerSession();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(refreshes, 1, 'successful peer recovery refreshes the visible auth error');
    assert.equal(telemetryRows[0].event, 'session_issued');
    assert.equal(telemetryRows[0].details.error_code, 'peer_recovery');
    assert.equal(telemetryRows[0].details.context.lead_id, '7');
  }

  // C: no donor means a bounded peer-only retry; no new amoCRM JWT is requested.
  {
    const scheduled = [];
    const stubs = {
      destroyed: false,
      state: { contextKey: 'a1:u42' },
      resolveContext: () => 'a1:u42',
      contextIsCurrent: () => true,
      navigator: { onLine: true },
      Date: TestDate,
      peerSessionLive: () => false,
      knownCanarySession: () => ({ token: null, expires: 0, absoluteExpires: 0 }),
      requestLivePeerSession: () => Promise.resolve(null),
      telemetry: () => { throw new Error('no issuance telemetry without a session'); },
      captureTelemetryContext: () => ({}),
      authErrorVisible: true,
      refreshCurrentCategory: () => { throw new Error('must not refresh without a session'); },
      schedulePeerRecovery: delay => scheduled.push(delay),
      peerRecoveryDelay: 10000,
      FH_PEER_RECOVERY_MIN_MS: 10000,
      FH_PEER_RECOVERY_MAX_MS: 60000,
      Math
    };
    const names = Object.keys(stubs);
    const probePeerSession = new Function(...names,
      `return (${extractFn('probePeerSession')});`)(...names.map(name => stubs[name]));
    probePeerSession();
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(scheduled, [20000], 'peer probe backs off without touching the auth endpoint');
  }

  console.log('PASS: transient null context preserves the cached validated account/user');
  console.log('PASS: a live tab answers peer discovery despite stale widget state');
  console.log('PASS: availability notifications wake blocked tabs');
  console.log('PASS: peer recovery adopts a session and refreshes the visible error');
  console.log('PASS: peer recovery backs off without spending a new JWT');
}

main().catch(error => {
  console.error('FAIL:', (error && error.message) || error);
  process.exit(1);
});
