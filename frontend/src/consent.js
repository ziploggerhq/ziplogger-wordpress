// Consent state machine.
//
// ZipLogger itself has no consent handling, so the plugin owns it. Evidence, strongest first:
//   1. Do-Not-Track / Global Privacy Control (for the identifying categories, when the site honours them);
//   2. the WordPress Consent API (window.wp_has_consent), when the site uses it;
//   3. this plugin's own cookie, written by ZipLoggerWP.consent.grant() / revoke().
// No evidence means "unknown", which is treated as NOT granted whenever a category requires consent.

export const CATEGORIES = ['browser', 'analytics', 'replay', 'commerce'];
const IDENTIFYING = { analytics: true, replay: true };
const MAX_AGE = 60 * 60 * 24 * 180; // Six months, in seconds.

function readCookie(doc, name) {
  try {
    const parts = String(doc.cookie || '').split(';');
    for (let i = 0; i < parts.length; i++) {
      const p = parts[i].trim();
      if (p.indexOf(name + '=') === 0) return decodeURIComponent(p.slice(name.length + 1));
    }
  } catch (e) { /* cookies unavailable */ }
  return null;
}

/**
 * @param {object} cfg   policy, wpConsentApi, wpCategories, cookie, cookiePath, honorDnt
 * @param {object} env   { win, doc } (injected so the logic runs under test)
 */
export function createConsent(cfg, env) {
  const win = env.win;
  const doc = env.doc;
  const nav = win.navigator || {};
  const cookieName = cfg.cookie || 'ziplogger_consent';
  const policy = cfg.policy || {};
  const listeners = [];

  // null: no cookie (nothing decided); otherwise the set of granted categories.
  let own = null;
  const raw = readCookie(doc, cookieName);
  if (raw !== null && /^[a-z,]{0,64}$/.test(raw)) {
    own = {};
    raw.split(',').forEach(function (c) { if (CATEGORIES.indexOf(c) !== -1) own[c] = true; });
  }

  function honoursDnt() {
    return cfg.honorDnt !== false && (nav.doNotTrack === '1' || win.doNotTrack === '1' || nav.globalPrivacyControl === true);
  }

  function wpState(category) {
    if (!cfg.wpConsentApi || typeof win.wp_has_consent !== 'function') return null;
    const mapped = cfg.wpCategories && cfg.wpCategories[category];
    if (!mapped) return null;
    try {
      return win.wp_has_consent(mapped) ? 'granted' : 'denied';
    } catch (e) {
      return null;
    }
  }

  function state(category) {
    if (IDENTIFYING[category] && honoursDnt()) return 'denied';
    const fromWp = wpState(category);
    if (fromWp) return fromWp;
    if (own !== null) return own[category] ? 'granted' : 'denied';
    return 'unknown';
  }

  function allows(category) {
    if (IDENTIFYING[category] && honoursDnt()) return false;
    return policy[category] === 'none' ? true : state(category) === 'granted';
  }

  const last = {};
  CATEGORIES.forEach(function (c) { last[c] = allows(c); });

  function notify() {
    CATEGORIES.forEach(function (c) {
      const now = allows(c);
      if (now === last[c]) return;
      last[c] = now;
      listeners.slice().forEach(function (fn) {
        try { fn(c, now); } catch (e) { /* a listener must never break consent handling */ }
      });
      try {
        doc.dispatchEvent(new win.CustomEvent('ziplogger:consent', { detail: { category: c, allowed: now } }));
      } catch (e) { /* CustomEvent unavailable */ }
    });
  }

  function persist() {
    const granted = CATEGORIES.filter(function (c) { return own && own[c]; });
    try {
      let cookie = cookieName + '=' + encodeURIComponent(granted.join(',')) + '; Max-Age=' + MAX_AGE + '; Path=' + (cfg.cookiePath || '/') + '; SameSite=Lax';
      if (win.location && win.location.protocol === 'https:') cookie += '; Secure';
      doc.cookie = cookie;
    } catch (e) { /* cookies blocked: the choice still holds for this page view */ }
  }

  function normalize(categories) {
    const list = categories === undefined ? CATEGORIES : (Array.isArray(categories) ? categories : [categories]);
    return list.filter(function (c) { return CATEGORIES.indexOf(c) !== -1; });
  }

  // Watch the WordPress Consent API for changes made by the site's consent banner.
  if (cfg.wpConsentApi) {
    ['wp_listen_for_consent_change', 'wp_consent_type_defined'].forEach(function (name) {
      try { doc.addEventListener(name, notify); } catch (e) { /* ignore */ }
    });
  }

  return {
    state: state,
    allows: allows,
    /** Record consent for categories (default: all) and start whatever they permit. */
    grant: function (categories) {
      own = own || {};
      normalize(categories).forEach(function (c) { own[c] = true; });
      persist();
      notify();
    },
    /** Withdraw consent for categories (default: all). Collection and recording stop. */
    revoke: function (categories) {
      own = own || {};
      normalize(categories).forEach(function (c) { delete own[c]; });
      persist();
      notify();
    },
    /** Re-read external evidence (the WP Consent API) and notify about changes. */
    refresh: notify,
    /** Subscribe to changes: fn(category, allowed). Returns an unsubscribe function. */
    onChange: function (fn) {
      listeners.push(fn);
      return function () {
        const i = listeners.indexOf(fn);
        if (i !== -1) listeners.splice(i, 1);
      };
    },
    categories: CATEGORIES,
  };
}
