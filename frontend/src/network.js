// fetch() and XMLHttpRequest observation.
//
// One pair of wrappers serves every feature (failed/slow request reports, trace propagation). They
// are written to be invisible to the page:
//   - the ORIGINAL promise / XHR is returned untouched, so results, ordering and errors are identical;
//   - our own follow-up work hangs off a separate branch whose errors are swallowed;
//   - request and response bodies are never read, and neither are cookies or authorization headers;
//   - a copy of the script that already wrapped the page's network functions is reused, never wrapped twice;
//   - requests to ZipLogger itself (this plugin's telemetry) are never observed or altered.

import { safePath, parseUrl, isSameOrigin, hostOf, guard, sampledIn, truncate } from './util.js';

const MARK = '__ziploggerNetwork';

/**
 * @param {object} ctx { win, cfg, sampleKey, isOwn(url), log(entry), pageFields() }
 * @returns {{addHook:function, stop:function}}
 */
export function installNetwork(ctx) {
  const win = ctx.win;
  if (win[MARK]) return win[MARK]; // Another copy is already in place: share it.

  const hooks = [];
  const origFetch = typeof win.fetch === 'function' ? win.fetch : null;
  const XHR = win.XMLHttpRequest;
  const origOpen = XHR && XHR.prototype.open;
  const origSend = XHR && XHR.prototype.send;
  const xhrMeta = typeof WeakMap === 'function' ? new WeakMap() : null;
  const clock = function () { return win.performance && win.performance.now ? win.performance.now() : Date.now(); };

  function before(info) {
    let headers = null;
    for (let i = 0; i < hooks.length; i++) {
      if (!hooks[i].before) continue;
      try {
        const h = hooks[i].before(info);
        if (h) headers = Object.assign(headers || {}, h);
      } catch (e) { /* a hook must never break a request */ }
    }
    return headers;
  }

  function after(info) {
    for (let i = 0; i < hooks.length; i++) {
      if (!hooks[i].after) continue;
      try { hooks[i].after(info); } catch (e) { /* ignore */ }
    }
  }

  function describe(input, init, kind) {
    let url = '';
    let method = 'GET';
    if (typeof input === 'string') url = input;
    else if (input && typeof input.url === 'string') { url = input.url; method = input.method || 'GET'; } // Request
    else if (input && typeof input.href === 'string') url = input.href; // URL
    if (init && typeof init.method === 'string') method = init.method;
    const u = parseUrl(url);
    return { kind: kind, method: String(method).toUpperCase(), url: u ? u.href : '', sameOrigin: u ? isSameOrigin(u.href) : false, start: 0, status: 0, error: null, aborted: false, duration: 0 };
  }

  // -------- fetch --------
  function injectFetchHeaders(input, init, extra) {
    const isRequest = typeof win.Request === 'function' && input instanceof win.Request;
    if (isRequest) {
      // A Request may carry a body that cloning would consume: only decorate body-less ones.
      if (input.method !== 'GET' && input.method !== 'HEAD') return null;
      const h = new win.Headers(input.headers);
      Object.keys(extra).forEach(function (k) { if (!h.has(k)) h.set(k, extra[k]); });
      return [new win.Request(input, { headers: h })];
    }
    const init2 = Object.assign({}, init || {});
    const h2 = new win.Headers(init2.headers || undefined);
    Object.keys(extra).forEach(function (k) { if (!h2.has(k)) h2.set(k, extra[k]); });
    init2.headers = h2;
    return [input, init2];
  }

  function wrappedFetch(input, init) {
    let info = null;
    let args = arguments;
    try {
      info = describe(input, init, 'fetch');
      if (!info.url || ctx.isOwn(info.url)) {
        info = null;
      } else {
        const extra = before(info);
        if (extra) {
          const replaced = injectFetchHeaders(input, init, extra);
          if (replaced) args = replaced;
        }
        info.start = clock();
      }
    } catch (e) {
      info = null;
      args = arguments;
    }

    const promise = origFetch.apply(this, args); // Exactly the page's own call; a synchronous throw is the page's to see.

    if (info) {
      try {
        promise.then(function (response) {
          try { info.status = response.status; info.duration = clock() - info.start; after(info); } catch (e) { /* ignore */ }
        }, function (err) {
          try {
            info.error = (err && err.name) || 'NetworkError';
            info.aborted = info.error === 'AbortError';
            info.duration = clock() - info.start;
            after(info);
          } catch (e) { /* ignore */ }
        });
      } catch (e) { /* ignore */ }
    }
    return promise;
  }

  // -------- XMLHttpRequest --------
  function wrappedOpen(method, url) {
    try {
      if (xhrMeta) xhrMeta.set(this, { method: method, url: url });
    } catch (e) { /* ignore */ }
    return origOpen.apply(this, arguments);
  }

  function wrappedSend() {
    try {
      const meta = xhrMeta && xhrMeta.get(this);
      if (meta) {
        const info = describe(meta.url, { method: meta.method }, 'xhr');
        if (info.url && !ctx.isOwn(info.url)) {
          const extra = before(info);
          if (extra) {
            const xhr = this;
            Object.keys(extra).forEach(function (k) { try { xhr.setRequestHeader(k, extra[k]); } catch (e) { /* ignore */ } });
          }
          info.start = clock();
          this.addEventListener('loadend', function () {
            try {
              info.status = this.status;
              info.error = this.status === 0 ? 'NetworkError' : null;
              info.duration = clock() - info.start;
              after(info);
            } catch (e) { /* ignore */ }
          });
        }
      }
    } catch (e) { /* ignore */ }
    return origSend.apply(this, arguments);
  }

  if (origFetch) win.fetch = wrappedFetch;
  if (XHR && origOpen && origSend) {
    XHR.prototype.open = wrappedOpen;
    XHR.prototype.send = wrappedSend;
  }

  const api = {
    addHook: function (hook) { hooks.push(hook); },
    stop: function () {
      hooks.length = 0;
      if (origFetch && win.fetch === wrappedFetch) win.fetch = origFetch;
      if (XHR && XHR.prototype.open === wrappedOpen) XHR.prototype.open = origOpen;
      if (XHR && XHR.prototype.send === wrappedSend) XHR.prototype.send = origSend;
      try { delete win[MARK]; } catch (e) { win[MARK] = undefined; }
    },
  };
  try {
    Object.defineProperty(win, MARK, { value: api, configurable: true });
  } catch (e) { win[MARK] = api; }
  return api;
}

/**
 * The failed / slow request reporter, registered as a hook.
 * Reports host (for cross-origin only), method, a normalized path (no query string, ids replaced),
 * status and duration. Nothing else about the request or response is read.
 */
export function requestReporter(ctx) {
  const b = ctx.cfg.browser || {};
  const perfSampled = sampledIn(ctx.sampleKey + ':perf', b.perfSampleRate);
  const seen = {};
  let total = 0;

  return {
    after: guard(function (info) {
      if (info.aborted) return;
      const failed = !!info.error || info.status >= 500;
      const slow = info.duration >= (b.slowThresholdMs || 3000);
      const wantFailed = failed && b.failedRequests;
      const wantSlow = !failed && slow && b.slowRequests && perfSampled;
      if (!wantFailed && !wantSlow) return;
      if (total >= 50) return;

      const path = safePath(info.url, { normalize: true });
      const key = info.method + ' ' + path + ' ' + (info.status || info.error) + (wantSlow ? ' slow' : '');
      const count = (seen[key] = (seen[key] || 0) + 1);
      if (count > 1 && count !== 10 && count !== 100) return;
      total++;

      const where = info.sameOrigin ? path : hostOf(info.url) + path;
      const outcome = info.error ? 'network error' : String(info.status);
      const fields = Object.assign({
        eventType: wantFailed ? 'request_failed' : 'request_slow',
        method: info.method,
        requestPath: path,
        status: info.status || 0,
        durationMs: Math.round(info.duration),
        transport: info.kind,
        occurrences: count,
      }, ctx.pageFields());
      if (!info.sameOrigin) fields.requestHost = hostOf(info.url);
      if (info.error) fields.errorName = truncate(info.error, 60);

      ctx.log({
        severity: wantFailed ? (info.status >= 500 || info.error ? 'error' : 'warn') : 'info',
        message: wantFailed ? truncate(info.method + ' ' + where + ' failed (' + outcome + ')', 300) : truncate(info.method + ' ' + where + ' was slow (' + Math.round(info.duration) + ' ms)', 300),
        fields: fields,
      });
    }),
  };
}
