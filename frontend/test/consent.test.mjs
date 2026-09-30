import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom } from './env.mjs';

const { createConsent } = await (async () => { setupDom(); return import('../src/consent.js'); })();

function make(cfg = {}, dom = {}) {
  const env = setupDom(dom);
  const consent = createConsent({ policy: { browser: 'none', analytics: 'required', replay: 'required', commerce: 'none' }, cookie: 'ziplogger_consent', cookiePath: '/', honorDnt: true, ...cfg }, { win: env.win, doc: env.doc });
  return { env, consent };
}

test('a category whose policy is "none" is allowed without any evidence', () => {
  const { consent } = make();
  assert.equal(consent.allows('browser'), true);
  assert.equal(consent.allows('commerce'), true);
});

test('a category that requires consent is NOT allowed while there is no evidence', () => {
  const { consent } = make();
  assert.equal(consent.state('analytics'), 'unknown');
  assert.equal(consent.allows('analytics'), false);
  assert.equal(consent.allows('replay'), false);
});

test('grant enables only the named categories and writes a first-party cookie', () => {
  const { consent, env } = make();
  consent.grant('analytics');
  assert.equal(consent.allows('analytics'), true);
  assert.equal(consent.allows('replay'), false);
  assert.match(env.doc.cookie, /ziplogger_consent=analytics/);
});

test('revoke withdraws the category and tells subscribers', () => {
  const { consent } = make();
  const seen = [];
  consent.onChange((category, allowed) => seen.push([category, allowed]));
  consent.grant(['analytics', 'replay']);
  consent.revoke('replay');
  assert.deepEqual(seen, [['analytics', true], ['replay', true], ['replay', false]]);
  assert.equal(consent.allows('replay'), false);
  assert.equal(consent.allows('analytics'), true);
});

test('the stored choice survives a reload (a new instance reads the cookie)', () => {
  const first = make();
  first.consent.grant('analytics');
  const cookie = first.env.doc.cookie;
  const env2 = setupDom();
  env2.doc.cookie = cookie + '; path=/';
  const again = createConsent({ policy: { analytics: 'required' }, cookie: 'ziplogger_consent' }, { win: env2.win, doc: env2.doc });
  assert.equal(again.allows('analytics'), true);
});

test('a malformed cookie is ignored, not trusted', () => {
  const env = setupDom();
  env.doc.cookie = 'ziplogger_consent=analytics%3B%20drop; path=/';
  const consent = createConsent({ policy: { analytics: 'required' }, cookie: 'ziplogger_consent' }, { win: env.win, doc: env.doc });
  assert.equal(consent.allows('analytics'), false);
});

test('Do Not Track and Global Privacy Control block identifying categories, not plain error monitoring', () => {
  const { consent, env } = make();
  Object.defineProperty(env.win.navigator, 'doNotTrack', { value: '1', configurable: true });
  const dnt = createConsent({ policy: { browser: 'none', analytics: 'none', replay: 'required' }, cookie: 'c', honorDnt: true }, { win: env.win, doc: env.doc });
  dnt.grant('replay');
  assert.equal(dnt.allows('analytics'), false, 'even a "no consent needed" policy yields to DNT');
  assert.equal(dnt.allows('replay'), false, 'even after a grant');
  assert.equal(dnt.allows('browser'), true);
  const relaxed = createConsent({ policy: { analytics: 'none' }, cookie: 'c', honorDnt: false }, { win: env.win, doc: env.doc });
  assert.equal(relaxed.allows('analytics'), true);
  assert.ok(consent);
});

test('the WordPress Consent API decides when it is present and configured', () => {
  const env = setupDom();
  const answers = { statistics: false };
  env.win.wp_has_consent = (c) => !!answers[c];
  const consent = createConsent({ policy: { analytics: 'required' }, wpConsentApi: true, wpCategories: { analytics: 'statistics' }, cookie: 'c' }, { win: env.win, doc: env.doc });
  assert.equal(consent.allows('analytics'), false);
  answers.statistics = true;
  const seen = [];
  consent.onChange((c, a) => seen.push([c, a]));
  env.doc.dispatchEvent(new env.win.CustomEvent('wp_listen_for_consent_change', { detail: { statistics: 'allow' } }));
  assert.equal(consent.allows('analytics'), true);
  assert.deepEqual(seen, [['analytics', true]]);
});

test('a throwing consent manager counts as no answer, never as consent', () => {
  const env = setupDom();
  env.win.wp_has_consent = () => { throw new Error('banner crashed'); };
  const consent = createConsent({ policy: { analytics: 'required' }, wpConsentApi: true, wpCategories: { analytics: 'statistics' }, cookie: 'c' }, { win: env.win, doc: env.doc });
  assert.equal(consent.allows('analytics'), false);
});

test('the cookie is Secure on https pages', () => {
  const env = setupDom({ url: 'https://shop.example.test/' });
  const writes = [];
  const doc = { cookie: '', dispatchEvent() {}, addEventListener() {} };
  Object.defineProperty(doc, 'cookie', { get: () => '', set: (v) => writes.push(v) });
  const consent = createConsent({ policy: { analytics: 'required' }, cookie: 'c' }, { win: env.win, doc });
  consent.grant('analytics');
  assert.match(writes[0], /; Secure/);
  assert.match(writes[0], /SameSite=Lax/);
});
