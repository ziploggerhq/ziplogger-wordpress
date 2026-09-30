// End-to-end: the WordPress dashboard, in a real browser, reading back what this site sent.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node --test tests/e2e/m6-dashboard.test.mjs
//
// The read side is the local stand-in (mock-read-model.mjs), which follows the shapes in the service's source.
// So this proves the plugin's handling of those shapes and the credential boundaries; it does not prove the
// live service answers exactly so.
import test from 'node:test';
import assert from 'node:assert/strict';
import { KEYS, wp, mock, sleep, waitFor, setSettings, installZip, setKeys, runBrowser } from './lib.mjs';

const ENDPOINT = 'http://mock:5081';
const WRONG_SCOPE = 'zk_e2e_wrong_scope_key_000000';

const configure = (extra = {}) => setSettings({
  enabled: true, endpoint: ENDPOINT, source: 'e2e-site', min_severity: 'warn', collectors: { php_errors: true },
  browser: { enabled: true, errors: true, failed_requests: true, navigation_timing: true, web_vitals: true, perf_sample_rate: 100, error_sample_rate: 100 },
  tracing: { enabled: true, server_spans: true, outbound_spans: true, sample_rate: 100, service_name: 'e2e-shop' },
  ...extra,
});

const flush = () => wp(['ziplogger', 'flush'], { allowFail: true });
const get = (path) => fetch(`http://localhost:8088${path}`, { redirect: 'manual' }).then((r) => r.text());

test('the stack is up and the ZIP is installed', async () => {
  await waitFor(async () => (await fetch('http://localhost:5081/__stats')).ok, { what: 'receiver' });
  await waitFor(async () => (await fetch('http://localhost:8088/')).status < 500, { what: 'WordPress', timeout: 120000 });
  if (wp(['core', 'is-installed'], { allowFail: true }).status !== 0) {
    wp(['core', 'install', '--url=http://wordpress', '--title=ZipLogger E2E', '--admin_user=admin', '--admin_password=e2e-admin-pass-1', '--admin_email=admin@example.org', '--skip-email']);
  }
  installZip();
  wp(['user', 'get', 'shopper', '--field=ID'], { allowFail: true }).status === 0 || wp(['user', 'create', 'shopper', 'shopper@example.org', '--role=subscriber', '--user_pass=shopper-pass-1']);
  wp(['user', 'update', 'admin', '--user_pass=e2e-admin-pass-1']);
});

test('generate some real activity: server errors, browser errors and vitals, server spans', async () => {
  setKeys();
  configure();
  await mock.clear();
  await get('/?zl_fixture=warning');
  await get('/?zl_fixture=exception');
  await get('/wp-json/zl-e2e/v1/fail');
  await get('/?zl_fixture=outbound');
  await get('/');
  runBrowser('jsErrors');
  runBrowser('survey', { path: '/', wait: 3000 });
  await sleep(1500);
  flush();
  await sleep(1500);
  assert.ok((await mock.received('/ingest/v1/logs')).length >= 2, 'logs were delivered');
  assert.ok((await mock.received('/v1/traces')).length >= 1, 'spans were delivered');
});

test('the dashboard reads recent errors, trends, vitals, request stats and traces back, on the right tabs', async () => {
  const r = runBrowser('adminDashboard', { screenshots: true, testRead: true, refresh: true });
  assert.deepEqual(r.pageErrors, []);
  const panel = (tab, id) => r.tabs[tab].panels.find((p) => p.id === id);

  // Server errors.
  for (const tab of ['overview', 'logs']) {
    const errors = panel(tab, 'errors');
    assert.ok(errors, `${tab} has the errors panel`);
    assert.match(errors.text, /Uncaught RuntimeException: e2e fixture uncaught exception/, `${tab}: ${errors.text.slice(0, 200)}`);
    assert.doesNotMatch(errors.text, /file=|eventType=|phpErrorType/, 'the message, not the structured tail');
    const trend = panel(tab, 'error_trend');
    assert.match(trend.html, /<svg[^>]*role="img"[^>]*aria-label="[^"]*in total, at most/);
    assert.match(trend.text, /Show the numbers/);
  }

  // Browser.
  const js = panel('browser', 'browser_errors');
  assert.match(js.text, /e2e uncaught error for \[email\] token=\[redacted\]/, 'the JavaScript error, as sanitized in the browser');
  assert.doesNotMatch(js.text, /jane@example\.com|abc123secret/);
  assert.match(panel('browser', 'browser_failed').html, /<svg/, 'the failed-requests chart');
  const vitals = panel('browser', 'vitals');
  assert.match(vitals.text, /\b(LCP|FCP|TTFB|CLS)\b[\s\S]*\b(Good|Needs improvement|Poor)\b/, vitals.text);

  // Tracing.
  const stats = panel('tracing', 'tracing_stats');
  assert.match(stats.text, /Sampled requests\s+\d+/);
  assert.match(stats.text, /Latest hourly p95 latency\s+[\d,]+ ms/);
  const traces = panel('tracing', 'traces');
  assert.match(traces.html, /href="http:\/\/mock:5081\/traces\/[0-9a-f]{32}"/, 'links into the application, by trace id');
  assert.match(traces.text, /Recent failed requests/);

  // Tabs the read interface cannot answer show local facts and links, not invented numbers.
  for (const tab of ['analytics', 'replay', 'woocommerce']) assert.deepEqual(r.tabs[tab].panels, [], `${tab} has no live panel`);
  assert.ok(r.tabs.analytics.links.includes('http://mock:5081/events'));
  assert.ok(r.tabs.replay.links.includes('http://mock:5081/replays'));
  assert.ok(r.tabs.woocommerce.links.some((l) => l.endsWith('/events/name/order_created')));
  assert.match(r.tabs.woocommerce.body, /No order event has been queued yet/);

  // The read-key test button and the refresh link.
  assert.match(r.readTest, /The read key works\. It belongs to the workspace "e2e-workspace"\./);
  assert.match(r.refresh, /Uncaught RuntimeException/);
});

test('the browser never sees a ZipLogger credential, and never talks to ZipLogger, while using the dashboard', async () => {
  const r = runBrowser('adminDashboard', {});
  for (const [tab, t] of Object.entries(r.tabs)) {
    for (const secret of [KEYS.read, KEYS.server, KEYS.browser, 'zk_e2e']) {
      assert.ok(!t.source.includes(secret), `${tab}: the page source contains ${secret}`);
    }
  }
  assert.deepEqual(r.xApiKeyRequests, [], 'no request from the browser carried an API key');
  assert.deepEqual(r.requests.filter((u) => u.startsWith(ENDPOINT)), [], 'the admin browser made no request to ZipLogger at all');
  // The key does reach ZipLogger - from the server.
  const reads = (await mock.received()).filter((e) => e.path.startsWith('/grafana/'));
  assert.ok(reads.length > 0);
  assert.ok(reads.every((e) => e.headers['x-api-key'] === KEYS.read), 'reads are made with the read key only');
  assert.ok(reads.every((e) => e.method === 'GET'));
});

test('a subscriber, and a visitor, get nothing from the panel endpoint', async () => {
  for (const args of [{ user: 'shopper', pass: 'shopper-pass-1' }, {}]) {
    const before = (await mock.received()).filter((e) => e.path.startsWith('/grafana/')).length;
    const { answers } = runBrowser('panelAsUser', args);
    for (const a of Object.values(answers)) assert.ok(!/Uncaught|e2e-workspace|Recent/.test(a.text), JSON.stringify(a));
    assert.equal(answers.get.status === 200 ? JSON.parse(answers.get.text).success : false, false);
    assert.equal((await mock.received()).filter((e) => e.path.startsWith('/grafana/')).length, before, 'nothing was requested from ZipLogger on their behalf');
  }
});

test('without a read key the panels explain what is missing and nothing is fetched', async () => {
  setKeys({ read: null });
  const before = (await mock.received()).filter((e) => e.path.startsWith('/grafana/')).length;
  const r = runBrowser('adminDashboard', { tabs: ['overview', 'logs', 'tracing', 'connection'] });
  for (const tab of ['overview', 'logs', 'tracing']) {
    assert.deepEqual(r.tabs[tab].panels, []);
    assert.ok(r.tabs[tab].unavailable.every((t) => /No read key is set\. Add one on the Connection tab/.test(t)), tab);
    assert.equal(r.tabs[tab].hasNonce, false, 'no nonce is handed out when there is nothing to fetch');
  }
  assert.match(r.tabs.connection.body, /No read key is set/);
  assert.equal((await mock.received()).filter((e) => e.path.startsWith('/grafana/')).length, before);
  setKeys();
});

test('a key without the read scope is reported as not accepted, in every panel, without breaking the screen', async () => {
  wp(['option', 'update', 'ziplogger_read_key', WRONG_SCOPE]);
  const r = runBrowser('adminDashboard', { tabs: ['logs', 'browser'], testRead: true });
  for (const tab of ['logs', 'browser']) {
    for (const p of r.tabs[tab].panels) assert.match(p.text, /did not accept the read key/, `${tab}/${p.id}: ${p.text}`);
  }
  assert.match(r.readTest, /did not accept the read key/);
  assert.deepEqual(r.pageErrors, []);
  setKeys();
});

test('when ZipLogger is down the dashboard still renders and says so', async () => {
  await mock.control({ mode: 'down' });
  const r = runBrowser('adminDashboard', { tabs: ['logs', 'tracing'] });
  await mock.control({ mode: 'ok' });
  for (const tab of ['logs', 'tracing']) {
    assert.ok(r.tabs[tab].panels.length >= 2);
    for (const p of r.tabs[tab].panels) assert.match(p.text, /reported a problem|could not be reached|did not answer in time/, `${tab}/${p.id}: ${p.text}`);
    assert.match(r.tabs[tab].body, /Delivery health|Live from ZipLogger|Traces/, 'the rest of the screen is intact');
  }
  assert.deepEqual(r.pageErrors, []);
});

test('accessibility: axe-core finds no serious or critical WCAG problems on any tab, in LTR, RTL and at phone width', async () => {
  setKeys();
  configure();
  const tabs = ['overview', 'connection', 'logs', 'browser', 'analytics', 'replay', 'tracing', 'woocommerce', 'privacy', 'diagnostics'];
  const r = runBrowser('accessibility', { tabs, screenshots: true }, { timeout: 900000 });
  const bad = [];
  const overflow = [];
  for (const [where, result] of Object.entries(r)) {
    for (const v of result.violations) if (['serious', 'critical'].includes(v.impact)) bad.push(`${where}: ${v.id} (${v.impact}) ${v.help} -> ${v.nodes.join(' | ')}`);
    if (result.horizontalOverflow && where.startsWith('phone')) overflow.push(where);
  }
  assert.deepEqual(bad, [], 'accessibility violations:\n' + bad.join('\n'));
  assert.deepEqual(overflow, [], 'horizontal scrolling at phone width');
  // Moderate and minor findings are reported, not failed on: keep them visible.
  const others = Object.entries(r).flatMap(([where, result]) => result.violations.filter((v) => !['serious', 'critical'].includes(v.impact)).map((v) => `${where}: ${v.id} (${v.impact})`));
  if (others.length) console.log('axe (moderate/minor):', [...new Set(others)].join('; '));
});
