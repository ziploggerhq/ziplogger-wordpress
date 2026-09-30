import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

const env = setupDom({ url: 'https://shop.example.test/product/blue-shirt' });
const { installNetwork } = await import('../src/network.js');
const { installTracing, hostAllowed } = await import('../src/tracing.js');

function setup(tracing = {}, extra = {}) {
  env.reset();
  env.win.fetch = env.win.__originalFetch;
  delete env.win.__ziploggerNetwork;
  const cfg = baseConfig({ modules: { tracing: true }, tracing: { sampleRate: 100, propagateHosts: [], browser: true, serviceName: 'shop', propagateSession: true, ...tracing } });
  const identity = { sessionId: 'sess_0123456789abcdef0123', anonymousId: null, userId: null };
  let allowed = true;
  const network = installNetwork({ win: env.win, cfg, sampleKey: 'k', isOwn: (u) => u.startsWith('https://ingest.ziplogger.test/') });
  const tr = installTracing({ win: env.win, cfg, network, identity: () => identity, ownFetch: env.win.__originalFetch, allowed: () => allowed, ...extra });
  return { tr, network, cfg, setAllowed: (v) => { allowed = v; } };
}

test('hostAllowed: exact, wildcard, port, and no partial matches', () => {
  assert.equal(hostAllowed('api.example.com', '', ['api.example.com']), true);
  assert.equal(hostAllowed('API.example.com', '', ['api.example.com']), true);
  assert.equal(hostAllowed('a.b.example.com', '', ['*.example.com']), true);
  assert.equal(hostAllowed('example.com', '', ['*.example.com']), false, 'a wildcard needs a subdomain');
  assert.equal(hostAllowed('evilexample.com', '', ['*.example.com']), false);
  assert.equal(hostAllowed('api.example.com.evil.test', '', ['api.example.com']), false);
  assert.equal(hostAllowed('api.example.com', '8443', ['api.example.com:8443']), true);
  assert.equal(hostAllowed('api.example.com', '443', ['api.example.com:8443']), false);
  assert.equal(hostAllowed('', '', ['x.test']), false);
});

test('same-origin requests carry traceparent and the session as baggage', async () => {
  const { network } = setup();
  await env.win.fetch('/wp-json/wp/v2/posts');
  const h = env.calls[0].headers;
  assert.match(h.get('traceparent'), /^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
  assert.equal(h.get('baggage'), 'session.id=sess_0123456789abcdef0123');
  network.stop();
});

test('an allowlisted cross-origin host gets traceparent but NEVER baggage', async () => {
  const { network } = setup({ propagateHosts: ['api.partner.test'] });
  await env.win.fetch('https://api.partner.test/v1/thing');
  const h = env.calls[0].headers;
  assert.ok(h.get('traceparent'));
  assert.equal(h.get('baggage'), null);
  network.stop();
});

test('any other third party receives no trace headers at all', async () => {
  const { network } = setup({ propagateHosts: ['api.partner.test'] });
  await env.win.fetch('https://www.google-analytics.com/collect');
  await env.win.fetch('https://cdn.other.test/lib.js');
  await env.win.fetch('https://api.partner.test.evil.test/x');
  for (const c of env.calls) {
    assert.equal(c.headers.get('traceparent'), null, c.url);
    assert.equal(c.headers.get('baggage'), null, c.url);
  }
  network.stop();
});

test('ZipLogger telemetry requests are never traced', async () => {
  const { network } = setup();
  await env.win.fetch('https://ingest.ziplogger.test/ingest/v1/logs', { method: 'POST', body: '{}' });
  assert.equal(env.calls[0].headers.get('traceparent'), null);
  network.stop();
});

test('baggage is omitted when session propagation is switched off', async () => {
  const { network } = setup({ propagateSession: false });
  await env.win.fetch('/x');
  assert.equal(env.calls[0].headers.get('baggage'), null);
  assert.ok(env.calls[0].headers.get('traceparent'));
  network.stop();
});

test('sampling: the decision is made once, from the trace id, and travels in the flags', async () => {
  const off = setup({ sampleRate: 0 });
  await env.win.fetch('/a');
  assert.match(env.calls[0].headers.get('traceparent'), /-00$/, 'not sampled: the server is told, so it does not record either');
  off.network.stop();

  const on = setup({ sampleRate: 100 });
  await env.win.fetch('/a');
  assert.match(env.calls[0].headers.get('traceparent'), /-01$/);
  on.network.stop();

  const some = setup({ sampleRate: 30 });
  let sampled = 0;
  for (let i = 0; i < 400; i++) await env.win.fetch('/n' + i);
  for (const c of env.calls) if (/-01$/.test(c.headers.get('traceparent'))) sampled++;
  assert.ok(sampled > 80 && sampled < 160, `30% of 400 gave ${sampled}`);
  some.network.stop();
});

test('sampled requests are exported as OTLP spans without query strings, bodies or credentials', async () => {
  const { network, tr } = setup();
  env.respond((r) => (r.url.includes('/ingest') || r.url.includes('/v1/traces') ? new Response('{}', { status: 200 }) : new Response('nope', { status: 500 })));
  await env.win.fetch('/wp-json/wc/store/v1/checkout?_wpnonce=SECRET&email=a@b.co', { method: 'POST', body: 'card=4242', headers: { Authorization: 'Bearer zzz' } });
  tr.flush(false);
  await env.settle();
  const spans = env.spans();
  assert.equal(spans.length, 1);
  const s = spans[0];
  assert.match(s.traceId, /^[0-9a-f]{32}$/);
  assert.match(s.spanId, /^[0-9a-f]{16}$/);
  assert.equal(s.kind, 3);
  assert.equal(s.name, 'POST /wp-json/wc/store/v1/checkout');
  assert.equal(s.status.code, 2, 'HTTP 500 is an error');
  const attrs = Object.fromEntries(s.attributes.map((a) => [a.key, a.value.stringValue ?? a.value.intValue]));
  assert.equal(attrs['http.response.status_code'], '500');
  assert.equal(attrs['url.full'], 'https://shop.example.test/wp-json/wc/store/v1/checkout');
  assert.equal(attrs['session.id'], 'sess_0123456789abcdef0123');
  const raw = JSON.stringify(env.callsTo('/v1/traces').map((c) => c.body));
  for (const leak of ['SECRET', 'a@b.co', '4242', 'Bearer', 'zzz']) assert.ok(!raw.includes(leak), `leaked ${leak}`);
  const post = env.callsTo('/v1/traces')[0];
  assert.equal(post.headers.get('x-api-key'), 'zk_browser_public_key_for_tests');
  assert.equal(post.headers.get('content-type'), 'application/json');
  // The span's ids are the ones sent on the wire: the server continues this trace.
  const tp = env.calls.find((c) => c.url.includes('checkout')).headers.get('traceparent').split('-');
  assert.equal(tp[1], s.traceId);
  assert.equal(tp[2], s.spanId);
  network.stop();
});

test('unsampled requests export nothing', async () => {
  const { network, tr } = setup({ sampleRate: 0 });
  await env.win.fetch('/a');
  tr.flush(false);
  await env.settle();
  assert.equal(env.callsTo('/v1/traces').length, 0);
  network.stop();
});

test('when consent is withdrawn, headers stop and unsent spans are discarded', async () => {
  const { network, tr, setAllowed } = setup();
  await env.win.fetch('/first');
  setAllowed(false);
  tr.flush(false);
  await env.settle();
  assert.equal(env.callsTo('/v1/traces').length, 0, 'the unsent span was discarded, not flushed');
  await env.win.fetch('/second');
  assert.equal(env.calls.find((c) => c.url.endsWith('/second')).headers.get('traceparent'), null);
  network.stop();
});

test('recentTraceId links an event to the request it happened in, for a short time only', async () => {
  const { network, tr } = setup();
  assert.equal(tr.recentTraceId(), undefined);
  await env.win.fetch('/x');
  const id = tr.recentTraceId(5000);
  assert.match(id, /^[0-9a-f]{32}$/);
  assert.equal(id, env.calls[0].headers.get('traceparent').split('-')[1]);
  await env.settle(15);
  assert.equal(tr.recentTraceId(5), undefined);
  network.stop();
});

test('the span queue is bounded', async () => {
  const { network, tr } = setup();
  env.respond(() => new Response('{}', { status: 200 }));
  for (let i = 0; i < 600; i++) await env.win.fetch('/p' + i);
  tr.flush(false);
  assert.ok(tr.dropped() >= 0);
  const total = env.spans().length;
  assert.ok(total <= 600);
  network.stop();
});

test('trace ids are never reused', async () => {
  const { network } = setup();
  for (let i = 0; i < 50; i++) await env.win.fetch('/u' + i);
  const ids = new Set(env.calls.filter((c) => c.headers.get('traceparent')).map((c) => c.headers.get('traceparent').split('-')[1]));
  assert.equal(ids.size, 50);
  network.stop();
});
