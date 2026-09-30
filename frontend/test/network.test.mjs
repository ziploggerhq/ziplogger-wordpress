import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

const env = setupDom({ url: 'https://shop.example.test/cart' });
const { installNetwork, requestReporter } = await import('../src/network.js');

function fresh() {
  env.reset();
  env.win.fetch = env.win.__originalFetch;
  delete env.win.__ziploggerNetwork;
}

const ctx = (extra = {}) => ({ win: env.win, cfg: baseConfig(), sampleKey: 'k', isOwn: (u) => u.startsWith('https://ingest.ziplogger.test/'), ...extra });

test('the page gets exactly the promise and response fetch would have given it', async () => {
  fresh();
  const marker = new Response('body-1', { status: 200 });
  env.respond(() => marker);
  const net = installNetwork(ctx());
  net.addHook({ before: () => null, after: () => {} });
  const res = await env.win.fetch('/wp-json/x');
  assert.equal(res, marker, 'the same Response object');
  assert.equal(await res.text(), 'body-1', 'the body is untouched and still readable');
  net.stop();
});

test('a network failure reaches the page unchanged', async () => {
  fresh();
  const boom = new TypeError('Failed to fetch');
  env.respond(() => { throw boom; });
  const net = installNetwork(ctx());
  await assert.rejects(env.win.fetch('/x'), (e) => e === boom);
  net.stop();
});

test('hooks may add headers for fetch(url, init) and body-less Requests, and never touch bodies', async () => {
  fresh();
  const net = installNetwork(ctx());
  net.addHook({ before: () => ({ traceparent: '00-a-b-01' }) });
  await env.win.fetch('/a', { method: 'POST', body: 'payload', headers: { 'X-Test': '1' } });
  await env.win.fetch(new Request('https://shop.example.test/b'));
  await env.win.fetch(new Request('https://shop.example.test/c', { method: 'POST', body: 'stream' }));
  const [a, b, c] = env.calls;
  assert.equal(a.headers.get('traceparent'), '00-a-b-01');
  assert.equal(a.headers.get('x-test'), '1', 'the page headers are kept');
  assert.equal(a.body, 'payload');
  assert.equal(b.headers.get('traceparent'), '00-a-b-01');
  assert.equal(c.headers.get('traceparent'), null, 'a Request with a body is passed through untouched');
  net.stop();
});

test('a header the page set itself is not overwritten', async () => {
  fresh();
  const net = installNetwork(ctx());
  net.addHook({ before: () => ({ traceparent: 'ours' }) });
  await env.win.fetch('/a', { headers: { traceparent: 'theirs' } });
  assert.equal(env.calls[0].headers.get('traceparent'), 'theirs');
  net.stop();
});

test('requests to ZipLogger itself are neither observed nor altered', async () => {
  fresh();
  const seen = [];
  const net = installNetwork(ctx());
  net.addHook({ before: (i) => { seen.push(i.url); return { traceparent: 'x' }; }, after: (i) => seen.push(i.url) });
  await env.win.fetch('https://ingest.ziplogger.test/ingest/v1/logs', { method: 'POST', body: '{}' });
  assert.deepEqual(seen, []);
  assert.equal(env.calls[0].headers.get('traceparent'), null);
  net.stop();
});

test('a hook that throws cannot break a request', async () => {
  fresh();
  const net = installNetwork(ctx());
  net.addHook({ before: () => { throw new Error('bad hook'); }, after: () => { throw new Error('bad hook 2'); } });
  const res = await env.win.fetch('/ok');
  assert.equal(res.status, 200);
  await env.settle();
  net.stop();
});

test('installing twice shares one wrapper (no double wrapping)', () => {
  fresh();
  const a = installNetwork(ctx());
  const wrapped = env.win.fetch;
  const b = installNetwork(ctx());
  assert.equal(a, b);
  assert.equal(env.win.fetch, wrapped);
  a.stop();
});

test('stop restores the original fetch', () => {
  fresh();
  const original = env.win.fetch;
  const net = installNetwork(ctx());
  assert.notEqual(env.win.fetch, original);
  net.stop();
  assert.equal(env.win.fetch, original);
});

test('a failed request is reported with method, path (no query), status and duration only', async () => {
  fresh();
  env.respond(() => new Response('secret error body with token=abc', { status: 503 }));
  const logs = [];
  const net = installNetwork(ctx());
  const cfg = baseConfig({ modules: { browser: true }, browser: { failedRequests: true } });
  net.addHook(requestReporter({ cfg, sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({ path: '/cart' }) }));
  await env.win.fetch('/wp-json/wc/store/v1/cart/items/12345?_wpnonce=SECRET&key=k', { method: 'POST', body: 'card=4242', headers: { Authorization: 'Bearer zzz' } });
  await env.settle();
  assert.equal(logs.length, 1);
  const e = logs[0];
  assert.equal(e.fields.eventType, 'request_failed');
  assert.equal(e.fields.method, 'POST');
  assert.equal(e.fields.requestPath, '/wp-json/wc/store/v1/cart/items/:id');
  assert.equal(e.fields.status, 503);
  assert.equal(typeof e.fields.durationMs, 'number');
  const all = JSON.stringify(e);
  for (const leak of ['SECRET', 'abc', '4242', 'Bearer', 'zzz', '_wpnonce']) assert.ok(!all.includes(leak), `leaked ${leak}`);
  net.stop();
});

test('4xx responses and aborted requests are not reported as failures', async () => {
  fresh();
  const logs = [];
  const net = installNetwork(ctx());
  net.addHook(requestReporter({ cfg: baseConfig({ browser: { failedRequests: true } }), sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({}) }));
  env.respond(() => new Response('', { status: 404 }));
  await env.win.fetch('/missing');
  env.respond(() => { const e = new Error('aborted'); e.name = 'AbortError'; throw e; });
  await assert.rejects(env.win.fetch('/slow'));
  await env.settle();
  assert.equal(logs.length, 0);
  net.stop();
});

test('a network error is reported and the repeat storm is collapsed', async () => {
  fresh();
  const logs = [];
  env.respond(() => { throw new TypeError('Failed to fetch'); });
  const net = installNetwork(ctx());
  net.addHook(requestReporter({ cfg: baseConfig({ browser: { failedRequests: true } }), sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({}) }));
  for (let i = 0; i < 30; i++) await env.win.fetch('https://cdn.other.test/lib.js?x=' + i).catch(() => {});
  await env.settle();
  assert.deepEqual(logs.map((l) => l.fields.occurrences), [1, 10]);
  assert.equal(logs[0].fields.requestHost, 'cdn.other.test', 'a cross-origin failure names the host');
  assert.equal(logs[0].fields.errorName, 'TypeError');
  net.stop();
});

test('slow successful requests are reported only when enabled and sampled', async () => {
  fresh();
  const logs = [];
  const net = installNetwork(ctx());
  const cfg = baseConfig({ browser: { failedRequests: true, slowRequests: true, slowThresholdMs: 5, perfSampleRate: 100 } });
  net.addHook(requestReporter({ cfg, sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({}) }));
  env.respond(() => new Promise((r) => setTimeout(() => r(new Response('', { status: 200 })), 30)));
  await env.win.fetch('/slowpoke?x=1');
  await env.settle(20);
  assert.equal(logs.length, 1);
  assert.equal(logs[0].fields.eventType, 'request_slow');
  assert.equal(logs[0].fields.requestPath, '/slowpoke');
  assert.equal(logs[0].severity, 'info');
  assert.ok(logs[0].fields.durationMs >= 5);
  net.stop();
});

test('slow requests are not reported when the option is off or the page is not in the performance sample', async () => {
  fresh();
  const off = [];
  const net = installNetwork(ctx());
  net.addHook(requestReporter({ cfg: baseConfig({ browser: { failedRequests: true, slowRequests: false, slowThresholdMs: 5, perfSampleRate: 100 } }), sampleKey: 'k', log: (e) => off.push(e), pageFields: () => ({}) }));
  net.addHook(requestReporter({ cfg: baseConfig({ browser: { failedRequests: true, slowRequests: true, slowThresholdMs: 5, perfSampleRate: 0 } }), sampleKey: 'k', log: (e) => off.push(e), pageFields: () => ({}) }));
  env.respond(() => new Promise((r) => setTimeout(() => r(new Response('', { status: 200 })), 30)));
  await env.win.fetch('/slowpoke');
  await env.settle(20);
  assert.equal(off.length, 0);
  net.stop();
});

// A minimal XMLHttpRequest, enough to exercise the wrapper's contract.
class FakeXHR {
  constructor() { this.listeners = {}; this.headers = {}; this.status = 0; }
  open(method, url) { this.method = method; this.url = url; }
  setRequestHeader(k, v) { this.headers[k] = v; }
  addEventListener(name, fn) { (this.listeners[name] = this.listeners[name] || []).push(fn); }
  send(body) { this.sentBody = body; setTimeout(() => { this.status = this.nextStatus; (this.listeners.loadend || []).forEach((f) => f.call(this)); }, 1); }
}

test('XMLHttpRequest: headers added before send, result untouched, failure reported', async () => {
  fresh();
  env.win.XMLHttpRequest = FakeXHR;
  const logs = [];
  const net = installNetwork(ctx());
  net.addHook({ before: () => ({ traceparent: 'tp' }) });
  net.addHook(requestReporter({ cfg: baseConfig({ browser: { failedRequests: true } }), sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({}) }));
  const xhr = new env.win.XMLHttpRequest();
  xhr.nextStatus = 500;
  xhr.open('POST', '/wp-admin/admin-ajax.php?action=x&secret=1');
  xhr.send('body-not-read');
  await env.settle(15);
  assert.equal(xhr.headers.traceparent, 'tp');
  assert.equal(xhr.sentBody, 'body-not-read');
  assert.equal(logs.length, 1);
  assert.equal(logs[0].fields.transport, 'xhr');
  assert.equal(logs[0].fields.requestPath, '/wp-admin/admin-ajax.php');
  assert.ok(!JSON.stringify(logs[0]).includes('secret'));
  net.stop();
  assert.ok(env.win.XMLHttpRequest.prototype.open.name !== 'wrappedOpen');
});

test('XMLHttpRequest to ZipLogger is not observed', async () => {
  fresh();
  env.win.XMLHttpRequest = FakeXHR;
  const seen = [];
  const net = installNetwork(ctx());
  net.addHook({ before: (i) => { seen.push(i.url); return { a: 'b' }; } });
  const xhr = new env.win.XMLHttpRequest();
  xhr.open('POST', 'https://ingest.ziplogger.test/ingest/v1/events');
  xhr.send('x');
  await env.settle(10);
  assert.deepEqual(seen, []);
  assert.deepEqual(xhr.headers, {});
  net.stop();
});
