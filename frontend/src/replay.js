// Session replay: when it may run, and what it may not record.
//
// The recording itself is the official ZipLogger replay module (which drives rrweb). This file decides:
//
//   WHETHER   replay is enabled, the visitor consented, the page and the visitor's role are not excluded,
//             and the session was sampled in. Any doubt means no recording (fail closed).
//   WHAT      text and inputs are masked by default; payment and password fields, address blocks and
//             payment frames are always masked or blocked, whatever the settings say.
//   WHEN      recording starts only after consent; it never reconstructs what happened before. It stops
//             the instant consent is withdrawn or the visitor navigates to an excluded page, and whatever
//             is still unsent is discarded.
//
// What the SDK cannot do, and this file therefore cannot promise: recall a chunk that was already sent,
// or record cross-origin iframes (browsers forbid it; payment frames are cross-origin).

import { attachSessionReplay, sampledIn as replaySampledIn } from '@ziplogger/browser/replay';
import { safePath, pathMatches, guard } from './util.js';

/** Paths that are never recorded, on any site, whatever the settings say. */
export const MANDATORY_EXCLUDED_PATHS = [
  '/wp-admin', '/wp-login.php', '/wp-signup.php', '/wp-activate.php', '/wp-json/*', '/xmlrpc.php',
  '/login', '/log-in', '/signin', '/sign-in', '/register', '/registration', '/lost-password', '/reset-password', '/password-reset',
  '/my-account', '/account', '/checkout', '/cart', '/order-pay', '/order-received', '/pay',
];

/** Never recorded at all (the subtree is replaced by an empty box). Always applied. */
export const MANDATORY_BLOCK = [
  'input[type="password"]',
  '[autocomplete^="cc-"]',
  '[autocomplete="one-time-code"]',
  'iframe[src*="stripe"]', 'iframe[src*="paypal"]', 'iframe[src*="braintree"]', 'iframe[src*="adyen"]', 'iframe[src*="square"]', 'iframe[src*="klarna"]',
  'iframe[name^="__privateStripe"]',
  '.StripeElement', '.paypal-buttons', '#payment', '.woocommerce-checkout-payment', '.wc-block-components-payment-method-icons',
];

/** Text always masked, even when "mask all text" is switched off. */
export const MANDATORY_MASK = [
  'address', '.woocommerce-customer-details', '.woocommerce-billing-fields', '.woocommerce-shipping-fields', '.woocommerce-address-fields',
  '.woocommerce-MyAccount-content', '.woocommerce-order-details', '[autocomplete]',
];

/**
 * Rewrites every URL a recording carries: the query string and fragment go, and so does the address in
 * mailto: and tel: links. Text masking does not touch attributes, and a URL is an attribute.
 */
export function scrubReplayUrl(value) {
  const v = String(value);
  if (/^\s*(?:mailto|tel|sms):/i.test(v)) return v.replace(/:.*$/, ':[redacted]');
  if (/^\s*javascript:/i.test(v)) return '';
  if (/^\s*(?:data|blob):/i.test(v)) return v; // inline resource (image), no address inside
  if (!/\s/.test(v.trim())) return v.split(/[?#]/)[0];
  // srcset: "a.png?x=1 1x, b.png?x=2 2x" - strip each candidate, keep the descriptors.
  return v.replace(/\S+/g, function (token) {
    if (/^\d+(?:\.\d+)?[wx],?$/.test(token)) return token;
    const comma = token.charAt(token.length - 1) === ',' ? ',' : '';
    return (comma ? token.slice(0, -1) : token).split(/[?#]/)[0] + comma;
  });
}

/** The options handed to the SDK client (recording is started by this file, never by the SDK). */
export function replayOptions(cfg) {
  const r = cfg.replay || {};
  return {
    enabled: false,
    sampleRate: Math.max(0, Math.min(1, (Number(r.sampleRate) || 0) / 100)),
    maskInputs: true,
    maskAllText: r.maskAllText !== false,
    maskSelector: MANDATORY_MASK.concat(r.maskSelector || []).join(','),
    blockSelector: MANDATORY_BLOCK.concat(r.blockSelector || []).join(','),
    scrubUrl: scrubReplayUrl,
    recordCanvas: false,
    maxSessionSeconds: Math.max(60, (Number(r.maxMinutes) || 30) * 60),
    maxSessionBytes: Math.max(1, Number(r.maxMegabytes) || 20) * 1000000,
  };
}

/**
 * @param {object} ctx { win, doc, cfg, client, consent, identity, navigation, ownFetch, loadRecorder,
 *                       context():Promise<{loggedIn,replayAllowed}>, hint():boolean, onState(state,reason) }
 */
export function createReplay(ctx) {
  const r = ctx.cfg.replay || {};
  const win = ctx.win;
  const excluded = MANDATORY_EXCLUDED_PATHS.concat(r.excludePaths || []);
  let controller = null;
  let discarding = false;
  let running = false;
  let generation = 0; // bumps on every stop, so a start that was in flight when consent was withdrawn is dropped
  let state = 'idle';
  let reason = '';
  let contextPromise = null;
  let contextHint = null;

  function set(next, why) {
    state = next;
    reason = why || '';
    if (ctx.onState) { try { ctx.onState(state, reason); } catch (e) { /* ignore */ } }
  }

  function pathExcluded() {
    const path = safePath(win.location.href);
    for (let i = 0; i < excluded.length; i++) if (pathMatches(excluded[i], path)) return true;
    return false;
  }

  function ensureController() {
    if (controller) return controller;
    controller = attachSessionReplay(ctx.client, {
      loadRecorder: ctx.loadRecorder,
      // Every request the recorder makes goes through here. While `discarding` nothing leaves the page.
      fetch: function (url, init) {
        if (discarding) return Promise.resolve(new win.Response(null, { status: 400 }));
        return ctx.ownFetch(url, init);
      },
    });
    return controller;
  }

  function context() {
    const hint = ctx.hint();
    if (contextPromise && contextHint === hint) return contextPromise;
    contextHint = hint;
    contextPromise = ctx.context().catch(function () { return null; });
    return contextPromise;
  }

  /** Stop recording. With `discard`, whatever has not been sent yet is thrown away instead of flushed. */
  function halt(why, discard) {
    generation++;
    if (discard) discarding = true;
    running = false;
    if (controller) {
      try { controller.stop(); } catch (e) { /* ignore */ }
    }
    if (discard) {
      // The SDK records { session, sampling decision, next sequence } in sessionStorage as it stops.
      // After a withdrawal nothing of the recording may stay on the device.
      try { win.sessionStorage.removeItem('zl_replay'); } catch (e) { /* storage blocked */ }
    }
    set('stopped', why);
  }

  /** Re-check every condition and start or stop accordingly. Safe to call as often as needed. */
  const evaluate = guard(function () {
    if (!ctx.cfg.modules || !ctx.cfg.modules.replay) return;

    if (!ctx.consent.allows('replay')) {
      if (running || state === 'starting') halt('consent', true);
      else set('idle', 'consent');
      return;
    }
    if (ctx.cfg.page && ctx.cfg.page.replay === false) { if (running) halt('page', false); else set('idle', 'page'); return; }
    if (pathExcluded()) { if (running) halt('path', false); else set('idle', 'path'); return; }

    if (running || state === 'starting') return;

    ctx.identity.sync();
    const session = ctx.identity.current().sessionId;
    const rate = Math.max(0, Math.min(1, (Number(r.sampleRate) || 0) / 100));
    if (!session || !replaySampledIn(session, rate)) { set('idle', 'not_sampled'); return; }

    const mine = generation;
    set('starting', '');
    context().then(function (c) {
      if (mine !== generation) return null; // stopped while we were asking
      if (!c) { set('idle', 'context_unavailable'); return null; }
      if (c.replayAllowed !== true) { set('idle', 'role'); return null; }
      if (!ctx.consent.allows('replay') || pathExcluded()) { set('idle', 'consent'); return null; }
      discarding = false;
      running = true;
      return ensureController().start().then(function () {
        if (mine !== generation) return;
        const c2 = controller;
        if (c2 && c2.isRecording && c2.isRecording()) set('recording', '');
        else { running = false; set('idle', (c2 && c2.lastReason) || 'not_started'); }
      });
    }).catch(function () { running = false; set('idle', 'error'); });
  });

  // Leaving for an excluded page, or a change of who is logged in, must be noticed at once.
  ctx.navigation.subscribe(function () { evaluate(); });
  win.document.addEventListener('visibilitychange', function () { if (win.document.visibilityState === 'visible') evaluate(); });

  return {
    evaluate: evaluate,
    /** Consent for replay was withdrawn: stop now and discard what is unsent. */
    withdraw: function () { halt('consent', true); },
    /** The session id changed (consent for analytics withdrawn, sign-out): end this recording, maybe start a new one. */
    restart: function () { if (running || state === 'starting') halt('session_changed', false); discarding = false; evaluate(); },
    /** A logged-in state change: the role check must be asked again. */
    invalidateContext: function () { contextPromise = null; contextHint = null; },
    state: function () { return { state: state, reason: reason, dropped: controller ? controller.dropped : 0, recording: !!(controller && controller.isRecording && controller.isRecording()) }; },
  };
}
