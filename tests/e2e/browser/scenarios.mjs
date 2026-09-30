// Browser scenarios. Each returns plain evidence; the host-side test decides what it means.
// They run inside the Playwright container, where the site is http://wordpress and the receiver is http://mock:5081.

const MOCK = process.env.MOCK || 'http://mock:5081';
const THIRD = 'http://thirdparty.example.org:5081';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const mockReceived = async (path) => (await fetch(`${MOCK}/__received${path ? `?path=${encodeURIComponent(path)}` : ''}`)).json();
const count = async (path) => (await mockReceived(path)).length;

async function open(browser, { dnt = false, block = [], storageState } = {}) {
  const context = await browser.newContext({ storageState });
  if (dnt) {
    await context.addInitScript(() => {
      Object.defineProperty(Navigator.prototype, 'doNotTrack', { get: () => '1', configurable: true });
    });
  }
  const page = await context.newPage();
  const log = { console: [], pageErrors: [], requests: [], failures: [] };
  page.on('console', (m) => log.console.push({ type: m.type(), text: m.text() }));
  page.on('pageerror', (e) => log.pageErrors.push(String(e.message)));
  page.on('request', (r) => {
    const h = r.headers();
    log.requests.push({ url: r.url(), method: r.method(), type: r.resourceType(), traceparent: h.traceparent || null, baggage: h.baggage || null });
  });
  page.on('requestfailed', (r) => log.failures.push({ url: r.url(), error: r.failure() && r.failure().errorText }));
  for (const pattern of block) await page.route(pattern, (route) => route.abort());
  return { context, page, log };
}

async function snapshot(page) {
  return page.evaluate(() => {
    // WordPress core itself caches its emoji-support probe in sessionStorage; that is not ours.
    const dump = (s) => { const o = {}; try { for (let i = 0; i < s.length; i++) { if (s.key(i) !== 'wpEmojiSettingsSupports') o[s.key(i)] = s.getItem(s.key(i)); } } catch (e) { return null; } return o; };
    return {
      url: location.pathname + location.search,
      local: dump(localStorage),
      session: dump(sessionStorage),
      cookies: document.cookie.split(';').map((c) => c.trim().split('=')[0]).filter(Boolean),
      hasConfig: !!document.getElementById('ziplogger-config'),
      hasScript: !!document.querySelector('script[src*="ziplogger.min.js"]'),
      api: !!window.ZipLoggerWP,
      status: window.ZipLoggerWP ? window.ZipLoggerWP.status() : null,
    };
  });
}

const flush = (page) => page.evaluate(() => (window.ZipLoggerWP ? window.ZipLoggerWP.flush() : null)).catch(() => null);

async function login(page, site, user, pass) {
  await page.goto(`${site}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  // The sign-in is done when the server has redirected away from the login form (the cookie is set by then).
  // Waiting for the destination's "load" event would make the test depend on how heavy that page is
  // (WooCommerce's admin screens load a lot), which is not what is under test.
  await Promise.all([page.waitForURL((url) => !/wp-login\.php/.test(url.pathname), { waitUntil: 'commit', timeout: 60000 }), page.click('#wp-submit')]);
}

// A small seeded generator, so that a run can be repeated with the same load order.
function mulberry32(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const ownRequests = (log) => log.requests.filter((r) => r.url.startsWith(MOCK));

export default {
  /** Load a page and report what the plugin did to it. */
  async survey({ browser, site, args }) {
    const { context, page, log } = await open(browser);
    await page.goto(site + (args.path || '/'), { waitUntil: 'load' });
    await sleep(args.wait || 1500);
    await flush(page);
    await sleep(300);
    const snap = await snapshot(page);
    await context.close();
    return { snap, own: ownRequests(log).length, ownRequests: ownRequests(log).map((r) => `${r.method} ${new URL(r.url).pathname}`), pageErrors: log.pageErrors, console: log.console };
  },

  /** Errors, rejections, failed and slow requests, on a real page. */
  async jsErrors({ browser, site }) {
    const { context, page, log } = await open(browser);
    await page.goto(`${site}/?zl_fixture=js_errors`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.__fixtureReady === true);
    await sleep(3000);
    await flush(page);
    await sleep(500);
    const page_state = await page.evaluate(() => ({ fail: window.__failStatus, xhr: window.__xhrStatus, ok: window.__okBody, slow: window.__slowStatus, notFound: window.__notFound }));
    await context.close();
    return { page_state, pageErrors: log.pageErrors, console: log.console };
  },

  /** The same page loaded three times; the second script tag must change nothing. */
  async duplicateInit({ browser, site }) {
    const { context, page, log } = await open(browser);
    await page.goto(`${site}/`, { waitUntil: 'load' });
    await sleep(500);
    const src = await page.evaluate(() => document.querySelector('script[src*="ziplogger.min.js"]').src);
    const before = await page.evaluate(() => window.fetch.toString().slice(0, 60));
    await page.addScriptTag({ url: src });
    await page.addScriptTag({ url: src });
    await sleep(300);
    const after = await page.evaluate(() => window.fetch.toString().slice(0, 60));
    await page.evaluate(() => { setTimeout(() => { throw new Error('dup-check-error'); }, 10); });
    await sleep(500);
    await flush(page);
    await sleep(500);
    await context.close();
    return { fetchUnchanged: before === after, pageErrors: log.pageErrors };
  },

  /** Consent: none -> granted -> reload -> withdrawn. */
  async consent({ browser, site, args }) {
    const { context, page } = await open(browser, { dnt: !!args.dnt });
    const steps = [];
    const step = async (label) => {
      await flush(page);
      await sleep(600);
      steps.push({ label, snap: await snapshot(page), events: await count('/ingest/v1/events'), logs: await count('/ingest/v1/logs'), replay: await count('/ingest/v1/replay') });
    };
    await page.goto(`${site}${args.path || '/'}`, { waitUntil: 'load' });
    await sleep(1200);
    await step('loaded, no choice made');
    await page.evaluate((c) => window.ZipLoggerWP.consent.grant(c), args.categories || ['analytics']);
    await sleep(1500);
    await step('after grant');
    await page.reload({ waitUntil: 'load' });
    await sleep(1500);
    await step('after reload (choice remembered)');
    await page.evaluate((c) => window.ZipLoggerWP.consent.revoke(c), args.categories || ['analytics']);
    await sleep(800);
    await step('after withdrawal');
    await page.evaluate(() => window.ZipLoggerWP && window.ZipLoggerWP.track('after_withdrawal_event', { a: 1 }));
    await sleep(800);
    await step('after a track call post-withdrawal');
    await context.close();
    return { steps };
  },

  /** Client-side navigation. */
  async spa({ browser, site }) {
    const { context, page } = await open(browser);
    await page.goto(`${site}/?zl_fixture=spa`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.__fixtureReady === true);
    await page.evaluate(() => window.ZipLoggerWP.consent.grant('analytics'));
    await sleep(600);
    for (const id of ['#go-a', '#go-b', '#go-checkout']) {
      await page.click(id);
      await sleep(400);
    }
    // (Going back is covered by the jsdom tests: this theme's Interactivity router turns a back step onto a
    // non-existent route into a full page load, which is real, but not what this scenario is about.)
    await flush(page);
    await sleep(600);
    const url = await page.evaluate(() => location.pathname + location.search);
    await context.close();
    return { finalUrl: url };
  },

  /** Trace propagation: which requests carry which headers. */
  async tracing({ browser, site }) {
    const { context, page, log } = await open(browser);
    await page.goto(`${site}/`, { waitUntil: 'load' });
    await sleep(800);
    const results = await page.evaluate(async (third) => {
      const out = {};
      out.same = await (await fetch('/wp-json/zl-e2e/v1/ok')).json();
      const xhr = await new Promise((resolve) => { const x = new XMLHttpRequest(); x.open('GET', '/wp-json/zl-e2e/v1/ok'); x.onloadend = () => resolve(JSON.parse(x.responseText)); x.send(); });
      out.sameXhr = xhr;
      try { await fetch(`${third}/third-party/probe`); out.thirdStatus = 'sent'; } catch (e) { out.thirdStatus = 'failed: ' + e.message; }
      return out;
    }, THIRD);
    await sleep(500);
    await flush(page);
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    await sleep(800);
    await context.close();
    return { results, requests: log.requests.filter((r) => /zl-e2e|third-party/.test(r.url)).map((r) => ({ url: r.url, traceparent: r.traceparent, baggage: r.baggage })) };
  },

  /**
   * Live check (tests/live/README.md): what a REAL ZipLogger endpoint answers to the browser script, cross-origin,
   * from a real Chromium: errors, analytics, replay and browser traces. Records status codes and the CORS header
   * of every request to the endpoint's host, and nothing else (no bodies, no keys).
   */
  async liveBrowser({ browser, site, args }) {
    const { context, page, log } = await open(browser);
    const host = new URL(args.endpoint).host;
    const seen = [];
    page.on('response', (r) => {
      const u = new URL(r.url());
      if (u.host !== host) return;
      seen.push({ method: r.request().method(), path: u.pathname, status: r.status(), allowOrigin: r.headers()['access-control-allow-origin'] || null });
    });
    page.on('requestfailed', (r) => {
      const u = new URL(r.url());
      if (u.host === host) seen.push({ method: r.method(), path: u.pathname, failed: (r.failure() && r.failure().errorText) || 'failed' });
    });
    await page.goto(`${site}${args.path || '/?zl_fixture=js_errors'}`, { waitUntil: 'load' });
    await sleep(1000);
    await page.evaluate(() => window.ZipLoggerWP.consent.grant(['analytics', 'replay']));
    await sleep(4000);
    await page.mouse.click(60, 60);
    await page.mouse.move(200, 300);
    await flush(page);
    await sleep(7000);
    await flush(page);
    await sleep(2000);
    const status = await page.evaluate(() => (window.ZipLoggerWP ? window.ZipLoggerWP.status() : null));
    await context.close();
    return { seen, status, consoleErrors: log.console.filter((c) => c.type === 'error').map((c) => c.text.slice(0, 200)) };
  },

  /** Session replay: what is recorded, on which pages, for whom. */
  async replay({ browser, site, args }) {
    const { context, page, log } = await open(browser);
    if (args.login) await login(page, site, args.login.user, args.login.pass);
    await page.goto(`${site}${args.path || '/?zl_fixture=replay_page'}`, { waitUntil: 'load' });
    await sleep(800);
    if (args.consent !== false) await page.evaluate(() => window.ZipLoggerWP.consent.grant('replay'));
    await sleep(2500);
    const timeline = [];
    const mark = async (label) => timeline.push({ label, replay: await count('/ingest/v1/replay'), config: await count('/ingest/v1/replay/config'), status: await page.evaluate(() => window.ZipLoggerWP && ZipLoggerWP.status().replay) });
    await mark('recording started');

    if (await page.$('#zl-add')) {
      await page.click('#zl-add');
      await page.fill('#zl-name', 'TYPED-NAME-SECRET');
      await page.fill('#zl-notes', 'TYPED-NOTE-SECRET');
      await sleep(2500);
      await mark('after interaction');
    }
    if (args.navigate) {
      await page.evaluate((to) => { history.pushState({}, '', to); }, args.navigate);
      await sleep(300);
      await mark(`after navigating to ${args.navigate}`);
    }
    if (args.revoke) {
      // Content that only exists in the unsent buffer at the moment of withdrawal.
      if (await page.$('#zl-add')) {
        await page.fill('#zl-notes', 'AFTER-WITHDRAWAL-SECRET');
      }
      await page.evaluate(() => window.ZipLoggerWP.consent.revoke('replay'));
      await sleep(3500);
      await mark('after withdrawal');
    }
    const snap = await snapshot(page);
    const chunks = (await mockReceived('/ingest/v1/replay')).map((e) => ({ replay: e.replay, duplicate: !!e.duplicate, body: e.body, outcome: e.outcome, headers: { key: e.headers['x-api-key'] } }));
    await context.close();
    return { timeline, snap, chunks, pageErrors: log.pageErrors, requests: log.requests.filter((r) => r.url.startsWith(MOCK)).map((r) => `${r.method} ${new URL(r.url).pathname}`) };
  },

  /** The plugin's own script or the ZipLogger service is unreachable. */
  async blocked({ browser, site, args }) {
    const block = args.block === 'script' ? ['**/ziplogger.min.js*'] : args.block === 'ingest' ? [`${MOCK}/**`] : [];
    const { context, page, log } = await open(browser, { block });
    const t0 = Date.now();
    await page.goto(`${site}/?zl_fixture=js_errors`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.__fixtureReady === true);
    await sleep(2500);
    const loadMs = Date.now() - t0;
    const state = await page.evaluate(() => ({ fail: window.__failStatus, ok: window.__okBody && window.__okBody.ok, title: document.title, bodyLength: document.body.innerText.length }));
    await context.close();
    return { state, loadMs, pageErrors: log.pageErrors, failures: log.failures.map((f) => f.url), console: log.console };
  },

  /** Sign in, look at the hint cookie and the context endpoint, sign out. */
  async login({ browser, site, args }) {
    const { context, page } = await open(browser);
    const anonymous = await (async () => {
      await page.goto(`${site}/`, { waitUntil: 'load' });
      return page.evaluate(async () => (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ziplogger_context' })).json());
    })();
    await login(page, site, args.user, args.pass);
    await page.goto(`${site}/`, { waitUntil: 'load' });
    const signedIn = await page.evaluate(async () => ({
      cookie: document.cookie.split(';').map((c) => c.trim()).filter((c) => c.startsWith('ziplogger_li=')),
      context: await (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ziplogger_context' })).json(),
      viaGet: await (await fetch('/wp-admin/admin-ajax.php?action=ziplogger_context')).json(),
    }));
    const cookies = (await context.cookies()).filter((c) => c.name === 'ziplogger_li').map((c) => ({ httpOnly: c.httpOnly, sameSite: c.sameSite, path: c.path, value: c.value }));
    await page.goto(`${site}/wp-login.php?action=logout`, { waitUntil: 'load' });
    const confirm = await page.$('a[href*="action=logout"]');
    if (confirm) await Promise.all([page.waitForNavigation(), confirm.click()]);
    await page.goto(`${site}/`, { waitUntil: 'load' });
    const afterLogout = await page.evaluate(() => ({ cookie: document.cookie.split(';').map((c) => c.trim()).filter((c) => c.startsWith('ziplogger_li=')) }));
    await context.close();
    return { anonymous, signedIn, cookies, afterLogout };
  },

  /**
   * What a page cache stores. The host test has put the anonymous page on disk as a plain static file
   * (args.staticPath), served by Apache with no PHP involved - exactly what a full-page cache does.
   * Two different visitors load that same file.
   */
  async cachedPage({ browser, site, args }) {
    const a = await open(browser);
    const anonymousHtml = await (await a.context.request.get(`${site}/`)).text();
    await login(a.page, site, args.user, args.pass);
    const signedInHtml = await (await a.context.request.get(`${site}/`)).text();
    await a.context.close();

    const ids = [];
    for (let i = 0; i < 2; i++) {
      const v = await open(browser);
      await v.page.goto(`${site}${args.staticPath}`, { waitUntil: 'load' });
      await sleep(600);
      await v.page.evaluate(() => window.ZipLoggerWP.consent.grant(['analytics']));
      await sleep(800);
      ids.push(await v.page.evaluate(() => ({ anon: localStorage.getItem('zl_anon'), sess: sessionStorage.getItem('zl_sess') })));
      await v.context.close();
    }
    return { anonymousHtml, signedInHtml, ids };
  },

  /**
   * A shopper buys the widget: product page -> cart -> checkout (classic shortcode or block) -> order received.
   * args: checkout 'classic'|'blocks', gateway id, consent [categories], addVia 'form'|'url', dnt, blockScript,
   *       identity: what to type into the checkout form (the test greps for it afterwards).
   */
  async placeOrder({ browser, site, args }) {
    const block = args.blockScript ? ['**/ziplogger.min.js*'] : [];
    const { context, page, log } = await open(browser, { dnt: !!args.dnt, block });
    await page.setViewportSize({ width: 1280, height: 1800 });
    const who = { first: 'Jane', last: 'Customer', email: 'jane.customer@customer.example', phone: '+1 555 010 0177', street: '221B Baker Street', city: 'San Francisco', zip: '94103', ...(args.identity || {}) };
    const steps = {};

    await page.goto(`${site}/product/e2e-widget/`, { waitUntil: 'load' });
    await sleep(800);
    if ((args.consent || []).length) await page.evaluate((c) => window.ZipLoggerWP.consent.grant(c), args.consent);
    await sleep(1200);
    steps.afterProductPage = await snapshot(page);

    if (args.addVia === 'url') {
      await page.goto(`${site}/?add-to-cart=${args.product}`, { waitUntil: 'load' });
    } else {
      await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button.single_add_to_cart_button')]);
    }
    await sleep(800);

    const classic = args.checkout === 'classic';
    await page.goto(`${site}/${classic ? 'classic-checkout' : 'checkout'}/`, { waitUntil: 'load' });
    await sleep(2500);
    steps.atCheckout = await snapshot(page);

    if (classic) {
      await page.fill('#billing_first_name', who.first);
      await page.fill('#billing_last_name', who.last);
      await page.fill('#billing_address_1', who.street);
      await page.fill('#billing_city', who.city);
      await page.fill('#billing_postcode', who.zip);
      await page.fill('#billing_phone', who.phone);
      await page.fill('#billing_email', who.email);
      await sleep(1500);
      await page.check(`#payment_method_${args.gateway || 'zl_e2e'}`, { force: true });
      await sleep(500);
      await page.click('#place_order');
    } else {
      await page.fill('#email', who.email);
      await page.fill('#billing-first_name', who.first);
      await page.fill('#billing-last_name', who.last);
      await page.fill('#billing-address_1', who.street);
      await page.fill('#billing-city', who.city);
      await page.fill('#billing-postcode', who.zip);
      await page.fill('#billing-phone', who.phone);
      await sleep(1500);
      await page.click(`label[for="radio-control-wc-payment-method-options-${args.gateway || 'bacs'}"]`);
      await sleep(800);
      await page.click('.wc-block-components-checkout-place-order-button');
    }
    let outcome = 'placed';
    try {
      await page.waitForURL(/order-received|order-pay/, { timeout: 30000 });
    } catch (e) {
      outcome = 'stayed';
    }
    await sleep(1500);
    await flush(page);
    await sleep(800);
    const url = page.url();
    const notice = outcome === 'stayed' ? await page.evaluate(() => (document.querySelector('.woocommerce-error, .wc-block-components-notice-banner.is-error') || {}).innerText || '') : '';
    const orderId = (/order-received\/(\d+)/.exec(url) || [])[1] || null;
    steps.afterOrder = await snapshot(page);
    // Reload the thank-you page twice, like an impatient shopper.
    if (args.reloadThankYou && orderId) { await page.reload({ waitUntil: 'load' }); await page.reload({ waitUntil: 'load' }); await sleep(800); }
    await context.close();
    return { outcome, url: url.replace(site, ''), orderId, notice, steps, pageErrors: log.pageErrors, who };
  },

  /** A shopper browses a product and uses the classic AJAX button on the shop page. */
  async shopBrowse({ browser, site, args }) {
    const { context, page } = await open(browser);
    await page.goto(`${site}/product/e2e-widget/`, { waitUntil: 'load' });
    await sleep(800);
    await page.evaluate((c) => window.ZipLoggerWP.consent.grant(c), args.consent || ['analytics']);
    await sleep(1500);
    await flush(page);
    const view = await snapshot(page);
    await context.close();
    return { view };
  },

  /**
   * The WordPress admin dashboard, as an administrator: every tab, its live panels, and everything the
   * browser could see or send while doing so.
   */
  async adminDashboard({ browser, site, args }) {
    const { context, page, log } = await open(browser);
    const headersSeen = [];
    page.on('request', (r) => { const h = r.headers(); if (Object.keys(h).some((k) => k.toLowerCase() === 'x-api-key')) headersSeen.push(r.url()); });
    await login(page, site, args.user || 'admin', args.pass || 'e2e-admin-pass-1');
    const tabs = {};
    for (const tab of args.tabs || ['overview', 'logs', 'browser', 'tracing', 'analytics', 'replay', 'woocommerce', 'connection']) {
      await page.goto(`${site}/wp-admin/options-general.php?page=ziplogger&tab=${tab}`, { waitUntil: 'load' });
      await page.waitForFunction(() => [...document.querySelectorAll('.ziplogger-live-body')].every((b) => !/Loading/.test(b.textContent)), null, { timeout: 30000 }).catch(() => {});
      await sleep(300);
      tabs[tab] = await page.evaluate(() => ({
        panels: [...document.querySelectorAll('[data-ziplogger-panel]')].map((p) => ({ id: p.getAttribute('data-ziplogger-panel'), text: p.querySelector('.ziplogger-live-body').innerText, html: p.querySelector('.ziplogger-live-body').innerHTML })),
        unavailable: [...document.querySelectorAll('.ziplogger-live:not([data-ziplogger-panel])')].map((p) => p.innerText),
        hasNonce: document.documentElement.outerHTML.includes('data-nonce'),
        links: [...document.querySelectorAll('.ziplogger-links a')].map((a) => a.href),
        source: document.documentElement.outerHTML,
        body: document.body.innerText,
      }));
      if (args.screenshots) await page.screenshot({ path: `out/dashboard-${tab}.png`, fullPage: true });
    }
    let readTest = null;
    if (args.testRead) {
      await page.goto(`${site}/wp-admin/options-general.php?page=ziplogger&tab=connection`, { waitUntil: 'load' });
      await Promise.all([page.waitForNavigation(), page.click('input[value="Test the read key"]')]);
      readTest = await page.evaluate(() => (document.querySelector('.notice') || {}).innerText || '');
    }
    let refresh = null;
    if (args.refresh) {
      await page.goto(`${site}/wp-admin/options-general.php?page=ziplogger&tab=logs`, { waitUntil: 'load' });
      await page.waitForSelector('.ziplogger-live-refresh');
      await page.click('.ziplogger-live-refresh');
      await sleep(1500);
      refresh = await page.evaluate(() => document.querySelector('.ziplogger-live-body').innerText);
    }
    await context.close();
    return { tabs, readTest, refresh, xApiKeyRequests: headersSeen, requests: log.requests.map((r) => r.url), pageErrors: log.pageErrors };
  },

  /** A non-administrator asking the panel endpoint directly. */
  async panelAsUser({ browser, site, args }) {
    const { context, page } = await open(browser);
    if (args.user) await login(page, site, args.user, args.pass);
    await page.goto(`${site}/`, { waitUntil: 'load' });
    const answers = await page.evaluate(async () => {
      const out = {};
      for (const [name, body] of [['post', 'action=ziplogger_panel&panel=errors'], ['post-nonce', 'action=ziplogger_panel&panel=errors&nonce=1234567890']]) {
        const r = await fetch('/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        out[name] = { status: r.status, text: (await r.text()).slice(0, 200) };
      }
      const g = await fetch('/wp-admin/admin-ajax.php?action=ziplogger_panel&panel=errors');
      out.get = { status: g.status, text: (await g.text()).slice(0, 200) };
      return out;
    });
    await context.close();
    return { answers };
  },

  /**
   * Browser cost of a page: repeated cold loads (a fresh browser context each time), measured by Chromium itself
   * (Performance.getMetrics), plus what the plugin's own scripts weigh on the wire.
   *
   * args.variants is a list of { id, path, consent }. Every round loads every variant once, in a shuffled order
   * (seeded), so drift of the machine over the run lands on all variants equally. The first args.warmup rounds
   * are loaded but not reported.
   */
  async perfLoad({ browser, site, args }) {
    const variants = args.variants || [{ id: 'default', path: args.path || '/', consent: args.consent || [] }];
    const rounds = args.rounds || 10;
    const warmup = args.warmup || 0;
    const rand = mulberry32(args.seed || 20260930);
    const shuffled = (list) => {
      const a = [...list];
      for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(rand() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
      }
      return a;
    };
    const loadOnce = async (variant) => {
      const context = await browser.newContext();
      const page = await context.newPage();
      // The configuration for this load travels in cookies, so the page's follow-up requests share it.
      if (variant.cfg) await context.addCookies([{ name: 'zl_perf', value: '1', url: site }, { name: 'zl_cfg', value: variant.cfg, url: site }]);
      const cdp = await context.newCDPSession(page);
      await cdp.send('Performance.enable');
      const own = [];
      page.on('request', (r) => { if (r.url().startsWith(MOCK)) own.push(r.url()); });
      await page.goto(site + (variant.path || '/'), { waitUntil: 'load' });
      if ((variant.consent || []).length) await page.evaluate((c) => window.ZipLoggerWP && window.ZipLoggerWP.consent.grant(c), variant.consent);
      await sleep(args.settle || 3000);
      const metrics = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
      const pageMetrics = await page.evaluate(async () => {
        const nav = performance.getEntriesByType('navigation')[0];
        const ours = performance.getEntriesByType('resource').filter((r) => /ziplogger/i.test(r.name));
        const longtask = await new Promise((resolve) => {
          let total = 0;
          try {
            const po = new PerformanceObserver((list) => { for (const e of list.getEntries()) total += e.duration; });
            po.observe({ type: 'longtask', buffered: true });
            setTimeout(() => { po.disconnect(); resolve(total); }, 150);
          } catch (e) { resolve(0); }
        });
        return {
          domContentLoadedMs: nav.domContentLoadedEventEnd,
          loadMs: nav.loadEventEnd,
          pluginEncodedBytes: ours.reduce((a, r) => a + (r.encodedBodySize || 0), 0),
          pluginDecodedBytes: ours.reduce((a, r) => a + (r.decodedBodySize || 0), 0),
          pluginResources: ours.map((r) => r.name.split('/').pop().split('?')[0]),
          longTaskMs: longtask,
        };
      });
      await context.close();
      return {
        ...pageMetrics,
        scriptMs: metrics.ScriptDuration * 1000,
        taskMs: metrics.TaskDuration * 1000,
        layoutMs: metrics.LayoutDuration * 1000,
        jsHeapMB: metrics.JSHeapUsedSize / 1048576,
        ownRequests: own.length,
      };
    };
    const samples = Object.fromEntries(variants.map((v) => [v.id, []]));
    for (let round = 0; round < warmup + rounds; round++) {
      for (const variant of shuffled(variants)) {
        const sample = await loadOnce(variant);
        if (round >= warmup) samples[variant.id].push(sample);
      }
    }
    return { samples };
  },

  /**
   * Accessibility: axe-core (WCAG 2 A/AA and best practices) on every tab of the settings screen and its live
   * panels, in left-to-right and right-to-left, at desktop and phone width.
   */
  async accessibility({ browser, site, args }) {
    const { context, page } = await open(browser);
    await login(page, site, 'admin', 'e2e-admin-pass-1');
    const out = {};
    const axePath = new URL('./node_modules/axe-core/axe.min.js', import.meta.url).pathname;
    for (const mode of args.modes || ['ltr', 'rtl', 'phone']) {
      await page.setViewportSize(mode === 'phone' ? { width: 375, height: 812 } : { width: 1280, height: 900 });
      for (const tab of args.tabs) {
        await page.goto(`${site}/wp-admin/options-general.php?page=ziplogger&tab=${tab}`, { waitUntil: 'load' });
        await page.waitForFunction(() => [...document.querySelectorAll('.ziplogger-live-body')].every((b) => !/Loading/.test(b.textContent)), null, { timeout: 30000 }).catch(() => {});
        if (mode === 'rtl') await page.evaluate(() => { document.documentElement.dir = 'rtl'; document.body.classList.add('rtl'); });
        await page.addScriptTag({ path: axePath });
        const result = await page.evaluate(async () => {
          const r = await window.axe.run(document.querySelector('.ziplogger-wrap'), { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] } });
          return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
        });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        const wide = overflow ? await page.evaluate(() => [...document.querySelectorAll('.ziplogger-wrap *')].filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1).slice(0, 6).map((el) => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').join('.') : '') + ' right=' + Math.round(el.getBoundingClientRect().right))) : [];
        out[`${mode}:${tab}`] = { violations: result, horizontalOverflow: overflow, wide };
        if (args.screenshots) await page.screenshot({ path: `out/a11y-${mode}-${tab}.png`, fullPage: true });
      }
    }
    await context.close();
    return out;
  },
};
