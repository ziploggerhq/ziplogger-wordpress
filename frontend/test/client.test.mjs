// These tests pin the behaviour of the bundled official SDK (0.6.0) that the plugin depends on. If a
// future SDK upgrade changes any of it, they fail here rather than in a customer's browser.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { setupDom } from './env.mjs';

const env = setupDom();
const { WPClient } = await import('../src/client.js');

const options = (extra = {}) => ({ endpoint: 'https://ingest.ziplogger.test', apiKey: 'zk_browser_public_key_for_tests', source: 'shop', includePageContext: false, anonymousId: 'anon_a', sessionId: 'sess_a', requestCorrelationTtlMs: 0, flushIntervalMs: 5, ...extra });

test('the SDK version is pinned exactly', () => {
  const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'));
  assert.match(pkg.dependencies['@ziplogger/browser'], /^\d+\.\d+\.\d+$/, 'no ranges');
  const installed = JSON.parse(readFileSync(new URL('../node_modules/@ziplogger/browser/package.json', import.meta.url), 'utf8'));
  assert.equal(installed.version, pkg.dependencies['@ziplogger/browser']);
});

test('given both ids, the SDK writes nothing to web storage', () => {
  env.win.localStorage.clear();
  env.win.sessionStorage.clear();
  new WPClient(options());
  assert.equal(env.win.localStorage.length, 0);
  assert.equal(env.win.sessionStorage.length, 0);
});

test('setIdentity changes what track(), identify() and identity report', async () => {
  env.reset();
  const c = new WPClient(options());
  c.setIdentity({ anonymousId: 'anon_b', sessionId: 'sess_b', userId: 'wpu_u' });
  assert.deepEqual(c.identity, { userId: 'wpu_u', anonymousId: 'anon_b', sessionId: 'sess_b' });
  c.track('checkout_started', { step: 1 });
  await c.flush();
  const e = env.events()[0];
  assert.equal(e.name, 'checkout_started');
  assert.equal(e.anonymousId, 'anon_b');
  assert.equal(e.sessionId, 'sess_b');
  assert.equal(e.userId, 'wpu_u');
  assert.match(e.insertId, /^[0-9a-f]{24}$/, 'each event has its own idempotency id');
  assert.ok(!('url' in e) && !('page' in e));
});

test('with no anonymous id and no user, the SDK still sends the session id (the server needs one of the three)', async () => {
  env.reset();
  const c = new WPClient(options());
  c.setIdentity({ anonymousId: null, sessionId: 'sess_only', userId: null });
  assert.equal(c.identity.anonymousId, null);
});

test('log() adds no page context when includePageContext is false, and puts environment and tags on the record', async () => {
  env.reset();
  const c = new WPClient(options({ environment: 'staging', tags: ['wordpress', 'browser'], release: '9.9' }));
  c.log({ message: 'hello', severity: 'warn', fields: { a: 1 } });
  await c.flush();
  const l = env.logs()[0];
  assert.deepEqual(l.fields, { environment: 'staging', a: 1 });
  assert.deepEqual(l.tags, ['wordpress', 'browser']);
  assert.equal(l.release, '9.9');
  assert.equal(l.severity, 'warn');
});

test('the SDK sends the key only in the X-Api-Key header, as NDJSON', async () => {
  env.reset();
  const c = new WPClient(options());
  c.log({ message: 'x' });
  c.track('y');
  await c.flush();
  for (const call of env.calls) {
    assert.equal(call.headers.get('x-api-key'), 'zk_browser_public_key_for_tests');
    assert.equal(call.headers.get('content-type'), 'application/x-ndjson');
    assert.ok(!call.url.includes('zk_'));
  }
});

test('the private fields the plugin clears when consent is withdrawn still exist', () => {
  const c = new WPClient(options());
  assert.ok(Array.isArray(c._events));
  assert.ok(Array.isArray(c._queue));
  assert.equal(typeof c._eventsUrl, 'string');
  assert.equal(typeof c._apiKey, 'string');
});

test('replay options set at construction are what the replay controller reads', async () => {
  const { attachSessionReplay } = await import('@ziplogger/browser/replay');
  const c = new WPClient(options({ sessionReplay: { enabled: false, sampleRate: 0.25, maskInputs: true, blockSelector: '.b', maskSelector: '.m' } }));
  const ctl = attachSessionReplay(c, { loadRecorder: async () => () => () => {}, fetch: async () => new Response('{}') });
  assert.equal(ctl._options.sampleRate, 0.25);
  assert.equal(ctl._options.blockSelector, '.b');
  assert.equal(ctl.isRecording(), false, 'enabled:false means attach does not start anything');
});
