// Runs ONE scenario in a real Chromium and prints its result as a single JSON line.
//   node run.mjs <scenario> '<json arguments>'
// The host-side test (../m3-browser.test.mjs) configures WordPress and the receiver, calls this through
// docker compose, and makes the assertions.
import { chromium } from 'playwright';
import scenarios from './scenarios.mjs';

const [name, argRaw] = process.argv.slice(2);
const raw = argRaw || process.env.SCENARIO_ARGS;
const args = raw ? JSON.parse(raw) : {};
if (!scenarios[name]) {
  console.error(`unknown scenario ${name}; known: ${Object.keys(scenarios).join(', ')}`);
  process.exit(2);
}

const browser = await chromium.launch({ args: ['--disable-dev-shm-usage'] });
try {
  const result = await scenarios[name]({ browser, site: process.env.SITE || 'http://wordpress', args });
  console.log('@@RESULT@@' + JSON.stringify(result));
} catch (e) {
  console.log('@@RESULT@@' + JSON.stringify({ scenarioError: String((e && e.stack) || e) }));
  process.exitCode = 1;
} finally {
  await browser.close();
}
