// Browser-side distributed tracing.
//
// The plugin does its own propagation instead of the SDK's instrumentFetch() because the SDK sends the
// full request URL (query string included) in span attributes and sends baggage to every propagation
// target. Here:
//   - a W3C `traceparent` header goes ONLY to same-origin requests and to hosts the administrator listed;
//   - `baggage` (the session id) goes only to same-origin requests, never to a third party;
//   - the sampling decision is made once, at the root (this browser), from the trace id, and travels in
//     the traceparent flags, so the server continues it (parent-aware sampling);
//   - exported spans carry method, host, path without query string, status and duration - nothing else;
//   - ZipLogger's own telemetry requests are never traced.

import { randomHex, fraction, safeUrl, safePath, hostOf, parseUrl, isSameOrigin, guard, truncate } from './util.js';

const MAX_QUEUE = 200;
const BATCH = 50;
const FLUSH_MS = 5000;

/** Does a host (with optional port) match an allowlist entry ("api.example.com" or "*.example.com")? */
export function hostAllowed(host, port, list) {
  if (!host) return false;
  const h = host.toLowerCase();
  for (let i = 0; i < list.length; i++) {
    const entry = String(list[i]).toLowerCase();
    const colon = entry.lastIndexOf(':');
    const entryHost = colon === -1 ? entry : entry.slice(0, colon);
    const entryPort = colon === -1 ? '' : entry.slice(colon + 1);
    if (entryPort && entryPort !== String(port)) continue;
    if (entryHost.indexOf('*.') === 0) {
      const suffix = entryHost.slice(1); // ".example.com"
      if (h.length > suffix.length && h.slice(-suffix.length) === suffix) return true;
    } else if (entryHost === h) {
      return true;
    }
  }
  return false;
}

/**
 * @param {object} ctx { win, cfg, network, isOwn(url), identity(), allowed():bool, ownFetch, sampleKey }
 * @returns {{recentTraceId:function, flush:function, stop:function}}
 */
export function installTracing(ctx) {
  const t = ctx.cfg.tracing || {};
  const win = ctx.win;
  const hosts = Array.isArray(t.propagateHosts) ? t.propagateHosts : [];
  const rate = Number(t.sampleRate) || 0;
  const queue = [];
  let timer = null;
  let last = null;
  let dropped = 0;

  const origin = String(ctx.cfg.endpoint || '').replace(/\/+$/, '');
  const tracesUrl = origin + '/v1/traces';
  const service = (t.serviceName || ctx.cfg.source || 'wordpress') + '-browser';

  function targetKind(url) {
    if (isSameOrigin(url)) return 'same';
    const u = parseUrl(url);
    if (!u) return 'none';
    return hostAllowed(u.hostname, u.port, hosts) ? 'allowlisted' : 'none';
  }

  function nowNano(ms) {
    return String(Math.floor(ms)) + '000000';
  }

  function flush(keepalive) {
    if (timer !== null) { win.clearTimeout(timer); timer = null; }
    if (!queue.length || !ctx.allowed()) { queue.length = 0; return; }
    const spans = queue.splice(0, BATCH);
    const body = JSON.stringify({
      resourceSpans: [{
        resource: { attributes: [
          { key: 'service.name', value: { stringValue: service } },
          { key: 'deployment.environment.name', value: { stringValue: ctx.cfg.environment || 'production' } },
          { key: 'telemetry.sdk.name', value: { stringValue: 'ziplogger-wordpress' } },
          { key: 'telemetry.sdk.language', value: { stringValue: 'webjs' } },
        ] },
        scopeSpans: [{ scope: { name: 'ziplogger-wordpress-browser' }, spans: spans }],
      }],
    });
    try {
      // The plugin's own, unwrapped fetch: telemetry about telemetry would loop.
      const p = ctx.ownFetch(tracesUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Api-Key': ctx.cfg.key },
        body: body,
        keepalive: !!keepalive && body.length < 60000,
      });
      if (p && p.catch) p.catch(function () { dropped += spans.length; });
    } catch (e) { dropped += spans.length; }
    if (queue.length) timer = win.setTimeout(function () { flush(false); }, FLUSH_MS);
  }

  function enqueue(span) {
    if (queue.length >= MAX_QUEUE) { dropped++; return; }
    queue.push(span);
    if (queue.length >= BATCH) flush(false);
    else if (timer === null) timer = win.setTimeout(function () { flush(false); }, FLUSH_MS);
  }

  const hook = {
    before: guard(function (info) {
      if (!ctx.allowed()) return null;
      const kind = targetKind(info.url);
      if (kind === 'none') return null;

      const traceId = randomHex(16);
      const spanId = randomHex(8);
      const sampled = rate > 0 && fraction(traceId) * 100 < rate;
      info.trace = { traceId: traceId, spanId: spanId, sampled: sampled, wall: Date.now(), kind: kind };
      last = { traceId: traceId, at: Date.now() };

      const headers = { traceparent: '00-' + traceId + '-' + spanId + (sampled ? '-01' : '-00') };
      if (kind === 'same' && t.propagateSession !== false) {
        const id = ctx.identity().sessionId;
        if (id) headers.baggage = 'session.id=' + encodeURIComponent(id);
      }
      return headers;
    }),

    after: guard(function (info) {
      const tr = info.trace;
      if (!tr || !tr.sampled || !ctx.allowed()) return;
      const status = info.status || 0;
      const failed = !!info.error || status >= 400;
      const path = safePath(info.url, { normalize: true });
      const attrs = [
        { key: 'http.request.method', value: { stringValue: info.method } },
        { key: 'url.full', value: { stringValue: truncate(safeUrl(info.url), 300) } },
        { key: 'server.address', value: { stringValue: hostOf(info.url) } },
        { key: 'ziplogger.transport', value: { stringValue: info.kind } },
      ];
      const session = ctx.identity().sessionId;
      if (session) attrs.push({ key: 'session.id', value: { stringValue: session } });
      if (status) attrs.push({ key: 'http.response.status_code', value: { intValue: String(status) } });
      if (info.error) attrs.push({ key: 'error.type', value: { stringValue: truncate(info.error, 60) } });

      const span = {
        traceId: tr.traceId,
        spanId: tr.spanId,
        name: truncate(info.method + ' ' + path, 120),
        kind: 3, // client
        startTimeUnixNano: nowNano(tr.wall),
        endTimeUnixNano: nowNano(tr.wall + Math.max(0, info.duration)),
        attributes: attrs,
      };
      if (failed) span.status = { code: 2, message: info.error ? truncate(info.error, 60) : 'HTTP ' + status };
      enqueue(span);
    }),
  };

  ctx.network.addHook(hook);

  const onHide = function () { flush(true); };
  win.addEventListener('pagehide', onHide);
  win.document.addEventListener('visibilitychange', function () { if (win.document.visibilityState === 'hidden') flush(true); });

  return {
    /** The trace id of the latest traced request if it is still fresh (for linking events to requests). */
    recentTraceId: function (withinMs) {
      return last && Date.now() - last.at <= (withinMs || 5000) ? last.traceId : undefined;
    },
    flush: flush,
    dropped: function () { return dropped; },
    stop: function () {
      win.removeEventListener('pagehide', onHide);
      queue.length = 0;
    },
    _hook: hook,
  };
}
