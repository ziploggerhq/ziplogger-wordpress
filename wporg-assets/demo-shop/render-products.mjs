// Renders the ziplogger.dev demo shop product images (fictional "Northwind Coffee" brand) from product.html.
// Run inside the pinned Playwright image from /e2e/browser (see ../src/render.mjs for the docker command).
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const require = createRequire(path.join(process.cwd(), 'noop.js'));
const { chromium } = require('playwright');

const here = path.dirname(new URL(import.meta.url).pathname);
const products = [
  { file: 'house-blend.png', name: 'House Blend', sub: 'Chocolate · caramel · medium roast', bag: '#7a4a2a', accent: '#a5522c', bg1: '#f3e5d6', bg2: '#d9bfa5' },
  { file: 'ethiopia.png', name: 'Ethiopia Yirgacheffe', sub: 'Jasmine · lemon · light roast', bag: '#2f6f5e', accent: '#2f6f5e', bg1: '#e3efe9', bg2: '#bcd6cb' },
  { file: 'espresso.png', name: 'Espresso Roast', sub: 'Dark cocoa · molasses · dark roast', bag: '#262022', accent: '#b0412e', bg1: '#ece3df', bg2: '#c9b8b1' },
  { file: 'decaf.png', name: 'Colombia Decaf', sub: 'Sugarcane process · medium roast', bag: '#4a5d8a', accent: '#4a5d8a', bg1: '#e4e8f2', bg2: '#bfc8de' },
  { file: 'pour-over-kit.png', name: 'Pour-over Kit', sub: 'Dripper · filters · glass server', bag: '#c9a66b', accent: '#8a6a32', bg1: '#f5eedf', bg2: '#dfcfae', shape: 'box', mark: '&#9670;' },
  { file: 'subscription.png', name: 'Monthly Subscription', sub: 'A new single origin every month', bag: '#a5522c', accent: '#a5522c', bg1: '#f6e4d8', bg2: '#e0bfa8', shape: 'box', mark: '&#8635;' },
];

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 800, height: 800 } });
const page = await context.newPage();
for (const p of products) {
  const q = new URLSearchParams(Object.fromEntries(Object.entries(p).filter(([k]) => k !== 'file')));
  await page.goto(pathToFileURL(path.join(here, 'product.html')).href + '?' + q.toString());
  await page.screenshot({ path: path.join(here, 'out', p.file) });
  console.log(p.file);
}
await browser.close();
