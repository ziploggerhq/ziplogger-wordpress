# Verified limitations and external blockers

This page lists what the plugin does **not** do, and what could **not be verified**, and why. Nothing here is hidden in the feature list: if something is claimed elsewhere, it was tested; if it could not be tested, it is here.

## External blockers: what could not be verified

**Verified live, on 2026-09-30, against a real ZipLogger workspace** (`https://app.ziplogger.ai`, one ingestion key supplied for the purpose): the plugin's own delivery of logs, a server-side event and spans; the HTTP contract the plugin relies on (below); the plugin's reaction to a bad key; and a real Chromium at another origin sending errors, analytics, browser spans and a replay chunk cross-origin. 24 checks, all passed (`tests/live/live-check.mjs`, see `tests/live/README.md`; it can be re-run with any key). It ran against an earlier build of the plugin; the changes since are the plugin's name, the privacy-policy text, removal of the translation-loader call, third-party notices, documentation and code comments, none of which touches delivery or the browser script, but the live check has not been repeated on the final ZIP.

**Still not verified live:**

- **The read interface (`/grafana/...`) and therefore the dashboard's live panels.** The key that was available had no read scope (the service answered 401 to a read request). The panels were verified against a stand-in modelled on the service's source.
- **How the data looks inside ZipLogger** (its screens, the replay player, trace views). The service accepted everything with the expected status; nobody read it back. Look for the source `wp-plugin-live-test`.
- **Whether the key type used for the browser is an ingestion-only "browser" key.** The one key played the server role and, separately, the browser role; the service accepted it in both, cross-origin. A workspace's separate browser key was not exercised.
- **Overload and quota behaviour**: `429` with `Retry-After` and partial acceptance, `503`, `413`, plan-quota `403` were not provoked (they would need a flood or a full quota); those paths are verified against the stand-in and the service's source only.
- Behaviour over days, at volume, or from a production site.

| Area | Verified live | Verified only against the stand-in / source |
| --- | --- | --- |
| Logs (`POST /ingest/v1/logs`) | 202 with `accepted`/`rejected`; the same `Idempotency-Key` and body answered again as before; the same key with another body refused (422); bad JSON (400); wrong or missing key (401); an out-of-range severity is accepted | Partial acceptance with `429` and `Retry-After`; `409` in flight; `413` |
| Events (`/ingest/v1/events`) | Accepted with a user or anonymous id; an event without either is processed but rejected (not retried); server-side events from the plugin | Quota responses; the `identified` count with real user ids |
| Traces (`/v1/traces`) | OTLP/JSON spans from the server and from the browser: `200`; nested (`kvlist`) attributes also answer `200` (whether they are stored is not known) | Span de-duplication under retry |
| Replay (`/ingest/v1/replay`, `/config`) | Configuration fetched cross-origin (`200`, input masking on); a recorded chunk accepted (`202`) | Chunk ordering and the `final` reopening; the recording in ZipLogger's player |
| Browser cross-origin use | Requests from another origin succeed and carry `access-control-allow-origin: *` (real Chromium, all five request types) | An ad-blocker or a corporate proxy in front of the browser |
| Bad key handling in the plugin | `401 Invalid API key` pauses delivery and keeps the data; after the key changes the data is held; retargeting delivers it | — |
| Read interface (`/grafana/...`) and dashboard | Only that a non-read key is refused (`401`) | Everything else: health, Loki-style, Prometheus-style and Tempo-style queries, the panels |

## Gaps in the ZipLogger platform that shaped the design

- **No consent or Do-Not-Track handling in ZipLogger or its browser SDK.** The plugin implements consent gating itself (in the browser and on the server). The SDK also lacks Core Web Vitals, XHR instrumentation, error sampling and privacy-preserving request instrumentation (it sends full URLs, including query strings, and sends baggage to every propagation target), so the plugin wraps the SDK and does its own network observation and tracing.
- **No read API for product analytics or replays.** The only credentialed read surface is the Grafana-compatible one (logs, request metrics, traces). The dashboard therefore shows live numbers for server errors, JavaScript errors, Core Web Vitals, request statistics and traces, and **links into ZipLogger** for analytics, replay and WooCommerce.
- **Server-side events are geolocated by the sender's address** (a default setting of the service), so order and payment events show the web server's location, not the customer's.

## Server-side capture

- **Requests that never reach PHP are invisible to the server modules**: a page served straight from a full-page cache produces no PHP error, no request span, no server-side analytics event. Browser modules still work on those pages. Trace correlation between the browser and the *document* request is impossible for the same reason and is deliberately not attempted with a header or meta tag (a cache would hand one visitor's trace id to the next).
- **Fatal errors are captured best-effort**: through WordPress's own fatal-error handling, a wrapped `wp_die` handler and a shutdown function with reserved memory. A process killed outright (out-of-memory kill, `SIGKILL`) leaves nothing to record.
- **Delivery depends on WP-Cron** unless you run a system cron (see the cron guide). Delivery is at-least-once; a rare duplicate or loss is possible if the site dies at the wrong moment.
- **Database timing exists only if your site already defines `SAVEQUERIES`.** The plugin never defines it (it stores a backtrace per query and slows every request). Without it, spans carry the query *count* (free, from `$wpdb->num_queries`) but no timing, and never any SQL text.
- **Outbound spans cover the WordPress HTTP API** (`wp_remote_*`), not code that calls cURL or sockets directly.
- **Inbound sampling is bounded**: a caller can ask us to record a trace only up to a per-minute budget (default 120/min); beyond it the local rate decides.
- The queue is bounded (5,000 items, 32 MiB, 48 hours by default). When it is full, new items are dropped and counted; spans and events are capped at shares of the queue so they cannot crowd out errors.

## Browser

- **The script is deferred**, so errors that happen while the HTML is still being parsed (before it starts) are not captured.
- **Ad blockers and privacy tools** may block the script or its requests. Server-side data is unaffected.
- **Query strings are never sent**, so campaign parameters (`utm_*`, `gclid`) are not collected, and hash-only route changes (`#/page`) are not counted as page views.
- **Interactions are limited to what you allowlist** (selectors or `data-ziplogger-event`), and only the selector or your own attributes are recorded, never the element's text.
- Storage (`localStorage`, `sessionStorage`, cookies) can be blocked or cleared by the browser; identifiers then live in memory for one page view.
- **One session per tab** (the platform's semantics): a visitor with two tabs open has two sessions.

## Session replay

- **Cross-origin iframes cannot be recorded** by any browser; payment frames are cross-origin. They are also blocked by selector as a second layer.
- **Canvas contents are not recorded** (off, and not exposed as a setting).
- **Recording begins at the moment of consent**, from a snapshot of the page as it is then. It never reconstructs earlier activity.
- **Withdrawing consent stops recording and discards what has not been sent.** A chunk already in flight cannot be recalled, and data already at ZipLogger is not deleted by this plugin.
- **Fails closed**: if the plugin cannot ask the server whether the visitor's role may be recorded (for example a security plugin blocks `admin-ajax.php` for visitors), it does not record.
- Masking is thorough for text and inputs but **cannot know what is sensitive in images, canvas, custom elements or CSS-generated content**. Review a few recordings on your own pages, and add block/mask selectors for anything specific to your site.

## WooCommerce

- **Draft orders are not orders.** Block-checkout drafts are reported only when they leave the draft state.
- **Variable products**: server events report the parent product id (or SKU); the block cart's data store can expose the variation id to the browser, so browser-side cart events for variable products may carry a different id than the order events.
- **Subscriptions and renewals** are treated as ordinary orders; no subscription-specific events.
- **Identity linking needs consent that the server can see.** The consent cookie, the WordPress Consent API and the `ziplogger_has_consent` filter are visible to PHP; a consent banner that only keeps its state in JavaScript is invisible to it unless it also calls `ZipLoggerWP.consent.grant()`. Without linking, events carry the order's opaque reference only.
- **Cash on delivery** does not count as paid until the order is completed (a filter overrides this).
- Compatibility was exercised with the classic (shortcode) and block checkouts, offline gateways, a test gateway, provider-style webhooks and HPOS on and off. **Real payment providers (Stripe, PayPal ...) and their webhooks were not tested.**

## Privacy tooling

- The plugin does not register WordPress personal-data exporters or erasers. The order fields it adds (`_ziplogger_identity`, `_ziplogger_events`) hold only pseudonymous ids and markers.
- Redaction is best-effort text scrubbing and name-based rules. It cannot guarantee that a secret typed into an arbitrary message is caught.

## Dashboard

- Live panels require a *read* key and a ZipLogger server that offers the read interface. Answers are cached for a minute.
- The panels' queries were verified against the service source and a stand-in, not the live service (see above).
- Analytics, replay and WooCommerce tabs show local counters and links, by design.

## Compatibility not exercised

See [COMPATIBILITY.md](COMPATIBILITY.md) for the matrix that was run (PHP 7.4 to 8.5, WordPress 6.0 to 7.1.2, WooCommerce 11.1.2, multisite). Not run: PHP 8.0 and 8.2, WordPress versions other than those listed, other WooCommerce versions, object-cache drop-ins (Redis/Memcached), specific page-cache or optimization plugins (WP Rocket, LiteSpeed and others), specific consent-management plugins, the real WP Consent API plugin (its documented `wp_has_consent()` function and change events were exercised through stand-ins), headless or REST-only front ends, Firefox and Safari (the real-browser suites run Chromium), and real mobile devices.

## Distribution

- **The name** is "ZipLogger: Error Monitoring & Session Replay" (slug `ziplogger`), which contains no WordPress trademark.
- **Plugin Check** reports 0 errors and 40 warnings, each assessed in [COMPATIBILITY.md](COMPATIBILITY.md) and [WORDPRESS-ORG-REVIEW.md](WORDPRESS-ORG-REVIEW.md); none is a defect.
- **Translations.** The plugin is translation-ready (`languages/ziplogger.pot` is shipped) but no translation is included. The admin screens were checked with axe-core with the page direction switched to right-to-left (not a real right-to-left locale) and at phone width; no native reader of a right-to-left language reviewed them.
- **Privacy tooling.** The plugin offers suggested text for the site's privacy policy (`wp_add_privacy_policy_content()`), describing the modules that are switched on. It does not register WordPress personal-data exporters or erasers: what it stores about a person is pseudonymous identifiers in the browser and two private markers on WooCommerce orders (see `plugin/ziplogger/docs/PRIVACY.md`).
- **The WordPress.org contributor username** in `readme.txt` is a placeholder until the account that will own the plugin exists.

