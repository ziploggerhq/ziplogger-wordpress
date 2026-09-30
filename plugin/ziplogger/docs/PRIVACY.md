# Privacy, consent and data flow

This page is written for the person responsible for your site's privacy notice. It says, per module, **what is collected, where it goes, which identifiers it creates, and when consent applies.** Copy what you need into your own notice; it is not legal advice.

All data goes to **ZipLogger** at the endpoint you configured (by default `https://app.ziplogger.ai`). Nothing goes to any other party. ZipLogger has **no consent or Do-Not-Track handling of its own**: this plugin decides, in the visitor's browser and on your server, what may be sent.

**The connection itself.** Browser modules send their data directly from the visitor's browser to ZipLogger, so ZipLogger's servers receive the visitor's IP address and browser details (such as the user agent) with each request, as any website does. The plugin puts no IP address into the data it builds. For usage (analytics and order) events, ZipLogger's service uses the IP address only to work out an approximate location (country, region and city) and the user agent to record the browser, operating system and device type; the IP address is not stored with the event. For events sent by your web server, the address ZipLogger sees is your server's, not the visitor's. How ZipLogger handles the connection for the other kinds of data (for example in server access logs) is described in [its privacy policy](https://ziplogger.ai/privacy).

## What is never collected

By design, in every module: passwords, API keys and tokens, cookies and authorization headers, request and response bodies, query-string values, form contents, payment card data, email addresses, user names, IP addresses inside the data the plugin builds, and function arguments in stack traces. Redaction is best-effort text scrubbing plus name-based rules: it lowers exposure but cannot promise that a secret typed into an arbitrary message is caught. Do not log secrets on purpose.

## The three kinds of feature

| Kind | Meaning | Modules |
| --- | --- | --- |
| **Anonymous** | Needs no identifier stored on the visitor's device. Ids live in memory for one page view. | Server logs, browser errors and performance (default policy: no consent needed), server tracing |
| **Identifier-creating** | Stores a random id on the device so activity can be linked across pages and visits. | Analytics (anonymous id + session id), Session replay (session id), WooCommerce identity cookie |
| **Consent-requiring by default** | Off until the visitor's consent is evident. | Analytics, Session replay (policy "required" out of the box) |

Every category has a policy on the **Privacy and consent** tab:

- **required** (default for analytics and replay): collected only while consent is evident. Before consent nothing is collected, buffered or queued, and consent given later **does not send earlier activity** (a recording begins at the moment of consent, from the current state of the page, and never reconstructs what happened before).
- **none** (default for browser errors/performance and for order/payment events): collected without asking. Choose this only where that is lawful for you.

### How consent is evidenced

In this order:

1. The `ziplogger_has_consent` filter, for consent managers (return `true`, `false`, or `null` to abstain).
2. The **WordPress Consent API** plugin, when active (`wp_has_consent`), with the mapping browser→`statistics-anonymous`, analytics and replay→`statistics`, commerce→`functional` (filterable with `ziplogger_wp_consent_categories`). The plugin declares itself Consent-API aware.
3. This plugin's own first-party cookie `ziplogger_consent`, written by the JavaScript API:

```js
ZipLoggerWP.consent.grant('analytics');            // or an array: ['analytics', 'replay']
ZipLoggerWP.consent.revoke('replay');
ZipLoggerWP.consent.state('analytics');            // 'granted' | 'denied' | 'unknown'
document.addEventListener('ziplogger:consent', (e) => console.log(e.detail)); // { category, allowed }
```

No evidence means "unknown", which is treated as **not** granted. **Do Not Track and Global Privacy Control** block the identifying categories (analytics, replay) even if consent was recorded; return `false` from the `ziplogger_honor_dnt` filter to stop honouring them.

### Withdrawing consent

Withdrawing stops collection immediately, removes the ids from the device, starts new unlinked ids, and **discards what was queued but not yet sent** (analytics events, trace spans, unsent replay chunks). It does **not** delete data that was already sent to ZipLogger: use ZipLogger's own deletion tools for that. A replay chunk already in flight when consent is withdrawn cannot be recalled.

## Module by module

### Server logs
Message, severity, time, host name, WordPress/PHP/plugin versions, file paths made relative to WordPress, a stack trace without arguments. Optional collectors: plugin/theme changes, update outcomes, failed logins (reason code only; never the user name, password or IP), outbound HTTP failures (host, method, status, duration only). Stored in a local queue for at most 48 hours, then sent; sent with the **server key**.

### Browser monitoring
Sanitized error message and stack, the page **path** (never query string or fragment), and for requests the host, method, status and duration. Optional navigation timing and Core Web Vitals numbers. No cookies, nothing stored on the device. Sent straight from the browser with the **browser key**.

### Analytics
Page views (path, page type, referrer **host** only), client-side navigations, the interactions you allowlist (only the *selector you configured* is recorded, never the element's text or value), and custom events you send. **Identifiers created:** an anonymous id in `localStorage` (`zl_anon`) and a session id in `sessionStorage` (`zl_sess`), only while analytics is allowed. Optional **identification** of signed-in users sends a keyed-hash pseudonym of the WordPress user id, obtained from your server; it is never the id, email or user name. Query strings are never sent, so campaign parameters (`utm_*`) are not collected.

### Session replay
A masked recording of the page's structure and interactions, for sessions in the sample. **All text and all inputs are masked by default**; password, one-time-code and payment fields, address blocks and payment frames are masked or blocked **regardless of settings**, and there is no setting that turns input masking off. These are never recorded: administrator/editor roles (configurable), the login, registration, password-reset, account, cart, checkout and order-pay pages, WordPress admin, password-protected posts, and any path you exclude. Links lose their query strings, and `mailto:`/`tel:` addresses are removed. **Identifier created:** the session id (`zl_sess`) plus replay state (`zl_replay`). Limits: recordings stop after the configured minutes/megabytes. Cross-origin iframes (payment frames) cannot be recorded by any browser and are not.

### Tracing
Server request spans (method, a route *name* — never the URL — status, duration, the number of database queries, memory peak, request type), outbound HTTP call spans (host, method, status, duration), and, if enabled, browser request spans. A `traceparent` header is sent **only** to your own site and to hosts you list; **baggage (which carries the session id) is sent only to your own site**, never to a third party. Inbound trace headers are treated as untrusted and validated. No trace id is ever written into HTML or a response header, so a page cache cannot hand one visitor another's ids.

### WooCommerce
- **Browser** (through Analytics, so the same consent): product viewed, added to / removed from cart, checkout started. Product id (or SKU, or nothing: your choice) and quantity.
- **Server** (authoritative): order created, payment completed, payment failed, status changed, refunded. Properties: an **opaque order reference** (a keyed hash: not the order number), currency, the amount in the currency's exact minor units, item count, product ids/SKUs if allowed, payment method id, how the order was created, whether the customer was a guest, whether a coupon was used. **Never** names, addresses, emails, phone numbers, order keys, notes, coupon codes, payment credentials or arbitrary order meta.
- **Thank-you pages never count as payment.** Only the order lifecycle does.
- **Identity.** A shopper who agreed to analytics gets a first-party cookie `ziplogger_v` (random anonymous and session ids) on cart/checkout pages and after adding to cart. When an order is created, those two ids (and, for a signed-in customer, the pseudonym of their user id) are stored in a private order field (`_ziplogger_identity`) so that a payment webhook arriving hours later can be attributed. **Without consent nothing about the visitor is stored**; the events then carry only the order's opaque reference (one "visitor" per order, so revenue and conversion counts stay right and nobody is followed).
- The policy for the **commerce** category defaults to "none" (order and payment events are operational data). If you set it to "required", only orders whose customer consented at checkout are reported, including their later webhooks.

### Dashboard
Reads recent errors, trends, vitals, request stats and traces back with the **read key**, on the server, for administrators only. The browser never receives the key and never contacts ZipLogger for this.

## Cookies and browser storage inventory

| Name | Where | Purpose | Set when |
| --- | --- | --- | --- |
| `ziplogger_consent` | Cookie, 6 months, `SameSite=Lax` | Remembers the visitor's choice | The visitor (or your consent manager, through the JavaScript API) records a choice |
| `zl_anon` | `localStorage` | Anonymous visitor id | Analytics allowed |
| `zl_sess` | `sessionStorage` | Session id (per tab) | Analytics or replay allowed |
| `zl_replay` | `sessionStorage` | Recording sequence | A session is being recorded |
| `ziplogger_v` | Cookie, 7 days, `SameSite=Lax` | Links order events to the browsing visitor | WooCommerce module on, analytics allowed, on cart/checkout pages or after adding to cart |
| `ziplogger_li` | Cookie (lasts as long as the sign-in cookie), readable by script | A hint that someone is signed in (value `1`), so anonymous visitors never ask the server about roles or identity. Not a credential; the server never trusts it | Sign-in, while a browser module is on |

Withdrawing consent removes `zl_anon`, `zl_sess`, `zl_replay` and `ziplogger_v`.

## Retention

Locally: undelivered items are dropped after 48 hours (or when the queue is full, and counted). At ZipLogger: per your plan and workspace settings, outside this plugin's control.

## Erasure and export

This plugin does not register WordPress personal-data exporters or erasers. What it stores about a person is limited to pseudonymous ids inside order fields (`_ziplogger_identity`, `_ziplogger_events`) and the browser cookies above. To remove the ids of an order, delete those two order meta keys; to remove data already at ZipLogger, use ZipLogger.

## A starting text for your privacy notice

> We use ZipLogger to monitor errors and, where you agree, to understand how the site is used. Error monitoring records technical details of failures (for example an error message and the page address without its parameters) and does not set cookies. With your consent, we also record pages viewed and actions taken using a random identifier stored in your browser, and, for a small sample of visits, a masked recording of the page in which text and form fields are hidden. We do not record your payment details, passwords, or the contents of forms. You can withdraw consent at any time; this stops collection and removes the identifier from your browser.
