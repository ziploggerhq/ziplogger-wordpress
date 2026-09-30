import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

const env = setupDom({ url: 'https://shop.example.test/product/shirt?x=1' });
const { installPerformance } = await import('../src/performance.js');

function fakeNavigation(win, entry) {
  Object.defineProperty(win, 'performance', {
    value: { now: () => 1, getEntriesByType: (t) => (t === 'navigation' ? [entry] : []) },
    configurable: true,
  });
}

test('navigation timing becomes one log entry with numbers only', async () => {
  fakeNavigation(env.win, { responseStart: 120.4, domInteractive: 800.2, domContentLoadedEventEnd: 900.9, loadEventEnd: 1500.1, transferSize: 45000, redirectCount: 1, type: 'navigate' });
  Object.defineProperty(env.doc, 'readyState', { value: 'complete', configurable: true });
  const logs = [];
  const cfg = baseConfig({ browser: { navigationTiming: true, webVitals: false, perfSampleRate: 100 } });
  installPerformance({ win: env.win, cfg, sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({ path: '/product/shirt' }) });
  await env.settle(10);
  assert.equal(logs.length, 1);
  const f = logs[0].fields;
  assert.equal(f.eventType, 'navigation_timing');
  assert.equal(f.ttfbMs, 120);
  assert.equal(f.loadMs, 1500);
  assert.equal(f.transferBytes, 45000);
  assert.equal(f.path, '/product/shirt');
  assert.match(logs[0].message, /Page load 1500 ms/);
});

test('nothing is measured for pages outside the performance sample, or when both options are off', async () => {
  fakeNavigation(env.win, { loadEventEnd: 10 });
  const logs = [];
  installPerformance({ win: env.win, cfg: baseConfig({ browser: { navigationTiming: true, perfSampleRate: 0 } }), sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({}) });
  installPerformance({ win: env.win, cfg: baseConfig({ browser: { navigationTiming: false, webVitals: false, perfSampleRate: 100 } }), sampleKey: 'k', log: (e) => logs.push(e), pageFields: () => ({}) });
  await env.settle(10);
  assert.equal(logs.length, 0);
});

test('registering Core Web Vitals never throws, even where the browser lacks the APIs (jsdom has none)', () => {
  assert.doesNotThrow(() => installPerformance({ win: env.win, cfg: baseConfig({ browser: { webVitals: true, perfSampleRate: 100 } }), sampleKey: 'k', log: () => {}, pageFields: () => ({}) }));
});
