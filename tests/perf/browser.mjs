// Browser-side overhead, measured in a real Chromium: what each module adds to a page load.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node tests/perf/browser.mjs [--rounds 15] [--warmup 2] [--out tests/perf/results-browser.json]
//
// Every sample is a cold load in a fresh browser context, followed by a few seconds of settling (during which
// consent is granted and, for replay, the recorder is downloaded and started). Numbers come from Chromium's own
// Performance.getMetrics (script execution and main-thread task time, JS heap) and the Resource Timing entries
// of the plugin's scripts.
//
// Every configuration is loaded in every round, in a shuffled order, so drift of the machine over the run lands
// on all configurations equally. A configuration applies to one browser session, through a cookie: the e2e
// fixture (a must-use plugin) swaps the plugin's settings for the requests of that session only, or removes the
// plugin for the baseline. The stored settings are never changed during the run.
//
// The site is a stock block theme with WooCommerce active in every configuration: use the numbers for
// relative cost. A page with more scripts than this one hides the plugin's share; a slower device shows more.
import fs from 'node:fs';
import path from 'node:path';
import { wp, setKeys, installZip, mock, sleep, runBrowser, ROOT } from '../e2e/lib.mjs';

const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i === -1 ? fallback : process.argv[i + 1]; };
const ROUNDS = Number(arg('rounds', 15));
const WARMUP = Number(arg('warmup', 2));
const OUT = arg('out', path.join(ROOT, 'tests', 'perf', 'results-browser.json'));
const ENDPOINT = 'http://mock:5081';
const base = { enabled: true, endpoint: ENDPOINT, source: 'perf-site', min_severity: 'warn', collectors: { php_errors: true } };

const BROWSER = { enabled: true, errors: true, failed_requests: true, slow_requests: true, navigation_timing: true, web_vitals: true, perf_sample_rate: 100 };
const CONFIGS = [
  { id: 'inactive', label: 'Plugin deactivated' },
  { id: 'idle', label: 'Plugin active, every browser module off', settings: {} },
  { id: 'browser', label: 'Browser monitoring (errors, requests, timing, Core Web Vitals at 100%)', settings: { browser: BROWSER } },
  { id: 'analytics', label: 'Analytics (consented, page views + SPA)', settings: { analytics: { enabled: true, page_views: true, spa_navigation: true } }, consent: ['analytics'] },
  { id: 'tracing', label: 'Browser tracing (100%)', settings: { browser: { enabled: true, errors: false, failed_requests: false }, tracing: { enabled: true, browser: true, sample_rate: 100 } } },
  { id: 'replay', label: 'Session replay (consented, sampled at 100%: recorder loaded and recording)', settings: { replay: { enabled: true, sample_rate: 100 } }, consent: ['replay'] },
  { id: 'all', label: 'Everything on and consented (replay at 100%)', settings: { browser: BROWSER, analytics: { enabled: true, page_views: true, spa_navigation: true, interactions: false }, replay: { enabled: true, sample_rate: 100 }, tracing: { enabled: true, browser: true, sample_rate: 100 }, woocommerce: { enabled: true } }, consent: ['analytics', 'replay'] },
];

const q = (values, p) => { const v = [...values].sort((a, b) => a - b); return v[Math.min(v.length - 1, Math.floor((p / 100) * v.length))]; };
const summary = (values) => ({ median: Number(q(values, 50).toFixed(2)), p90: Number(q(values, 90).toFixed(2)), min: Number(Math.min(...values).toFixed(2)), max: Number(Math.max(...values).toFixed(2)) });

installZip();
wp(['plugin', 'activate', 'woocommerce'], { allowFail: true });
wp(['plugin', 'activate', 'ziplogger-error-monitoring-session-replay'], { allowFail: true });
setKeys();
const map = {};
for (const c of CONFIGS) if (c.id !== 'inactive') map[c.id] = { ...base, ...(c.settings || {}) };
wp(['option', 'update', 'zl_perf_cfgs', JSON.stringify(map), '--format=json']);
wp(['option', 'update', 'ziplogger_settings', JSON.stringify(map.idle), '--format=json']);
wp(['cache', 'flush'], { allowFail: true });
wp(['cron', 'event', 'delete', 'ziplogger_deliver'], { allowFail: true });
await mock.clear();
await sleep(500);

const variants = CONFIGS.map((c) => ({ id: c.id, path: '/', cfg: c.id, consent: c.consent || [] }));
const { samples } = runBrowser('perfLoad', { variants, rounds: ROUNDS, warmup: WARMUP, settle: 3000 }, { timeout: 3600000 });

const results = { generated: new Date().toISOString(), rounds: ROUNDS, warmupRounds: WARMUP, page: '/ (configuration selected by the zl_cfg cookie)', order: 'interleaved, shuffled every round (seeded)', configs: [] };
for (const cfg of CONFIGS) {
  const mine = samples[cfg.id];
  const pick = (key) => mine.map((s) => s[key]);
  const entry = {
    id: cfg.id,
    label: cfg.label,
    domContentLoadedMs: summary(pick('domContentLoadedMs')),
    loadMs: summary(pick('loadMs')),
    scriptMs: summary(pick('scriptMs')),
    taskMs: summary(pick('taskMs')),
    jsHeapMB: summary(pick('jsHeapMB')),
    longTaskMs: summary(pick('longTaskMs')),
    pluginEncodedBytes: mine[0].pluginEncodedBytes,
    pluginDecodedBytes: mine[0].pluginDecodedBytes,
    pluginResources: mine[0].pluginResources,
    requestsToZipLogger: summary(pick('ownRequests')),
  };
  results.configs.push(entry);
  console.log(`${cfg.id.padEnd(10)} script ${String(entry.scriptMs.median).padStart(7)} ms  task ${String(entry.taskMs.median).padStart(7)} ms  DCL ${String(entry.domContentLoadedMs.median).padStart(7)}  load ${String(entry.loadMs.median).padStart(7)}  heap ${entry.jsHeapMB.median} MB  plugin ${entry.pluginEncodedBytes} B on the wire  to ZipLogger ${entry.requestsToZipLogger.median} req`);
}

wp(['option', 'delete', 'zl_perf_cfgs'], { allowFail: true });
fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, JSON.stringify(results, null, 2));
console.log('written', OUT);
