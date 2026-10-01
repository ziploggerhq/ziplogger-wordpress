# Notes for the WordPress.org plugin review

This page is for the plugin team's reviewer. It says what the plugin is, where every claim can be checked, and why the few remaining Plugin Check warnings are correct code.

## What it is

**ZipLogger: Error Monitoring & Session Replay** (slug `ziplogger`) is the WordPress connector for **ZipLogger** (https://ziplogger.ai/), an external software-as-a-service platform for logs, error monitoring, analytics, session replay and tracing. **A ZipLogger account is required** (API keys are created in it). ZipLogger has a free plan and optional paid plans (https://ziplogger.ai/pricing). Terms: https://ziplogger.ai/terms. Privacy policy: https://ziplogger.ai/privacy.

The plugin sends data **only** to the ZipLogger workspace that the site owner's keys belong to (`https://app.ziplogger.ai`, or a self-hosted address the owner types in, which must be HTTPS on a public host name). **It does nothing, and makes no network request, until the owner adds a key and switches a module on** (checked by the test that installs and activates the plugin and verifies that nothing is transmitted). Each module is off by default and independent of the others. What each module sends, and when, is in the "External services" section of `readme.txt`, in `docs/PRIVACY.md`, and, for the site's own privacy policy, in the text the plugin offers through `wp_add_privacy_policy_content()` (class `Privacy_Policy`, generated from the modules that are switched on).

## Guidelines, and where to look

| Guideline | How the plugin meets it | Where to check |
| --- | --- | --- |
| 1, 2. GPL-compatible; the author is responsible for every file | The plugin is GPL-2.0-or-later. Bundled in the browser scripts: `@ziplogger/browser` (MIT, by the same authors as the service), rrweb's recorder (MIT) and Google's `web-vitals` (Apache-2.0, which is compatible with GPL-3.0-or-later and therefore with the plugin distributed as GPL-3.0-or-later; the plugin team has the final word on that combination). The licence text of everything inside the bundles ships with the plugin | `assets/js/THIRD-PARTY-LICENSES.txt`, `assets/js/manifest.json` |
| 4. Human-readable code | The PHP is readable source. The two minified scripts are built from readable sources that are in the public repository, with the exact, deterministic build (pinned versions, lock file with integrity hashes, container command, a check that the shipped files are what the source builds) | https://github.com/ziploggerhq/ziplogger-wordpress, `frontend/README.md`, `docs/SOURCE.md` |
| 5, 6. Trialware, and services | The plugin is a connector for a service that works and that has a free plan; it does not lock features behind a licence key. The service is stated plainly in the readme | `readme.txt`, "External services" |
| 7. No tracking without consent | Off until configured. Analytics and session replay are consent-gated by default: nothing is collected, buffered or queued before consent, recording never reconstructs earlier activity, Do Not Track and Global Privacy Control are honoured, and withdrawing consent stops collection and discards what was not sent. Consent is read from the WordPress Consent API, a filter, or the plugin's own cookie | `docs/PRIVACY.md`; the browser end-to-end suite (28 tests) |
| 8. No code from third parties | The plugin downloads and executes nothing from any remote location. Its scripts are files in the plugin (the session recorder is a second file of the plugin, loaded on demand from the plugin's own folder). Remote calls only send data to the configured ZipLogger address (and, for the dashboard, read data back from it) | `includes/class-transport.php`, `includes/dashboard/class-remote.php` |
| 10. No links or credits without permission | The front end gets one `<script>` tag and a small JSON block, and no visible output | `includes/class-frontend.php` |
| 13. WordPress's own libraries | No copy of a library that WordPress ships is bundled | `assets/js` |
| 17. Trademarks | "ZipLogger" is the developer's own product name; the name does not contain a WordPress trademark | `ziplogger.php` |

## Review round 1

The first review (30 Sep) raised four points. Network-wide activation (the plugin refused it) is fixed and tested on a real multisite with `WP_DEBUG` on. The text domain and the slug are consistent once the permalink is `ziplogger`, which was requested. Two points are questions about intentional code: `error_reporting()` is only read, never set, in the error handler, to honour the `@` operator; `ABSPATH` and the content and plugin directory constants are used only as prefixes to remove from file paths in messages before they are sent, never to locate or load a file.

## Security, briefly

Capability and nonce checks on every administrative action and on the panel endpoint (`manage_options`, per-action nonces); output escaped, input sanitized; `$wpdb->prepare()` for every value in a query; API keys are never printed into a page or script (the browser key, which is designed to be public and is ingestion-only, is the only key a visitor's browser ever receives; the server key and the read key never leave the server, and the plugin refuses to use one key in two roles); HTTPS certificates are always verified and redirects are never followed by the plugin's requests; the configured endpoint is validated (HTTPS, no credentials, no IP literal, every resolved address must be public, including addresses that embed an IPv4 one); the plugin writes only its own two tables, its options and transients, the cookies listed in `docs/PRIVACY.md`, and (with WooCommerce) two private fields on an order that hold pseudonymous markers; `uninstall.php` removes the tables, options and transients when the owner asks. The PHP passes the WordPress-Extra and WordPress-Docs coding standards (which include the security sniffs) with no errors and no warnings, using `phpcs.xml.dist`, which has four documented exclusions (file naming of namespaced classes, one utility file, and two `error_log`-family sniffs in the error collector, whose job is to handle errors). The 86 inline `phpcs:ignore` comments in the plugin each carry a reason; they are almost all of three kinds: queries on the plugin's own table (name = site prefix + constant, values bound with `prepare()`), reads of `$_SERVER`, `$_GET` and cookies that only classify a request and are never output, and `@` suppressions where a malformed value is an expected outcome handled through the return value.

## Plugin Check

Plugin Check 2.1.0, run in a real WordPress on the ZIP that is submitted: **0 errors, 41 warnings**. No warning was silenced by changing correct code; each is explained:

| Warnings | Count | Assessment |
| --- | ---: | --- |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 38 | **False positives.** Every one is a query on the plugin's **own table**, whose name is `$wpdb->prefix` plus a constant (`Schema::queue_table()`, `Schema::meta_table()`), interpolated into a query whose **values** are bound with `$wpdb->prepare()`. An identifier cannot be bound as a value; the `%i` placeholder that can does not exist before WordPress 6.2 and the plugin supports 6.0. Where a further fragment is interpolated (the destination condition), it was itself produced by `prepare()`. Each such line carries a `phpcs:ignore` with this reason |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` and `.NoCaching` | 2 | **Intentional.** One query, `SHOW TABLES LIKE` in `Schema::tables_exist()`, the health check that asks whether the plugin's own tables exist. A cached answer would defeat the check |
| `PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall` | 1 | **Intentional.** `error_reporting()` is *read*, never changed, in the error handler (`includes/collectors/class-php-errors.php`), so that a warning silenced with the `@` operator stays silent (PHP lowers the level while an `@` expression runs). Reading the level is the documented way to honour that; `ini_get()` would not see it |

Reasons for every inline `phpcs:ignore` in the plugin are written next to it.

## What was tested

See `docs/COMPATIBILITY.md` (PHP 7.4 to 8.5, WordPress 6.0 to 7.1.2, WooCommerce, multisite; real-browser suites) and `docs/LIMITATIONS.md` (what was not tested, stated plainly).
