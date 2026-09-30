// End-to-end: server-side tracing against a real WordPress (built ZIP), including the browser -> server link.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node --test tests/e2e/m4-tracing.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { KEYS, wp, get, mock, sleep, waitFor, setSettings, installZip, setKeys, runBrowser, putStatic } from './lib.mjs';

const ENDPOINT = 'http://mock:5081';
const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
const PARENT = '00f067aa0ba902b7';
const SESSION = 'sess_0123456789abcdef0123';

const configure = (tracing = {}, extra = {}) => setSettings({
  enabled: true, endpoint: ENDPOINT, source: 'e2e-site', min_severity: 'warn', collectors: { php_errors: true, http_failures: false },
  tracing: { enabled: true, server_spans: true, outbound_spans: true, sample_rate: 100, ...tracing },
  ...extra,
});

const flush = () => wp(['ziplogger', 'flush'], { allowFail: true });
const traceEntries = () => mock.received('/v1/traces');
const allSpans = async () => (await traceEntries()).flatMap((e) => JSON.parse(e.body).resourceSpans.flatMap((r) => r.scopeSpans.flatMap((s) => s.spans.map((sp) => ({ ...sp, _key: e.headers['x-api-key'], _service: r.resource.attributes.find((a) => a.key === 'service.name')?.value.stringValue })))));
// The request under test only: WordPress spawns wp-cron.php after many requests, and that is traced (correctly) too.
const spans = async () => (await allSpans()).filter((s) => !/wp-cron/.test(s.name));
const attr = (span, key) => { const a = (span.attributes || []).find((x) => x.key === key); return a ? (a.value.stringValue ?? a.value.intValue ?? a.value.boolValue) : undefined; };
const request = (path, headers = {}) => fetch(`http://localhost:8088${path}`, { headers, redirect: 'manual' });
const logRecords = async () => (await mock.received('/ingest/v1/logs')).flatMap((e) => e.records || []);

// Spans are queued when a request ENDS, which can be just after its response was delivered. Let the previous
// test's tail finish and deliver before forgetting what the receiver saw.
async function drain() {
  await sleep(800);
  flush();
  await sleep(300);
  await mock.clear();
}

async function traced(path, headers = {}) {
  await drain();
  const res = await request(path, headers);
  await res.text();
  await sleep(300);
  flush();
  return res;
}

test('the stack is up and the built ZIP is active', async () => {
  await waitFor(async () => (await fetch('http://localhost:5081/__stats')).ok, { what: 'receiver' });
  await waitFor(async () => (await fetch('http://localhost:8088/')).status < 500, { what: 'WordPress', timeout: 120000 });
  if (wp(['core', 'is-installed'], { allowFail: true }).status !== 0) {
    wp(['core', 'install', '--url=http://wordpress', '--title=ZipLogger E2E', '--admin_user=admin', '--admin_password=e2e-admin-pass-1', '--admin_email=admin@example.org', '--skip-email']);
  }
  installZip();
  setKeys();
  wp(['rewrite', 'structure', '/%postname%/', '--hard'], { allowFail: true });
});

test('tracing off: no span is queued and no header is added to anything', async () => {
  setSettings({ enabled: true, endpoint: ENDPOINT, source: 'e2e-site' });
  await traced('/');
  assert.equal((await traceEntries()).length, 0);
});

test('a request with no trace context starts its own trace and its span is delivered with the SERVER key', async () => {
  configure();
  await traced('/');
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  const s = (await spans()).find((x) => x.kind === 2);
  assert.ok(s, 'a server span');
  assert.match(s.traceId, /^[0-9a-f]{32}$/);
  assert.equal(s.parentSpanId, undefined, 'a root span');
  assert.equal(s.name, 'GET page:front_page');
  assert.equal(attr(s, 'ziplogger.request.context'), 'frontend');
  assert.equal(attr(s, 'http.response.status_code'), '200');
  assert.equal(attr(s, 'wordpress.page.type'), 'front_page');
  assert.ok(Number(attr(s, 'wordpress.db.query_count')) > 3);
  assert.ok(Number(s.endTimeUnixNano) > Number(s.startTimeUnixNano));
  assert.equal(s._key, KEYS.server, 'server spans use the server key, never the browser key');
  assert.equal(s._service, 'e2e-site');
  assert.equal(attr(s, 'url.path'), undefined, 'no path unless enabled');
});

test('an inbound traceparent is continued: same trace, parent set, session from validated baggage', async () => {
  configure({ sample_rate: 0 });
  await traced('/', { traceparent: `00-${TRACE}-${PARENT}-01`, baggage: `session.id=${SESSION}` });
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  const s = (await spans()).find((x) => x.traceId === TRACE);
  assert.ok(s, 'the caller sampled it, so it is recorded even at a local rate of 0');
  assert.equal(s.parentSpanId, PARENT);
  assert.notEqual(s.spanId, PARENT);
  assert.equal(attr(s, 'session.id'), SESSION);
  assert.equal(attr(s, 'ziplogger.trace.remote_parent'), true);
});

test('parent-aware sampling: an unsampled inbound trace is not recorded even at a local rate of 100', async () => {
  configure({ sample_rate: 100 });
  await traced('/', { traceparent: `00-${TRACE}-${PARENT}-00` });
  await sleep(1500);
  assert.equal((await spans()).length, 0);
});

test('a malformed or hostile trace context is ignored: a fresh trace starts, nothing from the header is used', async () => {
  configure({ sample_rate: 100 });
  for (const bad of [`00-${'0'.repeat(32)}-${PARENT}-01`, `01-${TRACE}-${PARENT}-01`, `00-${TRACE.toUpperCase()}-${PARENT}-01`, 'garbage', `00-${TRACE}-${PARENT}-01-extra`]) {
    await traced('/', { traceparent: bad, baggage: `session.id=${SESSION}` });
    await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
    const s = (await spans())[0];
    assert.notEqual(s.traceId, TRACE, bad);
    assert.equal(s.parentSpanId, undefined, bad);
    assert.equal(attr(s, 'session.id'), undefined, 'baggage without a valid trace context is not used');
  }
  await traced('/', { traceparent: `00-${TRACE}-${PARENT}-01`, baggage: 'session.id=<script>alert(1)</script>, other=1' });
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  assert.equal(attr((await spans())[0], 'session.id'), undefined, 'a malformed session id is dropped');
});

test('REST requests are named by a normalized route and classified as REST', async () => {
  configure();
  await traced('/wp-json/zl-e2e/v1/ok?secret=QUERYSECRET');
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  const s = (await spans()).find((x) => x.kind === 2);
  assert.equal(s.name, 'GET /wp-json/zl-e2e/v1/ok');
  assert.equal(attr(s, 'ziplogger.request.context'), 'rest');
  assert.ok(!JSON.stringify(await spans()).includes('QUERYSECRET'));
  const posts = await wp(['post', 'create', '--post_title=Trace me', '--post_status=publish', '--porcelain']).stdout;
  await traced(`/wp-json/wp/v2/posts/${posts}`);
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  assert.equal((await spans()).find((x) => x.kind === 2).name, 'GET /wp-json/wp/v2/posts/{id}', 'identifiers never become part of a span name');
});

test('an invented admin-ajax action never becomes a span name', async () => {
  configure();
  await drain();
  await fetch('http://localhost:8088/wp-admin/admin-ajax.php?action=made_up_' + Date.now(), { method: 'POST' });
  await sleep(300);
  flush();
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  const s = (await spans()).find((x) => x.kind === 2);
  assert.equal(s.name, 'POST admin-ajax:unregistered');
  assert.equal(attr(s, 'ziplogger.request.context'), 'ajax');
});

test('the plugin\'s own visitor-context endpoint is not traced', async () => {
  configure();
  await drain();
  await fetch('http://localhost:8088/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'action=ziplogger_context' });
  await sleep(300);
  flush();
  await sleep(1500);
  assert.equal((await spans()).length, 0);
});

test('an uncaught exception marks the request span as failed and carries a redacted exception event', async () => {
  configure({}, { min_severity: 'warn' });
  const res = await traced('/?zl_fixture=exception');
  assert.equal(res.status, 500);
  await waitFor(async () => (await spans()).length > 0, { what: 'server span', timeout: 20000 });
  const s = (await spans()).find((x) => x.kind === 2);
  assert.equal(s.status.code, 2);
  assert.equal(attr(s, 'http.response.status_code'), '500');
  const event = (s.events || []).find((e) => e.name === 'exception');
  assert.ok(event, 'an exception event');
  assert.equal(event.attributes.find((a) => a.key === 'exception.type').value.stringValue, 'RuntimeException');
  assert.match(event.attributes.find((a) => a.key === 'exception.message').value.stringValue, /e2e fixture uncaught exception/);
});

test('logs written during a traced request carry its trace and span id', async () => {
  configure();
  await traced('/?zl_fixture=warning');
  await waitFor(async () => (await spans()).length > 0 && (await logRecords()).length > 0, { what: 'span and log', timeout: 20000 });
  const s = (await spans()).find((x) => x.kind === 2);
  const log = (await logRecords()).find((l) => /e2e fixture warning/.test(l.message));
  assert.ok(log, 'the warning was logged');
  assert.equal(log.fields.traceId, s.traceId);
  assert.equal(log.fields.spanId, s.spanId);
});

test('outbound HTTP calls become child spans (host, method, status, duration only); a failed one is an error', async () => {
  configure();
  await traced('/?zl_fixture=outbound');
  await waitFor(async () => (await spans()).length >= 4, { what: 'server + three child spans', timeout: 30000 });
  const all = await spans();
  const root = all.find((x) => x.kind === 2);
  const kids = all.filter((x) => x.kind === 3);
  assert.equal(kids.length, 3);
  assert.ok(kids.every((k) => k.traceId === root.traceId && k.parentSpanId === root.spanId));
  const ok = kids.find((k) => attr(k, 'http.response.status_code') === '200' && k.name === 'GET thirdparty.example.org');
  assert.ok(ok, 'the successful GET');
  assert.equal(attr(ok, 'server.address'), 'thirdparty.example.org');
  assert.equal(attr(ok, 'server.port'), '5081');
  assert.ok(kids.find((k) => k.name === 'POST thirdparty.example.org'));
  const failed = kids.find((k) => k.status.code === 2);
  assert.ok(failed, 'the refused connection is an error span');
  assert.ok(attr(failed, 'error.type'));
  const raw = JSON.stringify(all);
  for (const leak of ['SECRETQUERY', 'SECRETWEBHOOK', 'SECRETBODY', 'hooks/T000']) assert.ok(!raw.includes(leak), `leaked ${leak}`);
});

test('by default no trace header reaches a third party', async () => {
  configure({ propagate_hosts: '' });
  await drain();
  await request('/?zl_fixture=outbound').then((r) => r.text());
  const hits = await mock.received('/third-party/ok');
  assert.ok(hits.length >= 1);
  assert.equal(hits[0].headers.traceparent, undefined);
  assert.equal(hits[0].headers.baggage, undefined);
});

test('an allowlisted host gets traceparent with the request\'s trace id and the child span id, and never baggage', async () => {
  configure({ propagate_hosts: 'thirdparty.example.org:5081' });
  await drain();
  await request('/?zl_fixture=outbound', { traceparent: `00-${TRACE}-${PARENT}-01`, baggage: `session.id=${SESSION}` }).then((r) => r.text());
  await sleep(300);
  flush();
  const hit = (await mock.received('/third-party/ok'))[0];
  assert.match(hit.headers.traceparent, new RegExp(`^00-${TRACE}-[0-9a-f]{16}-01$`));
  assert.equal(hit.headers.baggage, undefined);
  await waitFor(async () => (await spans()).some((s) => s.kind === 3), { what: 'child span', timeout: 20000 });
  const child = (await spans()).find((s) => s.kind === 3 && s.name === 'GET thirdparty.example.org' && attr(s, 'http.response.status_code') === '200');
  assert.equal(hit.headers.traceparent.split('-')[2], child.spanId, 'the header carries the id of the child span that was recorded');
  // A host that is NOT listed still gets nothing: the port must match too.
  configure({ propagate_hosts: 'thirdparty.example.org:9999' });
  await drain();
  await request('/?zl_fixture=outbound').then((r) => r.text());
  assert.equal((await mock.received('/third-party/ok'))[0].headers.traceparent, undefined);
});

test('the trace never appears in the HTML or in response headers (a page cache could hand it to someone else)', async () => {
  configure();
  await drain();
  const res = await request('/', { traceparent: `00-${TRACE}-${PARENT}-01` });
  const html = await res.text();
  assert.ok(!html.includes(TRACE));
  for (const [name, value] of res.headers) {
    assert.ok(!value.includes(TRACE), `${name} header carries the trace id`);
    assert.notEqual(name.toLowerCase(), 'traceresponse');
    assert.notEqual(name.toLowerCase(), 'server-timing');
  }
});

test('a page served from a plain file (no PHP, like a full-page cache hit) has no server span: a documented gap', async () => {
  configure();
  putStatic('zl-static.html', '<html><body>static</body></html>');
  await drain();
  await request('/zl-static.html').then((r) => r.text());
  await sleep(500);
  flush();
  await sleep(1500);
  assert.deepEqual((await spans()).map((x) => x.name), [], 'no span for a request PHP never saw');
});

test('BROWSER -> SERVER: the browser\'s request span and WordPress\'s request span are one trace, linked by the ids on the wire', async () => {
  setKeys();
  setSettings({
    enabled: true, endpoint: ENDPOINT, source: 'e2e-site', collectors: { php_errors: true },
    browser: { enabled: true }, tracing: { enabled: true, server_spans: true, outbound_spans: true, sample_rate: 100, browser: true },
  });
  await drain();
  const r = runBrowser('tracing');
  const header = r.results.same.received.traceparent;
  const [, traceId, browserSpanId] = header.split('-');
  await sleep(500);
  flush();
  await waitFor(async () => (await spans()).some((s) => s.traceId === traceId && s.kind === 2), { what: 'server span of the browser request', timeout: 30000 });
  const all = (await spans()).filter((s) => s.traceId === traceId);
  const browser = all.find((s) => s.kind === 3 && s.spanId === browserSpanId);
  const server = all.find((s) => s.kind === 2);
  assert.ok(browser, 'the browser exported its span');
  assert.ok(server, 'WordPress exported its span');
  assert.equal(server.parentSpanId, browserSpanId, 'the server span is a child of the browser span');
  assert.equal(browser._key, KEYS.browser, 'the browser span was sent with the browser key');
  assert.equal(server._key, KEYS.server, 'the server span was sent with the server key');
  assert.equal(attr(server, 'session.id'), attr(browser, 'session.id'), 'and both carry the same session id');
  assert.match(attr(server, 'session.id'), /^sess_[0-9a-f]{20}$/);
  assert.equal(server.name, 'GET /wp-json/zl-e2e/v1/ok');
  assert.ok(browser._service.endsWith('-browser'));
});
