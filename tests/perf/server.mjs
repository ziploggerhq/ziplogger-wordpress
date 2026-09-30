// Server-side overhead, measured: what each module adds to a WordPress page request.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node tests/perf/server.mjs [--rounds 250] [--warmup 12] [--out tests/perf/results-server.json]
//
// How it is measured, and why:
//
//  * Every configuration is requested in every round, in a shuffled order, so slow drift of the machine (Docker
//    on a developer machine drifts by tens of percent over minutes) lands on all configurations equally instead of being
//    mistaken for the cost of whichever module happened to run at that moment.
//  * A configuration applies to ONE request: the e2e fixture (a must-use plugin) swaps the plugin's settings for
//    that request only (?zl_cfg=<id>), or removes the plugin from the active list for the baseline. Same site,
//    same database, same opcache, same everything else.
//  * The time, the database query count and the memory are measured by PHP itself, at the very end of the
//    request, after the plugin's shutdown work (queue insert, span write): the milliseconds since PHP received
//    the request. They exclude the network, Docker and this client. The wall-clock time seen by the client is
//    recorded too.
//  * No cron job is spawned during a measured request, so background delivery never runs inside a measurement.
//
// These are numbers from containers on a developer machine, with WooCommerce active in every configuration:
// use them for relative cost, not as absolute figures for your hosting.
import fs from 'node:fs';
import path from 'node:path';
import { wp, dc, setKeys, installZip, mock, sleep, ROOT } from '../e2e/lib.mjs';

const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i === -1 ? fallback : process.argv[i + 1]; };
const ROUNDS = Number(arg('rounds', 250));
const WARMUP = Number(arg('warmup', 12));
const OUT = arg('out', path.join(ROOT, 'tests', 'perf', 'results-server.json'));
const ENDPOINT = 'http://mock:5081';

const base = { enabled: true, endpoint: ENDPOINT, source: 'perf-site', min_severity: 'warn', collectors: { php_errors: true, plugin_theme: true, updates: true } };
const BROWSER = { enabled: true, navigation_timing: true, web_vitals: true, perf_sample_rate: 100 };
// Each "+" row is the server-logs baseline plus that one module, so its own cost is the difference to "logs".
const CONFIGS = [
  { id: 'inactive', label: 'Plugin deactivated (WordPress + WooCommerce only)' },
  { id: 'idle', label: 'Plugin active, collection off', settings: { enabled: false } },
  { id: 'logs', label: 'Server logs on (PHP error collector, plugin/theme and update collectors)', settings: {} },
  { id: 'browser', label: 'Logs + browser monitoring', settings: { browser: BROWSER } },
  { id: 'analytics', label: 'Logs + analytics', settings: { analytics: { enabled: true } } },
  { id: 'replay', label: 'Logs + session replay', settings: { replay: { enabled: true, sample_rate: 100 } } },
  { id: 'tracing10', label: 'Logs + tracing, server spans sampled at 10%', settings: { tracing: { enabled: true, sample_rate: 10 } } },
  { id: 'tracing100', label: 'Logs + tracing, server spans sampled at 100%', settings: { tracing: { enabled: true, sample_rate: 100 } } },
  { id: 'woo', label: 'Logs + WooCommerce events', settings: { woocommerce: { enabled: true } } },
  { id: 'all', label: 'All modules on (tracing at 10%)', settings: { browser: BROWSER, analytics: { enabled: true }, replay: { enabled: true }, tracing: { enabled: true, sample_rate: 10 }, woocommerce: { enabled: true } } },
  { id: 'logs-warning', label: 'Logs on, and this request itself logs one warning', settings: {}, extra: '&zl_fixture=warning' },
];

const stats = (values) => {
  const v = [...values].sort((a, b) => a - b);
  const q = (p) => v[Math.min(v.length - 1, Math.floor((p / 100) * v.length))];
  const mean = v.reduce((a, b) => a + b, 0) / v.length;
  return { n: v.length, median: q(50), p95: q(95), mean: Number(mean.toFixed(2)), min: v[0], max: v[v.length - 1] };
};

// A small seeded generator, so a run can be repeated with the same request order.
function mulberry32(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const rand = mulberry32(20260930);
const shuffled = (list) => {
  const a = [...list];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
};

installZip();
wp(['plugin', 'activate', 'woocommerce'], { allowFail: true });
wp(['option', 'update', 'zl_e2e_gateway_outcome', 'success'], { allowFail: true });
wp(['plugin', 'activate', 'ziplogger'], { allowFail: true });
setKeys();
const map = {};
for (const c of CONFIGS) if (c.id !== 'inactive') map[c.id] = { ...base, ...(c.settings || {}) };
wp(['option', 'update', 'zl_perf_cfgs', JSON.stringify(map), '--format=json']);
wp(['option', 'update', 'ziplogger_settings', JSON.stringify(map.logs), '--format=json']);
wp(['cache', 'flush'], { allowFail: true });
wp(['cron', 'event', 'delete', 'ziplogger_deliver'], { allowFail: true });
await mock.clear();
await sleep(500);
dc('exec', '-T', '-u', '0', 'wordpress', 'sh', '-c', ': > /var/www/html/wp-content/zl-perf.log && chmod 666 /var/www/html/wp-content/zl-perf.log');

const url = (cfg) => `http://localhost:8088/?zl_perf=1&zl_cfg=${cfg.id}${cfg.extra || ''}`;
const wall = Object.fromEntries(CONFIGS.map((c) => [c.id, []]));
const total = WARMUP + ROUNDS;
for (let round = 0; round < total; round++) {
  for (const cfg of shuffled(CONFIGS)) {
    const t0 = performance.now();
    const res = await fetch(url(cfg), { redirect: 'manual' });
    await res.text();
    const t1 = performance.now();
    if (round >= WARMUP) wall[cfg.id].push(t1 - t0);
  }
  if ((round + 1) % 25 === 0) console.log(`round ${round + 1}/${total}`);
}
await sleep(1000); // The last requests finish their shutdown work.

const lines = dc('exec', '-T', 'wordpress', 'cat', '/var/www/html/wp-content/zl-perf.log').stdout.split('\n').filter(Boolean).map((l) => JSON.parse(l));
const expected = total * CONFIGS.length;
if (lines.length < expected) throw new Error(`expected ${expected} probe lines, got ${lines.length}`);

// The first WARMUP rounds are dropped per configuration (a round holds one request of each configuration).
const results = { generated: new Date().toISOString(), rounds: ROUNDS, warmupRounds: WARMUP, page: '/?zl_perf=1&zl_cfg=<id>', order: 'interleaved, shuffled every round (seeded)', configs: [] };
for (const cfg of CONFIGS) {
  const mine = lines.filter((l) => l.cfg === cfg.id).slice(WARMUP);
  const r = {
    phpMs: stats(mine.map((l) => l.ms)),
    wallMs: stats(wall[cfg.id]),
    queries: stats(mine.map((l) => l.queries)),
    peakUsedBytes: stats(mine.map((l) => l.used)),
    peakAllocatedBytes: stats(mine.map((l) => l.peak)),
  };
  results.configs.push({ id: cfg.id, label: cfg.label, ...r });
  console.log(`${cfg.id.padEnd(14)} php median ${String(r.phpMs.median).padStart(7)} ms  p95 ${String(r.phpMs.p95).padStart(7)} ms  queries ${r.queries.median}  peak used ${(r.peakUsedBytes.median / 1048576).toFixed(2)} MiB`);
}

// Leave the site as it was found.
wp(['option', 'delete', 'zl_perf_cfgs'], { allowFail: true });
fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, JSON.stringify(results, null, 2));
console.log('written', OUT);
