// Bootstrap: read the (cache-safe) page configuration, wire the features that are switched on, expose the
// small public API (window.ZipLoggerWP), and make sure nothing in here can break the page.
//
// The configuration is identical for every visitor of a URL, so page caches can store it. Anything that
// differs per visitor (are they signed in, may they be recorded, what is their pseudonymous id) is asked
// of the server at run time, in a request that is never cached.

import { WPClient } from './client.js';
import { createConsent } from './consent.js';
import { createIdentity } from './identity.js';
import { installNavigation } from './navigation.js';
import { installNetwork, requestReporter } from './network.js';
import { installErrors } from './errors.js';
import { installPerformance } from './performance.js';
import { installTracing } from './tracing.js';
import { createAnalytics, maskEndpointIds } from './analytics.js';
import { createReplay, replayOptions } from './replay.js';
import { createCommerce } from './commerce.js';
import { safePath, safeUrl, randomHex, scrubText, scrubStack, cleanProperties, guard, truncate } from './util.js';

export const VERSION = '1.0.0';
const ENDPOINT_RE = /^https:\/\/[^\s/]+/i;

export function readConfig(doc) {
  try {
    const el = doc.getElementById('ziplogger-config');
    if (!el) return null;
    const cfg = JSON.parse(el.textContent || '');
    return cfg && cfg.v === 1 && typeof cfg.key === 'string' && cfg.key && typeof cfg.endpoint === 'string' && cfg.modules ? cfg : null;
  } catch (e) {
    return null;
  }
}

/**
 * @param {Window}   win
 * @param {Document} doc
 * @param {object}   [override] configuration (tests); normally read from the page
 * @returns {object|null} the public API, or null when nothing was started
 */
export function boot(win, doc, override) {
  // One instance per page: a theme that loads the script twice, or two copies of the plugin, must not
  // double every event or wrap the network functions twice.
  if (win.ZipLoggerWP && win.ZipLoggerWP.booted) return win.ZipLoggerWP;

  const cfg = override || readConfig(doc);
  if (!cfg) return null;
  // HTTPS only, unless the site's own wp-config.php carries the explicit development constant (the server
  // then adds allowInsecureEndpoint to the configuration and has already validated the address).
  const insecureOk = cfg.allowInsecureEndpoint === true && /^http:\/\/[^\s/]+/i.test(cfg.endpoint);
  if (!ENDPOINT_RE.test(cfg.endpoint) && !insecureOk) return null;

  const endpointBase = cfg.endpoint.replace(/\/+$/, '');
  const ownPrefixes = [endpointBase + '/ingest/', endpointBase + '/v1/'];
  const ownFetch = typeof win.fetch === 'function' ? win.fetch.bind(win) : null; // captured BEFORE anything wraps it
  const sampleKey = randomHex(8);
  const m = cfg.modules || {};
  const page = cfg.page || {};
  const state = { replayState: 'idle', replayReason: '' };

  const consent = createConsent(cfg.consent || {}, { win: win, doc: doc });
  let client = null;
  let replay = null;
  let analytics = null;
  let commerce = null;
  let tracing = null;
  let network = null;

  // ---- identity -----------------------------------------------------------------------------------
  const identity = createIdentity({ win: win }, consent, function (id, sessionChanged) {
    if (client) client.setIdentity(id);
    if (commerce) commerce.sync();
    if (sessionChanged && replay) replay.restart();
  });
  identity.sync();
  const first = identity.current();

  try {
    client = new WPClient({
      endpoint: endpointBase,
      apiKey: cfg.key,
      source: cfg.source || win.location.hostname,
      release: cfg.release || undefined,
      commitSha: cfg.commitSha || undefined,
      environment: cfg.environment || 'production',
      tags: ['wordpress', 'browser'],
      includePageContext: false, // the SDK would attach the full URL (query string included); the plugin adds a safe path instead
      requestCorrelationTtlMs: 0, // linking events to requests is done by the plugin's own tracing
      // Non-null ids keep the SDK from touching web storage; the plugin owns identity (see client.js).
      anonymousId: first.anonymousId || 'anon_unset',
      sessionId: first.sessionId,
      sessionReplay: m.replay ? replayOptions(cfg) : undefined,
    });
    client.setIdentity(first);
  } catch (e) {
    return null;
  }

  // ---- shared helpers -----------------------------------------------------------------------------
  function currentPath() {
    return maskEndpointIds(safePath(win.location.href), (page && page.sensitiveEndpoints) || []);
  }

  function pageFields() {
    const f = { path: currentPath(), sessionId: identity.current().sessionId };
    if (page.type) f.pageType = page.type;
    return f;
  }

  function isOwn(url) {
    const u = safeUrl(url);
    for (let i = 0; i < ownPrefixes.length; i++) if (u.indexOf(ownPrefixes[i]) === 0) return true;
    return false;
  }

  /** Browser-category log entry: consent checked at the moment of logging. */
  const log = guard(function (entry) {
    if (!consent.allows('browser')) return;
    client.log({
      severity: entry.severity || 'info',
      message: scrubText(entry.message, 500),
      stackTrace: entry.stackTrace,
      fields: entry.fields,
    });
  });

  function hint() {
    const name = (cfg.context && cfg.context.hintCookie) || 'ziplogger_li';
    try { return String(doc.cookie || '').split(';').some(function (p) { return p.trim().indexOf(name + '=') === 0; }); } catch (e) { return false; }
  }

  // ---- server-supplied, per-visitor facts ---------------------------------------------------------
  let contextMemo = null;
  let contextHint = null;
  function getContext() {
    if (!cfg.context || !cfg.context.url || !ownFetch) return Promise.resolve(null);
    const h = hint();
    if (contextMemo && contextHint === h) return contextMemo;
    contextHint = h;
    contextMemo = ownFetch(cfg.context.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + encodeURIComponent(cfg.context.action || 'ziplogger_context'),
    }).then(function (r) {
      if (!r.ok) throw new Error('context ' + r.status);
      return r.json();
    }).then(function (j) {
      return j && j.success && j.data ? j.data : null;
    }).catch(function () {
      contextMemo = null; // ask again next time
      return null;
    });
    return contextMemo;
  }

  let lastHint = hint();
  function syncAuth() {
    const h = hint();
    if (h !== lastHint) {
      const wasSignedIn = lastHint;
      lastHint = h;
      contextMemo = null;
      if (wasSignedIn && !h) identity.rotate(); // signed out: a new, unlinked visitor
    }
    if (h && m.analytics && cfg.analytics && cfg.analytics.identify && consent.allows('analytics') && !identity.current().userId) {
      getContext().then(function (c) {
        if (!c || !c.userRef || !consent.allows('analytics') || identity.current().userId) return;
        if (identity.setUser(c.userRef)) {
          client.setIdentity(identity.current());
          client.identify(c.userRef);
        }
      });
    }
  }

  // ---- features -----------------------------------------------------------------------------------
  const navigation = (m.analytics || m.replay) ? installNavigation(win) : null;

  const wantsRequests = m.browser && cfg.browser && (cfg.browser.failedRequests || cfg.browser.slowRequests);
  const wantsTracing = m.tracing && cfg.tracing && cfg.tracing.browser;
  if (wantsRequests || wantsTracing) {
    network = installNetwork({ win: win, cfg: cfg, sampleKey: sampleKey, isOwn: isOwn });
  }
  if (wantsRequests) {
    network.addHook(requestReporter({ cfg: cfg, sampleKey: sampleKey, log: log, pageFields: pageFields }));
  }
  if (wantsTracing && ownFetch) {
    tracing = installTracing({
      win: win,
      cfg: cfg,
      network: network,
      identity: identity.current,
      ownFetch: ownFetch,
      allowed: function () { return consent.allows('browser'); },
    });
  }

  if (m.browser) {
    installErrors({ win: win, cfg: cfg, sampleKey: sampleKey, log: log, pageFields: pageFields });
    installPerformance({ win: win, cfg: cfg, sampleKey: sampleKey, log: log, pageFields: pageFields });
  }

  if (m.analytics) {
    analytics = createAnalytics({ win: win, doc: doc, cfg: cfg, client: client, consent: consent, identity: identity.current, tracing: tracing, navigation: navigation });
  }
  // WooCommerce interactions ride on the analytics module: same consent, same cleaning, same sampling.
  if (m.commerce && analytics) {
    commerce = createCommerce({ win: win, doc: doc, cfg: cfg, analytics: analytics, consent: consent, identity: identity.current });
  }

  if (m.replay && ownFetch && navigation) {
    replay = createReplay({
      win: win,
      doc: doc,
      cfg: cfg,
      client: client,
      consent: consent,
      identity: identity,
      navigation: navigation,
      ownFetch: ownFetch,
      loadRecorder: function () { return loadRecorder(win, doc, cfg); },
      context: getContext,
      hint: hint,
      onState: function (s, why) { state.replayState = s; state.replayReason = why; },
    });
  }

  // ---- consent changes ----------------------------------------------------------------------------
  consent.onChange(function (category, allowed) {
    identity.sync();
    if (category === 'analytics') {
      if (allowed) {
        if (analytics) { analytics.reset(); analytics.start(); }
        if (commerce) { commerce.sync(); commerce.page(); }
        syncAuth();
      } else if (client && client._events) {
        if (commerce) commerce.sync(); // removes the identity cookie
        client._events.length = 0; // unsent analytics events are discarded, not flushed at page hide
      }
    }
    if (category === 'browser' && !allowed && client && client._queue) client._queue.length = 0;
    if (category === 'replay' && replay) {
      if (allowed) replay.evaluate(); else replay.withdraw();
    }
  });

  doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'visible') syncAuth(); });

  // ---- public API ---------------------------------------------------------------------------------
  const api = {
    booted: true,
    version: VERSION,
    /** Record a custom analytics event. Only allowlisted, cleaned properties are sent. */
    track: function (name, properties) { return analytics ? analytics.track(name, properties) : false; },
    /** Record a log line in ZipLogger from the browser. */
    log: function (severity, message, fields) {
      return log({ severity: typeof severity === 'string' ? severity : 'info', message: String(message), fields: Object.assign(cleanProperties(fields, 20), pageFields()) });
    },
    consent: {
      grant: function (categories) { consent.grant(categories); },
      revoke: function (categories) { consent.revoke(categories); },
      state: function (category) { return consent.state(category); },
      allows: function (category) { return consent.allows(category); },
      categories: consent.categories,
    },
    /** Send everything buffered now. */
    flush: function () { return client ? client.flush() : Promise.resolve(); },
    /** What the plugin is doing on this page, without any identifier. For support and tests. */
    status: function () {
      return {
        version: VERSION,
        modules: m,
        allowed: { browser: consent.allows('browser'), analytics: consent.allows('analytics'), replay: consent.allows('replay'), commerce: consent.allows('commerce') },
        replay: replay ? replay.state() : { state: 'off', reason: '' },
        persistedIds: identity.persisted(),
        dropped: client ? client.dropped : 0,
      };
    },
    _internal: { client: client, identity: identity, consent: consent, tracing: tracing, analytics: analytics, replay: replay },
  };

  win.ZipLoggerWP = Object.assign(win.ZipLoggerWP && !win.ZipLoggerWP.booted ? win.ZipLoggerWP : {}, api);

  // ---- start --------------------------------------------------------------------------------------
  if (analytics) analytics.start();
  if (commerce) commerce.start();
  syncAuth();
  if (replay) replay.evaluate();

  // A command queue for code that runs before this script: window.ziploggerQueue.push(['track', 'name', {}]).
  try {
    const pending = Array.isArray(win.ziploggerQueue) ? win.ziploggerQueue.splice(0) : [];
    const run = guard(function (cmd) {
      if (!Array.isArray(cmd)) return;
      const name = cmd[0];
      if (name === 'track') api.track(cmd[1], cmd[2]);
      else if (name === 'log') api.log(cmd[1], cmd[2], cmd[3]);
      else if (name === 'consent.grant') api.consent.grant(cmd[1]);
      else if (name === 'consent.revoke') api.consent.revoke(cmd[1]);
    });
    pending.forEach(run);
    win.ziploggerQueue = { push: function (cmd) { run(cmd); return 1; } };
  } catch (e) { /* the queue is a convenience */ }

  try { doc.dispatchEvent(new win.CustomEvent('ziplogger:ready', { detail: { version: VERSION } })); } catch (e) { /* ignore */ }
  return win.ZipLoggerWP;
}

/** Fetch the recorder bundle (rrweb) only when a session is actually going to be recorded. */
function loadRecorder(win, doc, cfg) {
  return new Promise(function (resolve, reject) {
    if (win.__ziploggerRecord) { resolve(win.__ziploggerRecord); return; }
    const url = cfg.replay && cfg.replay.recorderUrl;
    if (!url) { reject(new Error('no recorder')); return; }
    const s = doc.createElement('script');
    s.async = true;
    s.src = url;
    s.onload = function () { win.__ziploggerRecord ? resolve(win.__ziploggerRecord) : reject(new Error('recorder missing')); };
    s.onerror = function () { reject(new Error('recorder blocked')); };
    (doc.head || doc.documentElement).appendChild(s);
  });
}

export { truncate, scrubStack };
