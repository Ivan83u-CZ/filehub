'use strict';
/**
 * Server thumbnail contract regression for widget 2.8.20.
 *
 * The backend thumbnailer supports JPEG/PNG/WebP/GIF only. BMP/AVIF are valid
 * browser previews but have no server thumbUrl; they must not emit a failure.
 * Failures for supported images are split into a missing URL and a real image
 * load failure so the next telemetry sample is actionable.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'script.js'), 'utf8');
function extractFn(name) {
  const start = source.indexOf('function ' + name + '(');
  if (start < 0) throw new Error('function not found: ' + name);
  let depth = 0, started = false;
  for (let index = start; index < source.length; index++) {
    if (source[index] === '{') { depth++; started = true; }
    else if (source[index] === '}' && started && --depth === 0) return source.slice(start, index + 1);
  }
  throw new Error('unbalanced function: ' + name);
}

function load(name, stubs) {
  const names = Object.keys(stubs);
  return new Function(...names, `return (${extractFn(name)});`)(...names.map(key => stubs[key]));
}

function makeEl() {
  const el = {
    handlers: {}, attrs: {}, children: [], classes: {}, textValue: '', length: 1,
    append(x) { el.children.push(x); return el; },
    appendTo() { return el; },
    on(ev, fn) { (el.handlers[ev] = el.handlers[ev] || []).push(fn); return el; },
    off() { return el; },
    attr(k, value) { if (value === undefined) return el.attrs[k]; el.attrs[k] = value; return el; },
    removeAttr(k) { delete el.attrs[k]; return el; },
    prop() { return el; },
    text(value) { if (value === undefined) return el.textValue; el.textValue = String(value); return el; },
    empty() { el.children = []; return el; },
    addClass(name) { el.classes[name] = true; return el; },
    removeClass() { return el; },
    toggleClass() { return el; },
    hasClass(name) { return !!el.classes[name]; },
    find() { return makeEl(); },
    closest() { return makeEl(); },
    is() { return false; }
  };
  return el;
}

function runThumb(item, thumb, failCount) {
  const rows = [];
  const children = [];
  const $ = function () {
    const el = makeEl();
    children.push(el);
    return el;
  };
  $.contains = () => true;
  const state = { cat: 'photos', leadId: '123', production: true, canary: false,
                  trashView: false, historyView: false, davView: false };
  const stubs = {
    $, state, destroyed: false, document: {}, Date, Math, JSON, console,
    isImageItem: load('isImageItem', {}),
    isServerThumbItem: load('isServerThumbItem', {}),
    ext: name => /\.([^.]+)$/.exec(name || '')?.[1]?.toLowerCase() || '',
    thumbUrl: () => thumb,
    captureTelemetryContext: () => ({ operator_id: '2' }),
    telemetry: (event, details) => rows.push({ event, details }),
    signedExpiry: load('signedExpiry', { Math, JSON }),
    requestListAutoRefresh: () => {},
    setTimeout: fn => { fn(); return 0; },
    clearTimeout: () => {}
  };
  const createThumbNode = load('createThumbNode', stubs);
  const node = createThumbNode(item);

  if (failCount > 0) {
    const img = node.children[0];
    const errors = (img && img.handlers && img.handlers.error) || [];
    assert.ok(errors.length > 0, 'image error handler is installed');
    for (let i = 0; i < failCount; i++) errors.forEach(fn => fn());
  }
  return { node, rows, children };
}

const isServerThumbItem = load('isServerThumbItem', {});
for (const name of ['a.jpg', 'a.JPEG', 'a.png', 'a.webp', 'a.gif']) {
  assert.equal(isServerThumbItem({ name }), true, name + ' is supported by the thumbnailer');
}
for (const name of ['a.bmp', 'a.avif', 'a.heic', 'a.svg']) {
  assert.equal(isServerThumbItem({ name }), false, name + ' is not a server thumbnail format');
}
assert.equal(isServerThumbItem({ name: 'scan', contentType: 'image/pjpeg' }), true,
  'legacy pjpeg content type is supported');

for (const item of [
  { name: 'photo.avif', contentType: 'image/avif' },
  { name: 'photo.bmp', contentType: 'image/bmp' }
]) {
  const result = runThumb(item, '', 0);
  assert.equal(result.rows.length, 0, item.name + ' does not emit a false thumb failure');
  assert.equal(result.node.textValue, item.name.endsWith('.avif') ? 'AVIF' : 'BMP',
    'unsupported thumbnail format falls back to a file tile');
}

{
  const result = runThumb({ name: 'photo.png', contentType: 'image/png' }, '', 0);
  assert.equal(result.rows.length, 1, 'a supported image without thumbUrl is reported');
  assert.equal(result.rows[0].event, 'thumb_failed');
  assert.equal(result.rows[0].details.error_code, 'thumb_url_missing');
}

{
  const result = runThumb({ name: 'photo.png', contentType: 'image/png' },
    'https://filehub.example.test/wp-json/czfh/v1/thumb?leadId=1&exp=4102444800', 2);
  assert.equal(result.rows.length, 1, 'a real image failure is reported once');
  assert.equal(result.rows[0].details.error_code, 'thumb_image_failed');
}

console.log('PASS: server thumbnail formats and fallback telemetry are consistent');
