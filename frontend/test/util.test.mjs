import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { setupDom } from './env.mjs';

setupDom({ url: 'https://shop.example.test/page' });
const u = await import('../src/util.js');

test('scrubText removes credentials, tokens and personal data but keeps the meaning of the message', () => {
  const cases = [
    ['Failed at https://api.example.com/v1/users?token=abc123&email=a@b.co#frag', 'https://api.example.com/v1/users'],
    ['Authorization: Bearer abcdefghijklmnop', 'Authorization: [redacted]'],
    ['Cookie: wordpress_logged_in_x=abc; foo=bar', 'Cookie: [redacted]'],
    ['password=hunter2 and x', 'password=[redacted]'],
    ['{"api_key":"' + 'zk_' + 'live_abcdefgh1234"}', '[redacted]'],
    ['send to person@example.org now', '[email]'],
    ['from 203.0.113.42 refused', '[ip]'],
    ['card 4242 4242 4242 4242 declined', '[card]'],
    ['jwt ' + 'eyJ' + 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.abcdefghi', '[redacted]'],
  ];
  for (const [input, expected] of cases) {
    const out = u.scrubText(input);
    assert.ok(out.includes(expected), `${input} -> ${out}`);
  }
  assert.ok(!u.scrubText('token=abc123').includes('abc123'));
  assert.ok(!u.scrubText('see https://x.test/a?secret=zzz').includes('zzz'));
  assert.equal(u.scrubText('Cannot read properties of undefined (reading "length")'), 'Cannot read properties of undefined (reading "length")');
});

test('scrubText leaves a number that is not a card number alone', () => {
  assert.ok(u.scrubText('order 1234 5678 9012 3456 failed').includes('[card]') === false, 'fails Luhn, so it is not a card');
});

test('scrubText is bounded and never throws on hostile input', () => {
  const big = 'a'.repeat(200000);
  assert.ok(u.scrubText(big, 500).length <= 500);
  for (const v of [null, undefined, 12, {}, [], '\u0000￿']) assert.doesNotThrow(() => u.scrubText(v));
});

test('scrubStack keeps frames but scrubs each line and limits size', () => {
  const stack = 'Error: boom token=abc\n' + Array.from({ length: 80 }, (_, i) => `    at f${i} (https://shop.example.test/a.js?v=9:1:${i})`).join('\n');
  const out = u.scrubStack(stack, 25, 6000);
  assert.equal(out.split('\n').length, 25);
  assert.ok(!out.includes('abc') && !out.includes('v=9'));
});

test('isSensitiveKey recognises secrets and personal fields, not innocent names', () => {
  for (const k of ['password', 'user_pass', 'apiKey', 'x-api-key', 'authToken', 'email', 'billing_email', 'sessionId', 'creditCard', 'cvv', 'phone', 'nonce', '_wpnonce', 'ip', 'clientIP']) {
    assert.equal(u.isSensitiveKey(k), true, k);
  }
  for (const k of ['count', 'productId', 'currency', 'plan', 'page', 'keyboard', 'monkey']) {
    assert.equal(u.isSensitiveKey(k), false, k);
  }
});

test('safePath drops scheme, host, query and fragment, and normalizePath hides identifiers', () => {
  assert.equal(u.safePath('https://x.test/wp-admin/admin-ajax.php?action=a&token=t#z'), '/wp-admin/admin-ajax.php');
  assert.equal(u.safePath('/shop/?orderby=price'), '/shop/');
  assert.equal(u.safePath('/orders/12345/items/7', { normalize: true }), '/orders/:id/items/7');
  assert.equal(u.safePath('/u/3f2504e0-4f89-41d3-9a0c-0305e82c3301', { normalize: true }), '/u/:id');
  assert.ok(u.safePath('/' + 'a'.repeat(500)).length <= 200);
  assert.equal(u.safeUrl('https://user:pw@x.test:8443/p/q?s=1#h'), 'https://x.test:8443/p/q');
});

test('pathMatches uses whole segments and wildcards', () => {
  assert.equal(u.pathMatches('/checkout', '/checkout'), true);
  assert.equal(u.pathMatches('/checkout', '/checkout/order-pay/1'), true);
  assert.equal(u.pathMatches('/checkout', '/checkouts'), false);
  assert.equal(u.pathMatches('/members/*', '/members/a/b'), true);
  assert.equal(u.pathMatches('/wp-json/*', '/wp-json/wc/store/v1/cart'), true);
  assert.equal(u.pathMatches('', '/x'), false);
});

test('cleanProperties keeps flat primitives with safe names and drops the rest', () => {
  const out = u.cleanProperties({ plan: 'pro', count: 3, ok: true, email: 'a@b.co', password: 'x', nested: { a: 1 }, list: [1], 'bad key!': 1, fn() {}, big: 'z'.repeat(1000) });
  assert.deepEqual(Object.keys(out).sort(), ['big', 'count', 'ok', 'plan']);
  assert.ok(out.big.length <= 200);
  assert.deepEqual(u.cleanProperties(null), {});
  assert.deepEqual(u.cleanProperties([1, 2]), {});
});

test('eventName normalises to the shape the server keeps', () => {
  assert.equal(u.eventName('  Add To Cart! '), 'add_to_cart');
  assert.equal(u.eventName('checkout-started'), 'checkout_started');
  assert.equal(u.eventName(''), '');
  assert.equal(u.eventName(5), '');
  assert.ok(u.eventName('x'.repeat(500)).length <= 120);
});

test('sampledIn is deterministic and roughly proportional', () => {
  assert.equal(u.sampledIn('k', 0), false);
  assert.equal(u.sampledIn('k', 100), true);
  assert.equal(u.sampledIn('same', 30), u.sampledIn('same', 30));
  let hits = 0;
  for (let i = 0; i < 20000; i++) if (u.sampledIn('key-' + i, 10)) hits++;
  assert.ok(hits > 1600 && hits < 2400, `10% sample gave ${hits}/20000`);
});

test('randomHex produces the right length and does not repeat', () => {
  const a = u.randomHex(16);
  assert.match(a, /^[0-9a-f]{32}$/);
  assert.notEqual(a, u.randomHex(16));
  assert.match(u.randomId('sess'), /^sess_[0-9a-f]{20}$/);
});

test('guard swallows exceptions', () => {
  assert.equal(u.guard(() => { throw new Error('x'); }, 'fallback')(), 'fallback');
  assert.equal(u.guard((a, b) => a + b)(1, 2), 3);
});

test('no source file uses regex look-behind (Safari before 16.4 refuses to parse it)', () => {
  const dir = new URL('../src/', import.meta.url);
  for (const file of readdirSync(dir)) {
    const text = readFileSync(new URL(file, dir), 'utf8');
    assert.ok(!/\(\?<[=!]/.test(text), `${file} contains look-behind`);
  }
});
