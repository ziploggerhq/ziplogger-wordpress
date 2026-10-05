// End-to-end: WooCommerce, in real browsers, against a real WooCommerce (classic AND block checkout, HPOS off
// AND on), with a test gateway and simulated provider webhooks.
//
//   docker compose -f docker-compose.e2e.yml up -d
//   node --test tests/e2e/m5-commerce.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { KEYS, wp, wpEval, mock, sleep, waitFor, setSettings, installZip, setKeys, runBrowser, setupWoo, useCheckout, setHpos } from './lib.mjs';

const ENDPOINT = 'http://mock:5081';
const WEBHOOK = 'http://localhost:8088/wp-json/zl-e2e/v1/gateway-webhook';
let ids;

const configure = (woo = {}, extra = {}) => setSettings({
  enabled: true, endpoint: ENDPOINT, source: 'e2e-site', min_severity: 'warn', collectors: { php_errors: true },
  browser: { enabled: true }, analytics: { enabled: true, page_views: true },
  woocommerce: { enabled: true, ...woo },
  ...extra,
});

const flush = () => wp(['ziplogger', 'flush'], { allowFail: true });
const parseEvents = (entry) => (entry.body.trim().startsWith('[') ? JSON.parse(entry.body) : entry.body.split('\n').filter(Boolean).map((l) => JSON.parse(l)));
const allEvents = async () => (await mock.received('/ingest/v1/events')).flatMap((e) => parseEvents(e).map((ev) => ({ ...ev, _key: e.headers['x-api-key'] })));
const serverEvents = async () => (await allEvents()).filter((e) => e._key === KEYS.server);
const browserEvents = async () => (await allEvents()).filter((e) => e._key === KEYS.browser);
const named = (list, name) => list.filter((e) => e.name === name);
const orderRef = (id) => wpEval(`echo \\ZipLogger\\WordPress\\Secrets::pseudonym('ord', ${Number(id)});`);
const lastOrderId = () => wpEval("$o = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'return' => 'ids' ) ); echo $o ? $o[0] : 0;");

async function settle(ms = 1500) {
  await sleep(ms);
  flush();
  await sleep(1200);
}

async function drain() {
  await settle(600);
  await mock.clear();
}

async function shop(args) {
  await drain();
  const r = runBrowser('placeOrder', { product: ids.product, ...args }); // the product's id is whatever this site gave it
  await settle(1000);
  return r;
}

const webhook = (body) => fetch(WEBHOOK, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) }).then((r) => r.json());

/** Every string a shopper typed, and the order key: none of it may ever appear in what leaves the site. */
// The phone number is checked in the forms it could appear in, not as short digit runs (which occur by chance inside hashes).
const PII = ['Jane', 'Customer', 'jane.customer', 'customer.example', '555 010 0177', '5550100177', '555-010-0177', '221B', 'Baker', 'San Francisco', '94103', 'wc_order_'];
function assertNoPersonalData(events, extra = []) {
  const raw = JSON.stringify(events);
  for (const leak of [...PII, ...extra]) assert.ok(!raw.includes(leak), `personal data leaked into the events: ${leak}`);
}

test('the stack is up, the ZIP is installed and WooCommerce is set up', async () => {
  await waitFor(async () => (await fetch('http://localhost:5081/__stats')).ok, { what: 'receiver' });
  await waitFor(async () => (await fetch('http://localhost:8088/')).status < 500, { what: 'WordPress', timeout: 120000 });
  if (wp(['core', 'is-installed'], { allowFail: true }).status !== 0) {
    wp(['core', 'install', '--url=http://wordpress', '--title=ZipLogger E2E', '--admin_user=admin', '--admin_password=e2e-admin-pass-1', '--admin_email=admin@example.org', '--skip-email']);
  }
  installZip();
  setKeys();
  ids = JSON.parse(setupWoo());
  assert.ok(ids.blocksCheckout > 0 && ids.blocksCart > 0, 'WooCommerce installed its block cart and checkout pages');
  setHpos(false);
  wp(['option', 'update', 'zl_e2e_gateway_outcome', 'success']);
  wp(['rewrite', 'structure', '/%postname%/', '--hard'], { allowFail: true });
});

test('WooCommerce lists the plugin as compatible with HPOS and the block checkout', async () => {
  const out = JSON.parse(wpEval(`
    $c = wc_get_container()->get( \\Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::class );
    echo wp_json_encode( $c->get_compatible_features_for_plugin( 'ziplogger-error-monitoring-session-replay/ziplogger.php' ) );
  `));
  assert.ok(out.compatible.includes('custom_order_tables'), JSON.stringify(out));
  assert.ok(out.compatible.includes('cart_checkout_blocks'));
  assert.ok(!out.incompatible.includes('custom_order_tables'));
});

test('with the WooCommerce module off, a complete order sends no order events at all', async () => {
  useCheckout('classic', ids);
  configure({ enabled: false });
  await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'form' });
  const names = (await allEvents()).map((e) => e.name);
  assert.ok(!names.some((n) => /^order_|^payment_|^product_|^checkout_/.test(n)), 'no commerce events of any kind: ' + names.join(','));
  assert.ok(names.includes('page_view'), 'ordinary analytics still work');
});

test('CLASSIC checkout, consent given: browser and server events describe ONE shopper, with exact amounts and no personal data', async () => {
  useCheckout('classic', ids);
  configure();
  const r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'form' });
  assert.equal(r.outcome, 'placed', r.notice);
  const ref = orderRef(r.orderId);
  const anon = r.steps.afterOrder.local.zl_anon;
  const sess = r.steps.afterOrder.session.zl_sess;
  assert.match(anon, /^anon_/);

  const b = await browserEvents();
  const s = await serverEvents();
  assert.deepEqual(named(b, 'product_viewed').map((e) => e.properties.productId), [String(ids.product), String(ids.product)], 'the product page loaded twice (add-to-cart form reload)');
  assert.equal(named(b, 'product_added_to_cart').length, 1);
  assert.deepEqual([named(b, 'product_added_to_cart')[0].properties.productId, named(b, 'product_added_to_cart')[0].properties.quantity, named(b, 'product_added_to_cart')[0].properties.currency], [String(ids.product), 1, 'USD']);
  assert.equal(named(b, 'checkout_started').length, 1);
  assert.ok(b.every((e) => e.anonymousId === anon && e.sessionId === sess), 'every browser event is one visitor');

  assert.deepEqual(s.map((e) => e.name), ['order_created', 'order_status_changed', 'payment_completed']);
  for (const e of s) {
    assert.equal(e.anonymousId, anon, `${e.name}: the server event belongs to the browsing visitor`);
    assert.equal(e.sessionId, sess);
    assert.equal(e.properties.orderRef, ref);
    assert.equal(e.properties.origin, 'server');
  }
  const created = s[0].properties;
  assert.deepEqual([created.currency, created.value, created.valueMinor, created.itemCount, created.productIds, created.quantities, created.paymentMethod, created.createdVia, created.status, created.isGuest], ['USD', 19.99, 1999, 1, [String(ids.product)], [1], 'zl_e2e', 'checkout', 'pending', true]);
  assert.equal(s[2].properties.confirmedBy, 'gateway');
  assert.equal(s[2].properties.valueMinor, 1999);
  assert.equal(new Set(s.map((e) => e.insertId)).size, s.length, 'every event has its own id');
  assert.deepEqual(s.map((e) => e.insertId), [`oc:${ref}`, `st:${ref}:1`, `pc:${ref}`]);

  assertNoPersonalData([...b, ...s]);
  assert.ok(!JSON.stringify(s).includes(`"${r.orderId}"`) && !new RegExp(`[:_]${r.orderId}[:_"]`).test(JSON.stringify(s)), 'the order number is not disclosed');
  const entries = await mock.received('/ingest/v1/events');
  assert.ok(entries.filter((e) => e.headers['x-api-key'] === KEYS.server).every((e) => e.body.startsWith('[')), 'server events are one JSON array per request');
  assert.ok(r.steps.afterOrder.cookies.includes('ziplogger_v'));
  assert.equal(r.steps.afterProductPage.cookies.includes('ziplogger_v'), false, 'no identity cookie on an ordinary product page before shopping starts');
});

test('CLASSIC checkout, NO consent: the order is still reported (operational data), but nothing links it to the visitor', async () => {
  useCheckout('classic', ids);
  configure();
  const r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: [], addVia: 'url' });
  assert.equal(r.outcome, 'placed');
  const ref = orderRef(r.orderId);
  assert.deepEqual(await browserEvents(), [], 'no browser event at all without consent');
  assert.equal(r.steps.afterOrder.cookies.includes('ziplogger_v'), false);
  assert.deepEqual(r.steps.afterOrder.local, {});
  const s = await serverEvents();
  assert.deepEqual(s.map((e) => e.name), ['order_created', 'order_status_changed', 'payment_completed']);
  for (const e of s) {
    assert.equal(e.anonymousId, ref, 'the only identity is the order\'s own opaque reference');
    assert.equal(e.sessionId, undefined);
    assert.equal(e.userId, undefined);
  }
  assertNoPersonalData(s);
});

test('Do Not Track: browser analytics stay off and the order is not linked to the browser', async () => {
  useCheckout('classic', ids);
  configure();
  const r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'url', dnt: true });
  assert.equal(r.outcome, 'placed');
  assert.deepEqual(await browserEvents(), []);
  const s = await serverEvents();
  assert.ok(s.every((e) => /^ord_/.test(e.anonymousId) && e.sessionId === undefined));
});

test('when the plugin script is blocked (ad blocker) the server still reports the order, unlinked', async () => {
  useCheckout('classic', ids);
  configure();
  const r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: [], addVia: 'url', blockScript: true });
  assert.equal(r.outcome, 'placed');
  assert.deepEqual(await browserEvents(), []);
  const s = await serverEvents();
  assert.deepEqual(s.map((e) => e.name), ['order_created', 'order_status_changed', 'payment_completed']);
  assert.ok(s.every((e) => e.anonymousId === orderRef(r.orderId)));
});

test('BLOCK checkout, bank transfer: created on hold, NOT paid; a thank-you page reload proves nothing; the provider webhook confirms once', async () => {
  useCheckout('blocks', ids);
  configure();
  const r = await shop({ checkout: 'blocks', gateway: 'bacs', consent: ['analytics'], addVia: 'url', reloadThankYou: true });
  assert.equal(r.outcome, 'placed', r.notice);
  await settle();
  const ref = orderRef(r.orderId);
  const anon = r.steps.afterOrder.local.zl_anon;
  assert.match(r.steps.afterOrder.cookies.join(','), /ziplogger_v/, 'the block checkout also leaves the identity cookie');

  let s = await serverEvents();
  assert.deepEqual(s.map((e) => e.name), ['order_created', 'order_status_changed'], 'awaiting payment: created, then put on hold; not paid: ' + s.map((e) => e.name).join(','));
  assert.equal(s[0].properties.status, 'pending', 'the first real status of a block-checkout order');
  assert.deepEqual([s[1].properties.from, s[1].properties.to], ['pending', 'on-hold']);
  assert.equal(s[0].properties.createdVia, 'store-api', 'the block checkout is recognised');
  assert.equal(s[0].anonymousId, anon, 'linked through the Store API request\'s cookies');
  assert.equal(named(await browserEvents(), 'checkout_started').length, 1);

  // The provider's webhook: no cookies, no browser. Delivered twice.
  const first = await webhook({ order_id: Number(r.orderId), outcome: 'paid' });
  const second = await webhook({ order_id: Number(r.orderId), outcome: 'paid' });
  assert.equal(first.status, 'processing');
  assert.equal(second.ok, true);
  await settle();
  s = await serverEvents();
  assert.equal(named(s, 'payment_completed').length, 1, 'a duplicate webhook is one payment');
  const paid = named(s, 'payment_completed')[0];
  assert.equal(paid.anonymousId, anon, 'the webhook is still attributed to the shopper (identity was stored with the order)');
  assert.equal(paid.sessionId, r.steps.afterOrder.session.zl_sess);
  assert.equal(paid.properties.confirmedBy, 'gateway', 'the provider webhook calls payment_complete(): the gateway is the witness');
  assert.deepEqual(s.map((e) => e.name), ['order_created', 'order_status_changed', 'order_status_changed', 'payment_completed']);
  assertNoPersonalData(s);

  // Partial refunds, then one that completes it.
  await webhook({ order_id: Number(r.orderId), outcome: 'refund', amount: '5.00' });
  await webhook({ order_id: Number(r.orderId), outcome: 'refund', amount: '4.99' });
  await settle();
  const refunds = named(await serverEvents(), 'order_refunded');
  assert.equal(refunds.length, 2, 'each partial refund counts');
  assert.deepEqual(refunds.map((e) => e.properties.refundValueMinor), [500, 499]);
  assert.deepEqual(refunds.map((e) => e.properties.refundedValueMinor), [500, 999]);
  assert.deepEqual(refunds.map((e) => e.properties.fullyRefunded), [false, false]);
  assert.notEqual(refunds[0].insertId, refunds[1].insertId);
  assert.ok(refunds.every((e) => !JSON.stringify(e).includes('webhook refund')), 'refund reasons are never sent');
});

test('CLASSIC checkout, the gateway declines: a failure is reported, no payment; a retry that fails again is a second failure', async () => {
  useCheckout('classic', ids);
  configure();
  wp(['option', 'update', 'zl_e2e_gateway_outcome', 'failure']);
  const r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'url' });
  wp(['option', 'update', 'zl_e2e_gateway_outcome', 'success']);
  assert.equal(r.outcome, 'stayed');
  assert.match(r.notice, /declined/i);
  const orderId = Number(lastOrderId());
  await settle();
  let s = await serverEvents();
  assert.deepEqual(s.map((e) => e.name), ['order_created', 'payment_failed', 'order_status_changed'].sort((a, b) => s.map((e) => e.name).indexOf(a) - s.map((e) => e.name).indexOf(b)));
  assert.equal(named(s, 'payment_failed')[0].properties.failureNo, 1);
  assert.equal(named(s, 'payment_completed').length, 0);
  assert.equal(named(s, 'payment_failed')[0].properties.paymentMethod, 'zl_e2e');

  await webhook({ order_id: orderId, outcome: 'status', to: 'pending' });
  await webhook({ order_id: orderId, outcome: 'failed' });
  await webhook({ order_id: orderId, outcome: 'failed' }); // Same failure reported again: not a new failure.
  await settle();
  s = await serverEvents();
  const fails = named(s, 'payment_failed');
  assert.deepEqual(fails.map((e) => e.properties.failureNo), [1, 2]);
  assert.equal(new Set(fails.map((e) => e.insertId)).size, 2);
});

test('CLASSIC checkout, delayed payment: on hold, then confirmed later by the provider', async () => {
  useCheckout('classic', ids);
  configure();
  wp(['option', 'update', 'zl_e2e_gateway_outcome', 'pending']);
  const r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'url', reloadThankYou: true });
  wp(['option', 'update', 'zl_e2e_gateway_outcome', 'success']);
  assert.equal(r.outcome, 'placed');
  await settle();
  assert.equal(named(await serverEvents(), 'payment_completed').length, 0, 'not paid: the thank-you page was shown and reloaded');
  await webhook({ order_id: Number(r.orderId), outcome: 'paid' });
  await settle();
  const paid = named(await serverEvents(), 'payment_completed');
  assert.equal(paid.length, 1);
  assert.equal(paid[0].anonymousId, r.steps.afterOrder.local.zl_anon);
});

test('commerce consent "required": only a customer who agreed is reported, including their later webhooks', async () => {
  useCheckout('classic', ids);
  configure({}, { consent: { commerce: 'required', analytics: 'required', replay: 'required', browser: 'none' } });
  let r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'url' });
  assert.equal(r.outcome, 'placed');
  assert.deepEqual(await serverEvents(), [], 'no commerce consent: no server events');
  await webhook({ order_id: Number(r.orderId), outcome: 'refund', amount: '1.00' });
  await settle();
  assert.deepEqual(await serverEvents(), [], 'and not for the later refund either');

  r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics', 'commerce'], addVia: 'url' });
  assert.equal(r.outcome, 'placed');
  const s = await serverEvents();
  assert.deepEqual(named(s, 'payment_completed').length, 1);
  await webhook({ order_id: Number(r.orderId), outcome: 'refund', amount: '1.00' });
  await settle();
  assert.equal(named(await serverEvents(), 'order_refunded').length, 1, 'the earlier consent, remembered by the order, covers the webhook');
  configure(); // back to the default policy
});

test('product identifiers follow the setting: SKU, then none', async () => {
  useCheckout('classic', ids);
  configure({ product_identifier: 'sku' });
  let r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'form' });
  assert.equal(r.outcome, 'placed');
  assert.ok(named(await browserEvents(), 'product_viewed').every((e) => e.properties.productId === 'E2E-WIDGET'));
  assert.equal(named(await browserEvents(), 'product_added_to_cart')[0].properties.productId, 'E2E-WIDGET');
  assert.deepEqual(named(await serverEvents(), 'order_created')[0].properties.productIds, ['E2E-WIDGET']);

  configure({ product_identifier: 'none' });
  r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'form' });
  assert.equal(r.outcome, 'placed');
  assert.ok((await browserEvents()).filter((e) => /^product_/.test(e.name)).every((e) => !('productId' in e.properties)), 'no product id in the browser events');
  const created = named(await serverEvents(), 'order_created')[0].properties;
  assert.ok(!('productIds' in created));
  assert.equal(created.itemCount, 1);
  configure();
});

test('HIGH-PERFORMANCE ORDER STORAGE on: classic and block checkouts report exactly the same, and markers live in the order tables', async () => {
  setHpos(true);
  assert.equal(wpEval("echo wc_get_container()->get( \\Automattic\\WooCommerce\\Utilities\\OrderUtil::class )::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts';"), 'hpos');
  configure();

  useCheckout('classic', ids);
  let r = await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'url' });
  assert.equal(r.outcome, 'placed');
  let s = await serverEvents();
  assert.deepEqual(s.map((e) => e.name), ['order_created', 'order_status_changed', 'payment_completed']);
  assert.equal(s[2].properties.valueMinor, 1999);
  assert.equal(s[0].anonymousId, r.steps.afterOrder.local.zl_anon);
  const marker = JSON.parse(wpEval(`echo wp_json_encode( wc_get_order( ${r.orderId} )->get_meta( '_ziplogger_events' ) );`));
  assert.match(marker, /"c":1/);
  const inOrderTables = Number(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = '_ziplogger_events'", ${r.orderId} ) );`));
  assert.equal(inOrderTables, 1, 'stored in wc_orders_meta, not in post meta');
  const inPostMeta = Number(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_ziplogger_events'", ${r.orderId} ) );`));
  assert.equal(inPostMeta, 0);

  useCheckout('blocks', ids);
  r = await shop({ checkout: 'blocks', gateway: 'bacs', consent: ['analytics'], addVia: 'url' });
  assert.equal(r.outcome, 'placed', r.notice);
  await webhook({ order_id: Number(r.orderId), outcome: 'paid' });
  await settle();
  s = await serverEvents();
  assert.deepEqual(named(s, 'order_created').length + named(s, 'payment_completed').length, 2);
  assert.equal(named(s, 'order_created')[0].properties.status, 'pending', 'the first real status of a block-checkout order, as in the non-HPOS run');
  assert.equal(named(s, 'payment_completed')[0].anonymousId, r.steps.afterOrder.local.zl_anon);
  setHpos(false);
});

test('an order created by an administrator (no checkout, no visitor) is reported with its final amount and no identity beyond its reference', async () => {
  configure();
  await drain();
  const id = wpEval(`
    $o = wc_create_order( array( 'status' => 'pending' ) );
    $o->set_currency( 'EUR' ); $o->add_product( wc_get_product( ${ids.product} ), 3 ); $o->set_billing_email( 'admin.made@customer.example' );
    $o->set_created_via( 'admin' ); $o->calculate_totals(); $o->save(); echo $o->get_id();
  `);
  await settle();
  const s = await serverEvents();
  assert.deepEqual(s.map((e) => e.name), ['order_created']);
  assert.equal(s[0].properties.valueMinor, 5997);
  assert.equal(s[0].properties.currency, 'EUR');
  assert.equal(s[0].properties.createdVia, 'admin');
  assert.equal(s[0].anonymousId, orderRef(id));
  assertNoPersonalData(s, ['admin.made']);
});

test('the browser events reach ZipLogger with the browser key, the server events with the server key', async () => {
  useCheckout('classic', ids);
  configure();
  await shop({ checkout: 'classic', gateway: 'zl_e2e', consent: ['analytics'], addVia: 'url' });
  const entries = await mock.received('/ingest/v1/events');
  const byKey = { [KEYS.browser]: 0, [KEYS.server]: 0 };
  for (const e of entries) byKey[e.headers['x-api-key']]++;
  assert.ok(byKey[KEYS.browser] > 0 && byKey[KEYS.server] > 0);
  assert.ok(!JSON.stringify(entries).includes(KEYS.read));
});

test('WITHOUT WooCommerce the plugin runs normally: the module says why it cannot run, pages and admin screens render, nothing about commerce is sent', async () => {
  configure();
  await drain();
  wp(['plugin', 'deactivate', 'woocommerce']);
  try {
    const status = JSON.parse(wpEval(
      "echo wp_json_encode( array( 'active' => \\ZipLogger\\WordPress\\Modules::woocommerce_active(), 'effective' => \\ZipLogger\\WordPress\\Modules::effective( 'woocommerce' ), 'blockers' => \\ZipLogger\\WordPress\\Modules::blockers( 'woocommerce' ), 'commerceOn' => \\ZipLogger\\WordPress\\Frontend::config()['modules']['commerce'] ) );",
    ));
    assert.deepEqual(status, { active: false, effective: false, blockers: ['WooCommerce is not active on this site.'], commerceOn: false });

    // The front end renders, and the modules that do not need WooCommerce still work.
    const home = await fetch('http://localhost:8088/');
    assert.equal(home.status, 200);
    const config = JSON.parse((await home.text()).match(/<script type="application\/json" id="ziplogger-config">(.*?)<\/script>/s)[1]);
    assert.equal(config.modules.commerce, false);
    assert.equal(config.modules.browser, true, 'browser monitoring is unaffected');
    assert.equal((await fetch('http://localhost:8088/wp-login.php')).status, 200);

    // The admin screens render without a PHP or JavaScript error, and the WooCommerce tab explains itself.
    const admin = runBrowser('adminDashboard', { tabs: ['overview', 'woocommerce', 'connection'] });
    assert.deepEqual(admin.pageErrors, []);
    assert.match(admin.tabs.woocommerce.body, /WooCommerce active on this site\s*\(missing\)/, 'the requirements list says WooCommerce is missing');
    assert.doesNotMatch(admin.tabs.overview.body, /Fatal error|Uncaught|Warning:/);

    // A server-side event still works, and no commerce event exists anywhere.
    wp(['eval', "ziplogger_track( 'newsletter_signup', array( 'plan' => 'free' ) );"], { allowFail: true });
    await settle();
    const names = (await serverEvents()).map((e) => e.name);
    assert.ok(names.includes('newsletter_signup'), `the server event was delivered: ${names}`);
    assert.ok(!names.some((n) => /^(order_|payment_|product_|cart_|checkout_)/.test(n)), `unexpected commerce events: ${names}`);
  } finally {
    wp(['plugin', 'activate', 'woocommerce']);
  }
});
