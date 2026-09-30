// Product analytics: page views, client-side navigation, allowlisted interactions, custom events.
//
// Nothing is collected that the administrator or the site's own code did not name:
//   - page views carry the path (no query string), the page type WordPress reports, and the referrer HOST;
//   - interactions are recorded only for elements matching an allowlisted selector, or carrying an
//     explicit data-ziplogger-event attribute; the event holds the matched SELECTOR, never the element's
//     text, value or attributes;
//   - custom events (ZipLoggerWP.track) pass through the same property cleaning as everything else.
// Every emit checks consent at the moment it happens; nothing is buffered on behalf of a category that
// has not been granted.

import { safePath, parseUrl, hostOf, cleanProperties, eventName, guard, sampledIn, truncate, isSensitiveKey } from './util.js';

/**
 * Replace the identifier that follows a sensitive WooCommerce endpoint slug ("order-received/123").
 * @param {string} path
 * @param {string[]} slugs
 */
export function maskEndpointIds(path, slugs) {
  if (!slugs || !slugs.length) return path;
  const parts = path.split('/');
  for (let i = 0; i < parts.length - 1; i++) {
    if (slugs.indexOf(parts[i]) !== -1 && parts[i + 1]) parts[i + 1] = ':id';
  }
  return parts.join('/');
}

/**
 * @param {object} ctx { win, doc, cfg, client, consent, identity, tracing, sampleKey }
 */
export function createAnalytics(ctx) {
  const a = ctx.cfg.analytics || {};
  const win = ctx.win;
  const doc = ctx.doc;
  const sensitiveSlugs = (ctx.cfg.page && ctx.cfg.page.sensitiveEndpoints) || [];
  const selectors = (a.selectors || []).filter(Boolean);
  const inSample = function () { return sampledIn(ctx.identity().sessionId + ':an', a.sampleRate); };
  let lastPath = null;
  let stopped = false;
  const cleanups = [];

  function currentPath() {
    return maskEndpointIds(safePath(win.location.href), sensitiveSlugs);
  }

  function requestId() {
    return ctx.tracing ? ctx.tracing.recentTraceId(5000) : undefined;
  }

  /** The one place an analytics event leaves the plugin. */
  function emit(name, properties) {
    if (stopped || !ctx.consent.allows('analytics') || !inSample()) return false;
    const n = eventName(name);
    if (!n) return false;
    const props = Object.assign(cleanProperties(properties, 30), { path: currentPath() });
    if (ctx.cfg.page && ctx.cfg.page.type && !props.pageType) props.pageType = ctx.cfg.page.type;
    try {
      const rid = requestId();
      ctx.client.track(n, props, rid ? { requestId: rid } : undefined);
      return true;
    } catch (e) {
      return false;
    }
  }

  function pageView(how) {
    const path = currentPath();
    if (path === lastPath) return;
    lastPath = path;
    const props = { navigation: how };
    try {
      const ref = doc.referrer ? parseUrl(doc.referrer) : null;
      const own = win.location.hostname.toLowerCase();
      if (how === 'load' && ref && ref.hostname && ref.hostname.toLowerCase() !== own) props.referrerHost = hostOf(doc.referrer);
    } catch (e) { /* no referrer */ }
    emit('page_view', props);
  }

  // ---- interactions -------------------------------------------------------------------------------

  function matchSelector(el) {
    for (let i = 0; i < selectors.length; i++) {
      try {
        const hit = el.closest ? el.closest(selectors[i]) : null;
        if (hit) return selectors[i];
      } catch (e) { /* an invalid selector was already rejected server side; skip defensively */ }
    }
    return null;
  }

  function dataAttributes(el) {
    const props = {};
    const attrs = el.attributes || [];
    for (let i = 0; i < attrs.length; i++) {
      const name = attrs[i].name;
      if (name.indexOf('data-ziplogger-prop-') !== 0) continue;
      const key = name.slice('data-ziplogger-prop-'.length).replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); });
      if (isSensitiveKey(key)) continue;
      props[key] = attrs[i].value;
    }
    return props;
  }

  const onClick = guard(function (event) {
    if (!a.interactions || !ctx.consent.allows('analytics')) return;
    const target = event.target && event.target.nodeType === 1 ? event.target : (event.target && event.target.parentElement);
    if (!target) return;

    // 1. Explicit markup written by the site's developer.
    const marked = target.closest ? target.closest('[data-ziplogger-event]') : null;
    if (marked) {
      const name = eventName(marked.getAttribute('data-ziplogger-event') || '');
      if (name) { emit(name, dataAttributes(marked)); return; }
    }
    // 2. An allowlisted selector from the settings page.
    const matched = matchSelector(target);
    if (matched) emit('interaction', { selector: truncate(matched, 120), action: 'click', tag: String(target.tagName || '').toLowerCase() });
  });

  // ---- client-side navigation --------------------------------------------------------------------

  if (a.interactions) {
    doc.addEventListener('click', onClick, true);
    cleanups.push(function () { doc.removeEventListener('click', onClick, true); });
  }
  if (a.spaNavigation && ctx.navigation) {
    // After the URL changed, once the app has had a chance to update the page (and its title).
    cleanups.push(ctx.navigation.subscribe(function () { win.setTimeout(function () { pageView('spa'); }, 0); }));
  }

  return {
    /** Record the first page view (call once at start-up, and again when consent is granted). */
    start: function () { if (a.pageViews) pageView('load'); },
    /** A custom event from ZipLoggerWP.track(). */
    track: function (name, properties) { return emit(name, properties); },
    /** Forget which path was reported, so the current page is counted again (after consent is granted). */
    reset: function () { lastPath = null; },
    stop: function () { stopped = true; cleanups.splice(0).forEach(function (fn) { fn(); }); },
  };
}
