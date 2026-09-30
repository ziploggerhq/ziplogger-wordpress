import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

let env = setupDom({ url: 'https://shop.example.test/product/blue-shirt?utm_source=x&token=secret' });
const { WPClient } = await import('../src/client.js');
const { createConsent } = await import('../src/consent.js');
const { createIdentity } = await import('../src/identity.js');
const { installNavigation } = await import('../src/navigation.js');
const { createAnalytics, maskEndpointIds } = await import('../src/analytics.js');

function setup(analytics = {}, options = {}) {
  env = setupDom({ url: options.url || 'https://shop.example.test/product/blue-shirt?utm_source=x&token=secret', html: options.html });
  const cfg = baseConfig({ modules: { analytics: true }, analytics: { pageViews: true, spaNavigation: true, interactions: false, selectors: [], identify: false, sampleRate: 100, ...analytics }, page: { type: 'product', replay: true, sensitiveEndpoints: ['order-received', 'view-order'] } });
  const consent = createConsent(cfg.consent, { win: env.win, doc: env.doc });
  const identity = createIdentity({ win: env.win }, consent, () => {});
  identity.sync();
  const client = new WPClient({ endpoint: cfg.endpoint, apiKey: cfg.key, source: 'shop', includePageContext: false, anonymousId: 'anon_unset', sessionId: identity.current().sessionId, requestCorrelationTtlMs: 0, flushIntervalMs: 5 });
  client.setIdentity(identity.current());
  const navigation = installNavigation(env.win);
  const sync = () => { identity.sync(); client.setIdentity(identity.current()); };
  consent.onChange(sync);
  const a = createAnalytics({ win: env.win, doc: env.doc, cfg, client, consent, identity: identity.current, tracing: null, navigation });
  return { a, consent, identity, client, navigation, cfg };
}

test('maskEndpointIds hides the identifier after a sensitive endpoint slug', () => {
  assert.equal(maskEndpointIds('/checkout/order-received/1234/', ['order-received']), '/checkout/order-received/:id/');
  assert.equal(maskEndpointIds('/my-account/view-order/77', ['view-order', 'order-received']), '/my-account/view-order/:id');
  assert.equal(maskEndpointIds('/shop/', ['order-received']), '/shop/');
  assert.equal(maskEndpointIds('/x/order-received', ['order-received']), '/x/order-received');
});

test('NOTHING is queued or sent before consent (default policy: required)', async () => {
  const { a, client } = setup();
  a.start();
  a.track('signup');
  await client.flush();
  await env.settle();
  assert.equal(env.callsTo('/ingest/v1/events').length, 0);
  assert.equal(client._events.length, 0, 'not even buffered');
});

test('after consent the current page is counted once, with a path only and the page type', async () => {
  const { a, consent, client } = setup();
  a.start();
  consent.grant('analytics');
  a.reset();
  a.start();
  a.start();
  await client.flush();
  const ev = env.events();
  assert.equal(ev.length, 1, 'no duplicate page view');
  assert.equal(ev[0].name, 'page_view');
  assert.equal(ev[0].properties.path, '/product/blue-shirt');
  assert.equal(ev[0].properties.pageType, 'product');
  assert.equal(ev[0].properties.navigation, 'load');
  assert.ok(!('url' in ev[0]) && !('page' in ev[0]), 'the SDK added no page context');
  const raw = JSON.stringify(ev[0]);
  assert.ok(!raw.includes('secret') && !raw.includes('utm_source'), 'no query string anywhere');
  assert.match(ev[0].anonymousId, /^anon_[0-9a-f]{20}$/);
  assert.match(ev[0].sessionId, /^sess_[0-9a-f]{20}$/);
  assert.equal(ev[0].userId, undefined);
});

test('client-side navigation produces a page view per new path, not per call', async () => {
  const { a, consent, client } = setup();
  consent.grant('analytics');
  a.start();
  env.win.history.pushState({}, '', '/cart/');
  await env.settle(10);
  env.win.history.replaceState({}, '', '/cart/');
  env.win.history.pushState({}, '', '/cart/?coupon=SAVE10');
  await env.settle(10);
  env.win.history.pushState({}, '', '/shop/');
  await env.settle(10);
  await client.flush();
  const paths = env.events().map((e) => e.properties.path);
  assert.deepEqual(paths, ['/product/blue-shirt', '/cart/', '/shop/']);
  assert.ok(env.events().slice(1).every((e) => e.properties.navigation === 'spa'));
  assert.ok(!JSON.stringify(env.events()).includes('SAVE10'));
});

test('a thank-you URL is reported without the order number', async () => {
  const { a, consent, client } = setup();
  consent.grant('analytics');
  a.start();
  env.win.history.pushState({}, '', '/checkout/order-received/4711/?key=wc_order_abc');
  await env.settle(10);
  await client.flush();
  const last = env.events().pop();
  assert.equal(last.properties.path, '/checkout/order-received/:id/');
  assert.ok(!JSON.stringify(env.events()).includes('4711') && !JSON.stringify(env.events()).includes('wc_order_abc'));
});

test('interactions: only elements that match the allowlist are recorded, and only as the selector', async () => {
  const { a, consent, client } = setup({ interactions: true, selectors: ['.add-to-cart', 'a[href="/pricing"]'] }, {
    html: '<body><button class="add-to-cart">Buy for John Smith</button><button class="other">x</button><a href="/pricing"><span id="in">Pricing</span></a></body>',
  });
  consent.grant('analytics');
  env.doc.querySelector('.add-to-cart').click();
  env.doc.querySelector('.other').click();
  env.doc.querySelector('#in').click();
  await client.flush();
  const inter = env.events().filter((e) => e.name === 'interaction');
  assert.equal(inter.length, 2);
  assert.deepEqual(inter.map((e) => e.properties.selector), ['.add-to-cart', 'a[href="/pricing"]']);
  assert.ok(!JSON.stringify(inter).includes('John Smith'), 'element text is never collected');
  a.stop();
});

test('explicit data-ziplogger-event markup names the event and its properties; sensitive names are dropped', async () => {
  const { a, consent, client } = setup({ interactions: true }, {
    html: '<body><button data-ziplogger-event="Newsletter Signup" data-ziplogger-prop-plan="pro" data-ziplogger-prop-email="a@b.co" data-ziplogger-prop-source-page="footer"><i id="child">go</i></button></body>',
  });
  consent.grant('analytics');
  env.doc.querySelector('#child').click();
  await client.flush();
  const e = env.events().find((x) => x.name === 'newsletter_signup');
  assert.ok(e);
  assert.equal(e.properties.plan, 'pro');
  assert.equal(e.properties.sourcePage, 'footer');
  assert.ok(!('email' in e.properties));
  a.stop();
});

test('interaction tracking is off unless enabled, even with markup present', async () => {
  const { a, consent, client } = setup({ interactions: false }, { html: '<body><button data-ziplogger-event="x">b</button></body>' });
  consent.grant('analytics');
  env.doc.querySelector('button').click();
  await client.flush();
  assert.equal(env.events().filter((e) => e.name === 'x').length, 0);
  a.stop();
});

test('custom events are cleaned like everything else', async () => {
  const { a, consent, client } = setup();
  consent.grant('analytics');
  assert.equal(a.track('Coupon Applied', { code: 'SAVE10', amount: 5, email: 'a@b.co', nested: { x: 1 }, note: 'call 203.0.113.9' }), true);
  await client.flush();
  const e = env.events().find((x) => x.name === 'coupon_applied');
  assert.equal(e.properties.amount, 5);
  assert.ok(!('email' in e.properties) && !('nested' in e.properties));
  assert.ok(!JSON.stringify(e).includes('203.0.113.9'));
  assert.equal(a.track('', {}), false);
  assert.equal(a.track(null, {}), false);
});

test('withdrawing consent stops collection at once', async () => {
  const { a, consent, client } = setup();
  consent.grant('analytics');
  a.track('one');
  consent.revoke('analytics');
  assert.equal(a.track('two'), false);
  await client.flush();
  a.stop();
});

test('the sample rate applies per session and 0 means none', async () => {
  const { a, consent, client } = setup({ sampleRate: 0 });
  consent.grant('analytics');
  a.start();
  a.track('x');
  await client.flush();
  assert.equal(env.events().length, 0);
});

test('identify links the anonymous history to the pseudonymous user id (never an email)', async () => {
  const { consent, identity, client } = setup();
  consent.grant('analytics');
  identity.sync();
  const anon = identity.current().anonymousId;
  identity.setUser('wpu_0123456789abcdef01234567');
  client.setIdentity(identity.current());
  client.identify('wpu_0123456789abcdef01234567');
  await client.flush();
  const idEvent = env.events().find((e) => e.type === 'identify');
  assert.equal(idEvent.userId, 'wpu_0123456789abcdef01234567');
  assert.equal(idEvent.anonymousId, anon);
});

test('several navigations in the same tick (an in-app redirect) count as one page view of the final page', async () => {
  const { a, consent, client } = setup();
  consent.grant('analytics');
  a.start();
  env.win.history.pushState({}, '', '/old/');
  env.win.history.replaceState({}, '', '/new/');
  await env.settle(10);
  await client.flush();
  assert.deepEqual(env.events().map((e) => e.properties.path), ['/product/blue-shirt', '/new/']);
});
test('going back (popstate) counts the page returned to', async () => {
  const { a, consent, client } = setup();
  consent.grant('analytics');
  a.start();
  env.win.history.pushState({}, '', '/a/');
  await env.settle(10);
  env.win.history.pushState({}, '', '/b/');
  await env.settle(10);
  env.win.history.back();
  await env.settle(40);
  await client.flush();
  assert.deepEqual(env.events().map((e) => e.properties.path), ['/product/blue-shirt', '/a/', '/b/', '/a/']);
});
