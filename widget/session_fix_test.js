'use strict';
/**
 * FileHub widget 2.8.17 — регрессия правок ревью 19.11.2026.
 *
 * Проверяет исправления, найденные ревью виджета 2.8.16:
 *   F1 (A1) ошибка превью НЕ порождает бесконечный цикл loadList -> render ->
 *          новая ошибка: авто-обновление списка не чаще одного раза на список,
 *          и только если подпись capability действительно истекла;
 *   F2 (A2) на retryNotBefore клиент НЕ отдаёт заведомо мёртвый по idle токен,
 *          а отклоняется с session_refresh_throttled;
 *   F3 (A3) свежевыданная сессия принимается, даже если часы браузера отстают
 *          от серверных (верхняя граница абсолютного срока считается по
 *          серверным часам), а заведомо «длинная» сессия — отвергается;
 *   F4 (A5) код ошибки вида 'constructor' не выдаёт текст прототипа;
 *   F5 (A6) признак операции в телеметрии берётся из endpoint'а.
 *
 * Функции извлекаются из script.js исходником и выполняются с подменёнными
 * зависимостями — проверяется РЕАЛЬНЫЙ код виджета.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'script.js'), 'utf8');

/** Извлечение функции по имени с подсчётом вложенных скобок. */
function extractFn(name) {
  const i = source.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('исходник ' + name + ' не найден');
  let depth = 0, started = false;
  for (let j = i; j < source.length; j++) {
    const ch = source[j];
    if (ch === '{') { depth++; started = true; }
    else if (ch === '}') { depth--; if (started && depth === 0) return source.slice(i, j + 1); }
  }
  throw new Error('несбалансированные скобки в ' + name);
}

let now = 1_700_000_000_000;
const nowS = () => Math.floor(now / 1000);
class TestDate extends Date { static now() { return now; } }

function load(name, stubs) {
  const names = Object.keys(stubs);
  return new Function(...names, `return (${extractFn(name)});`)(...names.map(k => stubs[k]));
}

const fhError = load('fhError', { Math, JSON, Date: TestDate, console });

/* ------------------------------------------------------------------ */
/* F1: цикл авто-обновления превью                                    */
/* ------------------------------------------------------------------ */

/** Минимальный DOM-каркас для createThumbNode. */
function makeElFactory(counter) {
  function makeEl() {
    const el = {
      handlers: {}, attrs: {}, children: [], length: 1,
      append(x) { el.children.push(x); return el; },
      appendTo() { return el; },
      on(ev, fn) { (el.handlers[ev] = el.handlers[ev] || []).push(fn); return el; },
      off() { return el; },
      attr(k, v) { if (v === undefined) return el.attrs[k]; el.attrs[k] = v; return el; },
      removeAttr(k) { delete el.attrs[k]; return el; },
      prop() { return el; }, text() { return el; }, empty() { el.children = []; return el; },
      addClass() { return el; }, removeClass() { return el; }, toggleClass() { return el; },
      hasClass() { return false; }, find() { return makeEl(); },
      closest() { return makeEl(); }, is() { return false; }
    };
    el[0] = { node: true, seq: ++counter.n };
    return el;
  }
  return makeEl;
}

/**
 * Прогон реального createThumbNode + requestListAutoRefresh.
 * thumbExp — время истечения подписи capability (unix-секунды).
 */
function runThumbScenario(thumbExp) {
  const counter = { n: 0 };
  const makeEl = makeElFactory(counter);
  const $ = function () { return makeEl(); };
  $.contains = () => true;

  const calls = { loadList: 0, failures: 0, rendered: 0 };
  const state = { cat: 'photos', leadId: '123', canary: false, production: true,
                  contextKey: 'a1:u2', trashView: false, historyView: false, davView: false };
  const listAutoRefresh = { used: false, timer: null, key: '' };
  const lastNodes = [];

  function renderAndCollect() {
    calls.rendered++;
    const made = [];
    for (const name of ['a.png', 'b.png', 'c.png']) {
      made.push(createThumbNode({ name, contentType: 'image/png' }));
    }
    lastNodes.length = 0;
    lastNodes.push(...made);
  }

  const stubs = {
    $, state, destroyed: false, listAutoRefresh,
    Math, JSON, Date: TestDate, console, document: {},
    isImageItem: it => /\.png$/i.test(it.name || ''),
    isServerThumbItem: it => /\.png$/i.test(it.name || ''),
    thumbUrl: () => 'https://filehub.example.test/wp-json/czfh/v1/thumb?leadId=1&exp=' + thumbExp,
    signedExpiry: load('signedExpiry', { Math, JSON, Date: TestDate, console }),
    listAutoRefreshKey: load('listAutoRefreshKey', { state }),
    captureTelemetryContext: () => ({ operator_id: '2' }),
    telemetry: (name) => { if (name === 'thumb_failed') calls.failures++; },
    setTimeout: (fn) => { fn(); return 0; },
    clearTimeout: () => {},
    loadList: () => { calls.loadList++; renderAndCollect(); }
  };

  // Реальная функция ограничителя авто-обновления подставляется в каркас ДО
  // загрузки createThumbNode: тот обращается к ней по имени.
  stubs.requestListAutoRefresh = load('requestListAutoRefresh', stubs);
  const createThumbNode = load('createThumbNode', stubs);

  function fireError(node) {
    const img = node.children[0];
    ((img && img.handlers && img.handlers.error) || []).forEach(fn => fn());
  }

  renderAndCollect();
  // три раунда: в каждом каждая картинка падает дважды
  for (let cycle = 0; cycle < 3; cycle++) {
    lastNodes.slice().forEach(n => { fireError(n); fireError(n); });
  }
  return { calls };
}

{
  // F1a: подпись ЕЩЁ ЖИВА (exp в будущем) — авто-обновления быть не должно вовсе.
  const res = runThumbScenario(nowS() + 300);
  assert.equal(res.calls.loadList, 0,
    'F1a при живой подписи авто-обновление списка не вызывается');
  assert.ok(res.calls.failures >= 3,
    'F1a превью показало «Миниатюра недоступна» (ошибка не скрывается)');

  // F1b: подпись ИСТЕКЛА — авто-обновление ровно одно на список, а не по одному
  //       на каждую упавшую картинку (прежнее поведение давало цикл).
  const res2 = runThumbScenario(nowS() - 60);
  assert.equal(res2.calls.loadList, 1,
    'F1b авто-обновление списка не чаще одного раза на список');
  assert.ok(res2.calls.rendered <= 3,
    'F1b перерисовок списка немного, а не лавина');
}

/* ------------------------------------------------------------------ */
/* F2: retryNotBefore не отдаёт мёртвый токен                          */
/* ------------------------------------------------------------------ */

async function runRefreshScenario(shared, fetchImpl) {
  const stubs = {
    fhError, Math, JSON, Date: TestDate, console, Promise,
    authStateFor: () => shared,
    knownCanarySession: () => shared,
    underAuthLock: (ctx, op) => Promise.resolve().then(op),
    requestLivePeerSession: () => Promise.resolve(null),
    saveCanarySession: () => 'old-token',
    scheduleCanaryRefresh: () => { shared.scheduled = (shared.scheduled || 0) + 1; },
    clearCanarySession: () => {},
    captureTelemetryContext: () => ({}),
    newCorrId: () => 'c1',
    noteServerTime: () => {},
    FH_REFRESH_EARLY_S: 120,
    FH_REFRESH_RETRY_MS: 35000,
    fetchWithDeadline: fetchImpl,
    CANARY_API: 'https://filehub.example.test/wp-json/czfh/v1'
  };
  return load('refreshCanarySession', stubs)('a1:u2', {});
}

async function main() {
  // F2a: токен мёртв по idle, но абсолютный срок жив, сервер просит подождать.
  {
    const shared = { token: 'old-token', expires: nowS() - 30, absoluteExpires: nowS() + 600,
                     inflight: null, refreshInflight: null, retryNotBefore: now + 30000,
                     scheduled: 0 };
    let networkCalled = false;
    await runRefreshScenario(shared, () => { networkCalled = true; throw new Error('нельзя'); })
      .then(tok => { throw new Error('ожидался отказ, а получен токен ' + tok); },
            err => {
              assert.equal(err.code, 'session_refresh_throttled',
                'F2a мёртвый токен не отдаётся — код session_refresh_throttled');
              assert.equal(err.status, 429, 'F2a статус 429 сохранён');
            });
    assert.equal(networkCalled, false, 'F2a сетевого вызова не было');
    assert.ok(shared.scheduled >= 1, 'F2a таймер повторного продления взведён');
  }

  // F2b: токен ещё ЖИВ (idle не истёк) — отдавать его можно и нужно.
  {
    const shared = { token: 'old-token', expires: nowS() + 300, absoluteExpires: nowS() + 600,
                     inflight: null, refreshInflight: null, retryNotBefore: now + 30000 };
    const token = await runRefreshScenario(shared, () => { throw new Error('нельзя'); });
    assert.equal(token, 'old-token', 'F2b живой токен возвращается как раньше');
  }

  // F2c: 429 при попытке продления не убивает сессию с живым абсолютным сроком.
  {
    const shared = { token: 'old-token', expires: nowS() - 30, absoluteExpires: nowS() + 600,
                     inflight: null, refreshInflight: null, retryNotBefore: 0, scheduled: 0 };
    const failing = () => Promise.reject(fhError('http_429', 429, false));
    await runRefreshScenario(shared, failing)
      .then(tok => { throw new Error('ожидался отказ, а получен токен ' + tok); },
            err => {
              assert.equal(err.code, 'session_refresh_throttled',
                'F2c 429 с мёртвым по idle токеном не превращается в session_refresh_failed');
            });
    assert.ok(shared.scheduled >= 1, 'F2c таймер повторного продления взведён');
  }

  /* ---------------------------------------------------------------- */
  /* F3: допуск по абсолютному сроку и часы                             */
  /* ---------------------------------------------------------------- */

  function saveStubs(offsetMs, extra) {
    const sessionStore = new Map();
    const stubs = {
      fhError, Math, JSON, Date: TestDate, console,
      state: { contextKey: 'a1:u2' },
      destroyed: false,
      resolveContext: () => 'a1:u2',
      contextIsCurrent: () => true,
      authStateFor: () => ({ token: null, expires: 0, absoluteExpires: 0, retryNotBefore: 0 }),
      sessionStorage: {
        getItem: k => (sessionStore.has(k) ? sessionStore.get(k) : null),
        setItem: (k, v) => sessionStore.set(k, v),
        removeItem: k => sessionStore.delete(k)
      },
      scheduleCanaryRefresh: () => {},
      FH_SESSION_STORAGE_PREFIX: 'czfh_session_v4:',
      telemetryEvents: [], telemetryTimer: null, flushTelemetry: () => {},
      setTimeout: () => 0,
      serverClockOffsetMs: offsetMs,
      serverNowS: load('serverNowS', { Math, JSON, Date: TestDate, console, serverClockOffsetMs: offsetMs }),
      noteServerTime: () => {}
    };
    return Object.assign(stubs, extra || {});
  }

  const token64 = 'a'.repeat(64);
  {
    // Сервер на 30 секунд впереди клиентских часов; сервер выдал absolute = server_now+1800.
    const serverNow = nowS() + 30;
    const stubs = saveStubs(30000);
    const save = load('saveCanarySession', stubs);
    const saved = save('a1:u2', token64, serverNow + 600, serverNow + 1800);
    assert.equal(saved, token64, 'F3a сессия принята при отставании часов браузера на 30 с');
  }
  {
    // Серверное время ещё неизвестно (первый ответ) — допуск 60 секунд.
    const stubs = saveStubs(null);
    const save = load('saveCanarySession', stubs);
    const saved = save('a1:u2', token64, nowS() + 600, nowS() + 1830);
    assert.equal(saved, token64, 'F3b сессия принята по клиентским часам при допуске 60 с');
  }
  {
    // Заведомо «длинная» сессия (больше политики сервера) — отвергается.
    const stubs = saveStubs(null);
    const save = load('saveCanarySession', stubs);
    assert.throws(() => save('a1:u2', token64, nowS() + 600, nowS() + 100000),
      err => err && err.code === 'session_invalid',
      'F3c сессия длиннее политики сервера отвергается');
  }
  {
    // С серверными часами та же проверка: absolute = server_now+1800 — предел.
    const serverNow = nowS() + 30;
    const stubs = saveStubs(30000);
    const save = load('saveCanarySession', stubs);
    assert.throws(() => save('a1:u2', token64, serverNow + 600, serverNow + 1900),
      err => err && err.code === 'session_invalid',
      'F3d по серверным часам предел абсолютного срока = server_now+1800');
  }

  /* ---------------------------------------------------------------- */
  /* F4: код ошибки не выдаёт прототип                                */
  /* ---------------------------------------------------------------- */

  {
    const e = fhError('constructor', 401);
    assert.ok(!/native code/.test(e.message),
      'F4 код ошибки вида constructor не выдаёт текст прототипа');
    const e2 = fhError('toString');
    assert.ok(!/function/.test(e2.message), 'F4 код toString не выдаёт функцию прототипа');
    const e3 = fhError('session_refresh_throttled', 429);
    assert.ok(/подождать/i.test(e3.message), 'F4 у нового кода есть человеческий текст');
  }

  /* ---------------------------------------------------------------- */
  /* F5: признак операции в телеметрии                                 */
  /* ---------------------------------------------------------------- */

  {
    const feature = load('telemetryFeature', { Math, JSON, console });
    const base = 'https://filehub.example.test/wp-json/czfh/v1';
    assert.equal(feature('POST', base + '/upload'), 'upload', 'F5 upload → upload');
    assert.equal(feature('POST', base + '/rename'), 'rename', 'F5 rename → rename');
    assert.equal(feature('POST', base + '/restore'), 'restore', 'F5 restore → restore');
    assert.equal(feature('POST', base + '/trash'), 'trash', 'F5 trash → trash');
    assert.equal(feature('GET', base + '/files?leadId=1'), 'files', 'F5 GET → files');
    assert.equal(feature('GET', base + '/calls_feed'), 'calls', 'F5 calls_feed → calls');
  }

  /* ---------------------------------------------------------------- */
  /* F6: общая сессия не стирается гонкой                               */
  /* ---------------------------------------------------------------- */

  function clearStubs(storedRow) {
    const store = new Map();
    if (storedRow) store.set('czfh_session_v4:a1:u2', JSON.stringify(storedRow));
    return {
      Math, JSON, Date: TestDate, console,
      FH_SESSION_STORAGE_PREFIX: 'czfh_session_v4:',
      sessionStorage: {
        getItem: k => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => store.set(k, v),
        removeItem: k => store.delete(k)
      },
      store
    };
  }

  {
    // В общей записи уже НОВЫЙ токен (соседняя карточка уже продлила сессию).
    // Отказ по старому токену не должен стирать новую сессию.
    const stubs = clearStubs({ context: 'a1:u2', token: 'b'.repeat(64), expires: nowS() + 600,
                              absoluteExpires: nowS() + 1800 });
    const clear = load('clearStoredSession', stubs);
    const removed = clear('a1:u2', 'a'.repeat(64));
    assert.equal(removed, false, 'F6 новая сессия в общей записи не тронута');
    assert.ok(stubs.store.get('czfh_session_v4:a1:u2'), 'F6 запись на месте после отказа по старому токену');
  }
  {
    // В общей записи тот самый токен, который отверг сервер — стираем.
    const stubs = clearStubs({ context: 'a1:u2', token: 'a'.repeat(64), expires: nowS() + 600,
                              absoluteExpires: nowS() + 1800 });
    const clear = load('clearStoredSession', stubs);
    const removed = clear('a1:u2', 'a'.repeat(64));
    assert.equal(removed, true, 'F6 мёртвая запись стёрта');
    assert.equal(stubs.store.get('czfh_session_v4:a1:u2'), undefined, 'F6 запись удалена');
  }

  /* ---------------------------------------------------------------- */
  /* F7/F8: повторная попытка авторизации                              */
  /* ---------------------------------------------------------------- */

  function ensureStubs(opts) {
    const calls = { ajax: 0, telemetry: [] };
    const self = {
      $authorizedAjax: (o) => {
        calls.ajax++;
        const d = $.Deferred ? $.Deferred() : null;
        return d;
      }
    };
    // минимальный Deferred
    function deferred(ok) {
      const d = {
        done(fn) { if (ok) fn({ sessionToken: 'c'.repeat(64), expires: nowS() + 600,
                                absoluteExpires: nowS() + 1800, serverTime: nowS() }); return d; },
        fail(fn) { if (!ok) fn({ status: 401 }, 'error'); return d; }
      };
      return d;
    }
    self.$authorizedAjax = () => { calls.ajax++; return deferred(opts.authorizedOk !== false); };
    const stubs = {
      fhError, Math, JSON, Date: TestDate, console, Promise, self,
      CANARY_API: 'https://filehub.example.test/wp-json/czfh/v1',
      state: { contextKey: 'a1:u2' },
      destroyed: false,
      resolveContext: () => 'a1:u2',
      contextIsCurrent: () => true,
      captureTelemetryContext: () => ({}),
      telemetry: (name, data) => calls.telemetry.push([name, data]),
      ensurePeerChannel: () => true,
      requestLivePeerSession: () => Promise.resolve(null),
      knownCanarySession: () => ({ token: null, expires: 0, absoluteExpires: 0 }),
      cachedCanarySession: () => null,
      authStateFor: () => ({ token: null, expires: 0, absoluteExpires: 0, inflight: null,
                             refreshInflight: null, retryNotBefore: 0 }),
      underAuthLock: (ctx, op) => Promise.resolve().then(op),
      knownAttempt: () => opts.blocked === true,
      authRetryAllowed: () => opts.retryAllowed === true,
      reserveAttempt: (ctx, force) => (opts.reserveOk === false ? null : 'attempt-1'),
      saveCanarySession: () => 'c'.repeat(64),
      recordAttemptSession: () => {},
      noteServerTime: () => {},
      serverNowS: () => nowS(),
      FH_REAUTH_RETRY_COOLDOWN_MS: 300000
    };
    return { stubs, calls };
  }

  {
    // F7: маркер свежий, но сессии нет ни у нас, ни у донора — делаем ОДНУ новую
    // попытку (amoCRM выдаёт JWT заново) вместо глухой блокировки на 30 минут.
    const { stubs, calls } = ensureStubs({ blocked: true, retryAllowed: true });
    const ensure = load('ensureCanarySession', stubs);
    const token = await ensure({});
    assert.equal(calls.ajax, 1, 'F7 выполнена ровно одна новая попытка авторизации');
    assert.equal(token, 'c'.repeat(64), 'F7 сессия получена и возвращена');
    assert.ok(calls.telemetry.some(t => t[0] === 'session_attempt'), 'F7 попытка зафиксирована в телеметрии');
  }
  {
    // F7b: попытка сделана, но amoCRM вернул тот же JWT — сервер отказал.
    // Пользователь должен получить прежний честный текст с кнопкой восстановления.
    const { stubs, calls } = ensureStubs({ blocked: true, retryAllowed: true, authorizedOk: false });
    const ensure = load('ensureCanarySession', stubs);
    await ensure({})
      .then(() => { throw new Error('ожидался отказ'); },
            err => {
              assert.equal(err.code, 'session_reauth_blocked',
                'F7b отказ по тому же JWT даёт session_reauth_blocked (кнопка восстановления)');
            });
    assert.equal(calls.ajax, 1, 'F7b попытка была одна');
  }
  {
    // F8: пауза между повторными попытками — клиент не долбит сервер.
    const { stubs, calls } = ensureStubs({ blocked: true, retryAllowed: false });
    const ensure = load('ensureCanarySession', stubs);
    await ensure({})
      .then(() => { throw new Error('ожидался отказ'); },
            err => {
              assert.equal(err.code, 'session_reauth_blocked', 'F8 гейт сохранён');
            });
    assert.equal(calls.ajax, 0, 'F8 во время паузы новых попыток нет');
  }
  {
    // F8b: маркера нет вовсе — обычная попытка без повторной логики.
    const { stubs, calls } = ensureStubs({ blocked: false, retryAllowed: false });
    const ensure = load('ensureCanarySession', stubs);
    const token = await ensure({});
    assert.equal(calls.ajax, 1, 'F8b обычная попытка выполнена');
    assert.equal(token, 'c'.repeat(64), 'F8b сессия получена');
  }

  console.log('PASS: ошибка превью больше не создаёт бесконечный цикл обновлений списка');
  console.log('PASS: мёртвый токен не отдаётся — сессия ждёт окно троттлинга');
  console.log('PASS: расхождение часов браузера и сервера больше не убивает сессию');
  console.log('PASS: код ошибки не выдаёт содержимое прототипа');
  console.log('PASS: телеметрия различает upload/rename/restore/trash/calls');
  console.log('PASS: общая сессия не стирается гонкой');
  console.log('PASS: при отсутствии сессии делается одна свежая попытка авторизации');
}

main().catch(err => { console.error('FAIL:', (err && err.message) || err); process.exit(1); });
