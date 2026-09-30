// Session and visitor ids, and when they may be written to the device.
//
//   session id    per browser tab. Persisted (sessionStorage "zl_sess", the SDK's own key) only while
//                 analytics or replay is allowed; otherwise it lives in memory for one page view.
//   anonymous id  per browser. Persisted (localStorage "zl_anon", the SDK's own key) only while analytics
//                 is allowed; otherwise there is none (null) and nothing that needs one is sent.
//   user id       set by identify() from the server-supplied pseudonym; never an email or username.
//
// Withdrawing consent removes what was stored and starts new, unlinked ids, so nothing sent afterwards
// can be tied to what was sent before.

import { randomId } from './util.js';

const ANON_KEY = 'zl_anon';
const SESSION_KEY = 'zl_sess';
const REPLAY_KEY = 'zl_replay';

function store(win, kind) {
  try {
    return kind === 'local' ? win.localStorage : win.sessionStorage;
  } catch (e) {
    return null;
  }
}

function read(win, kind, key) {
  try {
    const s = store(win, kind);
    const v = s ? s.getItem(key) : null;
    return typeof v === 'string' && /^[a-z]{2,8}_[0-9a-f]{20}$/.test(v) ? v : null;
  } catch (e) {
    return null;
  }
}

function write(win, kind, key, value) {
  try { const s = store(win, kind); if (s) s.setItem(key, value); } catch (e) { /* storage blocked: the id stays in memory */ }
}

function remove(win, kind, key) {
  try { const s = store(win, kind); if (s) s.removeItem(key); } catch (e) { /* ignore */ }
}

/**
 * @param {object}   env      { win }
 * @param {object}   consent  createConsent() result
 * @param {function} onChange fn(identity, sessionChanged), called whenever an id changes
 */
export function createIdentity(env, consent, onChange) {
  const win = env.win;
  let session = null;
  let anonymous = null;
  let user = null;
  let memorySession = null;
  let persistingSession = false;

  function persistSession() { return consent.allows('analytics') || consent.allows('replay'); }
  function persistAnonymous() { return consent.allows('analytics'); }

  function current() {
    return { sessionId: session, anonymousId: anonymous, userId: user };
  }

  function announce(sessionChanged) {
    if (!onChange) return;
    try { onChange(current(), sessionChanged); } catch (e) { /* never break consent handling */ }
  }

  /** Recompute the ids from the current consent. Returns true when the session id changed. */
  function sync() {
    const beforeSession = session;
    const beforeAnonymous = anonymous;
    const beforeUser = user;

    // 1. The anonymous id.
    if (persistAnonymous()) {
      anonymous = read(win, 'local', ANON_KEY) || anonymous || randomId('anon');
      write(win, 'local', ANON_KEY, anonymous);
    } else {
      remove(win, 'local', ANON_KEY);
      anonymous = null;
      user = null; // A user id is only ever attached to an anonymous history the visitor agreed to.
    }
    const anonymousWithdrawn = beforeAnonymous !== null && anonymous === null;

    // 2. The session id.
    if (persistSession() && !anonymousWithdrawn) {
      // A id already used on this page view is kept: consent given mid-page does not split the visit.
      session = read(win, 'session', SESSION_KEY) || memorySession || session || randomId('sess');
      write(win, 'session', SESSION_KEY, session);
      persistingSession = true;
    } else {
      if (persistingSession || anonymousWithdrawn || memorySession === null) memorySession = randomId('sess');
      session = memorySession;
      remove(win, 'session', SESSION_KEY);
      remove(win, 'session', REPLAY_KEY);
      persistingSession = false;
      if (anonymousWithdrawn && persistSession()) {
        // Replay is still allowed but analytics was withdrawn: the replay continues under a NEW session.
        write(win, 'session', SESSION_KEY, session);
        persistingSession = true;
      }
    }

    const sessionChanged = session !== beforeSession;
    if (sessionChanged || anonymous !== beforeAnonymous || user !== beforeUser) announce(sessionChanged && beforeSession !== null);
    return sessionChanged;
  }

  return {
    current: current,
    sync: sync,
    /** True while ids are written to the device. */
    persisted: function () { return persistingSession; },
    setUser: function (userId) {
      if (typeof userId === 'string' && /^[A-Za-z0-9_-]{8,80}$/.test(userId) && anonymous) user = userId;
      return user;
    },
    clearUser: function () { user = null; },
    /** Forget the signed-in user and start a new anonymous visitor and session (sign-out). */
    rotate: function () {
      memorySession = randomId('sess');
      session = memorySession;
      user = null;
      remove(win, 'session', REPLAY_KEY);
      if (persistSession()) { write(win, 'session', SESSION_KEY, session); persistingSession = true; }
      if (persistAnonymous()) { anonymous = randomId('anon'); write(win, 'local', ANON_KEY, anonymous); } else { anonymous = null; }
      announce(true);
    },
    keys: { ANON_KEY: ANON_KEY, SESSION_KEY: SESSION_KEY, REPLAY_KEY: REPLAY_KEY },
  };
}
