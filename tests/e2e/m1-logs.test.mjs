// End-to-end: the server-side logs integration against a real WordPress, using the built ZIP.
// Run: node --test tests/e2e/m1-logs.test.mjs   (after `docker compose -f docker-compose.e2e.yml up -d`)
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { ROOT, KEYS, dc, wp, wpEval, get, mock, sleep, waitFor, setSettings, status, seedLogs } from './lib.mjs';

const ENDPOINT = 'http://mock:5081';
const zip = path.join(ROOT, 'dist', 'ziplogger.zip');

const enable = (extra = {}) => setSettings({
  enabled: true, endpoint: ENDPOINT, source: 'e2e-site', min_severity: 'warn',
  collectors: { php_errors: true, plugin_theme: true, updates: true, failed_logins: true, http_failures: true, http_slow: false },
  ...extra,
});
const flush = () => wp(['ziplogger', 'flush'], { allowFail: true });
const queued = () => Number(wpEval("global $wpdb; echo (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'ziplogger_queue');"));
// Isolate scenarios from each other: empty queue, zero counters, no pause.
const resetState = () => wpEval("global $wpdb; $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'ziplogger_queue'); $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'ziplogger_meta');");
const messagesOf = (records) => records.map((r) => r.message);
const settingsExist = () => wp(['option', 'get', 'ziplogger_settings'], { allowFail: true }).status === 0;

test('the stack is up and WordPress is installed', async () => {
  assert.ok(fs.existsSync(zip), 'build the ZIP first: php bin/build-zip.php');
  await waitFor(async () => (await fetch('http://localhost:5081/__stats')).ok, { what: 'mock receiver' });
  await waitFor(async () => (await fetch('http://localhost:8088/')).status < 500, { what: 'wordpress container', timeout: 120000 });
  const installed = wp(['core', 'is-installed'], { allowFail: true });
  if (installed.status !== 0) {
    wp(['core', 'install', '--url=http://wordpress', '--title=ZipLogger E2E', '--admin_user=admin', '--admin_password=e2e-admin-pass-1', '--admin_email=admin@example.org', '--skip-email']);
  }
  await mock.clear();
});

test('the ZIP installs and activates without transmitting anything', async () => {
  wp(['plugin', 'deactivate', 'ziplogger'], { allowFail: true });
  wp(['plugin', 'delete', 'ziplogger'], { allowFail: true });
  // A fresh site: deleting a plugin keeps its data by design, so earlier runs' options are removed explicitly.
  for (const option of ['ziplogger_settings', 'ziplogger_api_key', 'ziplogger_browser_key', 'ziplogger_read_key', 'ziplogger_secret', 'ziplogger_db_version']) {
    wp(['option', 'delete', option], { allowFail: true });
  }
  const cp = dc('cp', zip, 'wordpress:/tmp/ziplogger.zip');
  assert.equal(cp.status, 0, cp.stderr);
  dc('exec', '-T', '-u', '0', 'wordpress', 'sh', '-c', 'cp /tmp/ziplogger.zip /var/www/html/ziplogger.zip && chown www-data:www-data /var/www/html/ziplogger.zip');
  // Whatever the receiver was sent before this test (an earlier suite) is not this test's business.
  await mock.clear();
  wp(['plugin', 'install', '/var/www/html/ziplogger.zip', '--activate']);
  assert.match(wp(['plugin', 'list', '--name=ziplogger', '--field=status']).stdout, /^active$/m);

  await get('/');
  await get('/wp-login.php');
  await sleep(500);
  assert.equal((await mock.received()).length, 0, 'activation alone must not contact ZipLogger');
  const s = JSON.parse(wp(['option', 'get', 'ziplogger_settings', '--format=json'], { allowFail: true }).stdout || 'null');
  assert.ok(s === null || s.enabled === false, 'collection stays off');
  assert.equal(status().state, 'off');
});

test('a test event reaches the receiver with the exact contract', async () => {
  wp(['option', 'add', 'ziplogger_api_key', KEYS.server, '--autoload=no'], { allowFail: true });
  wp(['option', 'update', 'ziplogger_api_key', KEYS.server]);
  enable({ enabled: false }); // The test works even before collection is switched on.
  const r = wp(['ziplogger', 'test']);
  assert.match(r.stdout, /accepted the test event/i);

  const [entry] = await mock.logs();
  assert.equal(entry.method, 'POST');
  assert.equal(entry.headers['x-api-key'], KEYS.server);
  assert.equal(entry.headers['content-type'], 'application/json');
  assert.match(entry.headers['idempotency-key'], /^zlwp-test-[0-9a-f]{32}$/);
  assert.match(entry.headers['user-agent'], /^ZipLogger-WordPress\//);
  assert.equal(entry.outcome, 202);
  assert.deepEqual(entry.lint, [], 'the record must satisfy the ingestion contract');
  const rec = entry.records[0];
  assert.equal(rec.source, 'e2e-site');
  assert.equal(rec.fields.eventType, 'connection_test');
  assert.equal(rec.fields.environment, 'staging');
  assert.equal(rec.severity, 'info');
  assert.equal(entry.records.length, 1);
});

test('a PHP warning is captured, the page is unaffected, and delivery is asynchronous', async () => {
  await mock.clear();
  resetState();
  enable();
  const page = await get('/?zl_fixture=warning');
  assert.equal(page.status, 200, 'the site keeps working');
  assert.equal((await mock.logs()).length, 0, 'the request itself must not call ZipLogger');
  assert.equal(queued(), 1);

  const out = flush();
  assert.equal(out.status, 0, out.stderr);
  const admitted = await mock.admitted();
  assert.equal(admitted.length, 1);
  const ev = admitted[0];
  assert.match(ev.message, /^PHP Warning: e2e fixture warning in /);
  assert.equal(ev.severity, 'warn');
  assert.equal(ev.fields.eventType, 'php_error');
  assert.equal(ev.fields.component, 'mu-plugin');
  assert.equal(ev.fields.componentSlug, 'zl-e2e-fixtures');
  assert.ok(!/\/var\/www\/html/.test(JSON.stringify(ev)), 'no absolute server path leaves the site');
  assert.match(ev.stackTrace, /zl-e2e-fixtures\.php:\d+/);
  assert.equal(queued(), 0);
  assert.deepEqual((await mock.logs())[0].lint, []);
});

test('an uncaught exception and a fatal error are each reported exactly once, and WordPress still shows its error page', async () => {
  for (const [fixture, pattern] of [['exception', /Uncaught RuntimeException: e2e fixture uncaught exception/], ['fatal', /Call to undefined function zl_e2e_undefined_function/]]) {
    await mock.clear();
    const page = await get(`/?zl_fixture=${fixture}`);
    assert.equal(page.status, 500, `${fixture}: WordPress's critical-error handling is preserved`);
    assert.match(page.text, /critical error|technical difficulties/i);
    flush();
    const admitted = await mock.admitted();
    const hits = admitted.filter((r) => pattern.test(r.message));
    assert.equal(hits.length, 1, `${fixture}: reported exactly once, got ${admitted.length} events: ${JSON.stringify(messagesOf(admitted))}`);
    assert.equal(hits[0].severity, 'fatal');
    assert.ok(!/\/var\/www\/html/.test(JSON.stringify(hits[0])));
  }
});

test('an uncaught exception under WP-CLI behaves like plain PHP and is captured once', async () => {
  await mock.clear();
  resetState();
  const r = wp(['eval', 'throw new RuntimeException("cli boom");'], { allowFail: true });
  assert.notEqual(r.status, 0, 'the command still fails, as it would without the plugin');
  assert.match(r.stderr + r.stdout, /cli boom/);
  assert.ok(!/Maximum call stack/.test(r.stderr + r.stdout), 'no handler recursion');
  flush();
  const hits = (await mock.admitted()).filter((e) => /cli boom/.test(e.message));
  assert.equal(hits.length, 1);
  assert.equal(hits[0].fields.eventType, 'php_exception');
  assert.equal(hits[0].fields.exceptionClass, 'RuntimeException');
});

test('secrets in a message never leave the site', async () => {
  await mock.clear();
  await get('/?zl_fixture=secret');
  flush();
  const wire = JSON.stringify(await mock.logs());
  for (const secret of ['jane.doe@example.com', 'hunter2', 'abc123secret', '203.0.113.9', 'user:pw@', 'SECRETKEY']) {
    assert.ok(!wire.includes(secret), `${secret} reached the receiver`);
  }
  assert.ok((await mock.admitted()).length === 1);
});

test('a warning in a loop becomes one event with a count', async () => {
  await mock.clear();
  await get('/?zl_fixture=loop');
  // Delivery may already have been started by WP-Cron (a page view spawns it), so the queue is not asserted on:
  // what matters is that exactly one event, carrying the count, reaches the receiver.
  flush();
  await waitFor(async () => (await mock.admitted()).some((r) => /repeated warning/.test(r.message)), { what: 'the repeated warning at the receiver' });
  const events = (await mock.admitted()).filter((r) => /repeated warning/.test(r.message));
  assert.equal(events.length, 1, 'one event, however many times the warning was raised');
  assert.equal(events[0].fields.occurrencesInRequest, 50);
});

test('the developer API and outbound-HTTP collector work end to end', async () => {
  await mock.clear();
  await get('/?zl_fixture=log');
  await get('/?zl_fixture=http');
  flush();
  const admitted = await mock.admitted();
  const dev = admitted.find((r) => r.message === 'Background import failed');
  assert.ok(dev, 'developer event delivered');
  assert.equal(dev.fields.processed_count, 42);
  assert.equal(dev.fields.password, '[redacted]');
  const http = admitted.find((r) => r.fields.eventType === 'http_failure');
  assert.ok(http, 'outbound failure delivered');
  assert.equal(http.fields.host, 'unreachable.invalid');
  assert.ok(!JSON.stringify(http).includes('SECRET-PATH') && !JSON.stringify(http).includes('token=abc'));
  // Delivering to the receiver must not have produced HTTP-failure events about itself.
  assert.ok(!admitted.some((r) => r.fields.host === 'mock'));
});

test('an outage loses nothing: events wait, retries reuse the same key and bytes, and everything arrives after recovery', async () => {
  await mock.clear();
  resetState();
  await mock.control({ mode: 'down' });
  seedLogs(30, 'outage event');
  assert.equal(queued(), 30);

  for (let i = 0; i < 3; i++) flush(); // Each forced attempt hits the outage.
  assert.equal(queued(), 30, 'nothing is deleted while ZipLogger is down');
  const attempts = await mock.logs();
  assert.ok(attempts.length >= 3);
  const byKey = new Map();
  for (const a of attempts) {
    const k = a.headers['idempotency-key'];
    if (byKey.has(k)) assert.equal(a.body, byKey.get(k), 'a retried batch must be byte-identical');
    byKey.set(k, a.body);
  }
  assert.ok(byKey.size < attempts.length, 'the same batch was retried under the same key');
  assert.equal(status().dropped_total, 0);

  await mock.control({ mode: 'ok' });
  const out = flush();
  assert.equal(out.status, 0, out.stderr);
  assert.equal(queued(), 0, 'the backlog is delivered');
  const delivered = messagesOf(await mock.admitted());
  for (let i = 1; i <= 30; i++) assert.ok(delivered.includes('outage event ' + i), `outage event ${i} arrived`);
  assert.equal(new Set(delivered).size, delivered.length, 'no event was delivered twice');
});

test('partial acceptance: the same batch and key are retried and the receiver ingests only the remainder', async () => {
  await mock.clear();
  resetState();
  seedLogs(6, 'partial event');
  await mock.control({ mode: 'partial', acceptedPrefix: 4 });
  flush();
  let logs = await mock.logs();
  assert.equal(logs.at(-1).outcome, 429);
  assert.equal(queued(), 6, 'not deleted until the whole batch is accepted');

  await mock.control({ mode: 'ok' });
  flush();
  logs = await mock.logs();
  const [first, second] = logs;
  assert.equal(first.headers['idempotency-key'], second.headers['idempotency-key']);
  assert.equal(first.body, second.body);
  assert.equal(queued(), 0);
  const delivered = messagesOf(await mock.admitted());
  assert.deepEqual([...delivered].sort(), [1, 2, 3, 4, 5, 6].map((i) => `partial event ${i}`).sort(), 'every event stored exactly once');
});

test('four workers running at once deliver every event exactly once', async () => {
  await mock.clear();
  resetState();
  seedLogs(400, 'concurrent event');
  assert.equal(queued(), 400);
  const procs = await Promise.all([1, 2, 3, 4].map(() => new Promise((resolve) => {
    const p = spawn('docker', ['compose', '-f', 'docker-compose.e2e.yml', '--profile', 'tools', 'run', '--rm', '-T', 'wpcli', 'wp', 'ziplogger', 'flush'], { cwd: ROOT, env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
    let out = '';
    p.stdout.on('data', (d) => (out += d));
    p.stderr.on('data', (d) => (out += d));
    p.on('close', (code) => resolve({ code, out }));
  })));
  assert.ok(procs.every((p) => p.code === 0), JSON.stringify(procs));
  assert.equal(queued(), 0);
  const delivered = messagesOf(await mock.admitted());
  assert.equal(delivered.length, 400);
  assert.equal(new Set(delivered).size, 400, 'no duplicates and no losses');
  const keys = (await mock.logs()).map((e) => e.headers['idempotency-key']);
  assert.equal(new Set(keys).size, keys.length, 'each batch was claimed by exactly one worker');
});

test('a poison event does not block the queue', async () => {
  await mock.clear();
  resetState();
  wpEval("ziplogger_log('error', 'ok before'); ziplogger_log('error', 'POISON'); ziplogger_log('error', 'ok after'); \\ZipLogger\\WordPress\\Plugin::instance()->recorder()->flush();");
  await mock.control({ mode: 'poison' });
  for (let i = 0; i < 4 && queued() > 0; i++) flush();
  assert.equal(queued(), 0);
  const delivered = messagesOf(await mock.admitted());
  assert.deepEqual([...delivered].sort(), ['ok after', 'ok before']);
  assert.ok(status().dropped_total >= 1);
  await mock.control({ mode: 'ok' });
});

test('a rejected key pauses delivery, keeps the data and resumes when fixed', async () => {
  await mock.clear();
  resetState();
  wpEval("ziplogger_log('error', 'kept during auth failure'); \\ZipLogger\\WordPress\\Plugin::instance()->recorder()->flush();");
  await mock.control({ mode: 'auth' });
  flush();
  assert.equal(queued(), 1);
  assert.equal(status().state, 'blocked');
  await mock.control({ mode: 'ok' });
  wp(['option', 'update', 'ziplogger_api_key', KEYS.server]);
  const r = wp(['ziplogger', 'test']); // A successful test lifts the pause.
  assert.match(r.stdout, /accepted/i);
  flush();
  assert.equal(queued(), 0);
});

test('WP-Cron delivers in the background after a page view (traffic-driven), and the CLI status agrees', async () => {
  await mock.clear();
  resetState();
  await get('/?zl_fixture=warning');
  assert.equal((await mock.logs()).length, 0);
  assert.equal(status().pending, 1);
  // Run the scheduled delivery event exactly as WP-Cron would.
  wp(['cron', 'event', 'run', 'ziplogger_deliver'], { allowFail: true });
  await waitFor(async () => (await mock.admitted()).length >= 1, { timeout: 30000, what: 'cron delivery' });
  assert.equal(queued(), 0);
});

test('the plugin can be deactivated and uninstalled; data is kept by default and removed on request', async () => {
  wp(['plugin', 'deactivate', 'ziplogger']);
  const cronHooks = wp(['cron', 'event', 'list', '--fields=hook', '--format=csv']).stdout;
  assert.ok(!/ziplogger_/.test(cronHooks), 'scheduled events are removed on deactivation');
  assert.ok(settingsExist(), 'settings survive deactivation');

  wp(['plugin', 'uninstall', 'ziplogger']); // WP-CLI's "plugin delete" skips uninstall.php; "uninstall" runs it.
  assert.ok(settingsExist(), 'default policy keeps data on uninstall');

  wp(['plugin', 'install', '/var/www/html/ziplogger.zip', '--activate']);
  setSettings({ enabled: false, delete_on_uninstall: true });
  wp(['plugin', 'uninstall', 'ziplogger', '--deactivate']);
  assert.ok(!settingsExist(), 'opt-in policy removes the settings');
  // The plugin's own two tables (not, for example, the copies that WordPress's Plugin Check makes under a "wp_pc_" prefix).
  const tables = wpEval("global $wpdb; echo implode( ',', $wpdb->get_col( \"SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('{$wpdb->prefix}ziplogger_queue', '{$wpdb->prefix}ziplogger_meta')\" ) );");
  assert.equal(tables, '', 'and the tables (left behind: ' + tables + ')');
});
