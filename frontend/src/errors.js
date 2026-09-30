// Uncaught errors and unhandled promise rejections.
//
// The SDK's own captureGlobalErrors() would attach the full page URL and raw messages, so the plugin
// listens itself and hands the SDK a sanitized entry. Capturing never changes what the page sees: the
// listeners only observe, and everything inside them is guarded.

import { safePath, scrubText, scrubStack, guard, sampledIn, hostOf } from './util.js';

/**
 * @param {object} ctx   { win, cfg, sampleKey, log(entry), pageFields() }
 * @returns {function} stop
 */
export function installErrors(ctx) {
  const win = ctx.win;
  const opts = ctx.cfg.browser;
  if (!opts || !opts.errors) return function () {};
  if (!sampledIn(ctx.sampleKey + ':err', opts.errorSampleRate)) return function () {};

  const seen = {};
  let distinct = 0;
  const max = Math.max(1, opts.maxErrorsPage || 20);

  function report(message, stack, extra) {
    const msg = scrubText(message || 'Unknown error', 500);
    const frames = scrubStack(stack, 25, 6000);
    const top = frames ? frames.split('\n')[0] : '';
    const key = msg + '|' + top;
    const entry = seen[key];
    if (entry) {
      entry.count++;
      // Repeats are reported at 10, 100, 1000 ... occurrences, carrying the count.
      if (entry.count !== 10 && entry.count !== 100 && entry.count !== 1000) return;
    } else {
      if (distinct >= max) return;
      distinct++;
      seen[key] = { count: 1 };
    }
    const fields = Object.assign({ eventType: 'js_error', occurrences: seen[key].count }, ctx.pageFields(), extra);
    ctx.log({ severity: 'error', message: msg, stackTrace: frames, fields: fields });
  }

  const onError = guard(function (event) {
    // Resource load failures (an <img> that 404s) are not JavaScript errors; only script errors reach window.
    if (!event || (event.target && event.target !== win && event.target.nodeType === 1)) return;
    const file = event.filename ? safePath(event.filename) : '';
    const extra = { handler: 'window.onerror', line: event.lineno || 0, column: event.colno || 0 };
    if (file) { extra.file = file; extra.fileHost = hostOf(event.filename); }
    report(event.message || (event.error && event.error.message), event.error && event.error.stack, extra);
  });

  const onRejection = guard(function (event) {
    const reason = event && event.reason;
    const message = reason && reason.message ? 'Unhandled rejection: ' + reason.message
      : (typeof reason === 'string' ? 'Unhandled rejection: ' + reason : 'Unhandled promise rejection');
    report(message, reason && reason.stack, { handler: 'unhandledrejection' });
  });

  win.addEventListener('error', onError);
  win.addEventListener('unhandledrejection', onRejection);
  return function stop() {
    win.removeEventListener('error', onError);
    win.removeEventListener('unhandledrejection', onRejection);
  };
}
