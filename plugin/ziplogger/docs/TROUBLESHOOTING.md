# Troubleshooting

Start with **Settings, ZipLogger, Overview** (what is on, what is blocked and why, delivery health) and **Diagnostics** (a redacted report you can paste into a support request; it never contains a key).

## Nothing arrives in ZipLogger

| Check | Fix |
| --- | --- |
| Overview says *Off* | Switch the module on (server collection is on the Server logs tab). Nothing is collected by default |
| The module shows *On, but not running* | The reason is printed under it: a missing server/browser key, an unusable endpoint, WooCommerce not active |
| **Send test event** fails with an authentication message | The key was rejected. Create a new key in ZipLogger; make sure it has the right scope, and that you did not paste the browser key into the server slot |
| **Send test event** fails with a network/TLS message | Your host cannot reach the endpoint over HTTPS (firewall, missing CA certificates, DNS). The plugin never disables certificate checks |
| Test works, real events do not | Delivery runs from WP-Cron: see [CRON.md](CRON.md). Look for **Overdue** and for `DISABLE_WP_CRON` |
| Items are **held** | You changed the key or endpoint after data was collected. Choose retarget/discard on the Overview tab |
| Only some events arrive | The minimum severity, sampling, or the per-request cap dropped the rest. Drops are counted, by reason, on the Overview and Diagnostics tabs |

## Browser modules do nothing

1. **View the page source.** You should see `<script type="application/json" id="ziplogger-config">` and a `ziplogger.min.js` script tag. If not: the module is off, no browser key is set, or the page is excluded (admin, AJAX, feeds, previews, or a `ziplogger_load_frontend` filter).
2. **The script is present but nothing is sent.** Open the browser console and run `ZipLoggerWP.status()`. `allowed.analytics: false` means consent is not evident (see PRIVACY: the default policy for analytics and replay is *required*). Do Not Track and Global Privacy Control also block analytics and replay.
3. **An optimization plugin moves or removes the script.** The tag carries `defer`, `data-cfasync="false"`, `data-no-optimize="1"`, `data-no-defer="1"` and `nowprocket`. If your optimizer ignores those, exclude `ziplogger.min.js` and `ziplogger-config` from delay, combination and removal.
4. **A Content-Security-Policy blocks it.** Allow the script from your own origin and `connect-src` to your ZipLogger endpoint (for replay also `script-src` for `ziplogger-recorder.min.js` from your origin). The configuration block is `application/json` data, not an inline script, so `script-src` does not affect it.
5. **An ad blocker blocks it.** Browser telemetry is lost for those visitors; server-side data (PHP errors, orders, payments, server spans) is unaffected.
6. **Errors before the script starts are not captured.** The script is deferred so it never blocks rendering; it starts after the HTML is parsed.

## Session replay records nothing

Replay needs *all* of: the module on, the browser key, consent for `replay`, the visitor's session inside the sample rate, a page that is not excluded, and a role that is not excluded. `ZipLoggerWP.status().replay` says why not:

| `reason` | Meaning |
| --- | --- |
| `consent` | Consent for replay is not evident |
| `not_sampled` | This session is outside the sample rate (deterministic per session: reloading does not change it) |
| `path` / `page` | The page is excluded: checkout, cart, account, login, password-protected content, or a path you excluded |
| `role` | The signed-in role is excluded (administrators and editors by default) |
| `context_unavailable` | The plugin could not ask the server about the visitor (see below): replay **fails closed** |
| `plan` / `disabled` | ZipLogger's own settings or plan switched recording off for the workspace |

`context_unavailable` means the `admin-ajax.php` request `action=ziplogger_context` failed. Security plugins that block `admin-ajax.php` for anonymous visitors cause this; allow that action. Recording without an answer would risk recording an excluded role, so it never happens.

## Numbers look wrong

- **Duplicate page views after adding a cache/CDN:** a page cache serves the same HTML to everyone by design; identifiers are created in the browser, not in the page. If you see duplicates, check that the script is not loaded twice by a theme or optimizer (a second copy does nothing, but two *different* analytics tools may both count).
- **Page views with no query parameters:** deliberate. Query strings are never sent.
- **Campaign parameters missing:** same reason.
- **Order events show one "visitor" per order:** the customer did not consent (or the script was blocked), so the only identity is the order's opaque reference. See PRIVACY.
- **Server events show your server's location:** ZipLogger can geolocate an event from the address that sent it (a default setting of the service), and a server-side event comes from your web server, not the customer.
- **Revenue in the wrong scale:** amounts are in the currency's exact minor units (`valueMinor`) and in major units (`value`). Zero-decimal currencies (JPY, KRW...) and three-decimal ones (KWD, BHD...) are handled by the currency, not by your shop's display decimals.

## WooCommerce

- **HPOS / block checkout warnings in WooCommerce:** the plugin declares compatibility with both. If WooCommerce lists it as incompatible, update to the current version.
- **No order events:** the module needs WooCommerce active, a server key, and the relevant checkbox (orders, payments, refunds, status changes). Orders created by importers are reported when they leave the *draft* state.
- **`payment_completed` never fires for cash-on-delivery at checkout:** correct: no money has changed hands. It fires when the order is completed. Override with the `ziplogger_wc_payment_confirmed` filter.
- **Payment events for a gateway that never calls `woocommerce_payment_complete()`:** the move into Processing/Completed from an unpaid status counts (`confirmedBy: status`).

## The dashboard is empty or says unavailable

- **"No read key is set"**: add a *read* key on the Connection tab (never your login).
- **"did not accept the read key"**: the key is revoked, mistyped, or lacks the read scope. Use **Test the read key**.
- **"does not offer the read interface"**: the ZipLogger server has the query surface switched off or is an older version.
- **Slow first load:** panels load in the background and are cached for a minute.
- The dashboard shows logs, request metrics and traces. ZipLogger's read interface has no product-analytics or replay query, so those tabs link into ZipLogger instead of showing numbers.

## Performance

Server work per request is one bounded database insert at shutdown (only when there is something to record). Browser cost is one deferred script and, for recorded sessions, a lazily loaded recorder. Measured overhead for each module and for all of them together is in `docs/PERFORMANCE.md` of the source repository; if a page seems slower after enabling a module, disable modules one by one (each has its own switch) and compare.

## Getting help

Copy the **Diagnostics** report. It contains versions, module states, queue and drop counts, the last sanitized error per signal, whether WP-Cron is disabled, and whether WooCommerce/HPOS are in use. It never contains keys, event contents or visitor data.
