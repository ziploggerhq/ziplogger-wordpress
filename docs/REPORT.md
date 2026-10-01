# ZipLogger: Error Monitoring & Session Replay 1.0.0 (WordPress plugin): final report

Date: 2026-09-30. Package: `dist/ziplogger.zip`, 80 files, 273,187 bytes, sha256 `27e95a0dd79d23383ce4b8ce45bde433adccd826ff0de1d1d974a61eead1d2e7`. Building it twice gives byte-identical files. This is the ZIP that was re-submitted to WordPress.org after the plugin team's first review; every test described below ran on it.

**Two levels of evidence, kept apart.** Almost everything was verified against a local stand-in that models ZipLogger as its own source code describes it. After the main work, one ingestion key for a real workspace became available, and the plugin's delivery, the HTTP contract and the browser script were then checked live (24 checks, all passed). **The read interface and the dashboard's live panels were not verified live** (that key has no read scope), and nobody has looked at the data inside ZipLogger's own screens. Details in [LIMITATIONS.md](LIMITATIONS.md).

## What was delivered

| | |
| --- | --- |
| The plugin | Server logs, browser monitoring, analytics, session replay, distributed tracing, WooCommerce events and a dashboard. Every module is off until switched on. 65 PHP files (about 14,700 lines), two browser scripts built from about 2,100 lines of readable source |
| Package | `dist/ziplogger.zip`, with `readme.txt`, setup, privacy, troubleshooting, cron and developer-API guides (PHP and JavaScript examples), and a translation template |
| Repository docs | [BUILD](BUILD.md) (reproducible build), [TESTING](TESTING.md), [COMPATIBILITY](COMPATIBILITY.md) (matrix and feature coverage), [PERFORMANCE](PERFORMANCE.md), [LIMITATIONS](LIMITATIONS.md) |
| Tests | 653 PHP tests (+ 9 in a separate run), 154 JavaScript tests, 86 end-to-end tests in a real WordPress and a real Chromium |

## Architecture decisions, and why

1. **A bounded database queue, delivered in the background.** Nothing is sent during a page request. Batches are leased and byte-identical across retries under one idempotency key, so a retry cannot double-count; poison items are isolated; the queue is capped and counts what it drops. Delivery uses WP-Cron with a watchdog, and the dashboard says when it is overdue (the caveats are in [CRON.md](../plugin/ziplogger/docs/CRON.md)).
2. **Three credentials that cannot be confused.** A server key, a public ingestion-only browser key, and an optional read key that never leaves the server. The plugin refuses one key in two roles, never puts the server or read key in a page (checked by scanning the served HTML and JavaScript), never asks for a personal login token, and reads data back only on the server, for administrators, behind a nonce.
3. **The plugin owns consent, because the platform has none.** Per category (browser, analytics, replay, commerce): a PHP filter, then the WordPress Consent API, then its own cookie; Do Not Track and Global Privacy Control block the identifying categories. Before consent nothing is collected, buffered or queued; consent given later does not send earlier activity; withdrawal stops collection and discards what was unsent.
4. **Safe behind page caches.** The page carries one static configuration identical for every visitor. Anything per-visitor comes from an uncached endpoint or is created in the browser. No trace id or visitor id is ever in HTML or a response header. Replay fails closed if the endpoint cannot be asked.
5. **The ZipLogger browser SDK is wrapped, not trusted with the page.** The SDK sends full URLs and sends session baggage to every propagation target, and has no consent handling, so the plugin does its own sanitizing, network observation and tracing. Query strings are never sent. Trace headers go only to the site itself and to hosts on an allowlist; baggage only to the site itself.
6. **Replay is masked by construction.** All text and inputs are masked; there is no setting that unmasks anything. Payment, password and address elements, checkout, cart, account and login pages, administrators, and payment frames are never recorded (frames from other origins cannot be recorded by any browser).
7. **WooCommerce: the server is authoritative.** Order, payment, failure and refund events come from the order lifecycle (never from a thank-you page), through WooCommerce's CRUD objects only (so HPOS on or off makes no difference), with amounts in exact minor units and an opaque keyed-hash order reference. No names, addresses, emails, order keys, payment details or arbitrary order fields are read. A shopper who consented is linked to their browser session through a private order field; without consent the order stands alone.
8. **Tracing without `SAVEQUERIES`.** Spans carry the database query count for free; timing exists only if the site already enabled `SAVEQUERIES`. Inbound "sample this" requests are honoured only up to a per-minute budget.
9. **The destination is a safety boundary.** HTTPS, no credentials or IP literals in the endpoint, every resolved address public (including addresses that embed an IPv4 one). If the key or endpoint changes, queued data is held rather than sent to the new destination until an administrator decides.

## What was tested, and the result

| Layer | Result |
| --- | --- |
| PHP unit and integration (WordPress PHPUnit library, MariaDB 11) | 653 tests pass on 9 cells: PHP 7.4 / 8.1 / 8.3 / 8.4 / 8.5, WordPress 6.0 / 6.6 / 7.1.2, WooCommerce 11.1.2, single site and multisite. 9 more tests (WP Consent API) pass in a separate run on every cell. No PHP notice, warning or deprecation in any cell |
| Coding standards | WordPress-Extra and -Docs plus PHP-compatibility 7.4 and later: 0 errors, 0 warnings |
| Plugin Check 2.1.0 on the shipped ZIP | 0 errors; 41 warnings, each explained in [COMPATIBILITY.md](COMPATIBILITY.md) and [WORDPRESS-ORG-REVIEW.md](WORDPRESS-ORG-REVIEW.md) |
| JavaScript | 154 tests pass (each module in jsdom, plus checks of the built files: size budgets, no secret, no `eval`, one instance only) |
| End-to-end (real WordPress 7.1.2, WooCommerce 11.1.2, Chromium; the built ZIP installed by WP-CLI) | 86 of 86 pass in one complete run: server logs 16, browser 28, tracing 17, WooCommerce 16, dashboard 9 |
| Accessibility | axe-core 4.13.0: no serious or critical finding on any admin tab in LTR, with the page direction switched to RTL, and at phone width, and no horizontal scrolling at phone width |
| **Live, against a real ZipLogger workspace** (`tests/live/live-check.mjs`), on an earlier build (not repeated on the final ZIP) | 24 of 24 checks pass: test event, logs, a server event and spans delivered by the plugin; 202/200/400/401/422 contract responses as the plugin expects; a bad key pauses delivery, keeps and holds the data, and retargeting delivers it; a real Chromium at another origin sends errors, analytics, browser spans and a replay chunk, all accepted with a CORS header. Read interface and dashboard: not verified live |
| Measured overhead | [PERFORMANCE.md](PERFORMANCE.md) |

The spec's validation list, item by item, is in the coverage table of [COMPATIBILITY.md](COMPATIBILITY.md).

### Defects the verification found (all fixed, all covered by a test)

- **PHP 7.4 let a private address through the endpoint safety check** when it arrived as an IPv4-mapped IPv6 address (`::ffff:10.0.0.1`). Found only by running the matrix. NAT64, 6to4 and Teredo forms are now covered.
- **PHP 8.4 deprecated a constant the plugin named** (`E_STRICT`), so the plugin itself would have emitted a deprecation notice on every classified error.
- **The dashboard hid the newest minute of errors** (its time window ended at the last whole minute).
- **A delivery-status table overflowed the screen at phone width**, and a code sample on the privacy tab was not keyboard-scrollable.
- **A dashboard message that contained `key=value` text was cut at it.**
- **The PHP side of the WordPress Consent API integration had no test**; it now has nine, and a deliberately broken version fails them.
- **Network-wide activation on multisite was refused** (the plugin stopped with `wp_die()`); the WordPress.org review flagged it. It is now supported: existing sites are set up on activation, new sites when they are created, any other site on its first request. Seven new tests, and a real multisite with `WP_DEBUG` on (activation prints nothing; every site gets its tables and schedule).
- **A privacy statement was too strong**: the docs said IP addresses are never collected. The plugin puts none in the data it builds, but a browser that sends data to ZipLogger directly necessarily shows ZipLogger its IP address; the docs, the readme and the privacy-policy text now say so, and what ZipLogger does with it.
- **Third-party notices were incomplete**: rrweb ships no licence file, so its MIT text (and that of a library compiled into it) is now kept in `frontend/licenses/` and added to the shipped notices by the build.
- **86 inline `phpcs:ignore` comments** now each state why the flagged line is correct.
- A first performance method was discarded because machine drift dominated the effects; the current one interleaves configurations (see [PERFORMANCE.md](PERFORMANCE.md)).

## Measured overhead, in short

On this machine and page (a 102 ms WordPress + WooCommerce front page): all modules on with tracing at 10% add about **1.6 ms of PHP time, no database queries and 0.16 MiB**; tracing every request adds about 1.4 ms and 4 queries; a request that logs something adds about 1.3 ms and 3 queries. In the browser the monitoring/analytics/tracing script is **22.8 KB gzipped and about 6 to 9 ms of script time**; session replay for a recorded session adds a second 23 KB script, about **49 ms of main-thread work and 3 MB of heap**. Read [PERFORMANCE.md](PERFORMANCE.md) for the method, the spread, and what was not measured.

## Limitations and external blockers

- **The read interface was not verified live** (see above); ingestion of logs, events, traces and replay was, with one ingestion key. Overload responses (`429`, `503`, `413`) were checked only against the stand-in and the service's source.
- **ZipLogger has no read API for product analytics or replays**, so those tabs show local counters and link into ZipLogger; the dashboard shows live numbers for server errors, JavaScript errors, Core Web Vitals, request statistics and traces.
- **ZipLogger and its browser SDK have no consent handling**, hence decision 3.
- **Server-side events are geolocated by the web server's address** (a platform default), so order events show the server's location, not the customer's.
- Requests that never reach PHP (full-page cache hits) are invisible to the server modules; delivery depends on WP-Cron unless a system cron is configured; cross-origin payment frames cannot be recorded; recording begins at consent and never reconstructs earlier activity.
- The WordPress.org contributor username in `readme.txt` is a placeholder until the owning account exists.

The full list is in [LIMITATIONS.md](LIMITATIONS.md).

## Exactly what was not tested

- **The read side of the real ZipLogger service** (the `/grafana/...` interface and so the dashboard panels), and **the appearance of the data inside ZipLogger** (its screens and replay player). Sending was verified live; see above. Overload responses (`429`, `503`, `413`) were not provoked live.
- **Real payment providers** (Stripe, PayPal ...) and their webhooks: a test gateway and a simulated provider webhook were used. Subscriptions and multi-currency plugins.
- **Browsers other than Chromium**, and real mobile devices (a phone-sized Chromium viewport only).
- **WordPress versions other than 6.0, 6.6 and 7.1.2** (PHP 8.0 and 8.2 too), and **WooCommerce versions other than 11.1.2**. WordPress 6.0 and PHP 7.4 were tested with the PHP suite only, not the browser suites.
- **Object-cache drop-ins, real page-cache and optimization plugins, real consent-management plugins, and the real WP Consent API plugin.** The cache behaviour was tested by requesting the page as different visitors and comparing; the Consent API through a stand-in of its documented function.
- **The plugin under real production load or on hosts with restricted PHP configurations.**
- Overhead of real order flows, the delivery job, admin screens and non-front pages (see [PERFORMANCE.md](PERFORMANCE.md)).
- Right-to-left layout reviewed by a native reader (only axe-core with the page direction switched).

## Reproduce it

```bash
# build (twice gives the same bytes)
docker run --rm -v "$PWD:/work" -w /work/frontend node:22-alpine sh -c "npm ci && node build.mjs"
docker run --rm -v "$PWD:/w" -w /w ziplogger-ci:php8.3-wplatest sh -c "php bin/build-zip.php && php bin/verify-zip.php dist/ziplogger.zip"
# test: see docs/TESTING.md   |   measure: see docs/PERFORMANCE.md
```

Licence: GPL-2.0-or-later. Bundled: the ZipLogger browser SDK and rrweb (MIT), web-vitals (Apache-2.0); texts in `assets/js/THIRD-PARTY-LICENSES.txt`.
