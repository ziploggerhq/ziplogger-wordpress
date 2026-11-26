// Runs inside the pinned Playwright image (see make-screenshots.mjs):
//   node browser.mjs traffic '{"rounds":3}'
//   node browser.mjs shots   '{"out":"/out","tabs":[...]}'
import { createRequire } from 'node:module';

const require = createRequire('/e2e/browser/noop.js');
const { chromium } = require('playwright');

const SITE = process.env.SITE;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const [mode, raw] = process.argv.slice(2);
const args = raw ? JSON.parse(raw) : {};

const browser = await chromium.launch({ args: ['--disable-dev-shm-usage'] });
const result = { mode, visits: 0, shots: [] };
try {
  if (mode === 'traffic') {
    const pages = args.pages || ['/', '/shop/', '/checkout/', '/contact/', '/brew-guides/', '/about/', '/shop/'];
    // Visitors on different devices: a desktop on broadband, a mid-range phone on 4G, an older phone on slow 4G.
    const profiles = [
      { viewport: { width: 1366, height: 800 }, latency: 30, kbps: 20000, cpu: 1 },
      { viewport: { width: 390, height: 844 }, latency: 90, kbps: 6000, cpu: 4 },
      { viewport: { width: 360, height: 780 }, latency: 160, kbps: 1600, cpu: 6 },
    ];
    let n = 0;
    for (let round = 0; round < (args.rounds || 3); round++) {
      for (const path of pages) {
        const p = profiles[n++ % profiles.length];
        const context = await browser.newContext({ viewport: p.viewport, isMobile: p.viewport.width < 500, hasTouch: p.viewport.width < 500 });
        const page = await context.newPage();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: p.latency, downloadThroughput: (p.kbps * 1024) / 8, uploadThroughput: (p.kbps * 512) / 8 });
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: p.cpu });
        await page.goto(SITE + path, { waitUntil: 'load' }).catch(() => {});
        await sleep(1400);
        await page.mouse.click(Math.round(p.viewport.width / 2), 400).catch(() => {});   // a real interaction, so INP is measured
        await sleep(300);
        await page.mouse.click(Math.round(p.viewport.width / 3), 500).catch(() => {});
        await sleep(900);
        // Leaving the page is what makes the browser report LCP, CLS and INP; then deliver what is queued.
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))).catch(() => {});
        await page.evaluate(() => (window.ZipLoggerWP ? window.ZipLoggerWP.flush() : null)).catch(() => {});
        await sleep(400);
        await context.close();
        result.visits++;
      }
    }
  }

  if (mode === 'shots') {
    const context = await browser.newContext({ viewport: { width: 1280, height: args.height || 900 }, deviceScaleFactor: args.scale || 1.5 });
    const page = await context.newPage();
    await page.goto(`${SITE}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'e2e-admin-pass-1');
    await Promise.all([page.waitForURL((u) => !/wp-login\.php/.test(u.pathname), { timeout: 60000 }), page.click('#wp-submit')]);
    for (const shot of args.tabs) {
      // A warm-up load delivers what the admin's earlier page loads queued (the demo plugin flushes on this page),
      // so the health card shows the steady state a real site shows, not "waiting to be sent".
      if (shot.warm) { await page.goto(`${SITE}/wp-admin/options-general.php?page=ziplogger&tab=${shot.tab}`, { waitUntil: 'load' }); await sleep(1500); }
      await page.goto(`${SITE}/wp-admin/options-general.php?page=ziplogger&tab=${shot.tab}`, { waitUntil: 'load' });
      await page.waitForFunction(() => [...document.querySelectorAll('.ziplogger-live-body')].every((b) => !/Loading/.test(b.textContent)), null, { timeout: 30000 }).catch(() => {});
      await sleep(500);
      if (shot.scrollTo) {
        await page.evaluate((sel) => { const el = document.querySelector(sel); if (el) window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 20); }, shot.scrollTo);
        await sleep(200);
      }
      if (shot.click) { await page.click(shot.click).catch(() => {}); await sleep(300); }
      const file = `${args.out}/${shot.file}`;
      await page.screenshot({ path: file, fullPage: !!shot.full });
      result.shots.push({ file: shot.file, height: await page.evaluate(() => document.documentElement.scrollHeight) });
    }
    await context.close();
  }

  if (mode === 'readtest') {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${SITE}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'e2e-admin-pass-1');
    await Promise.all([page.waitForURL((u) => !/wp-login\.php/.test(u.pathname), { timeout: 60000 }), page.click('#wp-submit')]);
    await page.goto(`${SITE}/wp-admin/options-general.php?page=ziplogger&tab=connection`, { waitUntil: 'load' });
    await Promise.all([page.waitForNavigation(), page.click('input[value="Test the read key"]')]);
    result.notice = await page.evaluate(() => (document.querySelector('.notice') || {}).innerText || '');
    await context.close();
  }
} catch (e) {
  result.error = String((e && e.stack) || e);
  process.exitCode = 1;
} finally {
  await browser.close();
}
console.log('@@RESULT@@' + JSON.stringify(result));
