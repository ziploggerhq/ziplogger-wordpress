import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupDom, baseConfig } from './env.mjs';

const env = setupDom({ url: 'https://shop.example.test/product/blue-shirt?utm=x&token=secret' });
const { installErrors } = await import('../src/errors.js');

function run(overrides = {}, sample = 'k') {
  const logs = [];
  const cfg = baseConfig({ modules: { browser: true }, browser: { errors: true, errorSampleRate: 100, maxErrorsPage: 20, ...overrides } });
  const stop = installErrors({ win: env.win, cfg, sampleKey: sample, log: (e) => logs.push(e), pageFields: () => ({ path: '/product/blue-shirt' }) });
  return { logs, stop };
}

function fireError(message, extra = {}) {
  const ev = new env.win.ErrorEvent('error', { message, filename: extra.filename || 'https://shop.example.test/wp-content/themes/t/app.js?ver=5.9', lineno: extra.lineno || 10, colno: 4, error: extra.error });
  env.win.dispatchEvent(ev);
}

test('an uncaught error becomes one sanitized log entry', () => {
  const { logs, stop } = run();
  const err = new Error('Cannot read token=abc123 of user a@b.co');
  fireError(err.message, { error: err });
  stop();
  assert.equal(logs.length, 1);
  const e = logs[0];
  assert.equal(e.severity, 'error');
  assert.ok(!/abc123|a@b\.co/.test(e.message), e.message);
  assert.equal(e.fields.eventType, 'js_error');
  assert.equal(e.fields.file, '/wp-content/themes/t/app.js', 'file is a path without query string');
  assert.equal(e.fields.handler, 'window.onerror');
  assert.equal(e.fields.path, '/product/blue-shirt');
  assert.ok(!JSON.stringify(e).includes('ver=5.9'));
  assert.ok(!JSON.stringify(e).includes('secret'), 'the page query string never appears');
});

test('an unhandled promise rejection is reported', () => {
  const { logs, stop } = run();
  const ev = new env.win.Event('unhandledrejection');
  ev.reason = new Error('fetch exploded');
  env.win.dispatchEvent(ev);
  stop();
  assert.equal(logs.length, 1);
  assert.match(logs[0].message, /Unhandled rejection: fetch exploded/);
  assert.equal(logs[0].fields.handler, 'unhandledrejection');
});

test('a string or empty rejection reason does not crash the handler', () => {
  const { logs, stop } = run();
  for (const reason of ['plain text', undefined, null, 42, { a: 1 }]) {
    const ev = new env.win.Event('unhandledrejection');
    ev.reason = reason;
    env.win.dispatchEvent(ev);
  }
  stop();
  assert.equal(logs.length >= 2, true);
});

test('repeats are counted, not repeated: reports at 1, 10 and 100 occurrences', () => {
  const { logs, stop } = run();
  for (let i = 0; i < 120; i++) fireError('same failure');
  stop();
  assert.deepEqual(logs.map((l) => l.fields.occurrences), [1, 10, 100]);
});

test('the number of distinct errors per page is capped', () => {
  const { logs, stop } = run({ maxErrorsPage: 3 });
  for (let i = 0; i < 20; i++) fireError('different failure ' + i);
  stop();
  assert.equal(logs.length, 3);
});

test('a failing <img> or <script> resource is not a JavaScript error', () => {
  const { logs, stop } = run();
  const img = env.doc.createElement('img');
  env.doc.body.appendChild(img);
  const ev = new env.win.Event('error', { bubbles: false });
  img.dispatchEvent(ev);
  const evOnWindow = new env.win.Event('error');
  Object.defineProperty(evOnWindow, 'target', { value: img });
  env.win.dispatchEvent(evOnWindow);
  stop();
  assert.equal(logs.length, 0);
});

test('sample rate 0 installs nothing; disabled errors install nothing', () => {
  const a = run({ errorSampleRate: 0 });
  fireError('x');
  a.stop();
  assert.equal(a.logs.length, 0);
  const b = run({ errors: false });
  fireError('y');
  b.stop();
  assert.equal(b.logs.length, 0);
});

test('a log function that throws never reaches the page', () => {
  const cfg = baseConfig({ modules: { browser: true } });
  const stop = installErrors({ win: env.win, cfg, sampleKey: 'z', log: () => { throw new Error('boom'); }, pageFields: () => { throw new Error('boom2'); } });
  assert.doesNotThrow(() => fireError('anything'));
  stop();
});

test('stopping removes the listeners', () => {
  const { logs, stop } = run();
  stop();
  fireError('after stop');
  assert.equal(logs.length, 0);
});

test('stack frames are scrubbed and limited', () => {
  const { logs, stop } = run();
  const err = new Error('x');
  err.stack = 'Error: x\n' + Array.from({ length: 60 }, (_, i) => `    at fn${i} (https://shop.example.test/a.js?sig=zz:1:${i})`).join('\n');
  fireError('with stack', { error: err });
  stop();
  const stack = logs[0].stackTrace;
  assert.ok(stack.split('\n').length <= 25);
  assert.ok(!stack.includes('sig=zz'));
});
