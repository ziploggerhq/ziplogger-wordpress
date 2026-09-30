// WooCommerce in the browser: product views, cart interactions, checkout start, and the identity cookie that
// lets the server attribute order and payment events to this visitor.
//
// Order, payment and refund events are NOT sent from here. They come from the server, from WooCommerce's own
// order lifecycle, because a browser can be closed, blocked, or faked, and a thank-you page proves nothing.
// What only the browser knows - who looked at which product, what went into the cart - is sent here, through
// the analytics module (so it obeys the same consent, sampling and cleaning).
//
// Nothing is read from the page beyond a product id (or SKU) and a quantity: no names, no prices, no cart
// contents beyond the change being made, no form fields.

import { guard } from './util.js';

const MAX_AGE = 60 * 60 * 24 * 7; // The identity cookie lives a week: long enough to finish an order.
const ID_RE = /^(anon_[0-9a-f]{20})?$/;
const SESS_RE = /^(sess_[0-9a-f]{20})?$/;

/**
 * @param {object} ctx { win, doc, cfg, analytics, consent, identity():{anonymousId,sessionId} }
 */
export function createCommerce(ctx) {
  const win = ctx.win;
  const doc = ctx.doc;
  const c = ctx.cfg.commerce || {};
  const page = ctx.cfg.page || {};
  const cookieName = c.cookie || 'ziplogger_v';
  const mode = c.productIdentifier || 'id';
  const recent = {};
  const cleanups = [];
  let started = false;

  function currency() { return /^[A-Z]{3}$/.test(c.currency || '') ? c.currency : undefined; }

  /** The product identifier the shop agreed to share, from a button's data attributes. */
  function identifierOf(el) {
    if (mode === 'none' || !el || typeof el.getAttribute !== 'function') return '';
    const raw = mode === 'sku' ? el.getAttribute('data-product_sku') : el.getAttribute('data-product_id');
    return /^[A-Za-z0-9_.-]{1,64}$/.test(raw || '') ? raw : '';
  }

  function quantityOf(el) {
    const raw = el && typeof el.getAttribute === 'function' ? el.getAttribute('data-quantity') : null;
    const n = parseInt(raw || '1', 10);
    return n > 0 && n < 1000 ? n : 1;
  }

  /** The same interaction reported by two paths (an AJAX add-to-cart and a form submit) counts once. */
  function fresh(key) {
    const now = Date.now();
    if (recent[key] && now - recent[key] < 1500) return false;
    recent[key] = now;
    return true;
  }

  // ---- the identity cookie ------------------------------------------------------------------------

  function writeCookie() {
    try {
      const id = ctx.identity();
      if (!ctx.consent.allows('analytics') || !id || !id.anonymousId) return removeCookie();
      if (!ID_RE.test(id.anonymousId) || !SESS_RE.test(id.sessionId || '')) return removeCookie();
      let cookie = cookieName + '=' + id.anonymousId + '.' + (id.sessionId || '') + '; Max-Age=' + MAX_AGE + '; Path=' + ((ctx.cfg.consent && ctx.cfg.consent.cookiePath) || '/') + '; SameSite=Lax';
      if (win.location.protocol === 'https:') cookie += '; Secure';
      doc.cookie = cookie;
    } catch (e) { /* cookies blocked: server events then use the order's own opaque reference */ }
    return undefined;
  }

  function removeCookie() {
    try {
      doc.cookie = cookieName + '=; Max-Age=0; Path=' + ((ctx.cfg.consent && ctx.cfg.consent.cookiePath) || '/') + '; SameSite=Lax';
    } catch (e) { /* ignore */ }
  }

  /** Called on cart and checkout pages and after a cart interaction: shoppers, not every visitor. */
  function shopping() { writeCookie(); }

  // ---- events -------------------------------------------------------------------------------------

  function trackProduct(name, id, quantity) {
    if (!c.cartEvents) return;
    const props = { quantity: quantity };
    if (id) props.productId = id;
    const cur = currency();
    if (cur) props.currency = cur;
    ctx.analytics.track(name, props);
  }

  function viewProduct() {
    if (!c.productViews) return;
    const p = page.commerce && page.commerce.product;
    if (!p || !p.id) return;
    const props = { productId: p.id };
    const cur = currency();
    if (cur) props.currency = cur;
    ctx.analytics.track('product_viewed', props);
  }

  function startCheckout() {
    if (!c.checkout || page.type !== 'checkout') return;
    ctx.analytics.track('checkout_started', {});
  }

  // ---- classic (jQuery) events ---------------------------------------------------------------------

  function bindClassic() {
    const $ = win.jQuery;
    if (!$ || !doc.body) return false;
    const body = $(doc.body);
    const added = guard(function (event, fragments, hash, $button) {
      const el = $button && $button[0];
      const id = identifierOf(el);
      if (fresh('add:' + id)) trackProduct('product_added_to_cart', id, quantityOf(el));
      shopping();
    });
    const removed = guard(function (event, fragments, hash, $button) {
      const el = $button && $button[0];
      const id = identifierOf(el);
      if (fresh('remove:' + id)) trackProduct('product_removed_from_cart', id, 1);
    });
    body.on('added_to_cart', added);
    body.on('removed_from_cart', removed);
    cleanups.push(function () { body.off('added_to_cart', added); body.off('removed_from_cart', removed); });
    return true;
  }

  /** A product page's own form (no AJAX): the click happens before the page reloads. */
  function bindSingleProductForm() {
    const onClick = guard(function (event) {
      const t = event.target && event.target.closest ? event.target.closest('button.single_add_to_cart_button, .single_add_to_cart_button') : null;
      if (!t || t.disabled || (t.classList && (t.classList.contains('disabled') || t.classList.contains('wc-variation-selection-needed')))) return;
      const form = t.closest('form.cart');
      if (!form) return;
      const holder = form.querySelector('[name="add-to-cart"]') || t;
      const raw = mode === 'sku' ? (page.commerce && page.commerce.product && page.commerce.product.id) : (holder.value || (holder.getAttribute && holder.getAttribute('value')));
      const id = mode !== 'none' && /^[A-Za-z0-9_.-]{1,64}$/.test(raw || '') ? raw : '';
      const qtyInput = form.querySelector('input.qty');
      const n = parseInt((qtyInput && qtyInput.value) || '1', 10);
      if (fresh('add:' + id)) trackProduct('product_added_to_cart', id, n > 0 && n < 1000 ? n : 1);
      shopping();
    });
    doc.addEventListener('click', onClick, true);
    cleanups.push(function () { doc.removeEventListener('click', onClick, true); });
  }

  // ---- block cart (the wc/store/cart data store) ---------------------------------------------------

  function bindBlocks() {
    const data = win.wp && win.wp.data;
    if (!data || typeof data.select !== 'function' || typeof data.subscribe !== 'function') return false;
    let store = null;
    try { store = data.select('wc/store/cart'); } catch (e) { store = null; }
    if (!store || typeof store.getCartData !== 'function') return false;

    let baseline = null;
    const snapshot = function () {
      const items = (store.getCartData() || {}).items || [];
      const map = {};
      items.forEach(function (it) {
        if (it && it.key) map[it.key] = { id: it.id, qty: parseInt(it.quantity, 10) || 0, sku: it.sku };
      });
      return map;
    };
    const unsubscribe = data.subscribe(guard(function () {
      const resolved = typeof store.hasFinishedResolution === 'function' ? store.hasFinishedResolution('getCartData') : true;
      if (!resolved) return;
      const now = snapshot();
      if (baseline === null) { baseline = now; return; } // What was already in the cart is not an event.
      Object.keys(now).forEach(function (k) {
        const before = baseline[k] ? baseline[k].qty : 0;
        const delta = now[k].qty - before;
        const id = mode === 'none' ? '' : (mode === 'sku' ? String(now[k].sku || '') : String(now[k].id || ''));
        if (delta > 0) { trackProduct('product_added_to_cart', /^[A-Za-z0-9_.-]{1,64}$/.test(id) ? id : '', delta); shopping(); }
        else if (delta < 0) trackProduct('product_removed_from_cart', /^[A-Za-z0-9_.-]{1,64}$/.test(id) ? id : '', -delta);
      });
      Object.keys(baseline).forEach(function (k) {
        if (!now[k]) {
          const id = mode === 'none' ? '' : (mode === 'sku' ? String(baseline[k].sku || '') : String(baseline[k].id || ''));
          trackProduct('product_removed_from_cart', /^[A-Za-z0-9_.-]{1,64}$/.test(id) ? id : '', baseline[k].qty);
        }
      });
      baseline = now;
    }));
    cleanups.push(function () { if (typeof unsubscribe === 'function') unsubscribe(); });
    return true;
  }

  /** Try now, and again a few times: WooCommerce's scripts may load after this one. */
  function bindWhenAvailable() {
    let classic = false;
    let blocks = false;
    const attempt = guard(function () {
      if (!classic) classic = bindClassic();
      if (!blocks) blocks = bindBlocks();
    });
    attempt();
    [400, 1500, 4000].forEach(function (ms) { win.setTimeout(attempt, ms); });
    win.addEventListener('load', attempt, { once: true });
  }

  return {
    /** Start listening, and report what is already true of this page (once consent allows). */
    start: function () {
      if (!started) {
        started = true;
        bindSingleProductForm();
        bindWhenAvailable();
      }
      this.page();
    },
    /** Page-level events: the product being viewed, the checkout being started. */
    page: function () {
      if (['cart', 'checkout', 'order_pay'].indexOf(page.type) !== -1) shopping();
      viewProduct();
      startCheckout();
    },
    /** Consent or identity changed: keep the cookie true to it. */
    sync: function () {
      if (['cart', 'checkout', 'order_pay'].indexOf(page.type) !== -1 || doc.cookie.indexOf(cookieName + '=') !== -1) writeCookie();
    },
    stop: function () { cleanups.splice(0).forEach(function (fn) { fn(); }); removeCookie(); },
    _write: writeCookie,
  };
}
