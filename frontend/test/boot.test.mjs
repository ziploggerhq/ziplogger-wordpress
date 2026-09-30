import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

let env = setupDom();
const { boot, readConfig } = await import('../src/index.js');

function page(cfgOverrides = {}, opts = {}) {
  env = setupDom({ url: opts.url || 'https://shop.example.test/blog/hello?token=secret' });
  const cfg = baseConfig(cfgOverrides);
  if (!opts.noConfigElement) {
    const el = env.doc.createElement('script');
    el.type = 'application/json';
    el.id = 'ziplogger-config';
    el.textContent = JSON.stringify(cfg);
    env.doc.head.appendChild(el);
  }
  return { cfg, env };
}

test('readConfig: absent, malformed and wrong-version configuration all mean "do nothing"', () => {
  const p = page({}, { noConfigElement: true });
  assert.equal(readConfig(p.env.doc), null);
  const bad = env.doc.createElement('script');
  bad.id = 'ziplogger-config';
  bad.textContent = '{not json';
  env.doc.head.appendChild(bad);
  assert.equal(readConfig(env.doc), null);
  bad.textContent = JSON.stringify({ v: 2, key: 'k', endpoint: 'https://x.test', modules: {} });
  assert.equal(readConfig(env.doc), null);
  bad.textContent = JSON.stringify({ v: 1, key: '', endpoint: 'https://x.test', modules: {} });
  assert.equal(readConfig(env.doc), null);
});

test('boot without configuration, or with a non-HTTPS endpoint, does nothing and does not throw', () => {
  const none = page({}, { noConfigElement: true });
  assert.equal(boot(none.env.win, none.env.doc), null);
  const plain = page({ endpoint: 'http://ingest.ziplogger.test' });
  assert.equal(boot(plain.env.win, plain.env.doc), null);
  assert.equal(plain.env.win.ZipLoggerWP, undefined);
  const local = page({ endpoint: 'http://localhost:5080', allowInsecureEndpoint: true });
  assert.ok(boot(local.env.win, local.env.doc), 'plain HTTP to localhost only when the site explicitly allowed it');
});

test('with every module off the plugin script changes nothing on the page', async () => {
  const p = page();
  const originalFetch = p.env.win.fetch;
  const originalPush = p.env.win.history.pushState;
  const api = boot(p.env.win, p.env.doc);
  assert.ok(api.booted);
  assert.equal(p.env.win.fetch, originalFetch, 'fetch is not wrapped');
  assert.equal(p.env.win.history.pushState, originalPush, 'history is not wrapped');
  assert.equal(p.env.win.localStorage.length, 0);
  assert.equal(p.env.win.sessionStorage.length, 0);
  assert.equal(p.env.doc.cookie, '');
  p.env.win.dispatchEvent(new p.env.win.ErrorEvent('error', { message: 'boom' }));
  await api.flush();
  await p.env.settle();
  assert.equal(p.env.calls.length, 0, 'not one request');
});

test('booting twice yields one instance: no second wrapper, no duplicate reports', async () => {
  const p = page({ modules: { browser: true } });
  const a = boot(p.env.win, p.env.doc);
  const wrapped = p.env.win.fetch;
  const b = boot(p.env.win, p.env.doc);
  assert.equal(a, b);
  assert.equal(p.env.win.fetch, wrapped);
  p.env.win.dispatchEvent(new p.env.win.ErrorEvent('error', { message: 'once only', filename: 'https://shop.example.test/a.js' }));
  await a.flush();
  assert.equal(p.env.logs().filter((l) => l.message === 'once only').length, 1);
});

test('browser monitoring: an error is delivered as a sanitized log with the site identity and no page URL', async () => {
  const p = page({ modules: { browser: true } });
  const api = boot(p.env.win, p.env.doc);
  p.env.win.dispatchEvent(new p.env.win.ErrorEvent('error', { message: 'Cannot use token=abc123', filename: 'https://shop.example.test/wp-content/plugins/x/app.js?ver=1', lineno: 3, colno: 9 }));
  await api.flush();
  const logs = p.env.logs();
  assert.equal(logs.length, 1);
  const l = logs[0];
  assert.equal(l.severity, 'error');
  assert.equal(l.source, 'shop.example.test');
  assert.equal(l.release, '1.2.3');
  assert.deepEqual(l.tags, ['wordpress', 'browser']);
  assert.equal(l.fields.environment, 'production');
  assert.equal(l.fields.path, '/blog/hello');
  assert.equal(l.fields.eventType, 'js_error');
  assert.ok(!('url' in l.fields) && !('userAgent' in l.fields), 'the SDK added no page context');
  assert.ok(!JSON.stringify(l).includes('abc123') && !JSON.stringify(l).includes('secret') && !JSON.stringify(l).includes('ver=1'));
  const post = p.env.callsTo('/ingest/v1/logs')[0];
  assert.equal(post.headers.get('x-api-key'), 'zk_browser_public_key_for_tests');
  assert.equal(p.env.win.sessionStorage.length + p.env.win.localStorage.length, 0, 'browser monitoring alone stores nothing on the device');
});

test('browser monitoring keeps working when the ZipLogger service is down: the page never notices', async () => {
  const p = page({ modules: { browser: true } });
  p.env.respond((r) => { if (r.url.includes('ziplogger.test')) throw new TypeError('offline'); return new Response('ok'); });
  const api = boot(p.env.win, p.env.doc);
  p.env.win.dispatchEvent(new p.env.win.ErrorEvent('error', { message: 'x' }));
  // The SDK retries with unref()'d timers; keep the loop alive so the test can observe the outcome.
  const keep = setInterval(() => {}, 50);
  try {
    await assert.doesNotReject(api.flush());
    const res = await p.env.win.fetch('/wp-json/x');
    assert.equal(res.status, 200);
  } finally { clearInterval(keep); }
});

test('a failed same-site request becomes a log entry; the telemetry request itself does not', async () => {
  const p = page({ modules: { browser: true } });
  p.env.respond((r) => (r.url.includes('/wp-json/') ? new Response('err', { status: 500 }) : new Response('{}', { status: 200 })));
  const api = boot(p.env.win, p.env.doc);
  await p.env.win.fetch('/wp-json/wc/store/v1/cart?nonce=n1');
  await p.env.settle();
  await api.flush();
  const entries = p.env.logs();
  assert.equal(entries.length, 1);
  assert.equal(entries[0].fields.eventType, 'request_failed');
  assert.equal(entries[0].fields.requestPath, '/wp-json/wc/store/v1/cart');
  assert.equal(entries[0].fields.path, '/blog/hello');
});

test('analytics: nothing before consent, no ids on the device, then page view after grant, and the ids persist', async () => {
  const p = page({ modules: { analytics: true } });
  const api = boot(p.env.win, p.env.doc);
  await api.flush();
  assert.equal(p.env.calls.length, 0);
  assert.equal(p.env.win.localStorage.getItem('zl_anon'), null);
  assert.equal(api.track('early'), false);
  api.consent.grant('analytics');
  await api.flush();
  const ev = p.env.events();
  assert.deepEqual(ev.map((e) => e.name), ['page_view']);
  assert.equal(p.env.win.localStorage.getItem('zl_anon'), ev[0].anonymousId);
  assert.equal(p.env.win.sessionStorage.getItem('zl_sess'), ev[0].sessionId);
  assert.match(p.env.doc.cookie, /ziplogger_consent=analytics/);
});

test('withdrawing analytics consent discards unsent events and removes the ids', async () => {
  const p = page({ modules: { analytics: true } });
  const api = boot(p.env.win, p.env.doc);
  api.consent.grant('analytics');
  api.track('queued_but_not_sent');
  api.consent.revoke('analytics');
  await api.flush();
  assert.equal(p.env.events().length, 0, 'not even the page view left the page');
  assert.equal(p.env.win.localStorage.getItem('zl_anon'), null);
  assert.equal(api.track('after'), false);
});

test('analytics policy "none" collects without a prompt, unless the visitor sends Do Not Track', async () => {
  const p = page({ modules: { analytics: true }, consent: { policy: { browser: 'none', analytics: 'none', replay: 'required', commerce: 'none' } } });
  const api = boot(p.env.win, p.env.doc);
  await api.flush();
  assert.equal(p.env.events().length, 1);

  const q = page({ modules: { analytics: true }, consent: { policy: { browser: 'none', analytics: 'none', replay: 'required', commerce: 'none' } } });
  Object.defineProperty(q.env.win.navigator, 'doNotTrack', { value: '1', configurable: true });
  const api2 = boot(q.env.win, q.env.doc);
  await api2.flush();
  assert.equal(q.env.events().length, 0);
});

test('identify: a signed-in visitor gets the SERVER-supplied pseudonym, only with consent', async () => {
  const p = page({ modules: { analytics: true }, analytics: { identify: true } });
  p.env.cookie('ziplogger_li', '1');
  p.env.respond((r) => (r.url.includes('admin-ajax.php')
    ? new Response(JSON.stringify({ success: true, data: { loggedIn: true, replayAllowed: true, userRef: 'wpu_0123456789abcdef01234567' } }), { status: 200 })
    : new Response('{}', { status: 200 })));
  const api = boot(p.env.win, p.env.doc);
  await p.env.settle();
  assert.equal(p.env.callsTo('admin-ajax.php').length, 0, 'no consent: the server is not even asked');
  api.consent.grant('analytics');
  await p.env.settle(30);
  await api.flush();
  const idEvent = p.env.events().find((e) => e.type === 'identify');
  assert.ok(idEvent, 'identify event sent');
  assert.equal(idEvent.userId, 'wpu_0123456789abcdef01234567');
  assert.match(idEvent.anonymousId, /^anon_/);
  const ask = p.env.callsTo('admin-ajax.php')[0];
  assert.equal(ask.init.method, 'POST');
  assert.equal(ask.init.credentials, 'same-origin');
  assert.ok(!JSON.stringify(p.env.events()).includes('@'), 'no email anywhere');
});

test('signing out (the hint cookie disappears) starts a new anonymous visitor', async () => {
  const p = page({ modules: { analytics: true }, consent: { policy: { browser: 'none', analytics: 'none', replay: 'required', commerce: 'none' } }, analytics: { identify: true } });
  p.env.cookie('ziplogger_li', '1');
  p.env.respond((r) => (r.url.includes('admin-ajax.php') ? new Response(JSON.stringify({ success: true, data: { userRef: 'wpu_0123456789abcdef01234567', replayAllowed: true } }), { status: 200 }) : new Response('{}', { status: 200 })));
  const api = boot(p.env.win, p.env.doc);
  await p.env.settle(30);
  const before = api._internal.identity.current();
  assert.equal(before.userId, 'wpu_0123456789abcdef01234567');
  p.env.doc.cookie = 'ziplogger_li=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  p.env.doc.dispatchEvent(new p.env.win.Event('visibilitychange'));
  const after = api._internal.identity.current();
  assert.equal(after.userId, null);
  assert.notEqual(after.anonymousId, before.anonymousId);
  assert.notEqual(after.sessionId, before.sessionId);
});

test('the command queue runs what was queued before the script, and later pushes', async () => {
  env = setupDom();
  env.win.ziploggerQueue = [['consent.grant', 'analytics'], ['track', 'queued_event', { plan: 'pro' }]];
  const el = env.doc.createElement('script');
  el.type = 'application/json';
  el.id = 'ziplogger-config';
  el.textContent = JSON.stringify(baseConfig({ modules: { analytics: true } }));
  env.doc.head.appendChild(el);
  const api = boot(env.win, env.doc);
  env.win.ziploggerQueue.push(['track', 'later_event']);
  await api.flush();
  const names = env.events().map((e) => e.name);
  assert.ok(names.includes('queued_event') && names.includes('later_event'));
});

test('a queued track before consent is dropped, not held for later', async () => {
  env = setupDom();
  env.win.ziploggerQueue = [['track', 'too_early']];
  const el = env.doc.createElement('script');
  el.type = 'application/json';
  el.id = 'ziplogger-config';
  el.textContent = JSON.stringify(baseConfig({ modules: { analytics: true } }));
  env.doc.head.appendChild(el);
  const api = boot(env.win, env.doc);
  api.consent.grant('analytics');
  await api.flush();
  assert.ok(!env.events().some((e) => e.name === 'too_early'));
});

test('ZipLoggerWP.log is sanitized like everything else', async () => {
  const p = page({ modules: { browser: true } });
  const api = boot(p.env.win, p.env.doc);
  api.log('warn', 'checkout failed for a@b.co token=xyz', { step: 'payment', password: 'p', count: 3 });
  await api.flush();
  const l = p.env.logs()[0];
  assert.equal(l.severity, 'warn');
  assert.ok(!/a@b\.co|xyz/.test(l.message));
  assert.equal(l.fields.step, 'payment');
  assert.equal(l.fields.count, 3);
  assert.ok(!('password' in l.fields));
});

test('the ready event fires and status() reports without identifiers', () => {
  const p = page({ modules: { browser: true } });
  let ready = false;
  p.env.doc.addEventListener('ziplogger:ready', () => { ready = true; });
  const api = boot(p.env.win, p.env.doc);
  assert.equal(ready, true);
  const s = JSON.stringify(api.status());
  assert.ok(!/sess_|anon_|wpu_/.test(s));
  assert.equal(api.status().allowed.browser, true);
  assert.equal(api.status().allowed.analytics, false);
});

test('blocked web storage or cookies do not stop boot', () => {
  const p = page({ modules: { browser: true, analytics: true } });
  Object.defineProperty(p.env.win, 'localStorage', { get() { throw new Error('denied'); }, configurable: true });
  Object.defineProperty(p.env.win, 'sessionStorage', { get() { throw new Error('denied'); }, configurable: true });
  assert.doesNotThrow(() => boot(p.env.win, p.env.doc));
});

test('plain HTTP is accepted for ANY host only when the server marked the endpoint as an explicit development one', () => {
  const on = page({ endpoint: 'http://mock:5081', allowInsecureEndpoint: true });
  assert.ok(boot(on.env.win, on.env.doc));
  const off = page({ endpoint: 'http://mock:5081' });
  assert.equal(boot(off.env.win, off.env.doc), null, 'without the flag, http:// is refused');
  const nonHttp = page({ endpoint: 'ftp://mock:5081', allowInsecureEndpoint: true });
  assert.equal(boot(nonHttp.env.win, nonHttp.env.doc), null);
});
