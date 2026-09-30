// Test environment: a jsdom "browser" wired into Node's globals the way a real page is (window IS the
// global object there, so `fetch` and `window.fetch` are one thing, and the SDK reads the globals).
//
// Import this BEFORE anything from ../src: the ZipLogger SDK decides at load time whether a window exists.

import { JSDOM } from 'jsdom';

export function setupDom(options = {}) {
  const url = options.url || 'https://shop.example.test/';
  const dom = new JSDOM(options.html || '<!doctype html><html><head></head><body></body></html>', { url, pretendToBeVisual: true });
  const win = dom.window;

  const define = (name, value) => Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
  define('window', win);
  define('document', win.document);
  define('location', win.location);
  define('navigator', win.navigator);
  define('history', win.history);
  define('localStorage', win.localStorage);
  define('sessionStorage', win.sessionStorage);
  define('CustomEvent', win.CustomEvent);
  define('Event', win.Event);
  define('Node', win.Node);
  define('HTMLElement', win.HTMLElement);

  // fetch: window.fetch and the global are the same slot, like in a browser. Node's Request/Headers/Response
  // are used for both so `instanceof` checks in the code under test behave.
  win.Request = globalThis.Request;
  win.Headers = globalThis.Headers;
  win.Response = globalThis.Response;
  win.URL = globalThis.URL;
  Object.defineProperty(globalThis, 'fetch', { get: () => win.fetch, set: (v) => { win.fetch = v; }, configurable: true });
  Object.defineProperty(globalThis, 'Request', { value: win.Request, configurable: true, writable: true });

  const calls = [];
  const state = { handler: null };
  win.fetch = function mockFetch(input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || String(input);
    const record = { url, method: (init && init.method) || (input && input.method) || 'GET', init: init || {}, input, headers: null, body: init && init.body };
    const source = (init && init.headers) || (input && input.headers);
    record.headers = new globalThis.Headers(source || undefined);
    calls.push(record);
    if (state.handler) {
      // Like the real fetch, a failure is a rejected promise, never a synchronous throw.
      try { return Promise.resolve(state.handler(record)); } catch (e) { return Promise.reject(e); }
    }
    return Promise.resolve(new globalThis.Response('{}', { status: 200, headers: { 'content-type': 'application/json' } }));
  };
  win.__originalFetch = win.fetch;

  return {
    dom,
    win,
    doc: win.document,
    calls,
    /** Replace the network behaviour: fn(record) returns a Response, or throws / rejects. */
    respond(fn) { state.handler = fn; },
    reset() { calls.length = 0; state.handler = null; },
    callsTo(fragment) { return calls.filter((c) => c.url.includes(fragment)); },
    /** Events posted to /ingest/v1/events, flattened from NDJSON. */
    events() {
      return this.callsTo('/ingest/v1/events').flatMap((c) => String(c.body).split('\n').filter(Boolean).map((l) => JSON.parse(l)));
    },
    logs() {
      return this.callsTo('/ingest/v1/logs').flatMap((c) => String(c.body).split('\n').filter(Boolean).map((l) => JSON.parse(l)));
    },
    spans() {
      return this.callsTo('/v1/traces').flatMap((c) => JSON.parse(c.body).resourceSpans.flatMap((r) => r.scopeSpans.flatMap((s) => s.spans)));
    },
    cookie(name, value) { win.document.cookie = `${name}=${value}; path=/`; },
    async settle(ms = 20) { await new Promise((r) => setTimeout(r, ms)); },
  };
}

/** A configuration in the shape the PHP side emits, with every module off unless overridden. */
export function baseConfig(overrides = {}) {
  const cfg = {
    v: 1,
    endpoint: 'https://ingest.ziplogger.test',
    key: 'zk_browser_public_key_for_tests',
    source: 'shop.example.test',
    environment: 'production',
    release: '1.2.3',
    commitSha: '',
    modules: { browser: false, analytics: false, replay: false, tracing: false, commerce: false },
    browser: { errors: true, failedRequests: true, slowRequests: false, slowThresholdMs: 3000, navigationTiming: false, webVitals: false, perfSampleRate: 100, errorSampleRate: 100, maxErrorsPage: 20 },
    analytics: { pageViews: true, spaNavigation: true, interactions: false, selectors: [], identify: false, sampleRate: 100 },
    replay: { sampleRate: 100, maskAllText: true, blockSelector: '', maskSelector: '', excludePaths: [], excludeRoles: [], maxMinutes: 30, maxMegabytes: 20, recorderUrl: 'https://shop.example.test/recorder.js' },
    tracing: { sampleRate: 100, propagateHosts: [], browser: true, serviceName: 'shop', propagateSession: true },
    consent: { policy: { browser: 'none', analytics: 'required', replay: 'required', commerce: 'none' }, wpConsentApi: false, wpCategories: {}, cookie: 'ziplogger_consent', cookiePath: '/', honorDnt: true },
    context: { url: 'https://shop.example.test/wp-admin/admin-ajax.php', action: 'ziplogger_context', hintCookie: 'ziplogger_li' },
    page: { type: 'page', replay: true, sensitiveEndpoints: [] },
  };
  for (const [key, value] of Object.entries(overrides)) {
    cfg[key] = value && typeof value === 'object' && !Array.isArray(value) && cfg[key] && typeof cfg[key] === 'object' ? { ...cfg[key], ...value } : value;
  }
  return cfg;
}
