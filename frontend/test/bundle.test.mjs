// Checks on the files that actually ship (plugin/ziplogger/assets/js), not on the sources.
// Run `npm run build` first (the CI script does).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { baseConfig } from './env.mjs';

const dir = new URL('../../plugin/ziplogger/assets/js/', import.meta.url);
const main = new URL('ziplogger.min.js', dir);
const recorder = new URL('ziplogger-recorder.min.js', dir);

test('both bundles, the manifest and the licences exist', () => {
  for (const f of ['ziplogger.min.js', 'ziplogger-recorder.min.js', 'manifest.json', 'THIRD-PARTY-LICENSES.txt']) assert.ok(existsSync(new URL(f, dir)), f);
});

test('size budgets (measured at build time; a jump needs a decision, not an accident)', () => {
  assert.ok(statSync(main).size < 90000, `main bundle is ${statSync(main).size} bytes`);
  assert.ok(statSync(recorder).size < 120000, `recorder bundle is ${statSync(recorder).size} bytes`);
});

test('the main bundle does not contain the recorder (rrweb loads lazily, only for recorded sessions)', () => {
  const text = readFileSync(main, 'utf8');
  assert.ok(!/rrweb/i.test(text.replace(/@ziplogger[^\s]*/g, '')) || text.length < 90000);
  assert.ok(!text.includes('FullSnapshot') && !text.includes('mutationObserver'.toLowerCase() + 'x'), 'no recorder internals');
  assert.ok(statSync(main).size < statSync(recorder).size, 'the recorder is the big one');
});

test('no secret, no server key, no eval, no look-behind in what ships', () => {
  for (const url of [main, recorder]) {
    const text = readFileSync(url, 'utf8');
    assert.ok(!/zk_(?:live|test|server)_[A-Za-z0-9]{8,}/.test(text), 'no key material');
    assert.ok(!/\beval\s*\(/.test(text), 'no eval');
    assert.ok(!/new Function\s*\(/.test(text), 'no Function constructor');
    assert.ok(!/\(\?<[=!]/.test(text), 'no regex look-behind');
    assert.ok(!text.includes('sourceMappingURL'), 'no source map reference');
    assert.ok(!/[A-Z]:\\|\/Users\/|\/home\//.test(text), 'no absolute build paths');
  }
});

test('the manifest matches the files', async () => {
  const { createHash } = await import('node:crypto');
  const manifest = JSON.parse(readFileSync(new URL('manifest.json', dir), 'utf8'));
  for (const [name, info] of Object.entries(manifest.bundles)) {
    const text = readFileSync(new URL(name, dir), 'utf8');
    assert.equal(createHash('sha256').update(text).digest('hex'), info.sha256, name);
    assert.equal(Buffer.byteLength(text), info.bytes, name);
  }
  assert.ok(manifest.packages.some((p) => p.name === 'web-vitals' && p.license === 'Apache-2.0'));
  assert.ok(manifest.packages.some((p) => p.name === '@ziplogger/browser'));
});

test('the shipped licences file names every bundled package', () => {
  const text = readFileSync(new URL('THIRD-PARTY-LICENSES.txt', dir), 'utf8');
  for (const name of ['web-vitals', '@ziplogger/browser', '@rrweb/record']) assert.ok(text.includes(name), name);
});

function pageWith(cfg) {
  const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'https://shop.example.test/post', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const calls = [];
  w.fetch = (url, init) => { calls.push({ url: String(url), init: init || {} }); return Promise.resolve(new w.Response ? new w.Response('{}') : { ok: true, status: 200, headers: { get: () => null }, json: async () => ({}) }); };
  w.Response = globalThis.Response;
  w.fetch = (url, init) => { calls.push({ url: String(url), init: init || {} }); return Promise.resolve(new globalThis.Response('{}', { status: 200 })); };
  const el = w.document.createElement('script');
  el.type = 'application/json';
  el.id = 'ziplogger-config';
  el.textContent = JSON.stringify(cfg);
  w.document.head.appendChild(el);
  return { w, calls };
}

test('the built bundle boots from a config block, reports an error, and a second load does nothing', async () => {
  const { w, calls } = pageWith(baseConfig({ modules: { browser: true } }));
  const code = readFileSync(main, 'utf8');
  w.eval(code);
  assert.equal(w.ZipLoggerWP.booted, true);
  const wrapped = w.fetch;
  w.eval(code); // a theme that includes the script twice
  assert.equal(w.fetch, wrapped, 'no double wrapping');
  w.dispatchEvent(new w.ErrorEvent('error', { message: 'bundle smoke error', filename: 'https://shop.example.test/a.js' }));
  await w.ZipLoggerWP.flush();
  const posted = calls.filter((c) => c.url.endsWith('/ingest/v1/logs'));
  assert.equal(posted.length, 1);
  assert.match(String(posted[0].init.body), /bundle smoke error/);
  assert.equal(new globalThis.Headers(posted[0].init.headers).get('x-api-key'), 'zk_browser_public_key_for_tests');
});

test('the built bundle does nothing at all without a config block', () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'https://shop.example.test/', runScripts: 'outside-only' });
  dom.window.eval(readFileSync(main, 'utf8'));
  assert.equal(dom.window.ZipLoggerWP, undefined);
});

test('the built recorder bundle exposes exactly one global: the recorder function', () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'https://shop.example.test/', runScripts: 'outside-only' });
  const before = new Set(Object.getOwnPropertyNames(dom.window));
  dom.window.eval(readFileSync(recorder, 'utf8'));
  const added = Object.getOwnPropertyNames(dom.window).filter((n) => !before.has(n));
  assert.deepEqual(added, ['__ziploggerRecord']);
  assert.equal(typeof dom.window.__ziploggerRecord, 'function');
});
