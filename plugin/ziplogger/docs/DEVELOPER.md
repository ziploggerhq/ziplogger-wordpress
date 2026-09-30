# Developer guide

Everything here is stable API. Anything not listed is internal and may change.

## PHP

### Log an event

```php
ziplogger_log( 'error', 'Payment webhook rejected', array(
    'provider'  => 'acme',
    'attempt'   => 3,
    'exception' => $e,          // a Throwable: its class and an argument-free stack trace are attached
) );

do_action( 'ziplogger_log', 'info', 'Import finished', array( 'rows' => 42 ) ); // same thing; safe if the plugin is absent
```

Severity: `debug`, `info`, `warn`, `error`, `fatal` (PSR-3 names are accepted). The event is redacted, bounded, queued locally and sent in the background; the call returns `true` when it was accepted into the request's buffer and `false` when collection is off, the severity is below the threshold, or a limit was hit. **Do not put secrets in messages**: redaction is best-effort.

### Send a product-analytics event from the server

```php
ziplogger_track( 'newsletter_confirmed', array( 'list' => 'weekly' ) );
ziplogger_track( 'plan_changed', array( 'plan' => 'pro' ), array( 'insert_id' => 'plan-change-' . $change_id ) );
do_action( 'ziplogger_track', 'export_run', array( 'rows' => 1200 ), array( 'actor' => 'system' ) );
```

Needs collection **and** the Analytics module on. The event carries the visitor's ids and a signed-in user's pseudonym only when analytics consent is evident in the request (see PRIVACY); otherwise it belongs to a fixed *system* actor. `insert_id` makes repeated calls for the same logical event count once. Properties are flat and cleaned by name; never pass an email address or user name.

### Filters and actions

| Hook | Type | Purpose |
| --- | --- | --- |
| `ziplogger_should_log( $bool, $spec )` | filter | Return `false` to drop an event (`$spec`: type, severity, message, fields, context, stack) before it is stored |
| `ziplogger_event( $spec )` | filter | Adjust (or return `false` to drop) an event before redaction and persistence; whatever you return is still redacted |
| `ziplogger_server_event( $event )` | filter | Adjust or drop a server event (order, payment, custom) before it is queued |
| `ziplogger_redact_keys( $keys )` | filter | Extra field-name fragments that must always be redacted, e.g. `iban` |
| `ziplogger_redact_patterns( $patterns )` | filter | Extra PCRE patterns (with delimiters) masked in free text |
| `ziplogger_tags( $tags )` | filter | Tags on every log record |
| `ziplogger_release( $release )`, `ziplogger_commit_sha( $sha )` | filter | Deployment attribution (or use the constants) |
| `ziplogger_limits( $limits )` | filter | Adjust resource bounds (queue size, batch size, timeouts...). Values are clamped to safe ranges |
| `ziplogger_allowed_endpoint_ports( $ports )` | filter | Ports accepted for a custom endpoint (default `443`) |
| `ziplogger_has_consent( $consent, $category )` | filter | Consent-manager integration: `true`, `false`, or `null` to abstain. Categories: `browser`, `analytics`, `replay`, `commerce` |
| `ziplogger_consent_state( $state, $category )` | filter | Final say on `granted` / `denied` / `unknown` |
| `ziplogger_wp_consent_categories( $map )` | filter | Map this plugin's categories onto WordPress Consent API categories |
| `ziplogger_honor_dnt( $bool )` | filter | Return `false` to stop honouring Do Not Track / Global Privacy Control |
| `ziplogger_load_frontend( $bool )` | filter | Keep the browser script off a page or template |
| `ziplogger_frontend_config( $config )` | filter | Adjust the browser configuration. **Keep it free of anything visitor-specific: page caches store it** |
| `ziplogger_replay_excluded_page( $bool )` | filter | Return `true` to make the current page never-record |
| `ziplogger_replay_allowed_for_user( $bool, $user )` | filter | Return anything but strict `true` to exclude a signed-in visitor from replay |
| `ziplogger_wc_payment_confirmed( $bool, $order, $source, $from, $to )` | filter | Decide whether a move into a paid status counts as confirmed payment (default excludes cash-on-delivery at checkout) |

Constants: `ZIPLOGGER_API_KEY`, `ZIPLOGGER_BROWSER_KEY`, `ZIPLOGGER_READ_KEY`, `ZIPLOGGER_ENDPOINT`, `ZIPLOGGER_RELEASE`, `ZIPLOGGER_COMMIT_SHA`, `ZIPLOGGER_SECRET`, `ZIPLOGGER_ALLOW_INSECURE_ENDPOINT` (development only).

### Example: a consent manager

```php
add_filter( 'ziplogger_has_consent', function ( $consent, $category ) {
    if ( ! function_exists( 'my_cmp_has' ) ) {
        return $consent;                      // abstain: another source may decide
    }
    return my_cmp_has( $category === 'replay' ? 'statistics' : 'marketing' );
}, 10, 2 );
```

Tell the browser script too, when the visitor decides:

```js
myCmp.on('change', (state) => {
  ZipLoggerWP.consent[state.statistics ? 'grant' : 'revoke'](['analytics', 'replay']);
});
```

### Example: exclude a members area from replay

```php
add_filter( 'ziplogger_replay_excluded_page', function ( $excluded ) {
    return $excluded || is_page( 'members' ) || is_singular( 'private_report' );
} );
```

### WP-CLI

`wp ziplogger status [--format=json]`, `flush [--max-batches=N] [--max-seconds=N]`, `test`, `clear [--yes]`.

## JavaScript

The browser script defines `window.ZipLoggerWP` once per page (a second copy does nothing). It exists only on pages where at least one browser module is on and a browser key is set.

```js
ZipLoggerWP.track('coupon_applied', { code_type: 'percent', amount: 10 });   // analytics event
ZipLoggerWP.log('warn', 'Checkout widget failed to load', { step: 'payment' }); // browser log entry
ZipLoggerWP.flush();                                                          // send what is buffered now
ZipLoggerWP.status();                                                         // what is on, allowed, recording (no identifiers)
ZipLoggerWP.consent.grant('analytics');  ZipLoggerWP.consent.revoke(['analytics', 'replay']);
ZipLoggerWP.consent.state('analytics');  ZipLoggerWP.consent.allows('replay');
```

- `track` returns `true` only when the event was accepted: analytics must be enabled **and** allowed (consent), the session sampled in, and the name valid. Properties are flat and cleaned: primitives only, sensitive-looking names dropped, strings scrubbed, at most 30 keys. Names are lower-cased to `[a-z0-9_.:$]`.
- `log` goes through the same sanitizing as automatic reports and obeys the *browser* consent policy.
- Nothing you pass is sent before consent when the category requires it, and nothing is queued on its behalf.

### Before the script has loaded

The script is deferred. Code that runs earlier can queue commands:

```html
<script>
  window.ziploggerQueue = window.ziploggerQueue || [];
  window.ziploggerQueue.push(['track', 'hero_seen', { variant: 'b' }]);
  window.ziploggerQueue.push(['consent.grant', 'analytics']);
</script>
```

Queued commands run once the script starts. A `track` queued before consent is **dropped** when it runs, not held. Or wait for the event: `document.addEventListener('ziplogger:ready', ...)`.

### Markup instead of code

```html
<button data-ziplogger-event="Newsletter Signup" data-ziplogger-prop-plan="weekly">Subscribe</button>
<div data-ziplogger-mask>never shown in a recording (text masked)</div>
<div data-ziplogger-ignore>not recorded at all</div>
```

`data-ziplogger-event` works when "Track interactions" is on; `data-ziplogger-prop-*` becomes a property (`data-ziplogger-prop-source-page` → `sourcePage`; sensitive-looking names are dropped). Only the attributes you wrote are collected, never the element's text or value.

### Events

- `ziplogger:ready` (on `document`): the script started. `detail.version`.
- `ziplogger:consent` (on `document`): a category changed. `detail = { category, allowed }`.

### Cache-safe by design

The configuration in the page (`<script type="application/json" id="ziplogger-config">`) depends only on your settings and on the URL being rendered, never on the visitor, so page caches and CDNs may store it. Per-visitor facts (who is signed in, whether they may be recorded, their pseudonym) come from an uncached `admin-ajax.php` POST (`action=ziplogger_context`) that answers only about the asker.

## Events ZipLogger receives from WooCommerce

Browser (through Analytics; `origin` is absent): `product_viewed`, `product_added_to_cart`, `product_removed_from_cart`, `checkout_started`.
Server (`origin: "server"`): `order_created`, `order_status_changed`, `payment_completed`, `payment_failed`, `order_refunded`.

Common properties: `orderRef` (opaque), `currency` (ISO 4217), `value` (major units) and `valueMinor` (integer minor units of the *currency*, so `JPY 500` is `500` and `KWD 1.234` is `1234`), `itemCount`, `productIds`/`quantities` (per your setting), `paymentMethod`, `createdVia`, `isGuest`, `couponUsed`. Refunds add `refundValue`/`refundValueMinor`, `refundedValue`/`refundedValueMinor` (cumulative) and `fullyRefunded`. Payments add `confirmedBy` (`gateway` or `status`); failures add `failureNo`; status changes add `from`, `to`, `changeNo`.

Every event has a deterministic `insertId` (`oc:<ref>`, `pc:<ref>`, `pf:<ref>:<n>`, `st:<ref>:<n>`, `rf:<ref>:<hash>`), so a repeated hook or webhook is stored once, while each partial refund and each new failure is its own event.

## Extending safely

- Keep secrets out of messages, properties and the frontend config filter.
- A custom `ziplogger_has_consent` implementation must return `null` when it does not know: returning `true` by default would grant consent for everything.
- Do not call `ziplogger_log()` from a function that runs inside an error handler for an error the plugin itself raised; the plugin already guards against recursion, but tight loops still cost you the per-request cap.
