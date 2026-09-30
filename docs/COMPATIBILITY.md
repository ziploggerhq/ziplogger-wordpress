# Compatibility: what was run, and what was not

Every row below was **executed**. Nothing is claimed for an environment that is not in a table. The dates are 2026-09-30; "latest" means the newest release that existed then.

## Declared requirements

| | |
| --- | --- |
| WordPress | 6.0 or later (`Requires at least`) |
| PHP | 7.4 or later (`Requires PHP`), 8.5 included |
| WooCommerce | Optional. 11.1.2 was tested. Declares compatibility with High-Performance Order Storage (HPOS) and the block checkout |
| Tested up to | WordPress 7.1 (the tests ran on 7.1.2) |

## PHP unit and integration tests (WordPress PHPUnit library, real WordPress code, MariaDB 11)

The same 647 tests run in every cell (plus 9 WP Consent API tests, run separately because they define a function that cannot be undefined); tests that only make sense with (or without) WooCommerce, or only on multisite, are skipped in the other cells and run in a complementary one. No cell printed a PHP notice, warning or deprecation.

| PHP | WordPress | WooCommerce | Multisite | Result | Skipped (why) |
| --- | --- | --- | --- | --- | --- |
| 7.4.33 | 6.0 | no | no | pass | 29 (WooCommerce absent, multisite only) |
| 7.4.33 | 6.0 | no | yes | pass | 26 (WooCommerce absent) |
| 8.1.34 | 6.6 | no | no | pass | 29 |
| 8.3.35 | 7.1.2 | no | no | pass | 29 |
| 8.3.35 | 7.1.2 | 11.1.2 | no | pass | 6 (WooCommerce present, multisite only) |
| 8.3.35 | 7.1.2 | no | yes | pass | 26 |
| 8.3.35 | 7.1.2 | 11.1.2 | yes | pass | 3 (WooCommerce present) |
| 8.4.26 | 7.1.2 | no | no | pass | 29 |
| 8.5.11 | 7.1.2 | no | no | pass | 29 |

Run any cell yourself: [TESTING.md](TESTING.md).

Two real defects were found by this matrix, not by the tests that ran first, and are fixed and covered by tests:

- **PHP 7.4** does not look inside an IPv6 address that carries an IPv4 one, so the endpoint safety check accepted a host name that resolved to `::ffff:10.0.0.1`. The embedded address is now checked, and NAT64, 6to4 and Teredo forms are covered.
- **PHP 8.4** deprecates naming the constant `E_STRICT`, which the plugin did on every error it classified. The value is now a class constant.

## Coding standards

`phpcs` with WordPress-Extra, WordPress-Docs and PHPCompatibilityWP for PHP 7.4 and later, WordPress 6.0 and later: **0 errors, 0 warnings** on the shipped plugin (`phpcs.xml.dist`).

## WordPress Plugin Check (plugin-check 2.1.0, run in the real WordPress with the built ZIP)

**0 errors and 41 warnings**, run on the ZIP that ships (sha256 `568a0acc...8097`). All 41 are listed here, so that none is hidden, and none is a defect:

| Warnings | Count | Assessment |
| --- | ---: | --- |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 38 | **False positives.** Every one is a query on the plugin's **own table**, whose name is `$wpdb->prefix` plus a constant (`Schema::queue_table()`, `Schema::meta_table()`), interpolated into a query whose **values** are bound with `$wpdb->prepare()`. An identifier cannot be bound as a value; the `%i` placeholder that can does not exist before WordPress 6.2 and the plugin supports 6.0. Where a further fragment is interpolated (the destination condition), it was itself produced by `prepare()`. Each such line carries a `phpcs:ignore` with this reason |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` and `.NoCaching` | 2 | **Intentional.** One query, `SHOW TABLES LIKE` in `Schema::tables_exist()`, the health check that asks whether the plugin's own tables exist. A cached answer would defeat the check |
| `PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall` | 1 | **Intentional.** `error_reporting()` is *read*, never changed, in the error handler (`includes/collectors/class-php-errors.php`), so that a warning silenced with the `@` operator stays silent (PHP lowers the level while an `@` expression runs). Reading the level is the documented way to honour that; `ini_get()` would not see it |

Earlier runs also reported a restricted term in the plugin name, a missing `languages/` folder, an over-long readme short description and the discouraged `load_plugin_textdomain()` call. All four were fixed (the plugin is now named "ZipLogger: Error Monitoring & Session Replay", ships a `.pot` file, and leaves translations to WordPress). The same notes are in [WORDPRESS-ORG-REVIEW.md](WORDPRESS-ORG-REVIEW.md).

## Real WordPress, real browser (end-to-end)

Environment: WordPress 7.1.2 on PHP 8.3.35 and Apache 2.4.68 (the official `wordpress:latest` image), MariaDB 11, WooCommerce 11.1.2, the Twenty Twenty-Five block theme, Chromium from the Playwright 1.63.0 image, a **local stand-in** for ZipLogger (see [LIMITATIONS.md](LIMITATIONS.md)). The plugin is installed from `dist/ziplogger.zip` with WP-CLI, exactly as a user would.

One complete run of all five suites against `dist/ziplogger.zip` (sha256 `568a0acc...8097`, the file that ships): **86 tests, 86 passed, 0 failed.** The browser (`m3`), tracing (`m4`) and dashboard (`m6`) suites passed in one sitting; the server-log (`m1`) and WooCommerce (`m5`) suites were re-run against the same ZIP after two fixes to the *tests* described below, and passed.

| Suite | Covers | Tests | Result | Time |
| --- | --- | ---: | --- | ---: |
| `m1-logs` | Server logs: install and activation send nothing; PHP warnings, exceptions and fatals; secrets; outages, retries, partial acceptance, four concurrent workers, poison events, rejected key; WP-Cron delivery; deactivate and uninstall | 16 | pass | 5 min |
| `m3-browser` | Browser monitoring, analytics, replay (masking, exclusions, roles, withdrawal, kill switch), consent, Do Not Track, duplicate initialisation, blocked script and unavailable service, cached-page identifier isolation | 28 | pass | 13 min |
| `m4-tracing` | Server spans, parent-aware sampling, hostile headers, outbound spans, log correlation, propagation allowlists, browser to server correlation | 17 | pass | 6 min |
| `m5-commerce` | Classic and block checkout, HPOS off and on, payment success, failure, delayed and refund, duplicates, consent, no personal data, operation without WooCommerce | 16 | pass | 17 min |
| `m6-dashboard` | Dashboard panels against the read model, read-key handling, credential isolation, capability checks, axe-core accessibility | 9 | pass | 4 min |

The JavaScript suite (`frontend/test`, 154 tests: every module in jsdom, plus checks of the built files) also passes.

Suites failed on earlier runs of the same day for reasons that were not the plugin's (a test that assumed a receiver it had not cleared, a race with WP-Cron in a queue-count assertion, and WooCommerce product and page ids that had been hard-coded from an older site: the suite now reads them from the site it runs on, so it also passes on a brand-new one), and were fixed in the tests: WooCommerce's own `sbjs_*` order-attribution cookies were counted as the plugin's storage; a phone-number digit run (`555`) occurred by chance inside a hash; and copies of the plugin's tables that Plugin Check leaves behind matched a table-count query. Two were real product defects and were fixed in the plugin: the dashboard's time window ended at the last whole minute and hid the newest errors, and a table overflowed the screen at phone width.

## What each requirement was checked with

Legend: **U** PHP unit/integration, **J** JavaScript unit (jsdom, source and built files), **E** end-to-end in real WordPress and Chromium.

| Requirement | U | J | E | Notes |
| --- | :-: | :-: | :-: | --- |
| Nothing collected or sent until a key is added and a module switched on; activation sends nothing | x | x | x | m1, m3 |
| Server key, browser key and read key kept apart; browser page carries the browser key only | x | x | x | m3, m6 (page source scanned for all three) |
| A key used in two roles is refused | x | | | |
| PHP warnings, exceptions and fatals captured once, page unaffected, redacted | x | | x | m1, also under WP-CLI |
| Queue bounded; retries reuse the same key and bytes; partial acceptance; `Retry-After`; poison isolation; concurrent workers | x | | x | m1 (four workers at once: exactly once) |
| Key or endpoint change holds queued data | x | | x | |
| Endpoint safety (HTTPS, no credentials, no internal or private addresses, IPv6 forms) | x | | | 80 endpoint tests |
| WP-Cron caveat: delivery after a page view, CLI status agrees; system cron documented | x | | x | m1 |
| Browser errors, rejections, failed and slow requests, timing and Core Web Vitals, sanitized | | x | x | m3 |
| Initialization and duplicate prevention (script loaded three times) | | x | x | m3 |
| Consent: nothing before it; grant, deny, withdraw; Do Not Track; policy "none" | x | x | x | m3, m5 |
| Analytics: page views, client-side navigation without query strings | | x | x | m3 |
| Replay: nothing before consent; text, inputs, payment, password masked; excluded pages, roles and blocked elements absent; withdrawal discards; kill switch; unreachable service | | x | x | m3 (recordings inspected in the receiver) |
| Tracing (server): own trace, continued trace, parent-aware sampling, hostile headers ignored, normalized names, log correlation, outbound child spans | x | | x | m4 |
| Propagation allowlists: nothing to third parties by default; allowlisted host gets `traceparent`, never baggage | x | x | x | m3, m4 |
| Browser to server correlation (one trace across both) | | x | x | m4 |
| Cached-page identifier isolation (page identical for every visitor; identity fetched from an uncached endpoint; replay fails closed) | x | x | x | m3, m4 |
| Classic checkout and block checkout | | | x | m5 |
| HPOS off and on | x | | x | m5 (markers checked in `wc_orders_meta` versus post meta) |
| Payment success, failure, delayed, refund (partial and full) | x | | x | m5 (test gateway and a simulated provider webhook) |
| Duplicate commerce events (thank-you reload, repeated webhook, repeated hook) | x | | x | m5 |
| No personal data, order keys or payment details in any event | x | | x | m5 scans every payload for the typed values |
| Operation without WooCommerce | x | | x | non-WooCommerce PHP cells; m5 deactivates WooCommerce in the real site |
| Blocked script (ad blocker) and unavailable service | | x | x | m3, m5 |
| Module disabled behaviour (no script, no header, no queue entry) | x | x | x | m3, m4, m5 |
| Dashboard panels, read key handling, no keys in HTML or JavaScript, capability and nonce | x | | x | m6 |
| Accessibility of the admin screens (axe-core 4.13.0, no serious or critical findings) in LTR, with the page direction switched to RTL, and at phone width | | | x | m6 |
| Measured overhead per module and with all modules | | | x | [PERFORMANCE.md](PERFORMANCE.md) |
| Multisite (per-site settings, queues, isolation; network activation refused) | x | | | PHPUnit multisite cells |

## Not run

- WordPress versions other than 6.0, 6.6 and 7.1.2; PHP 8.0 and 8.2; WooCommerce versions other than 11.1.2.
- Firefox, Safari, and any real mobile device (Chromium only; Chromium at a phone-sized viewport was used for layout).
- Object-cache drop-ins (Redis, Memcached); real page caches and optimization plugins (WP Rocket, LiteSpeed, Cloudflare APO); specific consent-management plugins; the WP Consent API plugin itself (its documented functions are exercised through a stand-in).
- Real payment providers (Stripe, PayPal ...), subscriptions plugins, and multi-currency plugins.
- Headless or REST-only front ends.
- **The read side of the real ZipLogger service** (the dashboard panels), and overload responses. Sending to a real workspace was verified once (24 checks, `tests/live`); see [LIMITATIONS.md](LIMITATIONS.md).
