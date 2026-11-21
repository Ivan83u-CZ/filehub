'use strict';
/**
 * FileHub widget 2.8.16 — регрессия восстановления сессии.
 *
 * Проверяет клиентскую часть плана REAUTH-GATE-PLAN.md (виджет):
 *   R1  401 с телом {error:'session_refreshable'} разбирается как session_refreshable,
 *       а не как безликое http_401;
 *   R2  на session_refreshable клиент ПРОДЛЕВАЕТ сессию и повторяет запрос один раз;
 *   R3  токен НЕ стирается при session_refreshable (он ещё пригоден для продления);
 *   R4  токен стирается при по-настоящему мёртвой сессии (session_invalid и др.);
 *   R5  сессия, истёкшая по idle, но живая по абсолютному сроку, ПРОДЛЕЖАЕТСЯ;
 *   R6  ensureCanarySession при истёкшей idle-сессии зовёт refresh, а не отказывает;
 *   R7  окно поиска живой вкладки-донора увеличено до 5000 мс.
 *
 * Функции извлекаются из script.js исходником и выполняются с подменёнными
 * зависимостями (тот же приём, что в session_guard_test.js): проверяется
 * РЕАЛЬНЫЙ код виджета, а не его копия.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'script.js'), 'utf8');
const extract = name => source.match(new RegExp(`function ${name}\\([^\\n]*\\)\\{[\\s\\S]*?\\n      \\}`));

for (const name of ['fhError', 'fetchWithDeadline', 'amoRequest', 'refreshCanarySession',
                    'ensureCanarySession', 'telemetryFeature']) {
  assert(extract(name), `исходник ${name} существует`);
}

let now = 1_700_000_000_000;              // мс
const nowS = () => Math.floor(now / 1000);
class TestDate extends Date {
  static now() { return now; }
}

const fhError = new Function(`return (${extract('fhError')[0]});`)();

function makeStubs(overrides) {
  const calls = {
    telemetry: [], refreshed: 0, wiped: false, sent: [], saved: null,
    // Общее in-memory состояние сессии: authStateFor() обязан возвращать
    // ОДИН И ТОТ ЖЕ объект, иначе виджет не видел бы свою сессию.
    authState: { token: null, expires: 0, absoluteExpires: 0, inflight: null,
                 refreshInflight: null, retryNotBefore: 0 }
  };
  const sessionStore = new Map();
  // Общая запись сессии вкладки: реальный виджет хранит токен здесь.
  sessionStore.set('czfh_session_v4:a123:u4242',
    JSON.stringify({ context: 'a123:u4242', token: 'old-token', expires: nowS() + 600,
                     absoluteExpires: nowS() + 1800 }));
  const sessionStorage = {
    getItem: k => (sessionStore.has(k) ? sessionStore.get(k) : null),
    setItem: (k, v) => sessionStore.set(k, v),
    removeItem: k => { calls.wiped = true; sessionStore.delete(k); }
  };
  const base = {
    fhError, Date: TestDate, Math, JSON, Promise, console, calls, sessionStorage,
    state: { contextKey: 'a123:u4242', canary: true, production: true, backend: { key: 'k' } },
    destroyed: false,
    CANARY_API: 'https://filehub.example.test/wp-json/czfh/v1',
    FH_SESSION_STORAGE_PREFIX: 'czfh_session_v4:',
    FH_REFRESH_EARLY_S: 120,
    FH_REFRESH_RETRY_MS: 3000,
    resolveContext: () => 'a123:u4242',
    contextIsCurrent: () => true,
    captureTelemetryContext: () => ({}),
    // Серверные часы: виджет сверяет верхнюю границу абсолютного срока по времени
    // сервера, а не по часам браузера (см. noteServerTime в script.js).
    noteServerTime: () => {},
    serverNowS: () => nowS(),
    telemetryFeature: load('telemetryFeature', {}),
    // Реальная функция очистки общей записи сессии: виджет вызывает её при
    // отказе сервера, и она стирает запись только если в ней тот же токен.
    clearStoredSession: load('clearStoredSession', { Math, JSON, Date: TestDate, console,
      FH_SESSION_STORAGE_PREFIX: 'czfh_session_v4:', sessionStorage }),
    newCorrId: () => 'corr-1',
    telemetry: (name, data) => calls.telemetry.push([name, data]),
    authStateFor: () => calls.authState,
    clearRefreshTimer: () => {},
    scheduleCanaryRefresh: () => {},
    clearCanarySession: () => { calls.wiped = true; },
    underAuthLock: (ctx, op) => Promise.resolve().then(op),
    requestLivePeerSession: () => Promise.resolve(null),
    ensurePeerChannel: () => true,
    knownCanarySession: () => ({ token: null, expires: 0, absoluteExpires: 0 }),
    saveCanarySession: (ctx, token, expires, absoluteExpires) => {
      calls.saved = { token, expires, absoluteExpires };
      return token;
    }
  };
  return Object.assign(base, overrides || {});
}

function load(name, stubs) {
  const names = Object.keys(stubs);
  const values = names.map(k => stubs[k]);
  return new Function(...names, `return (${extract(name)[0]});`)(...values);
}

async function main() {
  // ============================ R1: разбор 401 ==============================
  {
    const stubs = makeStubs({
      fetch: () => Promise.resolve({
        status: 401, ok: false, headers: { get: () => null },
        json: () => Promise.resolve({ error: 'session_refreshable' })
      })
    });
    const fetchWithDeadline = load('fetchWithDeadline', stubs);
    await fetchWithDeadline('https://filehub.example.test/wp-json/czfh/v1/files', { method: 'GET' },
                            'GET', 'files', 'corr-1', {})
      .then(() => { throw new Error('ожидалась ошибка'); },
            err => {
              assert.equal(err.code, 'session_refreshable', 'R1 код session_refreshable разобран из тела 401');
              assert.equal(err.status, 401, 'R1 статус 401 сохранён');
            });
  }
  {
    const stubs = makeStubs({
      fetch: () => Promise.resolve({
        status: 401, ok: false, headers: { get: () => null },
        json: () => Promise.reject(new Error('not json'))
      })
    });
    const fetchWithDeadline = load('fetchWithDeadline', stubs);
    await fetchWithDeadline('https://filehub.example.test/wp-json/czfh/v1/files', { method: 'GET' },
                            'GET', 'files', 'corr-1', {})
      .then(() => { throw new Error('ожидалась ошибка'); },
            err => { assert.equal(err.code, 'http_401', 'R1 без тела остаётся http_401'); });
  }

  // ============ R2/R3/R4: amoRequest — продление, повтор, очистка ============
  async function runAmoRequest(fetchBehaviour, refreshToken) {
    const stubs = makeStubs({
      ensureCanarySession: () => Promise.resolve('old-token'),
      refreshCanarySession: () => { stubs.calls.refreshed++; return Promise.resolve(refreshToken || 'new-token'); },
      fetchWithDeadline: (url, settings) => {
        stubs.calls.sent.push(settings.headers['X-FH-Session']);
        return fetchBehaviour(settings);
      }
    });
    const amoRequest = load('amoRequest', stubs);
    try {
      const result = await amoRequest('https://filehub.example.test/wp-json/czfh/v1/files?leadId=1&category=photos');
      return { ok: true, result, calls: stubs.calls };
    } catch (err) {
      return { ok: false, err, calls: stubs.calls };
    }
  }
  {
    const out = await runAmoRequest(
      settings => settings.headers['X-FH-Session'] === 'old-token'
        ? Promise.reject(fhError('session_refreshable', 401, false))
        : Promise.resolve(['ok']),
      'new-token');
    assert.equal(out.ok, true, 'R2 запрос повторён после продления и завершился успехом');
    assert.equal(out.calls.refreshed, 1, 'R2 продление выполнено ровно один раз');
    assert.deepEqual(out.calls.sent, ['old-token', 'new-token'], 'R2 второй запрос ушёл с новым токеном');
    assert.equal(out.calls.wiped, false, 'R3 токен не стёрт при session_refreshable');
  }
  {
    const out = await runAmoRequest(() => Promise.reject(fhError('session_invalid', 401, false)), 'new-token');
    assert.equal(out.ok, false, 'R4 мёртвая сессия не проходит');
    assert.equal(out.err.code, 'session_invalid', 'R4 код ошибки сохранён');
    assert.equal(out.calls.refreshed, 0, 'R4 продление не вызывается для мёртвой сессии');
    assert.equal(out.calls.wiped, true, 'R4 токен стёрт при session_invalid');
  }
  {
    const out = await runAmoRequest(() => Promise.reject(fhError('session_absolute_expired', 401, false)), 'new-token');
    assert.equal(out.calls.wiped, true, 'R4 токен стёрт при session_absolute_expired');
  }

  // ============ R5: refreshCanarySession продлевает истёкшую сессию ==========
  {
    const stubs = makeStubs({
      knownCanarySession: () => ({ token: 'old-token', expires: nowS() - 10, absoluteExpires: nowS() + 600 }),
      fetchWithDeadline: () => Promise.resolve({ sessionToken: 'old-token', expires: nowS() + 600,
                                                 absoluteExpires: nowS() + 600 })
    });
    stubs.calls.authState.token = 'old-token';
    stubs.calls.authState.expires = nowS() - 10;
    stubs.calls.authState.absoluteExpires = nowS() + 600;
    const refreshCanarySession = load('refreshCanarySession', stubs);
    const token = await refreshCanarySession('a123:u4242', {});
    assert.equal(token, 'old-token', 'R5 refresh вернул тот же токен (stable-режим)');
    assert.ok(stubs.calls.saved && stubs.calls.saved.expires > nowS(), 'R5 idle-дедлайн продлён');
  }
  {
    const stubs = makeStubs({
      knownCanarySession: () => ({ token: 'old-token', expires: nowS() - 10, absoluteExpires: nowS() - 5 })
    });
    stubs.calls.authState.token = 'old-token';
    stubs.calls.authState.expires = nowS() - 10;
    stubs.calls.authState.absoluteExpires = nowS() - 5;
    const refreshCanarySession = load('refreshCanarySession', stubs);
    await refreshCanarySession('a123:u4242', {})
      .then(() => { throw new Error('ожидался отказ'); },
            err => { assert.equal(err.code, 'session_absolute_expired', 'R5 истёкший абсолютный срок отвергается'); });
  }

  // ============ R6: ensureCanarySession зовёт refresh =======================
  {
    let refreshCalls = 0;
    const stubs = makeStubs({
      knownCanarySession: () => ({ token: 'old-token', expires: nowS() - 10, absoluteExpires: nowS() + 600 }),
      refreshCanarySession: () => { refreshCalls++; return Promise.resolve('new-token'); }
    });
    const ensureCanarySession = load('ensureCanarySession', stubs);
    const token = await ensureCanarySession({});
    assert.equal(token, 'new-token', 'R6 истёкшая idle-сессия продлена, а не отвергнута');
    assert.equal(refreshCalls, 1, 'R6 refresh вызван ровно один раз');
  }
  {
    const stubs = makeStubs({
      knownCanarySession: () => ({ token: 'old-token', expires: nowS() - 10, absoluteExpires: nowS() - 5 })
    });
    const ensureCanarySession = load('ensureCanarySession', stubs);
    await ensureCanarySession({})
      .then(() => { throw new Error('ожидался отказ'); },
            err => { assert.equal(err.code, 'session_absolute_expired', 'R6 истёкший абсолютный срок отвергается'); });
  }

  // ============================ R7: окно peer-вкладки ========================
  {
    const m = source.match(/var FH_PEER_REQUEST_WAIT_MS\s*=\s*(\d+)/);
    assert(m, 'R7 константа окна peer найдена');
    assert.equal(Number(m[1]), 5000, 'R7 окно поиска живой вкладки = 5000 мс');
  }

  console.log('PASS: 401 разбирается, сессия продлевается автоматически, токен не стирается зря');
  console.log('PASS: истёкшая по idle сессия продлевается, пока жив абсолютный срок');
  console.log('PASS: peer-вкладка ищется дольше (5000 мс)');
}

main().catch(err => { console.error('FAIL:', (err && err.message) || err); process.exit(1); });
