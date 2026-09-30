import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom } from './env.mjs';

setupDom();
const { createConsent } = await import('../src/consent.js');
const { createIdentity } = await import('../src/identity.js');

function make(policy = {}) {
  const env = setupDom();
  const consent = createConsent({ policy: { browser: 'none', analytics: 'required', replay: 'required', ...policy }, cookie: 'ziplogger_consent' }, { win: env.win, doc: env.doc });
  const changes = [];
  const identity = createIdentity({ win: env.win }, consent, (id, sessionChanged) => changes.push({ ...id, sessionChanged }));
  return { env, consent, identity, changes };
}

test('before consent nothing is written to the device and there is no anonymous id', () => {
  const { env, identity } = make();
  identity.sync();
  const id = identity.current();
  assert.match(id.sessionId, /^sess_[0-9a-f]{20}$/, 'a page-view id in memory');
  assert.equal(id.anonymousId, null);
  assert.equal(env.win.localStorage.length, 0);
  assert.equal(env.win.sessionStorage.length, 0);
  assert.equal(identity.persisted(), false);
});

test('analytics consent creates and stores both ids, and keeps the id already used on this page', () => {
  const { env, consent, identity } = make();
  identity.sync();
  const beforeSession = identity.current().sessionId;
  consent.grant('analytics');
  identity.sync();
  const id = identity.current();
  assert.equal(id.sessionId, beforeSession, 'consent given mid-page does not split the visit');
  assert.match(id.anonymousId, /^anon_[0-9a-f]{20}$/);
  assert.equal(env.win.localStorage.getItem('zl_anon'), id.anonymousId);
  assert.equal(env.win.sessionStorage.getItem('zl_sess'), id.sessionId);
});

test('replay consent alone stores a session id but never an anonymous id', () => {
  const { env, consent, identity } = make();
  consent.grant('replay');
  identity.sync();
  assert.equal(identity.current().anonymousId, null);
  assert.ok(env.win.sessionStorage.getItem('zl_sess'));
  assert.equal(env.win.localStorage.getItem('zl_anon'), null);
});

test('stored ids are reused on the next page view', () => {
  const { env, consent, identity } = make();
  consent.grant('analytics');
  identity.sync();
  const first = identity.current();
  const consent2 = createConsent({ policy: { analytics: 'none' } }, { win: env.win, doc: env.doc });
  const again = createIdentity({ win: env.win }, consent2, () => {});
  again.sync();
  assert.equal(again.current().anonymousId, first.anonymousId);
  assert.equal(again.current().sessionId, first.sessionId);
});

test('withdrawing consent removes stored ids and starts new, unlinked ones', () => {
  const { env, consent, identity, changes } = make();
  consent.grant(['analytics', 'replay']);
  identity.sync();
  const before = identity.current();
  consent.revoke(['analytics', 'replay']);
  identity.sync();
  const after = identity.current();
  assert.equal(env.win.localStorage.getItem('zl_anon'), null);
  assert.equal(env.win.sessionStorage.getItem('zl_sess'), null);
  assert.equal(after.anonymousId, null);
  assert.notEqual(after.sessionId, before.sessionId);
  assert.ok(changes.some((c) => c.sessionChanged));
});

test('withdrawing only analytics while replay stays allowed still cuts the link (new session)', () => {
  const { consent, identity } = make();
  consent.grant(['analytics', 'replay']);
  identity.sync();
  const before = identity.current();
  consent.revoke('analytics');
  identity.sync();
  const after = identity.current();
  assert.equal(after.anonymousId, null);
  assert.notEqual(after.sessionId, before.sessionId, 'a session id that connects old analytics to new recordings must not survive');
});

test('the replay state stored by the SDK is removed when replay consent goes', () => {
  const { env, consent, identity } = make();
  consent.grant('replay');
  identity.sync();
  env.win.sessionStorage.setItem('zl_replay', '{"sid":"x","sampled":true,"seq":3}');
  consent.revoke('replay');
  identity.sync();
  assert.equal(env.win.sessionStorage.getItem('zl_replay'), null);
});

test('a user id is accepted only with an anonymous history and only in the pseudonym shape', () => {
  const { consent, identity } = make();
  identity.sync();
  assert.equal(identity.setUser('wpu_0123456789abcdef01234567'), null, 'no consent, no anonymous id, no user id');
  consent.grant('analytics');
  identity.sync();
  assert.equal(identity.setUser('someone@example.com'), null, 'an email is refused');
  assert.equal(identity.setUser('wpu_0123456789abcdef01234567'), 'wpu_0123456789abcdef01234567');
  consent.revoke('analytics');
  identity.sync();
  assert.equal(identity.current().userId, null, 'withdrawal forgets the user');
});

test('rotate (sign-out) gives a new visitor and session', () => {
  const { consent, identity } = make();
  consent.grant('analytics');
  identity.sync();
  const before = identity.current();
  identity.setUser('wpu_0123456789abcdef01234567');
  identity.rotate();
  const after = identity.current();
  assert.notEqual(after.anonymousId, before.anonymousId);
  assert.notEqual(after.sessionId, before.sessionId);
  assert.equal(after.userId, null);
});

test('blocked storage does not break anything: ids stay in memory', () => {
  const env = setupDom();
  Object.defineProperty(env.win, 'localStorage', { get() { throw new Error('blocked'); }, configurable: true });
  Object.defineProperty(env.win, 'sessionStorage', { get() { throw new Error('blocked'); }, configurable: true });
  const consent = createConsent({ policy: { analytics: 'none' } }, { win: env.win, doc: env.doc });
  const identity = createIdentity({ win: env.win }, consent, () => {});
  assert.doesNotThrow(() => identity.sync());
  assert.ok(identity.current().anonymousId);
});
