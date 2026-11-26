// Builds the WordPress.org screenshots from the REAL plugin (dist/ziplogger.zip) running in the local e2e stack.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node wporg-assets/screenshots/make-screenshots.mjs
//
// What is real: the plugin, its screens, and the data it captured from a demo shop (real PHP errors, real
// JavaScript errors and Web Vitals from Chromium, real server spans). What is staged: the shop itself
// (northwind-store/), and earlier hours of the 24-hour charts, filled in by re-sending copies of the captured
// records with earlier timestamps, so the charts look like a site that has been running for a day.
// The read side is the local stand-in (tests/e2e/mock-read-model.mjs), as in the dashboard e2e suite.
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { ROOT, KEYS, MOCK, wp, wpEval, dc, run, mock, setSettings, setKeys, installZip, sleep } from '../../tests/e2e/lib.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(HERE, 'out');
const HOST = 'northwindcoffee.example';
const SITE = `http://${HOST}`;
const SOURCE = 'northwind-coffee';
const step = (s) => console.log(`\n== ${s}`);

function inspectIp(container) {
  const r = run('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', container]);
  return r.stdout.trim();
}

function browserRun(mode, args = {}) {
  const ip = inspectIp('ziplogger-e2e-wordpress-1');
  const r = run('docker', ['run', '--rm', '--network', 'ziplogger-e2e_default', '--add-host', `${HOST}:${ip}`, '-e', `SITE=${SITE}`,
    '-v', 'ziplogger-e2e_pwmodules:/e2e/browser/node_modules', '-v', `${HERE}:/shots`, '-v', `${OUT}:/out`,
    'mcr.microsoft.com/playwright:v1.63.0-noble', 'node', '/shots/browser.mjs', mode, JSON.stringify(args)],
  { env: { ...process.env, MSYS_NO_PATHCONV: '1' }, timeout: 900000 });
  const line = r.stdout.split('\n').reverse().find((l) => l.startsWith('@@RESULT@@'));
  if (!line) throw new Error(`browser ${mode}: no result (${r.status}) ${r.stderr.slice(0, 1500)}`);
  const res = JSON.parse(line.slice(10));
  if (res.error) throw new Error(`browser ${mode}: ${res.error}`);
  return res;
}

const settings = (endpoint) => ({
  enabled: true, endpoint, source: SOURCE, environment: 'production', min_severity: 'warn',
  collectors: { php_errors: true, failed_logins: true, plugin_theme: true, updates: true, http_failures: true, http_slow: false },
  browser: { enabled: true, errors: true, failed_requests: true, navigation_timing: true, web_vitals: true, perf_sample_rate: 100, error_sample_rate: 100 },
  analytics: { enabled: true, sample_rate: 100 },
  replay: { enabled: true, sample_rate: 10 },
  tracing: { enabled: true, server_spans: true, outbound_spans: true, sample_rate: 100, service_name: SOURCE },
});

fs.mkdirSync(OUT, { recursive: true });

step('WordPress: a fresh install of the plugin ZIP, on a demo coffee shop');
if (wp(['core', 'is-installed'], { allowFail: true }).status !== 0) {
  wp(['core', 'install', '--url=http://wordpress', '--title=Northwind Coffee', '--admin_user=admin', '--admin_password=e2e-admin-pass-1', '--admin_email=admin@example.org', '--skip-email']);
}
installZip();
wp(['option', 'update', 'blogname', 'Northwind Coffee']);
wp(['option', 'update', 'blogdescription', 'Small-batch coffee, roasted weekly']);
wp(['user', 'update', 'admin', '--user_pass=e2e-admin-pass-1', '--display_name=Maya', '--first_name=Maya']);
wp(['rewrite', 'structure', '/%postname%/', '--hard'], { allowFail: true });
const pages = { shop: 'Shop', checkout: 'Checkout', contact: 'Contact', 'brew-guides': 'Brew guides', about: 'About us', subscriptions: 'Subscriptions', wholesale: 'Wholesale', 'gift-cards': 'Gift cards', 'order-status': 'Order status', newsletter: 'Newsletter' };
wp(['option', 'update', 'timezone_string', 'America/New_York']);
wpEval(Object.entries(pages).map(([slug, title]) => `if ( ! get_page_by_path( '${slug}' ) ) { wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => '${slug}', 'post_title' => '${title}', 'post_content' => '<p>${title} at Northwind Coffee.</p>' ) ); }`).join(' '));
// The plugin's own e2e fixtures are must-use plugins in this stack; they stay idle without their query arguments.
dc('exec', '-T', '-u', '0', 'wordpress', 'sh', '-c', 'rm -rf /var/www/html/wp-content/plugins/northwind-store && mkdir -p /var/www/html/wp-content/plugins/northwind-store');
dc('cp', path.join(HERE, 'northwind-store', 'northwind-store.php'), 'wordpress:/var/www/html/wp-content/plugins/northwind-store/northwind-store.php');
dc('exec', '-T', '-u', '0', 'wordpress', 'chown', '-R', 'www-data:www-data', '/var/www/html/wp-content/plugins/northwind-store');
wp(['plugin', 'deactivate', 'demo-shop'], { allowFail: true });
wp(['plugin', 'delete', 'demo-shop'], { allowFail: true });
wp(['plugin', 'activate', 'northwind-store']);
wp(['plugin', 'delete', 'hello', 'akismet'], { allowFail: true });

step('ZipLogger: keys and modules, as a site owner would set them');
await mock.clear();
setKeys();
setSettings(settings('http://mock:5081'));

step('Visitors: real browsing in Chromium');
const traffic = browserRun('traffic', { rounds: 3 });
console.log(`visits: ${traffic.visits}`);
for (let i = 0; i < 2; i++) { wp(['ziplogger', 'flush'], { allowFail: true }); await sleep(1500); }

step('History: earlier hours of the 24-hour window');
const logEntries = await mock.received('/ingest/v1/logs');
const records = logEntries.filter((e) => e.admitted && !e.replayed).flatMap((e) => e.admitted);
const byKind = (pred) => records.filter(pred);
const BROWSER_KINDS = ['js_error', 'request_failed', 'web_vital', 'navigation_timing'];
const serverErrors = byKind((r) => /error|fatal/.test(r.severity) && !BROWSER_KINDS.includes((r.fields || {}).eventType));
const jsErrors = byKind((r) => (r.fields || {}).eventType === 'js_error');
const failed = byKind((r) => (r.fields || {}).eventType === 'request_failed');
const vitals = byKind((r) => (r.fields || {}).eventType === 'web_vital');
console.log(`captured: ${records.length} records (server errors ${serverErrors.length}, js ${jsErrors.length}, failed requests ${failed.length}, vitals ${vitals.length})`);

let seed = 7;
const rand = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
const pick = (list) => list[Math.floor(rand() * list.length)];
const now = Date.now();
const backfill = [];
for (let h = 1; h <= 23; h++) {
  // A quieter night, a busier day, and one bad hour around a deploy six hours ago.
  const hourOfDay = new Date(now - h * 3600e3).getUTCHours();
  const traffic = hourOfDay >= 6 && hourOfDay <= 21 ? 1 : 0.4;
  const incident = h === 6 || h === 7 ? 4 : 1;
  const counts = [[serverErrors, Math.round((1 + rand() * 2) * traffic * incident)], [jsErrors, Math.round((rand() * 3) * traffic)],
    [failed, Math.round((rand() * 3) * traffic * incident)], [vitals, Math.round((4 + rand() * 6) * traffic)]];
  for (const [list, n] of counts) {
    for (let i = 0; i < n && list.length; i++) {
      backfill.push({ ...pick(list), timestamp: new Date(now - h * 3600e3 + rand() * 3600e3).toISOString() });
    }
  }
}
for (let i = 0; i < backfill.length; i += 200) {
  const r = await fetch(`${MOCK}/ingest/v1/logs`, { method: 'POST', headers: { 'content-type': 'application/json', 'x-api-key': KEYS.server }, body: JSON.stringify(backfill.slice(i, i + 200)) });
  if (r.status !== 202) throw new Error(`backfill logs: ${r.status}`);
}
// One visitor request = one trace. Split the delivered batches into single traces from the shop (not wp-admin).
const traces = [];
for (const e of (await mock.received('/v1/traces')).filter((x) => x.outcome === 200)) {
  const doc = JSON.parse(e.body);
  for (const rs of doc.resourceSpans || []) {
    const byTrace = new Map();
    for (const ss of rs.scopeSpans || []) for (const sp of ss.spans || []) {
      if (!byTrace.has(sp.traceId)) byTrace.set(sp.traceId, []);
      byTrace.get(sp.traceId).push({ scope: ss.scope, sp });
    }
    for (const list of byTrace.values()) {
      const root = list.find((x) => x.sp.kind === 2);
      if (root && !/wp-admin|wp-login|wp-cron/.test(root.sp.name)) traces.push({ resource: rs.resource, list });
    }
  }
}
let spansSent = 0;
let tracesSent = 0;
for (let h = 1; h <= 23; h++) {
  const hourOfDay = new Date(now - h * 3600e3).getUTCHours();
  const n = Math.round((hourOfDay >= 6 && hourOfDay <= 21 ? 14 : 5) + rand() * 6) * (h === 6 || h === 7 ? 2 : 1);
  const batch = [];
  for (let i = 0; i < n && traces.length; i++) {
    const t = pick(traces);
    const shift = BigInt(Math.round((now - h * 3600e3 + rand() * 3600e3) * 1e6));
    const base = t.list.reduce((m, x) => (BigInt(x.sp.startTimeUnixNano) < m ? BigInt(x.sp.startTimeUnixNano) : m), BigInt(t.list[0].sp.startTimeUnixNano));
    const traceId = [...Array(16)].map(() => Math.floor(rand() * 256).toString(16).padStart(2, '0')).join('');
    const spans = t.list.map(({ sp }) => ({ ...sp, traceId, startTimeUnixNano: String(BigInt(sp.startTimeUnixNano) - base + shift), endTimeUnixNano: String(BigInt(sp.endTimeUnixNano) - base + shift) }));
    batch.push({ resource: t.resource, scopeSpans: [{ scope: t.list[0].scope, spans }] });
    spansSent += spans.length;
    tracesSent++;
  }
  if (!batch.length) continue;
  const r = await fetch(`${MOCK}/v1/traces`, { method: 'POST', headers: { 'content-type': 'application/json', 'x-api-key': KEYS.server }, body: JSON.stringify({ resourceSpans: batch }) });
  if (r.status !== 200) throw new Error(`backfill traces: ${r.status}`);
}
console.log(`shop traces captured: ${traces.length}, backfilled: ${tracesSent}`);

step('Latest activity: one visit to each page with a server-side problem');
browserRun('traffic', { rounds: 1, pages: ['/shop/', '/wholesale/', '/subscriptions/', '/checkout/', '/gift-cards/', '/newsletter/', '/order-status/', '/brew-guides/'] });
for (let i = 0; i < 2; i++) { wp(['ziplogger', 'flush'], { allowFail: true }); await sleep(1500); }
console.log(`backfilled: ${backfill.length} log records, ${spansSent} spans`);

step('Read key check (as the owner would click it), then screenshots');
console.log(browserRun('readtest').notice);
// The local stand-in names every workspace "e2e-workspace"; a real workspace has the owner's name.
wpEval("$m = new \\ZipLogger\\WordPress\\Meta_Store(); $v = $m->get_json('read_test'); if ( is_array( $v ) ) { $m->set_json('read_test', json_decode( str_replace( 'e2e-workspace', 'northwind-coffee', wp_json_encode( $v ) ), true ) ); } echo wp_json_encode( $m->get_json('read_test') );");
wp(['transient', 'delete', '--all'], { allowFail: true });   // the dashboard caches panel answers briefly

const shots = browserRun('shots', {
  out: '/out', scale: 1.5, height: 1000, tabs: [
    { tab: 'overview', file: 'screenshot-1.png', warm: true },
    { tab: 'browser', file: 'screenshot-2.png', scrollTo: '[data-ziplogger-panel]' },
    { tab: 'tracing', file: 'screenshot-3.png', scrollTo: '[data-ziplogger-panel]' },
    { tab: 'logs', file: 'screenshot-4.png' },
    { tab: 'replay', file: 'screenshot-5.png' },
    { tab: 'privacy', file: 'screenshot-6.png' },
    { tab: 'overview', file: 'full-overview.png', full: true, warm: true },
    { tab: 'browser', file: 'full-browser.png', full: true },
    { tab: 'tracing', file: 'full-tracing.png', full: true },
    { tab: 'logs', file: 'full-logs.png', full: true },
    { tab: 'replay', file: 'full-replay.png', full: true },
    { tab: 'privacy', file: 'full-privacy.png', full: true },
    { tab: 'analytics', file: 'full-analytics.png', full: true },
    { tab: 'woocommerce', file: 'full-woocommerce.png', full: true },
    { tab: 'diagnostics', file: 'full-diagnostics.png', full: true },
  ],
});
console.log(JSON.stringify(shots.shots));

step('Connection tab, with the default ZipLogger endpoint (as a real site has it)');
// No tracing for this shot: the admin's own page loads would otherwise queue spans for an endpoint the demo keys
// cannot reach. Nothing else on this screen depends on it.
setSettings({ ...settings(''), tracing: { ...settings('').tracing, enabled: false } });
wp(['ziplogger', 'clear', '--yes'], { allowFail: true });
const conn = browserRun('shots', { out: '/out', scale: 1.5, height: 1000, tabs: [{ tab: 'connection', file: 'screenshot-7.png' }, { tab: 'connection', file: 'full-connection.png', full: true }] });
console.log(JSON.stringify(conn.shots));
setSettings(settings('http://mock:5081'));
console.log('\ndone: ' + OUT);
