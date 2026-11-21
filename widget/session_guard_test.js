'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, 'script.js'), 'utf8');
const extract = name => source.match(new RegExp(`function ${name}\\([^\\n]*\\)\\{[\\s\\S]*?\\n      \\}`));
const knownMatch = extract('knownAttempt');
const recordMatch = extract('recordAttemptSession');
const snapshotMatch = extract('captureTelemetryContext');
const retryMatch = extract('telemetryRetryPolicy');
const queueKeyMatch = extract('telemetryQueueKey');
const queueCountMatch = extract('telemetryQueueCount');
const persistMatch = extract('persistTelemetryEvent');
const loadMatch = extract('loadPersistentTelemetry');
const removeMatch = extract('removePersistedTelemetry');
for (const [name, match] of Object.entries({knownMatch,recordMatch,snapshotMatch,retryMatch,
  queueKeyMatch,queueCountMatch,persistMatch,loadMatch,removeMatch})) assert(match, `${name} source exists`);

let now = 0;
class TestDate extends Date { static now() { return now; } }
const values = new Map();
const markerStorage = {
  getItem(key) { return values.get(key) || null; },
  setItem(key, value) { values.set(key, value); }
};
const storageKeyFor = context => `attempt:${context}`;
const knownAttempt = new Function('window','storageKeyFor','fhError','Date','JSON',
  'FH_JWT_ATTEMPT_GUARD_MS',`return (${knownMatch[0]});`)(
  {localStorage:markerStorage},storageKeyFor,code=>new Error(code),TestDate,JSON,31*60*1000);
const recordAttemptSession = new Function('window','storageKeyFor','JSON',
  `return (${recordMatch[0]});`)({localStorage:markerStorage},storageKeyFor,JSON);

const state = {contextKey:'a123:u12202974',leadId:'33942619',cat:'photos'};
const captureTelemetryContext = new Function('state','resolveContext','resolveOperatorId',
  `return (${snapshotMatch[0]});`)(state,()=>state.contextKey,()=>null);
const eventContext = captureTelemetryContext();
state.leadId='98765432';
state.cat='tickets';
assert.equal(eventContext.lead_id,'33942619','async event keeps the originating lead');
assert.equal(eventContext.category,'photos','async event keeps the originating category');

const attemptedAt = 1_000_000;
values.set(storageKeyFor('context'),JSON.stringify({ts:attemptedAt,id:'request-1'}));
recordAttemptSession('context','request-1',(attemptedAt+30*60*1000)/1000);
assert.equal(JSON.parse(values.get(storageKeyFor('context'))).absoluteExpires,
  (attemptedAt+30*60*1000)/1000);
now=attemptedAt+30*60*1000-1;
assert.equal(knownAttempt('context'),true,'guard remains while server session is live');
now=attemptedAt+30*60*1000;
assert.equal(knownAttempt('context'),false,'guard ends at absolute session expiry');
values.set(storageKeyFor('context'),JSON.stringify({ts:attemptedAt,id:'ambiguous'}));
now=attemptedAt+30*60*1000+30_000;
assert.equal(knownAttempt('context'),true,'ambiguous result retains replay guard');
now=attemptedAt+31*60*1000;
assert.equal(knownAttempt('context'),false,'ambiguous guard expires at fallback TTL');

const telemetryRetryPolicy = new Function(`return (${retryMatch[0]});`)();
assert.equal(telemetryRetryPolicy(0,true),'retry','transport errors retry');
assert.equal(telemetryRetryPolicy(503,false),'retry','server errors retry');
assert.equal(telemetryRetryPolicy(429,false),'retry','rate limits retry with backoff');
assert.equal(telemetryRetryPolicy(401,false),'session','401 waits for a refreshed session');
assert.equal(telemetryRetryPolicy(400,false),'split','malformed batches are isolated');
assert.equal(telemetryRetryPolicy(413,false),'split','oversized batches are isolated');
assert.equal(telemetryRetryPolicy(403,false),'reject','permanent client errors do not block later events');

const stored = new Map();
const localStorage = {
  get length() { return stored.size; },
  key(index) { return Array.from(stored.keys())[index] || null; },
  getItem(key) { return stored.has(key) ? stored.get(key) : null; },
  setItem(key,value) { stored.set(key,value); },
  removeItem(key) { stored.delete(key); }
};
const counts=Object.create(null),queue=[];
const prefix='queue:';
const telemetryQueueKey = new Function('TELEMETRY_QUEUE_PREFIX',
  `return (${queueKeyMatch[0]});`)(prefix);
const telemetryQueueCount = new Function('localStorage','TELEMETRY_QUEUE_PREFIX','telemetryQueueCounts',
  `return (${queueCountMatch[0]});`)(localStorage,prefix,counts);
const persistTelemetryEvent = new Function('telemetryQueueKey','localStorage','telemetryQueueCount',
  'telemetryQueueCounts','TELEMETRY_QUEUE_MAX','Date','JSON',`return (${persistMatch[0]});`)(
  telemetryQueueKey,localStorage,telemetryQueueCount,counts,5000,TestDate,JSON);
const loadPersistentTelemetry = new Function('localStorage','TELEMETRY_QUEUE_PREFIX','Date',
  'TELEMETRY_QUEUE_TTL_MS','TELEMETRY_ALLOWED','telemetryEvents','telemetryQueueCounts','JSON',
  `return (${loadMatch[0]});`)(localStorage,prefix,TestDate,14*24*60*60*1000,
  {list_failed:1},queue,counts,JSON);
const removePersistedTelemetry = new Function('telemetryQueueKey','localStorage','telemetryQueueCounts',
  `return (${removeMatch[0]});`)(telemetryQueueKey,localStorage,counts);
now=2_000_000;
const queuedEvent={event:'list_failed',event_id:'evt_m8x2_a31_2',operator_id:'12202974',lead_id:'33942619'};
assert.equal(persistTelemetryEvent(queuedEvent),true);
loadPersistentTelemetry('12202974');
assert.equal(queue[0].lead_id,'33942619','error context survives widget reload');
removePersistedTelemetry(queuedEvent);
assert.equal(stored.size,0,'accepted events are removed from persistent queue');

console.log('PASS: session guard follows the absolute expiry and keeps ambiguity protection');
console.log('PASS: async diagnostics retain original lead and category');
console.log('PASS: retry policy retains transport, backend, and client errors');
console.log('PASS: persistent queue restores and removes events by event id');
