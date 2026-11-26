// Renders the WordPress.org directory images (icon, banner) from the HTML next to this file.
// Run inside the pinned Playwright image, e.g.:
//   docker compose -f docker-compose.e2e.yml --profile tools run --rm -T -v "$PWD/wporg-assets:/wporg" browser \
//     sh -c "node /wporg/src/render.mjs"
// (run from /e2e/browser so `playwright` resolves from that folder's node_modules)
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const require = createRequire(path.join(process.cwd(), 'noop.js'));
const { chromium } = require('playwright');

const here = path.dirname(new URL(import.meta.url).pathname);
const out = path.join(here, '..');
const jobs = [
  { html: 'icon.html', w: 128, h: 128, scale: 1, file: 'icon-128x128.png' },
  { html: 'icon.html', w: 128, h: 128, scale: 2, file: 'icon-256x256.png' },
  { html: 'banner.html', w: 772, h: 250, scale: 1, file: 'banner-772x250.png' },
  { html: 'banner.html', w: 772, h: 250, scale: 2, file: 'banner-1544x500.png' },
];

const browser = await chromium.launch();
for (const job of jobs) {
  const context = await browser.newContext({ viewport: { width: job.w, height: job.h }, deviceScaleFactor: job.scale });
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(here, job.html)).href, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const fonts = await page.evaluate(() => [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family));
  await page.screenshot({ path: path.join(out, job.file), clip: { x: 0, y: 0, width: job.w, height: job.h } });
  console.log(`${job.file}  ${job.w * job.scale}x${job.h * job.scale}  fonts: ${[...new Set(fonts)].join(', ') || 'none (fallback)'}`);
  await context.close();
}
await browser.close();
