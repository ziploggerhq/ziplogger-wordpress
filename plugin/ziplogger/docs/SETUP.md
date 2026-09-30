# Setting up ZipLogger

**Nothing is collected or sent until you add a key and switch a module on.** Activating the plugin, or switching on one module, never turns another one on.

## What you need

- WordPress 6.0 or later and PHP 7.4 or later.
- A ZipLogger workspace, and up to three API keys from it (Settings, API keys in ZipLogger). Use a **separate key for each purpose**; the plugin refuses to save one key in two roles.

| Key | Used by | Where it lives | Scope to give it |
| --- | --- | --- | --- |
| **Server key** | Delivery of server logs, order/payment events and traces, from your server | Database (not autoloaded), or a constant or environment variable | Ingestion |
| **Browser key** | Browser errors, analytics, replay and browser traces, sent straight from visitors' browsers | Delivered to **every visitor's browser** in the page. Create it just for this, and never use a key that can read data | Ingestion only |
| **Read key** (optional) | This dashboard reading recent errors and trends back | The server only; never sent to a browser | Read |

The dashboard never asks for your ZipLogger login, a personal token or a password.

## Quick start

1. Install and activate the plugin.
2. **Settings, ZipLogger, Connection.** Paste the server key. Add the browser key if you want any browser module. Add the read key if you want live panels in the dashboard.
3. Press **Send test event**. It works even while collection is off, and shows what the endpoint answered.
4. Switch on what you need, each on its own tab:
   - **Server logs**: PHP errors (recommended), plugin/theme changes, update outcomes, and optional noisy collectors.
   - **Browser monitoring**, **Analytics**, **Session replay**, **Tracing**, **WooCommerce**: each is independent and has its own sampling.
5. Review **Privacy and consent** before enabling analytics or replay for visitors in a region that requires consent.

The **Overview** tab shows what is on, what is blocked (and why), and whether delivery is healthy.

## Configuration outside the database

Define these in `wp-config.php` (or set the environment variable of the same name). A value set this way wins over the database and cannot be edited on the screen.

```php
define( 'ZIPLOGGER_API_KEY',     'zk_...' );   // server key
define( 'ZIPLOGGER_BROWSER_KEY', 'zk_...' );   // browser key (public)
define( 'ZIPLOGGER_READ_KEY',    'zk_...' );   // read key
define( 'ZIPLOGGER_ENDPOINT',    'https://ziplogger.example.com' ); // self-hosted ZipLogger
define( 'ZIPLOGGER_RELEASE',     '2026.09.30' );   // shown on every event
define( 'ZIPLOGGER_COMMIT_SHA',  'a1b2c3d' );      // enables regression attribution
define( 'ZIPLOGGER_SECRET',      '...at least 16 characters...' ); // pseudonym secret, see below
```

- **Endpoint.** HTTPS only, no credentials or query string in the URL, and no IP literals or internal host names. Every address it resolves to must be public. `ZIPLOGGER_ALLOW_INSECURE_ENDPOINT` (set to `true`) relaxes exactly those checks for a local development server, and nothing else; do not use it in production.
- **Pseudonym secret.** Visitor and order references in analytics are keyed hashes, not ids. The key is generated on first use and stored separately from the WordPress salts, so rotating salts does not re-identify anyone. Define `ZIPLOGGER_SECRET` to keep it out of the database. Changing it changes every reference (a deliberate reset).

## When the key or endpoint changes

Data waiting in the queue was collected for one workspace. If the key or endpoint changes, it is **held**, not sent to the new destination. The Overview tab asks you to *retarget* (send it to the new destination), *keep holding*, or *discard*. You can set the default under Connection.

## Multisite

Each site has its own settings, keys, queue and cron job. **Network activation is refused**; activate the plugin on each site. Uninstalling honours each site's own "delete data on uninstall" choice.

## WP-CLI

```bash
wp ziplogger status            # health of every signal
wp ziplogger flush             # deliver what is queued now
wp ziplogger test              # send a test event
wp ziplogger clear             # delete undelivered items (asks first)
```

Add `--format=json` to `status` for scripts.

## Deactivating, and removing

- **Deactivating** keeps your settings and queue; the plugin simply stops collecting. Scheduled jobs are removed.
- **Deleting the plugin** removes its data only if you ticked "Also delete the queue, counters, keys and settings from the database" (Privacy and consent tab). By default your settings and queue are kept, so a reinstall picks up where it left off.

## Updating

The plugin upgrades its own database tables on the first request after an update. If the tables ever go missing, the Overview tab says so instead of failing silently.
