=== ZipLogger: Error Monitoring & Session Replay ===
Contributors: ahaliav
Tags: error monitoring, logging, analytics, session replay, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Error monitoring, analytics, masked session replay, tracing and WooCommerce events for ZipLogger. Every module is off until you turn it on.

== Description ==

ZipLogger connects your WordPress site to [ZipLogger](https://ziplogger.ai/). It sends what you choose to your ZipLogger workspace, in the background, without ever making a visitor's page wait for it.

**Nothing is collected or sent until you enter an API key and switch a module on. Activating the plugin, or switching on one module, never switches on another.**

= Modules (each has its own switch, sampling and consent policy) =

* **Server logs**: PHP warnings, uncaught exceptions and fatal errors (without changing how PHP or WordPress report them), plus optional plugin/theme changes, update outcomes, failed logins (no user names, no IP addresses) and failing or slow outbound HTTP calls. A developer API: `ziplogger_log()`.
* **Browser monitoring**: JavaScript errors, unhandled promise rejections, failing and slow requests, page-load timing and Core Web Vitals, sent straight from the visitor's browser. Sanitized in the browser: no query strings, no request bodies, no cookies or authorization headers.
* **Analytics**: page views, client-side navigation, interactions you allowlist, custom events (`ZipLoggerWP.track()` in JavaScript, `ziplogger_track()` in PHP) and optional pseudonymous identification of signed-in users. Off until the visitor consents, by default.
* **Session replay**: masked recordings of a sample of sessions. All text and all inputs are masked by default; password and payment fields, address blocks, and the login, account, cart and checkout pages are never recorded; administrators and editors are excluded. Consent-aware, with duration and size limits.
* **Tracing**: request spans, outbound HTTP spans and optional browser-to-server correlation using W3C Trace Context, with parent-aware sampling. Trace headers go only to your own site and to hosts you list.
* **WooCommerce**: product views, cart changes and checkout start from the browser; **authoritative** order created, payment completed or failed, status changed and refund events from the server, with exact amounts in the currency's minor units and opaque order references. Works with the classic and block checkouts and with High-Performance Order Storage. A thank-you page is never treated as proof of payment.
* **Dashboard** (Settings, ZipLogger): what is on and why something is blocked, delivery health, and, with an optional read-only key, recent errors, trends, Core Web Vitals, request statistics and traces read back from ZipLogger, on the server only.

= Built to be safe on a real site =

* A bounded database queue, batched background delivery, retries with backoff and jitter, `Retry-After` support and idempotent retries. If ZipLogger is unreachable your site is unaffected.
* Separate credentials: a server key, a public **ingestion-only** browser key, and an optional read key that never leaves the server. The plugin refuses to use one key for two purposes.
* Consent: the WordPress Consent API, a filter for consent managers, or a JavaScript API. Do Not Track and Global Privacy Control are honoured for analytics and replay. Withdrawing consent stops collection and discards what was not yet sent.
* Cache-safe: page caches and CDNs can store your pages; identifiers are created in the browser, never in the HTML.
* Multisite: activate per site (each site has its own keys and queue).

= External services =

**This plugin is a connector for an external software-as-a-service (SaaS) platform, ZipLogger (https://ziplogger.ai/). A ZipLogger account is required.** The plugin sends data only to the ZipLogger workspace that your API keys belong to, and it does nothing at all until you add a key and switch a module on. ZipLogger has a free plan and optional paid plans (https://ziplogger.ai/pricing). The service is operated by ZipLogger: [Terms of Service](https://ziplogger.ai/terms), [Privacy Policy](https://ziplogger.ai/privacy).

The service address is `https://app.ziplogger.ai` unless you enter your own (self-hosted ZipLogger). What is sent, by whom and when, for each module (a module sends nothing unless you switch it on; the consent-gated ones send nothing until the visitor has consented, unless you chose otherwise):

* **Connection test** (the "Send test event" button, `wp ziplogger test`): one test message from your server, with the site's host name and the WordPress, PHP and plugin versions.
* **Server logs** (from your web server, in the background, with your server key): PHP warnings, uncaught exceptions and fatal errors: the message, severity, time, a file path relative to WordPress, a stack trace without function arguments, the site's host name, an identifier of the request, and the WordPress, PHP and plugin versions. Optional collectors add plugin and theme changes, update results, failed-login reason codes (never user names, passwords or IP addresses) and failing outbound HTTP calls (host, method, status and duration only). Passwords, keys, tokens, cookies and request contents are removed before sending (best effort).
* **Server events** (from your web server, with your server key): if the WooCommerce module is on, order created, payment completed or failed, status changed and refunded: the amount and currency, number of items, product identifiers or SKUs (your choice), payment method identifier, how the order was created, whether the customer was a guest, and a reference that is not the order number. Never names, addresses, email addresses, phone numbers, order keys or payment details. Custom events that your own code sends with ziplogger_track().
* **Tracing** (from your web server, with your server key; and from the browser if you enable that): request spans with the method, a route name (never the URL), status, duration, number of database queries and memory use, and outbound request spans with host, method, status and duration.
* **Browser monitoring** (sent by the visitor's browser with your browser key, on the visitor's pages): JavaScript errors and failing or slow requests (sanitized message and stack; the page path without its query string; the host, method, status and duration of requests), page-load timing and Core Web Vitals. Nothing is stored on the visitor's device.
* **Analytics** (sent by the visitor's browser, by default only after the visitor consents): page views, client-side navigation, the interactions you allowlist and custom events, tied together by a random identifier stored in the visitor's browser. Optionally a one-way pseudonym of a signed-in user.
* **Session replay** (sent by the visitor's browser, only for a sample of sessions and only after the visitor consents): a recording of the page in which all text and form fields are masked; payment and password fields, and the sign-in, account, cart and checkout pages, are never recorded.
* **Dashboard** (your web server asks ZipLogger, with your read key, only for administrators): recent errors, trends, Core Web Vitals, request statistics and traces of your own site, for display in the WordPress admin.

Browser-side data is sent directly from the visitor's browser to ZipLogger, so ZipLogger's servers receive the visitor's IP address and browser details (such as the user agent) with each request, as any website does. For usage events, ZipLogger uses the IP address only to work out an approximate location (country, region, city) and the browser details to record browser, operating system and device type; the IP address is not stored with the event.

Each module's settings screen lists exactly what it collects. The complete data-flow description, the list of cookies and browser storage used, and a template for your privacy notice are in `docs/PRIVACY.md`. The plugin also adds suggested text to your site's privacy policy (Settings, Privacy in the WordPress admin), describing only the modules you have switched on.

= Bundled software =

The browser scripts bundle: the ZipLogger browser client (`@ziplogger/browser`, MIT), rrweb's recorder (`@rrweb/record` and its bundled dependencies, MIT) and Google's web-vitals library (Apache-2.0). Exact versions and licence texts are in `assets/js/manifest.json` and `assets/js/THIRD-PARTY-LICENSES.txt`. The readable sources and the build instructions are in the `frontend` directory of the public source repository, https://github.com/ziploggerhq/ziplogger-wordpress (see also `docs/SOURCE.md`).

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings, ZipLogger, Connection and paste a server API key (created in ZipLogger under Settings, API keys). Add a browser key (ingestion only) for browser modules, and optionally a read key for live dashboard panels.
3. Press "Send test event".
4. Switch on the modules you want, each on its own tab, and review "Privacy and consent".

Keys can also be set in `wp-config.php` (`ZIPLOGGER_API_KEY`, `ZIPLOGGER_BROWSER_KEY`, `ZIPLOGGER_READ_KEY`). See `docs/SETUP.md`.

== Frequently Asked Questions ==

= Do I need a ZipLogger account? =

Yes. The plugin sends what you choose to your own ZipLogger workspace, and the API keys it needs are created there (Settings, API keys, in ZipLogger). Without a key it does nothing at all. Sign up and see the plans at https://ziplogger.ai/.

= Does it slow my site down? =

Server side, nothing is sent during a request: events are written to your database in one insert at shutdown, and a background job (WP-Cron) delivers them. In the browser there is one deferred script (about 23 KB compressed); the session-replay recorder (about 23 KB compressed) loads only for sessions that are recorded. Measured overhead for each module is in `docs/PERFORMANCE.md` of the public source repository (https://github.com/ziploggerhq/ziplogger-wordpress).

= Delivery depends on WP-Cron. What if my site has little traffic, or WP-Cron is disabled? =

The dashboard says when delivery is overdue and whether `DISABLE_WP_CRON` is set. Use a system cron (`wp ziplogger flush` or `wp cron event run --due-now`). See `docs/CRON.md`.

= What if ZipLogger is unreachable? =

Your site is unaffected. Events wait in the local queue, are retried with increasing delays, and are dropped (and counted) after 48 hours or when the queue is full. Browser telemetry is dropped after a few retries.

= Is analytics or replay compliant with GDPR/ePrivacy? =

The plugin gives you the controls (consent gating, masking, exclusions, retention) and documents exactly what is collected. Whether your use is lawful is for you and your advisers to decide.

= Does it record passwords or payment details? =

No. Password, one-time-code and payment fields are never recorded in replays, and no form contents, request bodies, cookies or authorization headers are collected anywhere. Payment iframes are cross-origin, which browsers do not allow any script to record.

= Does it work with page caches, CDNs and optimization plugins? =

Yes: the page carries no visitor-specific data. Optimization plugins are asked (through attributes on the script tag) not to delay or combine it; see `docs/TROUBLESHOOTING.md`.

= Does it work on multisite? =

Activate it per site. Network activation is refused because each site needs its own keys, queue and settings.

= What does it not do? =

It does not capture PHP errors when the request never reaches PHP (a page served by a full-page cache), does not read product analytics or replays back into WordPress (the dashboard links into ZipLogger for those), and cannot recall data already sent. See `docs/LIMITATIONS.md` in the public source repository (https://github.com/ziploggerhq/ziplogger-wordpress).

== Changelog ==

= 1.0.0 =
* First release: server logs, browser monitoring, analytics, session replay, tracing, WooCommerce events and a dashboard, all opt-in.

== Upgrade Notice ==

= 1.0.0 =
First release.
