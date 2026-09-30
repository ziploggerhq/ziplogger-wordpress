// Live check: the built plugin, in the real-WordPress test stack, against a REAL ZipLogger service.
//
//   docker compose -f docker-compose.e2e.yml up -d          # then `wp core install` if the site is new
//   node tests/live/live-check.mjs <path to an env file>
//
// The env file is outside the repository and holds (KEY=value per line):
//   ZL_ENDPOINT=https://app.ziplogger.ai
//   ZL_SERVER_KEY=...    server key (ingestion)          or, with a single key:  ZL_KEY=...
//   ZL_BROWSER_KEY=...   browser key (ingestion only)    (single-key mode: ZL_KEY plays this role in phase B)
//   ZL_READ_KEY=...      optional; enables the read checks
//
// Use a TEST workspace. Everything written is tagged source "wp-plugin-live-test" and is a few dozen records.
// Keys are never printed (every output line is scrubbed) and never written into the repository. The stack's
// options hold the keys while it runs: `docker compose ... down` removes them (the database is in memory).
//
// What it checks, phase by phase (each line prints PASS or FAIL with the observed status):
//   A  the plugin's own delivery: test event, PHP warning + exception, developer log, server span, server event
//   B  raw HTTP contract: replay of an Idempotency-Key, reuse with another body, bad JSON, wrong/missing key,
//      an event without a user id, spans
//   C  the plugin against a bad key: it pauses, keeps the data, holds it after the key changes, and delivers
//      once an administrator retargets it
//   D  a real Chromium at a different origin: errors, analytics, replay and browser traces reach the service
//      (status codes and CORS headers)
//   E  (only with ZL_READ_KEY) the read interface and the dashboard panels
import fs from 'node:fs';
import { wp, wpEval, setSettings, get, sleep, runBrowser, installZip } from '../e2e/lib.mjs';

const file = process.argv[2];
if (!file) { console.error('usage: node tests/live/live-check.mjs <env file>'); process.exit(2); }
const env = Object.fromEntries(fs.readFileSync(file, 'utf8').split('\n').filter((l) => l.trim() && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)));
const ENDPOINT = (env.ZL_ENDPOINT || 'https://app.ziplogger.ai').replace(/\/$/, '');
const SERVER = env.ZL_SERVER_KEY || env.ZL_KEY;
const BROWSER = env.ZL_BROWSER_KEY || env.ZL_KEY;
const READ = env.ZL_READ_KEY || '';
if (!SERVER || !BROWSER) { console.error('need ZL_KEY, or ZL_SERVER_KEY and ZL_BROWSER_KEY'); process.exit(2); }
const secrets = [SERVER, BROWSER, READ].filter(Boolean);
const scrub = (t) => secrets.reduce((s, k) => String(s).split(k).join('[key]'), String(t));
let failures = 0;
const check = (ok, label, detail = '') => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  ' + scrub(detail) : ''}`); };
const placeholder = (role) => `zk_placeholder_${role}_key_0000000000`;
const now = () => new Date().toISOString();
const run = Math.random().toString(36).slice(2, 8);
const SOURCE = 'wp-plugin-live-test';
const status = () => JSON.parse(wp(['ziplogger', 'status', '--format=json']).stdout);
const flush = () => wp(['ziplogger', 'flush'], { allowFail: true }).stdout.trim();

async function raw(path, { method = 'POST', body, key = SERVER, headers = {} } = {}) {
  const r = await fetch(ENDPOINT + path, { method, body, redirect: 'manual', headers: { ...(key ? { 'X-Api-Key': key } : {}), ...(body ? { 'Content-Type': 'application/json' } : {}), ...headers } });
  return { status: r.status, text: (await r.text()).slice(0, 400) };
}

installZip();
wp(['plugin', 'activate', 'ziplogger'], { allowFail: true });

// ---- A: the plugin's own delivery -------------------------------------------------------------------------------
console.log('\n== A. delivery by the plugin ==');
wp(['option', 'update', 'ziplogger_api_key', SERVER]);
wp(['option', 'update', 'ziplogger_browser_key', SERVER === BROWSER ? placeholder('browser') : BROWSER]);
setSettings({
  enabled: true, endpoint: ENDPOINT, source: SOURCE, environment: 'live-test', min_severity: 'warn', collectors: { php_errors: true },
  analytics: { enabled: true },
  tracing: { enabled: true, server_spans: true, outbound_spans: true, sample_rate: 100, service_name: SOURCE },
});
const test = wp(['ziplogger', 'test'], { allowFail: true });
check(/accepted the test event \(HTTP 202\)/.test(test.stdout), 'the test event is accepted', test.stdout.trim());
await get('/?zl_fixture=warning');
await get('/?zl_fixture=exception');
await get('/');
wpEval("ziplogger_log('error', 'live check: developer log', array('purpose' => 'live verification')); ziplogger_track('live_check_event', array('step' => 1), array('insert_id' => 'live-check-" + run + "'));");
await sleep(1500);
flush();
await sleep(1500);
let st = status();
check(st.signals.logs.sent_events >= 3 && !st.signals.logs.last_error.message, 'logs (warning, exception, developer log) delivered', `sent ${st.signals.logs.sent_events}`);
check(st.signals.events.sent_events >= 1 && st.signals.events.server_rejected === 0, 'a server-side event delivered and not rejected', `sent ${st.signals.events.sent_events}, refused ${st.signals.events.server_rejected}`);
check(st.signals.traces.sent_events >= 1 && !st.signals.traces.last_error.message, 'server spans delivered', `sent ${st.signals.traces.sent_events}`);
check(st.pending === 0 && st.state === 'healthy', 'nothing left waiting; state healthy', `${st.state}, pending ${st.pending}`);

// ---- B: raw contract --------------------------------------------------------------------------------------------
console.log('\n== B. HTTP contract, observed ==');
const rec = (message, extra = {}) => ({ timestamp: now(), source: SOURCE, severity: 'info', message, fields: { probe: run }, tags: ['live-test'], ...extra });
const body1 = JSON.stringify([rec('live raw probe')]);
const idem = `zlwp-livecheck-${run}`;
let r1 = await raw('/ingest/v1/logs', { body: body1, headers: { 'Idempotency-Key': idem } });
check(r1.status === 202 && /"accepted":1,"rejected":0/.test(r1.text), 'logs: 202 with accepted 1, rejected 0', String(r1.status));
r1 = await raw('/ingest/v1/logs', { body: body1, headers: { 'Idempotency-Key': idem } });
check(r1.status === 202 && /"accepted":1/.test(r1.text), 'logs: the same key and body again is answered as before (a replay)', String(r1.status));
r1 = await raw('/ingest/v1/logs', { body: JSON.stringify([rec('another body')]), headers: { 'Idempotency-Key': idem } });
check(r1.status === 422, 'logs: the same key with a different body is refused (422)', String(r1.status));
r1 = await raw('/ingest/v1/logs', { body: '{not json', headers: { 'Idempotency-Key': `${idem}-b` } });
check(r1.status === 400, 'logs: unparsable JSON is 400', String(r1.status));
r1 = await raw('/ingest/v1/logs', { body: body1, key: 'zk_wrong_key_000000000000000000', headers: { 'Idempotency-Key': `${idem}-c` } });
check(r1.status === 401, 'logs: a wrong key is 401', String(r1.status));
r1 = await raw('/ingest/v1/logs', { body: body1, key: '', headers: { 'Idempotency-Key': `${idem}-d` } });
check(r1.status === 401, 'logs: no key is 401', String(r1.status));
const ev = (extra) => JSON.stringify([{ name: 'live_check_probe', timestamp: now(), insertId: `live-${run}-${Math.random().toString(36).slice(2, 6)}`, properties: { probe: run }, ...extra }]);
r1 = await raw('/ingest/v1/events', { body: ev({ anonymousId: `live-anon-${run}` }), headers: { 'Idempotency-Key': `${idem}-e` } });
check(r1.status === 202 && /"accepted":1,"rejected":0/.test(r1.text), 'events: accepted with an anonymous id', String(r1.status));
r1 = await raw('/ingest/v1/events', { body: ev({}), headers: { 'Idempotency-Key': `${idem}-f` } });
check(r1.status === 202 && /"accepted":0,"rejected":1/.test(r1.text), 'events: without a user or anonymous id, processed but rejected (not retried)', String(r1.status));
const span = JSON.stringify({ resourceSpans: [{ resource: { attributes: [{ key: 'service.name', value: { stringValue: SOURCE } }] }, scopeSpans: [{ scope: { name: 'live' }, spans: [{ traceId: (Date.now().toString(16) + 'f'.repeat(32)).slice(0, 32), spanId: (run + '0'.repeat(16)).slice(0, 16), name: 'GET /live-check', kind: 2, startTimeUnixNano: String(Date.now() * 1e6), endTimeUnixNano: String((Date.now() + 5) * 1e6), attributes: [{ key: 'http.request.method', value: { stringValue: 'GET' } }], status: { code: 1 } }] }] }] });
r1 = await raw('/v1/traces', { body: span });
check(r1.status === 200, 'traces: OTLP/JSON spans are accepted (200)', String(r1.status));
r1 = await raw('/ingest/v1/replay/config', { method: 'GET', key: BROWSER });
check(r1.status === 200 && /"maskInputs":true/.test(r1.text), 'replay: the configuration endpoint answers, with input masking on', `${r1.status} ${r1.text.slice(0, 120)}`);

// ---- C: a bad key, then a corrected one -------------------------------------------------------------------------
console.log('\n== C. the plugin against a bad key ==');
wp(['option', 'update', 'ziplogger_api_key', 'zk_wrong_key_000000000000000000']);
await get('/?zl_fixture=warning');
await sleep(800);
flush();
st = status();
check(st.state === 'blocked' && st.blocked === 'auth' && st.pending >= 1, 'delivery pauses on 401 and keeps the data', `${st.state}/${st.blocked}, pending ${st.pending}, error "${st.last_error.message}"`);
wp(['option', 'update', 'ziplogger_api_key', SERVER]);
await sleep(500);
flush();
st = status();
check(st.pending >= 1 && st.held >= 1, 'after the key changes, data collected for the old destination is held, not sent', `state ${st.state}, held ${st.held}`);
const n = wpEval('echo ( new \\ZipLogger\\WordPress\\Queue_Store() )->retarget_held( \\ZipLogger\\WordPress\\Destination::current() );');
flush();
st = status();
check(Number(n) >= 1 && st.pending === 0 && st.state === 'healthy', 'retargeting releases it and it is delivered', `retargeted ${n}, state ${st.state}`);

// ---- D: a real browser ------------------------------------------------------------------------------------------
console.log('\n== D. real Chromium, another origin ==');
wp(['option', 'update', 'ziplogger_api_key', SERVER === BROWSER ? placeholder('server') : SERVER]);
wp(['option', 'update', 'ziplogger_browser_key', BROWSER]);
setSettings({
  enabled: true, endpoint: ENDPOINT, source: SOURCE, environment: 'live-test', min_severity: 'warn',
  browser: { enabled: true, errors: true, failed_requests: true, slow_requests: true, navigation_timing: true, web_vitals: true, perf_sample_rate: 100, error_sample_rate: 100 },
  analytics: { enabled: true, page_views: true, spa_navigation: true },
  replay: { enabled: true, sample_rate: 100 },
  tracing: { enabled: true, browser: true, sample_rate: 100, service_name: SOURCE },
});
const b = runBrowser('liveBrowser', { endpoint: ENDPOINT }, { timeout: 300000 });
const ok = (path, method = 'POST') => b.seen.filter((s) => s.path === path && s.method === method);
const allOk = (list) => list.length > 0 && list.every((s) => s.status && s.status < 300 && s.allowOrigin);
check(allOk(ok('/ingest/v1/logs')), 'errors and performance reach /ingest/v1/logs, with a CORS header', ok('/ingest/v1/logs').map((s) => s.status).join(','));
check(allOk(ok('/ingest/v1/events')), 'analytics reaches /ingest/v1/events, with a CORS header', ok('/ingest/v1/events').map((s) => s.status).join(','));
check(allOk(ok('/v1/traces')), 'browser spans reach /v1/traces, with a CORS header', ok('/v1/traces').map((s) => s.status).join(','));
check(allOk(ok('/ingest/v1/replay/config', 'GET')), 'the replay configuration is fetched cross-origin', ok('/ingest/v1/replay/config', 'GET').map((s) => s.status).join(','));
check(allOk(ok('/ingest/v1/replay')), 'a replay chunk is accepted', ok('/ingest/v1/replay').map((s) => s.status).join(','));
check(!b.seen.some((s) => s.failed), 'no request to the service failed at the network level', b.seen.filter((s) => s.failed).map((s) => `${s.path}: ${s.failed}`).join('; '));
check(b.status && b.status.replay && b.status.replay.recording === true, 'the recorder was running', JSON.stringify(b.status && b.status.replay));

// ---- E: the read interface (optional) ---------------------------------------------------------------------------
if (READ) {
  console.log('\n== E. read interface and dashboard ==');
  const h = await raw('/grafana/health', { method: 'GET', key: READ });
  check(h.status === 200, 'the read key is accepted by /grafana/health', String(h.status));
  wp(['option', 'update', 'ziplogger_api_key', SERVER === BROWSER ? placeholder('server') : SERVER]);
  wp(['option', 'update', 'ziplogger_read_key', READ]);
  setSettings({ enabled: true, endpoint: ENDPOINT, source: SOURCE, environment: 'live-test', browser: { enabled: true }, tracing: { enabled: true, service_name: SOURCE } });
  const d = runBrowser('adminDashboard', { testRead: true, tabs: ['overview', 'logs', 'browser', 'tracing'] });
  check(/The read key works/.test(d.readTest || ''), 'the dashboard\'s "Test the read key" succeeds', d.readTest);
  const panels = Object.values(d.tabs).flatMap((t) => t.panels);
  check(panels.length > 0 && panels.every((p) => !/could not|not accepted|error/i.test(p.text.slice(0, 80))), 'the live panels render without an error', panels.map((p) => `${p.id}: ${p.text.slice(0, 60).replace(/\s+/g, ' ')}`).join(' | '));
  check(d.xApiKeyRequests.length === 0, 'the browser never sent an API key while using the dashboard');
} else {
  console.log('\n== E. skipped: no ZL_READ_KEY, so the read interface and the dashboard panels were NOT verified live ==');
}

console.log(`\n${failures === 0 ? 'ALL CHECKS PASSED' : failures + ' CHECK(S) FAILED'}`);
process.exit(failures === 0 ? 0 : 1);
