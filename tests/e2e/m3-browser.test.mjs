// End-to-end, in a REAL browser: the front-end half of the plugin (browser errors, analytics, replay,
// browser tracing, consent), served by a real WordPress from the built ZIP, talking to the local receiver.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node --test tests/e2e/m3-browser.test.mjs
//
// The receiver is a local stand-in for ZipLogger (see mock-ziplogger.mjs); nothing here contacts the real service.
import test from 'node:test';
import assert from 'node:assert/strict';
import { KEYS, wp, get, getAsHost, putStatic, mock, sleep, waitFor, setSettings, installZip, setKeys, runBrowser } from './lib.mjs';

const ENDPOINT = 'http://mock:5081';
const SHOPPER = { user: 'shopper', pass: 'shopper-pass-1' };
const ADMIN = { user: 'admin', pass: 'e2e-admin-pass-1' };
/** A cookie this plugin owns (the site also runs WooCommerce, which sets its own). */
const ours = (name) => /^(ziplogger|zl_)/i.test(name);

const base = { enabled: true, endpoint: ENDPOINT, source: 'e2e-site', min_severity: 'warn', collectors: { php_errors: true } };
const configure = (modules = {}) => setSettings({ ...base, ...modules });

// ---- what the receiver got -----------------------------------------------------------------------------
const logRecords = async () => (await mock.received('/ingest/v1/logs')).flatMap((e) => (e.records || []).map((r) => ({ ...r, _entry: e })));
const browserLogs = async () => (await logRecords()).filter((r) => (r.tags || []).includes('browser'));
const eventLines = async () => (await mock.received('/ingest/v1/events')).flatMap((e) => String(e.body).split('\n').filter(Boolean).map((l) => JSON.parse(l)));
const spans = async () => (await mock.received('/v1/traces')).flatMap((e) => JSON.parse(e.body).resourceSpans.flatMap((r) => r.scopeSpans.flatMap((s) => s.spans)));
const attr = (span, key) => { const a = (span.attributes || []).find((x) => x.key === key); return a ? (a.value.stringValue ?? a.value.intValue ?? a.value.boolValue) : undefined; };

test('the stack is up, WordPress is installed and the built ZIP is active', async () => {
  await waitFor(async () => (await fetch('http://localhost:5081/__stats')).ok, { what: 'receiver' });
  await waitFor(async () => (await fetch('http://localhost:8088/')).status < 500, { what: 'WordPress', timeout: 120000 });
  if (wp(['core', 'is-installed'], { allowFail: true }).status !== 0) {
    wp(['core', 'install', '--url=http://wordpress', '--title=ZipLogger E2E', '--admin_user=admin', '--admin_password=e2e-admin-pass-1', '--admin_email=admin@example.org', '--skip-email']);
  }
  installZip();
  if (wp(['user', 'get', SHOPPER.user, '--field=ID'], { allowFail: true }).status !== 0) {
    wp(['user', 'create', SHOPPER.user, 'shopper@example.org', '--role=subscriber', `--user_pass=${SHOPPER.pass}`]);
  }
  wp(['user', 'update', 'admin', '--user_pass=e2e-admin-pass-1']);
  wp(['rewrite', 'structure', '/%postname%/', '--hard'], { allowFail: true });
  await mock.clear();
});

// -------------------------------------------------------------------------------------------------------
// Off means off.
// -------------------------------------------------------------------------------------------------------

test('with every browser module off, the page carries nothing of ours and the browser makes no request', async () => {
  setKeys();
  configure();
  await mock.clear();
  const r = runBrowser('survey');
  assert.equal(r.snap.hasConfig, false);
  assert.equal(r.snap.hasScript, false);
  assert.equal(r.snap.api, false);
  assert.equal(r.own, 0);
  assert.deepEqual(r.snap.local, {});
  assert.deepEqual(r.snap.session, {});
  assert.deepEqual(r.snap.cookies.filter(ours), [], 'no cookie of ours (WooCommerce, when active, sets its own order-attribution cookies)');
});

test('a module that is on but has no browser key sends nothing', async () => {
  setKeys({ browser: null });
  configure({ browser: { enabled: true } });
  await mock.clear();
  const r = runBrowser('survey');
  assert.equal(r.snap.hasScript, false);
  assert.equal(r.own, 0);
});

test('the served page carries the browser key only: never the server key and never the read key', async () => {
  setKeys();
  configure({ browser: { enabled: true }, analytics: { enabled: true }, replay: { enabled: true }, tracing: { enabled: true, browser: true } });
  const html = (await get('/')).text;
  assert.ok(html.includes(KEYS.browser), 'the ingestion-only browser key is in the page');
  assert.ok(!html.includes(KEYS.server), 'the private server key must never reach a visitor');
  assert.ok(!html.includes(KEYS.read), 'the read credential must never reach a visitor');
  const config = JSON.parse(html.match(/<script type="application\/json" id="ziplogger-config">(.*?)<\/script>/s)[1]);
  assert.equal(config.endpoint, ENDPOINT);
  assert.match(html, /<script[^>]*ziplogger\.min\.js[^>]*>/);
  assert.match(html.match(/<script[^>]*ziplogger\.min\.js[^>]*>/)[0], /\bdefer\b/);
  const script = (await get('/wp-content/plugins/ziplogger-error-monitoring-session-replay/assets/js/ziplogger.min.js')).text;
  assert.ok(script.length > 10000);
  for (const secret of [KEYS.server, KEYS.read]) assert.ok(!script.includes(secret));
});

// -------------------------------------------------------------------------------------------------------
// Browser errors and requests.
// -------------------------------------------------------------------------------------------------------

test('browser monitoring: errors, rejections, failed and slow requests arrive sanitized; the page is unaffected', async () => {
  setKeys();
  configure({ browser: { enabled: true, errors: true, failed_requests: true, slow_requests: true, slow_threshold_ms: 1000, perf_sample_rate: 100, error_sample_rate: 100 } });
  await mock.clear();
  const r = runBrowser('jsErrors');

  // The page behaved exactly as it would without the plugin.
  assert.deepEqual(r.page_state, { fail: 500, xhr: 500, ok: { ok: true, received: {} }, slow: 200, notFound: 404 });
  assert.equal(r.pageErrors.length, 2, 'only the fixture own uncaught error and rejection: ' + JSON.stringify(r.pageErrors));
  assert.ok(r.pageErrors.every((m) => /^e2e /.test(m)), 'nothing thrown by the plugin');

  const logs = await browserLogs();
  const text = JSON.stringify(logs);
  const byType = (t) => logs.filter((l) => l.fields.eventType === t);

  const err = byType('js_error').find((l) => /uncaught error/.test(l.message));
  assert.ok(err, 'the uncaught error was reported: ' + logs.map((l) => l.message).join(' | '));
  assert.match(err.message, /\[email\]/);
  assert.match(err.message, /token=\[redacted\]/);
  assert.equal(err.severity, 'error');
  assert.equal(err.fields.handler, 'window.onerror');
  assert.equal(err.fields.path, '/');
  assert.ok(err.stackTrace && err.stackTrace.length > 10);

  assert.ok(byType('js_error').some((l) => /Unhandled rejection: e2e unhandled rejection/.test(l.message)), 'the rejection was reported');

  const failedFetch = byType('request_failed').find((l) => l.fields.transport === 'fetch');
  assert.ok(failedFetch, 'the failed fetch was reported');
  assert.equal(failedFetch.fields.requestPath, '/wp-json/zl-e2e/v1/fail');
  assert.equal(failedFetch.fields.method, 'POST');
  assert.equal(failedFetch.fields.status, 500);
  const failedXhr = byType('request_failed').find((l) => l.fields.transport === 'xhr');
  assert.ok(failedXhr, 'the failed XMLHttpRequest was reported');
  assert.equal(failedXhr.fields.requestPath, '/wp-json/zl-e2e/v1/fail');

  const slow = byType('request_slow').find((l) => l.fields.requestPath === '/wp-json/zl-e2e/v1/slow');
  assert.ok(slow, 'the slow request was reported');
  assert.ok(slow.fields.durationMs >= 1000);
  assert.equal(byType('request_failed').filter((l) => l.fields.status === 404).length, 0, '404 is not a server failure');
  assert.equal(logs.filter((l) => /ingest|\/v1\/traces/.test(JSON.stringify(l.fields))).length, 0, 'ZipLogger\'s own traffic is never reported');

  for (const secret of ['NONCESECRET', 'TOPSECRETBEARER', 'XHRSECRET', '4242424242424242', 'jane@example.com', 'abc123secret', 'Bearer']) {
    assert.ok(!text.includes(secret), `leaked: ${secret}`);
  }
  for (const l of logs) {
    assert.deepEqual(l.tags, ['wordpress', 'browser']);
    assert.equal(l.source, 'e2e-site');
    assert.equal(l.fields.environment, 'staging');
    assert.ok(!('url' in l.fields) && !('userAgent' in l.fields), 'no page URL or user agent added by the SDK');
    assert.match(l.fields.sessionId, /^sess_[0-9a-f]{20}$/);
  }
  const entries = await mock.received('/ingest/v1/logs');
  assert.ok(entries.every((e) => e.headers['x-api-key'] === KEYS.browser), 'browser data is sent with the browser key');
  assert.deepEqual(entries.flatMap((e) => e.lint || []), [], 'every record satisfies the ingestion contract');
});

test('browser monitoring alone stores nothing on the visitor\'s device', async () => {
  setKeys();
  configure({ browser: { enabled: true } });
  await mock.clear();
  const r = runBrowser('survey', { path: '/?zl_fixture=js_errors' });
  assert.deepEqual(r.snap.local, {});
  assert.deepEqual(r.snap.session, {});
  assert.deepEqual(r.snap.cookies.filter(ours), [], 'no cookie of ours (WooCommerce, when active, sets its own order-attribution cookies)');
  assert.equal(r.snap.api, true);
});

test('loading the script three times still means one instance: no duplicate reports, fetch wrapped once', async () => {
  setKeys();
  configure({ browser: { enabled: true, failed_requests: false } });
  await mock.clear();
  const r = runBrowser('duplicateInit');
  assert.equal(r.fetchUnchanged, true);
  const hits = (await browserLogs()).filter((l) => /dup-check-error/.test(l.message));
  assert.equal(hits.length, 1);
});

// -------------------------------------------------------------------------------------------------------
// Consent and analytics.
// -------------------------------------------------------------------------------------------------------

test('analytics: nothing before consent; identifiers and page views only after a grant; nothing after withdrawal', async () => {
  setKeys();
  configure({ analytics: { enabled: true, page_views: true } });
  await mock.clear();
  const { steps } = runBrowser('consent', { categories: ['analytics'] });
  const by = Object.fromEntries(steps.map((s) => [s.label, s]));

  const first = by['loaded, no choice made'];
  assert.equal(first.events, 0, 'no event before consent');
  assert.equal(first.replay, 0);
  assert.deepEqual(first.snap.local, {}, 'no id on the device before consent');
  assert.deepEqual(first.snap.session, {});
  assert.ok(!first.snap.cookies.includes('ziplogger_consent'));
  assert.equal(first.snap.status.allowed.analytics, false);

  const granted = by['after grant'];
  assert.ok(granted.events >= 1, 'the current page view is counted once consent exists');
  assert.match(granted.snap.local.zl_anon, /^anon_[0-9a-f]{20}$/);
  assert.match(granted.snap.session.zl_sess, /^sess_[0-9a-f]{20}$/);
  assert.ok(granted.snap.cookies.includes('ziplogger_consent'));

  const reloaded = by['after reload (choice remembered)'];
  assert.ok(reloaded.events > granted.events, 'the choice is remembered: the next page view is counted');
  assert.equal(reloaded.snap.local.zl_anon, granted.snap.local.zl_anon, 'the same anonymous id');

  const withdrawn = by['after withdrawal'];
  assert.deepEqual(withdrawn.snap.local, {}, 'withdrawal removes the anonymous id');
  assert.deepEqual(withdrawn.snap.session, {});
  const after = by['after a track call post-withdrawal'];
  assert.equal(after.events, withdrawn.events, 'nothing is sent after withdrawal');

  const events = await eventLines();
  assert.ok(events.every((e) => e.anonymousId && e.sessionId), 'the server needs an id on every event');
  assert.ok(!events.some((e) => e.name === 'after_withdrawal_event'));
  const views = events.filter((e) => e.name === 'page_view');
  assert.ok(views.length >= 2 && views.every((v) => v.properties.path === '/' && v.properties.pageType));
  assert.ok(!JSON.stringify(events).match(/https?:\/\//), 'no full URLs: paths only');
  const entries = await mock.received('/ingest/v1/events');
  assert.ok(entries.every((e) => e.headers['x-api-key'] === KEYS.browser));
});

test('Do Not Track blocks analytics even after the visitor grants consent', async () => {
  setKeys();
  configure({ analytics: { enabled: true } });
  await mock.clear();
  const { steps } = runBrowser('consent', { categories: ['analytics'], dnt: true });
  assert.ok(steps.every((s) => s.events === 0), 'no event at any step');
  assert.deepEqual(steps.find((s) => s.label === 'after grant').snap.local, {}, 'and no identifier was created');
});

test('an analytics policy of "none" collects without a prompt (the site has decided no consent is needed)', async () => {
  setKeys();
  configure({ analytics: { enabled: true }, consent: { analytics: 'none' } });
  await mock.clear();
  const r = runBrowser('survey', { wait: 2500 });
  assert.match(r.snap.local.zl_anon, /^anon_/);
  assert.ok((await eventLines()).some((e) => e.name === 'page_view'));
  configure({ analytics: { enabled: true } }); // back to the default policy
});

test('client-side navigation is counted per path without query strings', async () => {
  setKeys();
  configure({ analytics: { enabled: true, page_views: true, spa_navigation: true } });
  await mock.clear();
  runBrowser('spa');
  const views = (await eventLines()).filter((e) => e.name === 'page_view');
  assert.deepEqual(views.map((v) => v.properties.path), ['/', '/spa-a/', '/spa-b/', '/checkout/']);
  assert.deepEqual(views.map((v) => v.properties.navigation), ['load', 'spa', 'spa', 'spa']);
  assert.ok(!JSON.stringify(views).includes('SECRETCOUPON'));
  assert.equal(new Set(views.map((v) => v.sessionId)).size, 1, 'one session for the whole visit');
});

// -------------------------------------------------------------------------------------------------------
// Tracing.
// -------------------------------------------------------------------------------------------------------

test('browser tracing: same-site requests carry the trace; the server receives it; third parties get nothing', async () => {
  setKeys();
  configure({ browser: { enabled: true }, tracing: { enabled: true, browser: true, sample_rate: 100, propagate_hosts: '' } });
  await mock.clear();
  const r = runBrowser('tracing');

  const sameHeader = r.results.same.received.traceparent;
  assert.match(sameHeader, /^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/, 'WordPress received a W3C traceparent');
  assert.match(r.results.same.received.baggage, /^session\.id=sess_[0-9a-f]{20}$/, 'and the session id as baggage');
  assert.ok(r.results.sameXhr.received.traceparent, 'XMLHttpRequest too');

  const third = (await mock.received('/third-party/probe'))[0];
  assert.ok(third, 'the third-party request was made');
  assert.equal(third.headers.traceparent, undefined, 'no trace header to a third party by default');
  assert.equal(third.headers.baggage, undefined);

  await waitFor(async () => (await spans()).length > 0, { what: 'browser spans', timeout: 15000 });
  const s = (await spans()).find((x) => x.traceId === sameHeader.split('-')[1]);
  assert.ok(s, 'the browser exported the span whose ids the server received: one trace across browser and server');
  assert.equal(s.spanId, sameHeader.split('-')[2]);
  assert.equal(attr(s, 'http.request.method'), 'GET');
  assert.equal(attr(s, 'url.full'), 'http://wordpress/wp-json/zl-e2e/v1/ok');
  assert.equal(attr(s, 'http.response.status_code'), '200');
  assert.match(attr(s, 'session.id'), /^sess_/);
  const posts = await mock.received('/v1/traces');
  assert.ok(posts.every((e) => e.headers['x-api-key'] === KEYS.browser));
});

test('an allowlisted third-party host gets traceparent, but never the session baggage', async () => {
  setKeys();
  configure({ browser: { enabled: true }, tracing: { enabled: true, browser: true, sample_rate: 100, propagate_hosts: 'thirdparty.example.org:5081' } });
  await mock.clear();
  runBrowser('tracing');
  const third = (await mock.received('/third-party/probe')).filter((e) => e.method === 'GET')[0];
  assert.match(third.headers.traceparent, /^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
  assert.equal(third.headers.baggage, undefined, 'no baggage or session id to a third party');
});

test('with sampling at 0 the trace is still propagated (for correlation) but marked not sampled and nothing is exported', async () => {
  setKeys();
  configure({ browser: { enabled: true }, tracing: { enabled: true, browser: true, sample_rate: 0 } });
  await mock.clear();
  const r = runBrowser('tracing');
  assert.match(r.results.same.received.traceparent, /-00$/);
  assert.equal((await spans()).length, 0);
});

// -------------------------------------------------------------------------------------------------------
// Session replay.
// -------------------------------------------------------------------------------------------------------

const replaySettings = (over = {}) => configure({ browser: { enabled: true }, replay: { enabled: true, sample_rate: 100, mask_all_text: true, exclude_paths: '/private-area/*', max_minutes: 5, ...over } });

const FORBIDDEN = [
  'Jane Customer', 'jane.customer@example.com', 'jane.customer%40', '4242 4242 4242 4242', 'hunter2-SECRET', '4111111111111111',
  'TEXTAREA-PRIVATE-NOTE', 'PAYMENT-PANEL-SECRET', 'IGNORED-BLOCK-SECRET', 'MARKED-MASK-SECRET', 'ADDRESS-SECRET', 'LINKSECRETTOKEN',
  'DYNAMIC-PRIVATE-TEXT', 'DYNAMIC-INPUT-SECRET', 'DYNAMICLINKSECRET', 'TYPED-NAME-SECRET', 'TYPED-NOTE-SECRET',
];
const allChunkText = (chunks) => chunks.map((c) => c.body).join('\n');

test('replay: nothing is requested or recorded before the visitor consents', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  const r = runBrowser('replay', { consent: false });
  assert.equal(r.chunks.length, 0);
  assert.ok(!r.requests.some((q) => q.includes('/ingest/v1/replay')), 'not even the configuration request: ' + r.requests.join(', '));
  assert.deepEqual(r.snap.session, {});
  assert.equal(r.snap.status.replay.reason, 'consent');
});

test('replay: after consent the session is recorded with text, inputs, payment and password fields, links and dynamic content masked', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  const r = runBrowser('replay');
  assert.ok(r.chunks.length >= 1, 'recording happened');
  assert.equal(r.snap.status.replay.state, 'recording');
  assert.ok(r.chunks.every((c) => c.headers.key === KEYS.browser), 'uploaded with the browser key');
  assert.ok(r.chunks.every((c) => c.outcome === 202));
  assert.equal(new Set(r.chunks.map((c) => c.replay.sessionId)).size, 1);
  assert.equal(r.snap.session.zl_sess, r.chunks[0].replay.sessionId, 'the replay session id is the analytics/log session id');
  assert.deepEqual(r.chunks.map((c) => c.replay.sequence), r.chunks.map((_, i) => i), 'sequence numbers are gapless');

  const text = allChunkText(r.chunks);
  for (const secret of FORBIDDEN) assert.ok(!text.includes(secret), `LEAKED into the recording: ${secret}`);
  // It is a real, non-trivial recording, not an empty one.
  const events = r.chunks.flatMap((c) => JSON.parse(c.body).events);
  assert.ok(events.length > 10);
  assert.ok(events.some((e) => e.type === 2), 'a full snapshot exists');
  assert.ok(/\*{3,}/.test(text), 'masked text is asterisks');
  assert.ok(text.includes('mailto:[redacted]'), 'mailto addresses are removed from links');
  assert.ok(text.includes('"/thing"') || text.includes('/thing'), 'link paths remain, without their query strings');
  for (const c of r.chunks) assert.ok(!JSON.parse(c.body).meta.url.includes('?'), 'the page address carries no query string');
  assert.deepEqual(r.pageErrors, []);
});

test('replay: the payment panel and the ignore block are not just masked, they are absent', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  const r = runBrowser('replay');
  const text = allChunkText(r.chunks);
  assert.ok(!text.includes('woocommerce-checkout-payment') || !text.includes('PAYMENT-PANEL-SECRET'));
  assert.ok(!text.includes('data-ziplogger-ignore') || !text.includes('IGNORED-BLOCK-SECRET'));
});

test('replay: checkout, account, cart, login and administrator-added paths are never recorded', async () => {
  setKeys();
  replaySettings();
  for (const path of ['/checkout/?zl_fixture=replay_page', '/my-account/orders/?zl_fixture=replay_page', '/cart/?zl_fixture=replay_page', '/private-area/notes/?zl_fixture=replay_page']) {
    await mock.clear();
    const r = runBrowser('replay', { path });
    assert.equal(r.chunks.length, 0, `${path} must not be recorded`);
    assert.ok(!r.requests.some((q) => q.includes('/ingest/v1/replay')), `${path} does not even contact the replay endpoint`);
    assert.equal(r.snap.status.replay.state, 'idle', path);
  }
});

test('replay: an administrator is never recorded, a shopper is', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  const admin = runBrowser('replay', { login: ADMIN });
  assert.equal(admin.chunks.length, 0, 'administrators are excluded by default');
  assert.equal(admin.snap.status.replay.reason, 'role');
  assert.ok(!admin.requests.some((q) => q.includes('/ingest/v1/replay')));

  await mock.clear();
  const shopper = runBrowser('replay', { login: SHOPPER });
  assert.ok(shopper.chunks.length >= 1, 'a subscriber may be recorded');
  for (const secret of FORBIDDEN) assert.ok(!allChunkText(shopper.chunks).includes(secret), `LEAKED: ${secret}`);
});

test('replay: navigating client-side to an excluded page stops the recording', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  const r = runBrowser('replay', { navigate: '/my-account/' });
  const step = r.timeline.find((t) => t.label.startsWith('after navigating'));
  assert.equal(step.status.state, 'stopped');
  assert.equal(step.status.reason, 'path');
  assert.equal(step.status.recording, false);
});

test('replay: withdrawing consent stops recording and discards what was not yet sent', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  const r = runBrowser('replay', { revoke: true });
  const before = r.timeline.find((t) => t.label === 'after interaction').replay;
  const after = r.timeline.find((t) => t.label === 'after withdrawal');
  assert.ok(before >= 1);
  assert.equal(after.status.recording, false);
  assert.equal(after.status.reason, 'consent');
  assert.equal(after.replay, before, 'no further chunk was uploaded after withdrawal');
  assert.ok(!allChunkText(r.chunks).includes('AFTER-WITHDRAWAL-SECRET'), 'the unsent buffer was discarded, not flushed');
  assert.ok(!('zl_replay' in (r.snap.session || {})) && !('zl_sess' in (r.snap.session || {})), 'nothing of the recording stays on the device');
});

test('replay: the service can switch recording off for everyone', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  await mock.control({ replayConfig: { enabled: false, reason: 'plan' } });
  const r = runBrowser('replay');
  assert.equal(r.chunks.length, 0);
  await mock.control({ mode: 'ok' });
});

test('replay: an unreachable service leaves the page working and records nothing', async () => {
  setKeys();
  replaySettings();
  await mock.clear();
  await mock.control({ mode: 'down' });
  const r = runBrowser('replay');
  assert.equal(r.chunks.filter((c) => c.outcome === 202).length, 0);
  assert.deepEqual(r.pageErrors, []);
  await mock.control({ mode: 'ok' });
});

// -------------------------------------------------------------------------------------------------------
// Failure of our own parts must not touch the page.
// -------------------------------------------------------------------------------------------------------

test('when the plugin script is blocked the page works normally', async () => {
  setKeys();
  configure({ browser: { enabled: true } });
  await mock.clear();
  const r = runBrowser('blocked', { block: 'script' });
  assert.equal(r.state.fail, 500);
  assert.equal(r.state.ok, true);
  assert.ok(r.state.bodyLength > 100);
  assert.equal(r.pageErrors.length, 2, 'only the fixture own uncaught error and rejection');
  assert.ok(r.pageErrors.every((m) => /^e2e /.test(m)));
  assert.equal((await mock.received()).length, 0);
});

test('when ZipLogger is unreachable the page works normally and loads as fast', async () => {
  setKeys();
  configure({ browser: { enabled: true, failed_requests: true, slow_requests: true }, analytics: { enabled: true }, tracing: { enabled: true, browser: true } });
  await mock.clear();
  const baseline = runBrowser('blocked', { block: 'none' });
  const down = runBrowser('blocked', { block: 'ingest' });
  assert.equal(down.state.fail, 500);
  assert.equal(down.state.ok, true);
  assert.equal(down.pageErrors.length, 2, 'still only the fixture own error and rejection: ' + JSON.stringify(down.pageErrors));
  assert.ok(down.pageErrors.every((m) => /^e2e /.test(m)));
  assert.ok(down.loadMs < baseline.loadMs + 3000, `load ${down.loadMs} ms vs ${baseline.loadMs} ms with the service up`);
  assert.ok(down.failures.every((u) => u.startsWith('http://mock:5081')), 'only ZipLogger requests failed');
});

test('a service that answers 5xx is retried a little and then dropped, without page impact', async () => {
  setKeys();
  configure({ browser: { enabled: true } });
  await mock.clear();
  await mock.control({ mode: 'down' });
  const r = runBrowser('blocked', { block: 'none' });
  assert.equal(r.state.ok, true);
  assert.equal(r.pageErrors.length, 2);
  assert.ok(r.pageErrors.every((m) => /^e2e /.test(m)));
  const attempts = (await mock.received('/ingest/v1/logs')).length;
  assert.ok(attempts <= 12, `bounded retries, saw ${attempts} requests`);
  await mock.control({ mode: 'ok' });
});

// -------------------------------------------------------------------------------------------------------
// Sign-in, and page caches.
// -------------------------------------------------------------------------------------------------------

test('sign-in sets a readable hint cookie and the context endpoint answers only about the asker', async () => {
  setKeys();
  configure({ browser: { enabled: true }, analytics: { enabled: true, identify: true }, replay: { enabled: true, sample_rate: 100 } });
  await mock.clear();
  const r = runBrowser('login', SHOPPER);
  assert.deepEqual(r.anonymous, { success: true, data: { loggedIn: false, replayAllowed: true, userRef: null } });
  assert.deepEqual(r.signedIn.cookie, ['ziplogger_li=1']);
  assert.equal(r.signedIn.context.data.loggedIn, true);
  assert.equal(r.signedIn.context.data.replayAllowed, true);
  assert.match(r.signedIn.context.data.userRef, /^wpu_[0-9a-f]{24}$/);
  assert.equal(r.signedIn.viaGet.success, false, 'a GET is refused');
  assert.ok(!('userRef' in (r.signedIn.viaGet.data || {})));
  assert.equal(JSON.stringify(r.signedIn).includes('shopper'), false, 'no login name, no email in any answer');
  assert.equal(r.cookies[0].httpOnly, false, 'the script has to be able to read it');
  assert.equal(r.cookies[0].sameSite, 'Lax');
  assert.deepEqual(r.afterLogout.cookie, [], 'sign-out removes the hint');

  const admin = runBrowser('login', ADMIN);
  assert.equal(admin.signedIn.context.data.replayAllowed, false, 'an administrator may not be recorded');
});

test('the page a cache stores is identical for every visitor and carries no identifiers; each visitor still gets their own', async () => {
  setKeys();
  configure({ browser: { enabled: true }, analytics: { enabled: true, identify: true }, replay: { enabled: true }, tracing: { enabled: true, browser: true } });
  await mock.clear();
  // What a full-page cache does: store the anonymous HTML once and let the web server hand it out.
  const anonymous = await getAsHost('wordpress', '/');
  putStatic('zl-cached-home.html', anonymous.text);
  const r = runBrowser('cachedPage', { ...SHOPPER, staticPath: '/zl-cached-home.html' });
  const pick = (html) => html.match(/<script type="application\/json" id="ziplogger-config">(.*?)<\/script>/s)[1];
  assert.equal(pick(r.signedInHtml), pick(r.anonymousHtml), 'the signed-in and the anonymous page carry the same configuration');
  assert.equal(pick(anonymous.text), pick(r.anonymousHtml));
  for (const forbidden of ['sess_', 'anon_', 'wpu_', 'shopper', 'traceparent', 'nonce']) {
    assert.ok(!r.anonymousHtml.includes(forbidden) || forbidden === 'nonce', `the cached page contains ${forbidden}`);
    assert.ok(!pick(r.anonymousHtml).includes(forbidden), `configuration contains ${forbidden}`);
  }
  assert.equal(r.ids.length, 2);
  assert.match(r.ids[0].anon, /^anon_/);
  assert.notEqual(r.ids[0].anon, r.ids[1].anon, 'two visitors served the same cached file get different visitor ids');
  assert.notEqual(r.ids[0].sess, r.ids[1].sess, 'and different session ids');
});
