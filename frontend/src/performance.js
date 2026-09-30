// Page performance: navigation timing and Core Web Vitals.
//
// Core Web Vitals come from Google's maintained "web-vitals" library (bundled, pinned) rather than
// hand-rolled observers, because the measurement rules (what counts as the largest paint, how layout
// shifts are windowed, how interactions are grouped) are subtle and change between Chrome releases.
//
// Both are sampled per page view (browser.perf_sample_rate) and sent as ordinary log entries with a
// small set of numeric fields: no URLs beyond the path, no element selectors, no attribution data.

import { onLCP, onINP, onCLS, onFCP, onTTFB } from 'web-vitals';
import { guard, sampledIn } from './util.js';

function round(n, digits) {
  const f = Math.pow(10, digits || 0);
  return Math.round(n * f) / f;
}

/**
 * @param {object} ctx { win, cfg, sampleKey, log(entry), pageFields() }
 */
export function installPerformance(ctx) {
  const b = ctx.cfg.browser || {};
  if (!b.navigationTiming && !b.webVitals) return function () {};
  if (!sampledIn(ctx.sampleKey + ':perf', b.perfSampleRate)) return function () {};
  const win = ctx.win;
  const stops = [];

  if (b.webVitals) {
    const report = guard(function (metric) {
      const value = metric.name === 'CLS' ? round(metric.value, 4) : round(metric.value, 0);
      ctx.log({
        severity: metric.rating === 'poor' ? 'warn' : 'info',
        message: 'Web vital ' + metric.name + ': ' + value + (metric.name === 'CLS' ? '' : ' ms') + ' (' + metric.rating + ')',
        fields: Object.assign({
          eventType: 'web_vital',
          metric: metric.name,
          value: value,
          rating: metric.rating,
          navigationType: metric.navigationType || 'unknown',
        }, ctx.pageFields()),
      });
    });
    // web-vitals may throw on unsupported browsers; each registration is isolated.
    [onLCP, onINP, onCLS, onFCP, onTTFB].forEach(function (fn) {
      try { fn(report); } catch (e) { /* metric unsupported here */ }
    });
  }

  if (b.navigationTiming) {
    const measure = guard(function () {
      const perf = win.performance;
      const entries = perf && perf.getEntriesByType ? perf.getEntriesByType('navigation') : [];
      const nav = entries && entries[0];
      if (!nav || !(nav.loadEventEnd > 0)) return;
      ctx.log({
        severity: 'info',
        message: 'Page load ' + round(nav.loadEventEnd, 0) + ' ms',
        fields: Object.assign({
          eventType: 'navigation_timing',
          ttfbMs: round(nav.responseStart, 0),
          domInteractiveMs: round(nav.domInteractive, 0),
          domContentLoadedMs: round(nav.domContentLoadedEventEnd, 0),
          loadMs: round(nav.loadEventEnd, 0),
          transferBytes: nav.transferSize || 0,
          redirects: nav.redirectCount || 0,
          navigationType: nav.type || 'navigate',
        }, ctx.pageFields()),
      });
    });
    const schedule = function () { win.setTimeout(measure, 0); }; // loadEventEnd is only set after the load handlers return
    if (win.document.readyState === 'complete') {
      schedule();
    } else {
      win.addEventListener('load', schedule, { once: true });
      stops.push(function () { win.removeEventListener('load', schedule); });
    }
  }

  return function stop() {
    stops.forEach(function (fn) { fn(); });
  };
}
