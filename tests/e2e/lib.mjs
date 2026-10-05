// Helpers for the end-to-end tests: docker compose, WP-CLI, HTTP against the site and the receiver.
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
export const SITE = process.env.E2E_SITE || 'http://localhost:8088';
export const MOCK = process.env.E2E_MOCK || 'http://localhost:5081';
export const KEYS = {
  server: 'zk_e2e_server_key_0000000000',
  browser: 'zk_e2e_browser_key_00000000',
  read: 'zk_e2e_read_key_000000000000',
};

const COMPOSE = ['compose', '-f', 'docker-compose.e2e.yml'];

export function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, ...opts });
  return { status: r.status, stdout: (r.stdout || '').trim(), stderr: (r.stderr || '').trim() };
}

export const dc = (...args) => run('docker', [...COMPOSE, ...args], { env: { ...process.env, MSYS_NO_PATHCONV: '1' } });

/** Run a WP-CLI command inside the tools container. Throws on failure unless allowFail. */
export function wp(args, { allowFail = false } = {}) {
  const list = Array.isArray(args) ? args : args.split(/\s+/);
  const r = dc('--profile', 'tools', 'run', '--rm', '-T', 'wpcli', 'wp', ...list);
  if (r.status !== 0 && !allowFail) throw new Error(`wp ${list.join(' ')} failed (${r.status}): ${r.stderr || r.stdout}`);
  return r;
}

/** Evaluate PHP inside WordPress. */
export function wpEval(php, opts) {
  return wp(['eval', php], opts).stdout;
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function waitFor(fn, { timeout = 60000, every = 1000, what = 'condition' } = {}) {
  const end = Date.now() + timeout;
  let last;
  while (Date.now() < end) {
    try { last = await fn(); if (last) return last; } catch (e) { last = e; }
    await sleep(every);
  }
  throw new Error(`timed out waiting for ${what}: ${last && last.message ? last.message : last}`);
}

// ------------------------------------------------------------------------------------------------
// The receiver.
// ------------------------------------------------------------------------------------------------
export const mock = {
  async control(body) {
    const r = await fetch(`${MOCK}/__control`, { method: 'POST', body: JSON.stringify(body) });
    return r.json();
  },
  async received(pathFilter) {
    const r = await fetch(`${MOCK}/__received${pathFilter ? `?path=${encodeURIComponent(pathFilter)}` : ''}`);
    return r.json();
  },
  async clear() { await fetch(`${MOCK}/__received`, { method: 'DELETE' }); await this.control({ mode: 'ok' }); },
  async logs() { return (await this.received('/ingest/v1/logs')); },
  /** Every log record the receiver actually stored: the admitted prefix of 202 AND 429 responses, never a replay. */
  async admitted() {
    return (await this.logs()).filter((e) => e.admitted && !e.replayed).flatMap((e) => e.admitted);
  },
};

// ------------------------------------------------------------------------------------------------
// The site.
// ------------------------------------------------------------------------------------------------
export async function get(pathname, opts = {}) {
  const r = await fetch(SITE + pathname, { redirect: 'manual', ...opts });
  return { status: r.status, text: await r.text(), headers: r.headers };
}

export function jsonOption(name) {
  const r = wp(['option', 'get', name, '--format=json'], { allowFail: true });
  return r.status === 0 ? JSON.parse(r.stdout) : null;
}

export function setSettings(obj) {
  wp(['option', 'update', 'ziplogger_settings', JSON.stringify(obj), '--format=json']);
}

export function status() {
  return JSON.parse(wp(['ziplogger', 'status', '--format=json']).stdout);
}

/** Log N distinct developer events in one PHP process (the per-request cap is raised for the seeding process only). */
export function seedLogs(n, prefix = 'event') {
  return wpEval(
    "add_filter('ziplogger_limits', function ($l) { $l['request_event_cap'] = 1000; return $l; }); " +
      "for ($i = 1; $i <= " + n + "; $i++) { ziplogger_log('error', '" + prefix + " ' . $i, array('n' => $i)); } " +
      "\\ZipLogger\\WordPress\\Plugin::instance()->recorder()->flush();"
  );
}

// ------------------------------------------------------------------------------------------------
// Installing the built ZIP, and driving a real browser.
// ------------------------------------------------------------------------------------------------
import fs from 'node:fs';

/** A fresh install of dist/ziplogger.zip into the running WordPress (all plugin data removed first). */
export function installZip() {
  const zip = path.join(ROOT, 'dist', 'ziplogger.zip');
  if (!fs.existsSync(zip)) throw new Error('build the ZIP first: php bin/build-zip.php');
  // 'ziplogger' is the folder an earlier build installed into; two copies of the plugin would redeclare every class.
  for (const slug of ['ziplogger', 'ziplogger-error-monitoring-session-replay']) {
    wp(['plugin', 'deactivate', slug], { allowFail: true });
    wp(['plugin', 'delete', slug], { allowFail: true });
  }
  for (const option of ['ziplogger_settings', 'ziplogger_api_key', 'ziplogger_browser_key', 'ziplogger_read_key', 'ziplogger_secret', 'ziplogger_db_version']) {
    wp(['option', 'delete', option], { allowFail: true });
  }
  // Every suite starts from a site that has never seen the plugin: no queue rows, no counters from an earlier suite.
  wp(['db', 'query', 'DROP TABLE IF EXISTS wp_ziplogger_queue, wp_ziplogger_meta'], { allowFail: true });
  const cp = dc('cp', zip, 'wordpress:/tmp/ziplogger.zip');
  if (cp.status !== 0) throw new Error(cp.stderr);
  dc('exec', '-T', '-u', '0', 'wordpress', 'sh', '-c', 'cp /tmp/ziplogger.zip /var/www/html/ziplogger.zip && chown www-data:www-data /var/www/html/ziplogger.zip');
  wp(['plugin', 'install', '/var/www/html/ziplogger.zip', '--activate']);
}

/** Save the three credentials (pass null to remove one). */
export function setKeys({ server = KEYS.server, browser = KEYS.browser, read = KEYS.read } = {}) {
  for (const [name, value] of [['ziplogger_api_key', server], ['ziplogger_browser_key', browser], ['ziplogger_read_key', read]]) {
    if (value) wp(['option', 'update', name, value]); else wp(['option', 'delete', name], { allowFail: true });
  }
}

/**
 * Run one scenario in a real Chromium (tests/e2e/browser/scenarios.mjs) and return its evidence.
 * The first run installs Playwright into a Docker volume; the browser itself ships in the image.
 */
export function runBrowser(scenario, args = {}, { timeout = 240000 } = {}) {
  const script = '[ -d node_modules/playwright ] && [ -d node_modules/axe-core ] || npm install --no-audit --no-fund >/dev/null 2>&1; node run.mjs "$SCENARIO"';
  const r = run('docker', [...COMPOSE, '--profile', 'tools', 'run', '--rm', '-T', '-e', `SCENARIO=${scenario}`, '-e', `SCENARIO_ARGS=${JSON.stringify(args)}`, 'browser', 'sh', '-c', script], { env: { ...process.env, MSYS_NO_PATHCONV: '1' }, timeout });
  const line = r.stdout.split('\n').reverse().find((l) => l.startsWith('@@RESULT@@'));
  if (!line) throw new Error(`scenario ${scenario} produced no result (${r.status}): ${r.stderr || r.stdout}`.slice(0, 2000));
  const result = JSON.parse(line.slice('@@RESULT@@'.length));
  if (result.scenarioError) throw new Error(`scenario ${scenario} failed: ${result.scenarioError}`);
  return result;
}

import http from 'node:http';

/** GET a page from the site while presenting another Host name (the site builds its URLs from the Host header). */
export function getAsHost(host, pathname) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: 'localhost', port: 8088, path: pathname, method: 'GET', headers: { Host: host } }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, text: Buffer.concat(chunks).toString('utf8') }));
    });
    req.on('error', reject);
    req.end();
  });
}

/** Put a plain file into WordPress's web root: served by Apache directly, no PHP, like a page cache would. */
export function putStatic(name, content) {
  const r = run('docker', [...COMPOSE, 'exec', '-T', '-u', '0', 'wordpress', 'sh', '-c', `base64 -d > /var/www/html/${name} && chmod 644 /var/www/html/${name}`], { input: Buffer.from(content).toString('base64'), env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  if (r.status !== 0) throw new Error(r.stderr);
}

// ------------------------------------------------------------------------------------------------
// WooCommerce in the end-to-end site.
// ------------------------------------------------------------------------------------------------
export const WC_VERSION = '11.1.2';

/**
 * Install and configure WooCommerce once: guest checkout, USD, no tax, offline gateways, a virtual product,
 * the standard pages (block cart/checkout) and a classic (shortcode) cart/checkout pair.
 */
export function setupWoo() {
  if (wp(['plugin', 'is-installed', 'woocommerce'], { allowFail: true }).status !== 0) {
    wp(['plugin', 'install', 'woocommerce', `--version=${WC_VERSION}`]);
  }
  wp(['plugin', 'activate', 'woocommerce']);
  const options = {
    woocommerce_enable_guest_checkout: 'yes',
    woocommerce_currency: 'USD',
    woocommerce_default_country: 'US:CA',
    woocommerce_calc_taxes: 'no',
    woocommerce_enable_signup_and_login_from_checkout: 'no',
    woocommerce_ship_to_countries: 'disabled',
    woocommerce_onboarding_profile: '{"skipped":true}',
    woocommerce_task_list_hidden: 'yes',
    woocommerce_coming_soon: 'no',
    woocommerce_store_pages_only: 'no',
  };
  for (const [k, v] of Object.entries(options)) wp(['option', 'update', k, v], { allowFail: true });
  wp(['option', 'update', 'woocommerce_bacs_settings', JSON.stringify({ enabled: 'yes', title: 'Direct bank transfer', description: 'Pay by transfer' }), '--format=json']);
  wp(['option', 'update', 'woocommerce_cheque_settings', JSON.stringify({ enabled: 'yes', title: 'Cheque' }), '--format=json']);
  wp(['option', 'update', 'woocommerce_cod_settings', JSON.stringify({ enabled: 'yes', title: 'Cash on delivery', enable_for_virtual: 'yes' }), '--format=json']);
  wp(['wc', 'tool', 'run', 'install_pages', '--user=admin'], { allowFail: true });
  return wpEval(`
    $sku = 'E2E-WIDGET';
    $id = wc_get_product_id_by_sku( $sku );
    if ( ! $id ) {
      $p = new WC_Product_Simple();
      $p->set_name( 'E2E Widget' ); $p->set_slug( 'e2e-widget' ); $p->set_regular_price( '19.99' ); $p->set_sku( $sku );
      $p->set_virtual( true ); $p->set_status( 'publish' ); $p->set_catalog_visibility( 'visible' );
      $id = $p->save();
    }
    $find = function ( $name ) { $q = get_page_by_path( $name ); return $q ? $q->ID : 0; };
    $classic_checkout = $find( 'classic-checkout' );
    if ( ! $classic_checkout ) { $classic_checkout = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Classic Checkout', 'post_name' => 'classic-checkout', 'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]' ) ); }
    $classic_cart = $find( 'classic-cart' );
    if ( ! $classic_cart ) { $classic_cart = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Classic Cart', 'post_name' => 'classic-cart', 'post_status' => 'publish', 'post_content' => '[woocommerce_cart]' ) ); }
    /* The block-based pages WooCommerce installs have the slugs cart and checkout, whatever ids this site gave them. */
    echo wp_json_encode( array( 'product' => $id, 'checkout' => (int) get_option( 'woocommerce_checkout_page_id' ), 'cart' => (int) get_option( 'woocommerce_cart_page_id' ), 'blocksCheckout' => $find( 'checkout' ), 'blocksCart' => $find( 'cart' ), 'classicCheckout' => $classic_checkout, 'classicCart' => $classic_cart ) );
  `.replace(/\n\s*/g, ' '));
}

/** Which checkout WooCommerce uses: "blocks" (the pages it installed) or "classic" (the shortcode pair). */
export function useCheckout(kind, ids) {
  const [checkout, cart] = kind === 'classic' ? [ids.classicCheckout, ids.classicCart] : [ids.blocksCheckout, ids.blocksCart];
  wp(['option', 'update', 'woocommerce_checkout_page_id', String(checkout)]);
  wp(['option', 'update', 'woocommerce_cart_page_id', String(cart)]);
}

/** High-Performance Order Storage on or off (orders are synced first: WooCommerce refuses to switch otherwise). */
export function setHpos(on) {
  const enabled = /HPOS enabled\?: yes/.test(wp(['wc', 'hpos', 'status', '--user=admin'], { allowFail: true }).stdout);
  if (on === enabled) return;
  wp(['wc', 'hpos', 'sync', '--user=admin'], { allowFail: true });
  if (on) {
    wp(['wc', 'hpos', 'enable', '--with-sync', '--ignore-plugin-compatibility', '--user=admin']);
    // Compatibility mode would also write every order to the posts tables; HPOS on means the order tables only.
    wp(['option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no'], { allowFail: true });
  } else {
    wp(['wc', 'hpos', 'sync', '--user=admin'], { allowFail: true });
    wp(['wc', 'hpos', 'disable', '--user=admin']);
  }
}
