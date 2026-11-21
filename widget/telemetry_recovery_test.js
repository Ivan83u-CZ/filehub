'use strict';
/**
 * Regression tests for the telemetry lifecycle fixes:
 * - a queue persisted by a previous card/tab is loaded before the empty check;
 * - a newly saved session always schedules a flush;
 * - only a confirmed 2xx removes persisted events;
 * - 429 keeps the queue and honours Retry-After;
 * - pagehide uses keepalive when the API supports it.
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
function makeStore(initial) {
  const data = new Map(initial || []);
  return {
    data,
    get length() { return data.size; },
    key(i) { return Array.from(data.keys())[i] || null; },
    getItem(k) { return data.has(k) ? data.get(k) : null; },
    setItem(k, v) { data.set(k, String(v)); },
    removeItem(k) { data.delete(k); }
  };
}

let now = 1_800_000_000_000;
class TestDate extends Date { static now() { return now; } }

async function main() {
  const token = 'a'.repeat(64);

  // A: saveCanarySession schedules a flush even when the in-memory array is empty.
  {
    let scheduled = 0;
    const auth = { token: null, expires: 0, absoluteExpires: 0 };
    const sessionStore = makeStore();
    const save = new Function('Math','JSON','Date','fhError','state','destroyed','resolveContext','contextIsCurrent',
      'authStateFor','FH_SESSION_STORAGE_PREFIX','sessionStorage','serverClockOffsetMs','serverNowS',
      'scheduleCanaryRefresh','scheduleTelemetryFlush','telemetryEvents','telemetryTimer','flushTelemetry',
      `return (${extractFn('saveCanarySession')});`)(
      Math, JSON, TestDate, (c, s) => Object.assign(new Error(c), {code:c, status:s||0}),
      {contextKey:'a1:u42'}, false, () => 'a1:u42', () => true, () => auth, 'sess:',
      {setItem(k,v){sessionStore.setItem(k,v);}}, null,
      () => Math.floor(now/1000), () => {}, () => { scheduled++; }, [], null, () => {});
    save('a1:u42', token, Math.floor(now/1000)+600, Math.floor(now/1000)+1800);
    assert.ok(scheduled >= 1, 'new session schedules telemetry reconciliation');
  }

  // B: flush loads localStorage before checking telemetryEvents.length.
  {
    const operator = '42';
    const eventId = 'evt_abc_def_1';
    const persisted = {
      stored_at_ms: now,
      event: {schema_version:1, widget_version:'2.8.20', event:'list_failed', feature:'files',
              event_id:eventId, operator_id:operator, http_status:429,
              error_code:'gateway_rate_limited', queue_remaining:0, corr_id:'corr-1234567'}
    };
    const localStorage = makeStore([['czfh_telemetry_error_v1:42:' + eventId, JSON.stringify(persisted)]]);
    const events = [];
    let sent = null;
    const load = new Function('localStorage','TELEMETRY_QUEUE_PREFIX','Date','TELEMETRY_QUEUE_TTL_MS',
      'TELEMETRY_ALLOWED','telemetryEvents','telemetryQueueCounts','JSON',
      `return (${extractFn('loadPersistentTelemetry')});`)(
      localStorage, 'czfh_telemetry_error_v1:', TestDate, 14*86400000,
      {list_failed:1}, events, {}, JSON);
    const flush = new Function('TELEMETRY_ENABLED','destroyed','state','captureTelemetryContext',
      'loadPersistentTelemetry','knownCanarySession','sendTelemetryBatch','telemetryTimer','telemetryEvents',
      'telemetrySending','navigator','Date', `return (${extractFn('flushTelemetry')});`)(
      true, false, {contextKey:'a1:u42', production:true},
      () => ({operator_id:operator}), load, () => ({token, expires:Math.floor(now/1000)+600}),
      (op, session, ctx, keepalive) => { sent = {op, session, keepalive}; }, null, events, false,
      {locks:{request(name, options, fn){fn(); return Promise.resolve();}}}, TestDate);
    flush({keepalive:true});
    assert.ok(sent, 'persisted event triggers send even though the new array was empty');
    assert.equal(sent.op, operator, 'send uses the persisted operator id');
    assert.equal(events.length, 1, 'event was loaded into memory');
  }

  // B2: the cross-tab Web Lock must stay held until the fetch settles.
  {
    const operator = '42';
    const events = [{event_id:'evt_lock_hold_1', operator_id:operator, event:'list_failed'}];
    let releaseSend;
    let lockReleased = false;
    const pendingSend = new Promise(resolve => { releaseSend = resolve; });
    const flush = new Function('TELEMETRY_ENABLED','destroyed','state','captureTelemetryContext',
      'loadPersistentTelemetry','knownCanarySession','sendTelemetryBatch','telemetryTimer','telemetryEvents',
      'telemetrySending','navigator','Date', `return (${extractFn('flushTelemetry')});`)(
      true, false, {contextKey:'a1:u42', production:true},
      () => ({operator_id:operator}), () => {}, () => ({token, expires:Math.floor(now/1000)+600}),
      () => pendingSend, null, events, false,
      {locks:{request(name, options, fn){
        const held = fn();
        return Promise.resolve(held).then(value => { lockReleased = true; return value; });
      }}}, TestDate);
    flush();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(lockReleased, false, 'telemetry lock is held while fetch is pending');
    releaseSend();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(lockReleased, true, 'telemetry lock is released after fetch settles');
  }

  // C: a 429 preserves the persisted event and applies server Retry-After.
  {
    const operator = '42';
    const eventId = 'evt_abc_def_2';
    const event = {schema_version:1, widget_version:'2.8.20', event:'request_failed', feature:'files',
      event_id:eventId, operator_id:operator, http_status:429, error_code:'gateway_rate_limited',
      corr_id:'corr-1234567'};
    const key = 'czfh_telemetry_error_v1:42:' + eventId;
    const localStorage = makeStore([[key, JSON.stringify({stored_at_ms:now, event})]]);
    const events = [event];
    let retryDelay = 0;
    const timers = [];
    const setTimeoutStub = (fn, delay) => { timers.push(delay); return timers.length; };
    const send = new Function('fetch','CANARY_API','telemetrySending','telemetryRetryDelay',
      'telemetryBatchLimit','TELEMETRY_MAX_BODY_BYTES','telemetryEvents','window','JSON','Date',
      'telemetryRetryPolicy','telemetryRetryAfterMs','telemetryBatchFor','removePersistedTelemetry',
      'quarantineTelemetryBatch','telemetryTimer','flushTelemetry','setTimeout','clearTimeout','AbortController',
      `return (${extractFn('sendTelemetryBatch')});`)(
      () => Promise.resolve({ok:false, status:429, headers:{get:() => '2'},
        json:() => Promise.resolve({error:'gateway_rate_limited'})}),
      'https://example.test/api', false, 5000, 10, 7800, events, {TextEncoder}, JSON, TestDate,
      (status, transport) => status===429 && !transport ? 'retry' : 'reject', () => 2000,
      () => [event], e => localStorage.removeItem('czfh_telemetry_error_v1:42:' + e.event_id),
      () => {}, null, () => {}, setTimeoutStub, () => {}, function(){this.signal=null;});
    send(operator, {token}, {}, true);
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(localStorage.getItem(key) !== null, true, '429 must not acknowledge the event');
    assert.equal(events.length, 1, '429 keeps the event in memory');
    assert.ok(timers.includes(2000), 'Retry-After is used for backoff');
  }

  // D: a confirmed 2xx is the only path that removes the persistent row.
  {
    const operator = '42';
    const eventId = 'evt_abc_def_3';
    const event = {schema_version:1, widget_version:'2.8.20', event:'thumb_failed', feature:'files',
      event_id:eventId, operator_id:operator, http_status:0, error_code:'network_error',
      corr_id:'corr-1234567'};
    const key = 'czfh_telemetry_error_v1:42:' + eventId;
    const localStorage = makeStore([[key, JSON.stringify({stored_at_ms:now, event})]]);
    const events = [event];
    const send = new Function('fetch','CANARY_API','telemetrySending','telemetryRetryDelay',
      'telemetryBatchLimit','TELEMETRY_MAX_BODY_BYTES','telemetryEvents','window','JSON','Date',
      'telemetryRetryPolicy','telemetryRetryAfterMs','telemetryBatchFor','removePersistedTelemetry',
      'quarantineTelemetryBatch','telemetryTimer','flushTelemetry','setTimeout','clearTimeout','AbortController',
      `return (${extractFn('sendTelemetryBatch')});`)(
      () => Promise.resolve({ok:true, status:204}), 'https://example.test/api', false, 5000, 10, 7800,
      events, {TextEncoder}, JSON, TestDate, () => 'retry', () => 0, () => [event],
      e => localStorage.removeItem('czfh_telemetry_error_v1:42:' + e.event_id),
      () => {}, null, () => {}, () => 1, () => {}, function(){this.signal=null;});
    send(operator, {token}, {}, true);
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(localStorage.getItem(key), null, '2xx acknowledges and removes the persisted row');
    assert.equal(events.length, 0, '2xx removes the event from memory');
  }

  // E: the request body stays under the backend's 8 KiB and 10-event limits.
  {
    const events = Array.from({length:15}, (_, i) => ({
      schema_version:1, widget_version:'2.8.20', event:'list_failed', feature:'files',
      event_id:'evt_batch_' + i + '_x', operator_id:'42', http_status:429,
      error_code:'gateway_rate_limited', corr_id:'corr-1234567', duration_ms:600000,
      client_time_ms:now
    }));
    const payloadBytes = list => JSON.stringify({schema_version:1, events:list}).length;
    const batchFn = new Function('telemetryEvents','telemetryBatchLimit','TELEMETRY_MAX_BODY_BYTES',
      'window','JSON','telemetryPayloadBytes','quarantineTelemetryEvent','removePersistedTelemetry','removeTelemetryFromMemory',
      `return (${extractFn('telemetryBatchFor')});`)(events, 10, 7800, {TextEncoder}, JSON, payloadBytes,
      () => false, () => {}, event => { const i=events.indexOf(event); if(i>=0)events.splice(i,1); });
    const batch = batchFn('42');
    const payload = new Function('window','JSON','TELEMETRY_MAX_BODY_BYTES',
      `return (${extractFn('telemetryPayloadBytes')});`)({TextEncoder}, JSON, 7800);
    assert.ok(batch.length <= 10, 'batch never exceeds the server event limit');
    assert.ok(payload(batch) <= 7800, 'batch never exceeds the 8 KiB body budget');
  }

  // G: an oversized row is quarantined and does not block later events.
  {
    const oversized = {schema_version:1, widget_version:'2.8.20', event:'list_failed', feature:'files',
      event_id:'evt_oversized_1', operator_id:'42', error_code:'oversized', corr_id:'corr-1234567',
      padding:'x'.repeat(9000)};
    const normal = {schema_version:1, widget_version:'2.8.20', event:'list_failed', feature:'files',
      event_id:'evt_normal_2', operator_id:'42', error_code:'normal', corr_id:'corr-1234567'};
    const events = [oversized, normal];
    const quarantined = [], removed = [];
    const payloadBytes = list => JSON.stringify({schema_version:1, events:list}).length;
    const batchFn = new Function('telemetryEvents','telemetryBatchLimit','TELEMETRY_MAX_BODY_BYTES',
      'window','JSON','telemetryPayloadBytes','quarantineTelemetryEvent','removePersistedTelemetry','removeTelemetryFromMemory',
      `return (${extractFn('telemetryBatchFor')});`)(events, 10, 7800, {TextEncoder}, JSON, payloadBytes,
      (event, reason) => { quarantined.push({event, reason}); return true; },
      event => removed.push(event.event_id),
      event => { const i=events.indexOf(event); if(i>=0)events.splice(i,1); });
    const batch = batchFn('42');
    assert.equal(quarantined.length, 1, 'oversized row is quarantined');
    assert.equal(quarantined[0].reason, 'oversized', 'quarantine reason is explicit');
    assert.equal(removed[0], oversized.event_id, 'quarantined row leaves the delivery queue only after persistence');
    assert.deepEqual(batch.map(e => e.event_id), [normal.event_id], 'a later valid event still reaches the batch');
  }

  // H: quarantine storage retains the diagnostic event and reason.
  {
    const store = makeStore();
    const event = {event_id:'evt_quarantine_1', operator_id:'42', event:'request_failed'};
    const put = new Function('localStorage','TELEMETRY_QUARANTINE_PREFIX','TELEMETRY_QUARANTINE_MAX',
      'TELEMETRY_QUEUE_TTL_MS','Date','JSON','telemetryQuarantineKey','pruneTelemetryQuarantine',
      `return (${extractFn('quarantineTelemetryEvent')});`)(
      store, 'czfh_telemetry_quarantine_v1:', 1000, 14*86400000, TestDate, JSON,
      (operatorId, eventId) => 'czfh_telemetry_quarantine_v1:' + operatorId + ':' + eventId, () => {});
    assert.equal(put(event, 'http_400'), true, 'quarantine write succeeds');
    const saved = JSON.parse(store.getItem('czfh_telemetry_quarantine_v1:42:evt_quarantine_1'));
    assert.equal(saved.reason, 'http_400', 'quarantine keeps the rejection reason');
    assert.equal(saved.event.event_id, event.event_id, 'quarantine keeps the original event');
  }

  // I: a non-2xx singleton is quarantined, never acknowledged as delivered.
  {
    const operator = '42';
    const event = {schema_version:1, widget_version:'2.8.20', event:'request_failed', feature:'files',
      event_id:'evt_abc_def_4', operator_id:operator, error_code:'schema_invalid', corr_id:'corr-1234567'};
    let quarantined = 0, removed = 0;
    const send = new Function('fetch','CANARY_API','telemetrySending','telemetryRetryDelay',
      'telemetryBatchLimit','TELEMETRY_MAX_BODY_BYTES','telemetryEvents','window','JSON','Date',
      'telemetryRetryPolicy','telemetryRetryAfterMs','telemetryBatchFor','removePersistedTelemetry',
      'quarantineTelemetryBatch','telemetryTimer','flushTelemetry','setTimeout','clearTimeout','AbortController',
      `return (${extractFn('sendTelemetryBatch')});`)(
      () => Promise.resolve({ok:false, status:400, headers:{get:() => null}, json:() => Promise.resolve({})}),
      'https://example.test/api', false, 5000, 10, 7800, [event], {TextEncoder}, JSON, TestDate,
      () => 'split', () => 0, () => [event], () => { removed++; }, () => { quarantined++; },
      null, () => {}, () => 1, () => {}, function(){this.signal=null;});
    send(operator, {token}, {}, false);
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(quarantined, 1, 'singleton 400 is moved to quarantine');
    assert.equal(removed, 0, 'singleton 400 is not acknowledged as a successful delivery');
  }

  // F: pagehide/visibility handlers are installed and use keepalive.
  {
    const listeners = {};
    const documentStub = {visibilityState:'hidden', addEventListener:(n,f)=>listeners[n]=f,
      removeEventListener:n=>delete listeners[n]};
    const windowStub = {addEventListener:(n,f)=>listeners[n]=f, removeEventListener:n=>delete listeners[n]};
    let flushOptions = null;
    const install = new Function('document','window','telemetryLifecycleInstalled',
      'telemetryVisibilityHandler','telemetryPageHideHandler','telemetryOnlineHandler',
      'resumeWidgetSession','flushTelemetry', `return (${extractFn('installTelemetryLifecycle')});`)(
      documentStub, windowStub, false, null, null, null, () => {}, o => { flushOptions = o; });
    install();
    assert.equal(typeof listeners.pagehide, 'function', 'pagehide handler installed');
    listeners.pagehide();
    assert.equal(flushOptions.keepalive, true, 'pagehide uses keepalive');
    assert.equal(flushOptions.final, true, 'pagehide allows final delivery after destroy');
    assert.equal(flushOptions.direct, true, 'pagehide starts the keepalive fetch without waiting for a lock');
  }

  console.log('PASS: new instance restores and flushes the persistent telemetry queue');
  console.log('PASS: session issuance always schedules telemetry delivery');
  console.log('PASS: 429 keeps the event and honours Retry-After; only 2xx acknowledges it');
  console.log('PASS: batch respects the 8 KiB and 10-event server limits');
  console.log('PASS: oversized and rejected events are quarantined instead of silently deleted');
  console.log('PASS: pagehide performs a keepalive final flush');
}

main().catch(err => { console.error('FAIL:', (err && err.message) || err); process.exit(1); });
