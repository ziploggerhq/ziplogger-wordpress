import { test } from 'node:test';
import assert from 'node:assert/strict';
import { gunzipSync } from 'node:zlib';
import { setupDom, baseConfig } from './env.mjs';

let env = setupDom({ url: 'https://shop.example.test/blog/hello' });
const { WPClient } = await import('../src/client.js');
const { createConsent } = await import('../src/consent.js');
const { createIdentity } = await import('../src/identity.js');
const { installNavigation } = await import('../src/navigation.js');
const { createReplay, replayOptions, scrubReplayUrl, MANDATORY_BLOCK, MANDATORY_MASK, MANDATORY_EXCLUDED_PATHS } = await import('../src/replay.js');

const GUEST = { loggedIn: false, replayAllowed: true, userRef: null };

function setup({ url = 'https://shop.example.test/blog/hello', replay = {}, page = {}, context = GUEST, recorderFails = false, config = { enabled: true, sampleRate: 1 } } = {}) {
  env = setupDom({ url });
  const cfg = baseConfig({ modules: { replay: true }, replay: { sampleRate: 100, ...replay }, page: { type: 'post', replay: true, sensitiveEndpoints: [], ...page } });
  const consent = createConsent(cfg.consent, { win: env.win, doc: env.doc });
  const recorder = { starts: [], stops: 0, emit: null };
  const record = (options) => {
    recorder.starts.push(options);
    recorder.emit = options.emit;
    return () => { recorder.stops++; };
  };
  const identity = createIdentity({ win: env.win }, consent, (id, sessionChanged) => {
    client.setIdentity(id);
    if (sessionChanged && replay_) replay_.restart();
  });
  identity.sync();
  const client = new WPClient({ endpoint: cfg.endpoint, apiKey: cfg.key, source: 'shop', includePageContext: false, anonymousId: 'anon_unset', sessionId: identity.current().sessionId, sessionReplay: replayOptions(cfg) });
  client.setIdentity(identity.current());
  const navigation = installNavigation(env.win);
  const contextCalls = { n: 0 };
  env.respond((r) => {
    if (r.url.endsWith('/ingest/v1/replay/config')) return new Response(JSON.stringify(config), { status: 200 });
    if (r.url.endsWith('/ingest/v1/replay')) return new Response('{}', { status: 202 });
    return new Response('{}', { status: 200 });
  });
  const replay_ = createReplay({
    win: env.win, doc: env.doc, cfg, client, consent, identity, navigation,
    ownFetch: (u, i) => env.win.__originalFetch(u, i),
    loadRecorder: () => (recorderFails ? Promise.reject(new Error('blocked')) : Promise.resolve(record)),
    context: () => { contextCalls.n++; return context instanceof Error ? Promise.reject(context) : Promise.resolve(context); },
    hint: () => false,
  });
  consent.onChange((category, allowed) => {
    identity.sync();
    if (category === 'replay') { if (allowed) replay_.evaluate(); else replay_.withdraw(); }
  });
  return { replay: replay_, consent, identity, client, recorder, contextCalls, cfg, navigation };
}

const bodyText = (c) => (c.headers.get('content-encoding') === 'gzip' ? gunzipSync(Buffer.from(c.body)).toString('utf8') : String(c.body));
const uploads = () => env.callsTo('/ingest/v1/replay').filter((c) => !c.url.endsWith('/config'));

test('replayOptions: masking on by default, mandatory selectors always present, size limits from the settings', () => {
  const o = replayOptions(baseConfig({ replay: { sampleRate: 5, maskAllText: true, blockSelector: '.private', maskSelector: '.name', maxMinutes: 10, maxMegabytes: 5 } }));
  assert.equal(o.enabled, false, 'the plugin, never the SDK, decides when recording starts');
  assert.equal(o.maskInputs, true);
  assert.equal(o.maskAllText, true);
  assert.equal(o.sampleRate, 0.05);
  assert.equal(o.recordCanvas, false);
  assert.equal(o.maxSessionSeconds, 600);
  assert.equal(o.maxSessionBytes, 5000000);
  for (const s of MANDATORY_BLOCK) assert.ok(o.blockSelector.includes(s), s);
  for (const s of MANDATORY_MASK) assert.ok(o.maskSelector.includes(s), s);
  assert.ok(o.blockSelector.includes('.private') && o.maskSelector.includes('.name'));
  assert.ok(o.blockSelector.includes('input[type="password"]'));
  assert.ok(o.blockSelector.includes('[autocomplete^="cc-"]'));
});

test('even with "mask all text" switched off, address and account blocks stay masked and inputs are always masked', () => {
  const o = replayOptions(baseConfig({ replay: { maskAllText: false } }));
  assert.equal(o.maskAllText, false);
  assert.equal(o.maskInputs, true, 'there is no setting that turns input masking off');
  assert.ok(o.maskSelector.includes('.woocommerce-customer-details'));
});

test('scrubReplayUrl removes query strings and fragments, and the address in mailto:/tel: links', () => {
  assert.equal(scrubReplayUrl('https://x.test/a/b?token=1#frag'), 'https://x.test/a/b');
  assert.equal(scrubReplayUrl('/reset?key=abc&login=me'), '/reset');
  assert.equal(scrubReplayUrl('mailto:jane@example.com?subject=hi'), 'mailto:[redacted]');
  assert.equal(scrubReplayUrl('tel:+15551234567'), 'tel:[redacted]');
  assert.equal(scrubReplayUrl('javascript:alert(1)'), '');
  assert.equal(scrubReplayUrl('data:image/png;base64,AAAA'), 'data:image/png;base64,AAAA');
  assert.equal(scrubReplayUrl('a.png?x=1 1x, b.png?x=2 2x'), 'a.png 1x, b.png 2x');
  assert.equal(scrubReplayUrl('#section'), '');
});

test('the mandatory exclusions cover admin, login, account, cart and checkout paths', () => {
  for (const p of ['/wp-admin', '/wp-login.php', '/my-account', '/checkout', '/cart', '/order-pay', '/lost-password']) assert.ok(MANDATORY_EXCLUDED_PATHS.includes(p), p);
});

test('nothing happens before consent: no request, no recorder', async () => {
  const s = setup();
  s.replay.evaluate();
  await env.settle();
  assert.equal(env.calls.length, 0);
  assert.equal(s.recorder.starts.length, 0);
  assert.equal(s.contextCalls.n, 0);
  assert.deepEqual([s.replay.state().state, s.replay.state().reason], ['idle', 'consent']);
});

test('after consent, on an allowed page, for a permitted visitor, recording starts with masking applied', async () => {
  const s = setup();
  s.consent.grant('replay');
  await env.settle(40);
  assert.equal(s.replay.state().state, 'recording');
  assert.equal(s.recorder.starts.length, 1);
  const o = s.recorder.starts[0];
  assert.equal(o.maskAllInputs, true);
  assert.ok(o.maskTextSelector.split(',').includes('*'), 'all text masked');
  assert.ok(o.maskTextSelector.includes('input[type="password"]'));
  assert.ok(o.blockSelector.includes('.woocommerce-checkout-payment'));
  assert.equal(o.recordCanvas, false);
  const config = env.callsTo('/ingest/v1/replay/config')[0];
  assert.equal(config.headers.get('x-api-key'), 'zk_browser_public_key_for_tests');
});

test('a visitor who is not in the sample never triggers a role check, a config request or a download', async () => {
  const s = setup({ replay: { sampleRate: 0 } });
  s.consent.grant('replay');
  await env.settle(30);
  assert.equal(s.contextCalls.n, 0);
  assert.equal(env.calls.length, 0);
  assert.equal(s.recorder.starts.length, 0);
  assert.equal(s.replay.state().reason, 'not_sampled');
});

test('excluded pages never record: built-in paths, admin-added paths, and pages the server marked', async () => {
  for (const url of ['https://shop.example.test/checkout/', 'https://shop.example.test/my-account/orders/', 'https://shop.example.test/cart', 'https://shop.example.test/wp-login.php']) {
    const s = setup({ url });
    s.consent.grant('replay');
    await env.settle(30);
    assert.equal(s.recorder.starts.length, 0, url);
    assert.equal(s.contextCalls.n, 0, url + ' does not even ask');
  }
  const custom = setup({ url: 'https://shop.example.test/members/area/x', replay: { excludePaths: ['/members/*'] } });
  custom.consent.grant('replay');
  await env.settle(30);
  assert.equal(custom.recorder.starts.length, 0);

  const server = setup({ page: { replay: false } });
  server.consent.grant('replay');
  await env.settle(30);
  assert.equal(server.recorder.starts.length, 0);
  assert.equal(server.replay.state().reason, 'page');
});

test('a role that is excluded (or a failed role check) means no recording: fail closed', async () => {
  const denied = setup({ context: { loggedIn: true, replayAllowed: false, userRef: 'wpu_x' } });
  denied.consent.grant('replay');
  await env.settle(30);
  assert.equal(denied.recorder.starts.length, 0);
  assert.equal(denied.replay.state().reason, 'role');
  assert.equal(env.callsTo('/ingest/v1/replay').length, 0, 'not even the configuration request');

  const broken = setup({ context: new Error('admin-ajax down') });
  broken.consent.grant('replay');
  await env.settle(30);
  assert.equal(broken.recorder.starts.length, 0);
  assert.equal(broken.replay.state().reason, 'context_unavailable');

  const unknown = setup({ context: { loggedIn: true } }); // replayAllowed missing: not "true"
  unknown.consent.grant('replay');
  await env.settle(30);
  assert.equal(unknown.recorder.starts.length, 0);
});

test('navigating (client-side) to an excluded page stops the recording SYNCHRONOUSLY', async () => {
  const s = setup();
  s.consent.grant('replay');
  await env.settle(40);
  assert.equal(s.client.sessionReplay.isRecording(), true);
  env.win.history.pushState({}, '', '/checkout/');
  assert.equal(s.client.sessionReplay.isRecording(), false, 'stopped before the app can render the checkout page');
  assert.equal(s.recorder.stops, 1);
  assert.equal(s.replay.state().reason, 'path');
});

test('leaving an excluded page for an allowed one resumes recording', async () => {
  const s = setup({ url: 'https://shop.example.test/cart' });
  s.consent.grant('replay');
  await env.settle(30);
  assert.equal(s.recorder.starts.length, 0);
  env.win.history.pushState({}, '', '/blog/next');
  await env.settle(40);
  assert.equal(s.recorder.starts.length, 1);
  assert.equal(s.replay.state().state, 'recording');
});

test('withdrawing consent stops recording and DISCARDS what was not yet sent', async () => {
  const s = setup();
  s.consent.grant('replay');
  await env.settle(40);
  // 150 events fill and send a first chunk; 5 more stay in the buffer.
  for (let i = 0; i < 150; i++) s.recorder.emit({ type: 3, data: { i }, timestamp: Date.now() });
  await env.settle(30);
  const sentBefore = uploads().length;
  assert.ok(sentBefore >= 1, 'the first chunk went out while consent stood');
  for (let i = 0; i < 5; i++) s.recorder.emit({ type: 3, data: { late: i }, timestamp: Date.now() });

  s.consent.revoke('replay');
  await env.settle(60);
  assert.equal(s.client.sessionReplay.isRecording(), false);
  assert.equal(s.recorder.stops, 1);
  assert.equal(uploads().filter((c) => bodyText(c).includes('late')).length, 0, 'the unsent events never left the page');
  assert.equal(uploads().length, sentBefore, 'no further upload after withdrawal');
  assert.equal(s.replay.state().reason, 'consent');
  // The next session has a different id: nothing links it to the earlier recording.
  assert.equal(env.win.sessionStorage.getItem('zl_replay'), null);
});

test('replay consent given AFTER a page was viewed does not record earlier activity', async () => {
  const s = setup();
  s.replay.evaluate();
  await env.settle(20);
  assert.equal(s.recorder.starts.length, 0);
  s.consent.grant('replay');
  await env.settle(40);
  assert.equal(s.recorder.starts.length, 1, 'recording begins at the moment of consent, from the current state of the page');
});

test('withdrawing analytics while replay stays allowed ends the old recording and starts a new session', async () => {
  const s = setup();
  s.consent.grant(['analytics', 'replay']);
  await env.settle(40);
  const first = s.identity.current().sessionId;
  s.consent.revoke('analytics');
  await env.settle(60);
  assert.notEqual(s.identity.current().sessionId, first);
  assert.equal(s.recorder.starts.length, 2, 'a new recording under the new session');
});

test('a blocked recorder download or unreachable service leaves the page untouched', async () => {
  const blocked = setup({ recorderFails: true });
  blocked.consent.grant('replay');
  await env.settle(40);
  assert.equal(blocked.replay.state().recording, false);
  assert.equal(blocked.recorder.starts.length, 0);

  const down = setup({ config: null });
  env.respond(() => { throw new TypeError('offline'); });
  down.consent.grant('replay');
  await env.settle(40);
  assert.equal(down.recorder.starts.length, 0);
  assert.equal(down.replay.state().recording, false);
});

test('the service can switch recording off for everyone', async () => {
  const s = setup({ config: { enabled: false, reason: 'plan' } });
  s.consent.grant('replay');
  await env.settle(40);
  assert.equal(s.recorder.starts.length, 0);
});

test('uploads carry no query string in the address and the ingest key only in the header', async () => {
  const s = setup();
  s.consent.grant('replay');
  await env.settle(40);
  for (let i = 0; i < 150; i++) s.recorder.emit({ type: 3, data: { href: `https://x.test/p?token=SECRET${i}`, i }, timestamp: Date.now() });
  await env.settle(40);
  const up = uploads();
  assert.ok(up.length >= 1);
  for (const c of up) {
    assert.equal(c.headers.get('x-api-key'), 'zk_browser_public_key_for_tests');
    assert.ok(!bodyText(c).includes('SECRET'), 'query string values were scrubbed before upload');
    assert.ok(!c.url.includes('zk_'));
  }
});
