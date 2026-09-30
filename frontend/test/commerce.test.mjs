import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

setupDom();
const { createConsent } = await import('../src/consent.js');
const { createIdentity } = await import('../src/identity.js');
const { createCommerce } = await import('../src/commerce.js');

/** A jQuery stand-in: enough of .on/.off/trigger and [0] for WooCommerce's events. */
function fakeJQuery(win) {
  const handlers = {};
  const $ = (target) => ({
    0: target,
    on(name, fn) { (handlers[name] = handlers[name] || []).push(fn); return this; },
    off(name, fn) { handlers[name] = (handlers[name] || []).filter((f) => f !== fn); return this; },
  });
  $.trigger = (name, ...args) => (handlers[name] || []).slice().forEach((fn) => fn({ type: name }, ...args));
  win.jQuery = $;
  return $;
}

function setup({ page = {}, commerce = {}, html = '<body></body>', allowAnalytics = true } = {}) {
  const env = setupDom({ url: 'https://shop.example.test/', html });
  const cfg = baseConfig({
    modules: { analytics: true, commerce: true },
    commerce: { currency: 'USD', productViews: true, cartEvents: true, checkout: true, cookie: 'ziplogger_v', productIdentifier: 'id', ...commerce },
    page: { type: 'page', replay: true, sensitiveEndpoints: [], commerce: {}, ...page },
  });
  const consent = createConsent(cfg.consent, { win: env.win, doc: env.doc });
  const identity = createIdentity({ win: env.win }, consent, () => {});
  if (allowAnalytics) consent.grant('analytics');
  identity.sync();
  const calls = [];
  const analytics = { track: (name, props) => { if (!consent.allows('analytics')) return false; calls.push({ name, props }); return true; } };
  const c = createCommerce({ win: env.win, doc: env.doc, cfg, analytics, consent, identity: identity.current });
  return { env, cfg, consent, identity, calls, c };
}

const cookieValue = (env) => (env.doc.cookie.match(/ziplogger_v=([^;]*)/) || [])[1];

test('a product page reports the view once, with the product id and currency and nothing else', () => {
  const { c, calls } = setup({ page: { type: 'product', commerce: { product: { id: '123' } } } });
  c.start();
  assert.deepEqual(calls, [{ name: 'product_viewed', props: { productId: '123', currency: 'USD' } }]);
});

test('nothing is reported before consent, and the view appears once consent is given', () => {
  const s = setup({ page: { type: 'product', commerce: { product: { id: '123' } } }, allowAnalytics: false });
  s.c.start();
  assert.deepEqual(s.calls, []);
  assert.equal(cookieValue(s.env), undefined);
  s.consent.grant('analytics');
  s.identity.sync();
  s.c.page();
  assert.equal(s.calls.length, 1);
});

test('checkout_started only on the checkout page', () => {
  const on = setup({ page: { type: 'checkout' } });
  on.c.start();
  assert.deepEqual(on.calls.map((x) => x.name), ['checkout_started']);
  const off = setup({ page: { type: 'checkout' }, commerce: { checkout: false } });
  off.c.start();
  assert.deepEqual(off.calls, []);
  const other = setup({ page: { type: 'cart' } });
  other.c.start();
  assert.deepEqual(other.calls, []);
});

test('the identity cookie is written for shoppers only, in the exact shape the server accepts', () => {
  const home = setup();
  home.c.start();
  assert.equal(cookieValue(home.env), undefined, 'not on an ordinary page');

  for (const type of ['cart', 'checkout', 'order_pay']) {
    const s = setup({ page: { type } });
    s.c.start();
    const v = cookieValue(s.env);
    assert.match(v, /^anon_[0-9a-f]{20}\.sess_[0-9a-f]{20}$/, type);
    assert.equal(v, `${s.identity.current().anonymousId}.${s.identity.current().sessionId}`);
  }
});

test('the cookie is removed when analytics consent is withdrawn and never holds anything but the two random ids', () => {
  const s = setup({ page: { type: 'cart' } });
  s.c.start();
  assert.ok(cookieValue(s.env));
  s.consent.revoke('analytics');
  s.identity.sync();
  s.c.sync();
  assert.equal(cookieValue(s.env), undefined);
});

test('a classic AJAX add-to-cart is reported with the product id and quantity, once, and writes the cookie', () => {
  const { env, c, calls } = setup({ html: '<body><a id="btn" data-product_id="55" data-product_sku="SKU-55" data-quantity="3">Add</a></body>' });
  const $ = fakeJQuery(env.win);
  c.start();
  const btn = env.doc.getElementById('btn');
  $.trigger('added_to_cart', {}, 'hash', $(btn));
  $.trigger('added_to_cart', {}, 'hash', $(btn)); // the same interaction reported twice within a moment
  assert.deepEqual(calls, [{ name: 'product_added_to_cart', props: { quantity: 3, productId: '55', currency: 'USD' } }]);
  assert.ok(cookieValue(env), 'shopping has started: the server can now attribute the order');
});

test('with the SKU identifier the SKU is used, and with none no product id is sent', () => {
  const sku = setup({ commerce: { productIdentifier: 'sku' }, html: '<body><a id="btn" data-product_id="55" data-product_sku="SKU-55">Add</a></body>' });
  const $ = fakeJQuery(sku.env.win);
  sku.c.start();
  $.trigger('added_to_cart', {}, 'h', $(sku.env.doc.getElementById('btn')));
  assert.equal(sku.calls[0].props.productId, 'SKU-55');

  const none = setup({ commerce: { productIdentifier: 'none' }, html: '<body><a id="btn" data-product_id="55" data-product_sku="SKU-55">Add</a></body>' });
  const $2 = fakeJQuery(none.env.win);
  none.c.start();
  $2.trigger('added_to_cart', {}, 'h', $2(none.env.doc.getElementById('btn')));
  assert.ok(!('productId' in none.calls[0].props), 'the shop chose not to share product identifiers');
});

test('removing from a classic cart is reported', () => {
  const { env, c, calls } = setup({ html: '<body><a id="rm" data-product_id="55">x</a></body>' });
  const $ = fakeJQuery(env.win);
  c.start();
  $.trigger('removed_from_cart', {}, 'h', $(env.doc.getElementById('rm')));
  assert.deepEqual(calls.map((x) => x.name), ['product_removed_from_cart']);
});

test('the product page form (no AJAX) reports the add at click time, but not a disabled button', () => {
  const html = '<body><form class="cart"><input class="qty" value="2"><button type="submit" name="add-to-cart" value="77" class="single_add_to_cart_button">Add</button></form>'
    + '<form class="cart"><button type="submit" name="add-to-cart" value="88" class="single_add_to_cart_button disabled wc-variation-selection-needed">Add</button></form></body>';
  const { env, c, calls } = setup({ html });
  c.start();
  const [first, second] = env.doc.querySelectorAll('.single_add_to_cart_button');
  first.addEventListener('click', (e) => e.preventDefault());
  second.addEventListener('click', (e) => e.preventDefault());
  first.click();
  second.click();
  assert.deepEqual(calls, [{ name: 'product_added_to_cart', props: { quantity: 2, productId: '77', currency: 'USD' } }]);
});

test('cart events can be switched off; product views too', () => {
  const s = setup({ commerce: { cartEvents: false, productViews: false }, page: { type: 'product', commerce: { product: { id: '1' } } }, html: '<body><a id="b" data-product_id="9">x</a></body>' });
  const $ = fakeJQuery(s.env.win);
  s.c.start();
  $.trigger('added_to_cart', {}, 'h', $(s.env.doc.getElementById('b')));
  assert.deepEqual(s.calls, []);
});

test('no cart event without consent', () => {
  const s = setup({ allowAnalytics: false, html: '<body><a id="b" data-product_id="9">x</a></body>' });
  const $ = fakeJQuery(s.env.win);
  s.c.start();
  $.trigger('added_to_cart', {}, 'h', $(s.env.doc.getElementById('b')));
  assert.deepEqual(s.calls, []);
  assert.equal(cookieValue(s.env), undefined);
});

// ---- the block cart --------------------------------------------------------------------------

function fakeStore(win, initial) {
  const state = { items: initial, resolved: true };
  const subs = [];
  win.wp = {
    data: {
      select: (name) => (name === 'wc/store/cart' ? { getCartData: () => ({ items: state.items }), hasFinishedResolution: () => state.resolved } : undefined),
      subscribe: (fn) => { subs.push(fn); return () => subs.splice(subs.indexOf(fn), 1); },
    },
  };
  return { set(items) { state.items = items; subs.slice().forEach((fn) => fn()); }, resolve() { state.resolved = true; subs.slice().forEach((fn) => fn()); }, unresolve() { state.resolved = false; }, state };
}

test('block cart: what was already in the cart is not an event; added, increased and removed items are', () => {
  const s = setup();
  const store = fakeStore(s.env.win, [{ key: 'k1', id: 10, quantity: 1 }]);
  s.c.start();
  store.set([{ key: 'k1', id: 10, quantity: 1 }]); // baseline
  assert.deepEqual(s.calls, []);
  store.set([{ key: 'k1', id: 10, quantity: 3 }, { key: 'k2', id: 20, quantity: 1 }]);
  assert.deepEqual(s.calls.map((x) => [x.name, x.props.productId, x.props.quantity]), [['product_added_to_cart', '10', 2], ['product_added_to_cart', '20', 1]]);
  s.calls.length = 0;
  store.set([{ key: 'k1', id: 10, quantity: 1 }]);
  assert.deepEqual(s.calls.map((x) => [x.name, x.props.productId, x.props.quantity]), [['product_removed_from_cart', '10', 2], ['product_removed_from_cart', '20', 1]]);
});

test('block cart: nothing is reported while the store is still loading', () => {
  const s = setup();
  const store = fakeStore(s.env.win, []);
  store.state.resolved = false;
  s.c.start();
  store.set([{ key: 'k1', id: 10, quantity: 5 }]);
  assert.deepEqual(s.calls, [], 'unresolved data is not a cart change');
});

test('a broken store or missing jQuery never throws into the page', () => {
  const s = setup();
  s.env.win.wp = { data: { select: () => { throw new Error('boom'); }, subscribe() { throw new Error('boom'); } } };
  assert.doesNotThrow(() => s.c.start());
  const t = setup();
  t.env.win.jQuery = () => { throw new Error('boom'); };
  assert.doesNotThrow(() => t.c.start());
});
