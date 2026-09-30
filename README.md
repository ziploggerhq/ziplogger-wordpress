# ZipLogger for your WordPress site

The source of the ZipLogger plugin for WordPress (public repository: https://github.com/ziploggerhq/ziplogger-wordpress). It sends a site's PHP errors, browser errors, product analytics, session replays, distributed traces and WooCommerce events to [ZipLogger](https://ziplogger.ai/), and shows the results in the WordPress dashboard.

Everything is **off until you switch it on**, the three credentials (server, browser, read) are kept apart, and nothing is queued, recorded or sent to a visitor's browser before the consent the site requires.

| You want to... | Read |
| --- | --- |
| Install and connect the plugin | [plugin/ziplogger/docs/SETUP.md](plugin/ziplogger/docs/SETUP.md) |
| Know what is collected, and what never is | [plugin/ziplogger/docs/PRIVACY.md](plugin/ziplogger/docs/PRIVACY.md) |
| Fix something that is not working | [plugin/ziplogger/docs/TROUBLESHOOTING.md](plugin/ziplogger/docs/TROUBLESHOOTING.md) |
| Make delivery reliable (WP-Cron and system cron) | [plugin/ziplogger/docs/CRON.md](plugin/ziplogger/docs/CRON.md) |
| Log, track events or trace from your own code | [plugin/ziplogger/docs/DEVELOPER.md](plugin/ziplogger/docs/DEVELOPER.md) |
| Build the ZIP yourself, reproducibly | [docs/BUILD.md](docs/BUILD.md) |
| Run the tests | [docs/TESTING.md](docs/TESTING.md) |
| See what was measured on real WordPress | [docs/PERFORMANCE.md](docs/PERFORMANCE.md) |
| See which versions and features were exercised | [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md) |
| See what does **not** work or could **not** be verified | [docs/LIMITATIONS.md](docs/LIMITATIONS.md) |
| Read the final delivery report | [docs/REPORT.md](docs/REPORT.md) |
| Review the plugin for WordPress.org (guidelines, Plugin Check, security) | [docs/WORDPRESS-ORG-REVIEW.md](docs/WORDPRESS-ORG-REVIEW.md) |
| Rebuild the minified browser scripts from source | [frontend/README.md](frontend/README.md) |

## Layout

```
plugin/ziplogger/     the plugin (what ships): PHP, admin UI, built browser scripts, readme.txt, docs
frontend/             readable source of the two browser scripts, and their tests (not shipped)
tests/                phpunit, end-to-end (real WordPress, real Chromium, local receiver), performance
bin/                  reproducible ZIP build and verification
docker/               test images (PHP x WordPress matrix)
dist/ziplogger.zip    the installable package (build output, not committed)
```

## Install

Upload the plugin ZIP (build it as described in [docs/BUILD.md](docs/BUILD.md); the result is `dist/ziplogger.zip`) in **Plugins > Add New > Upload Plugin**, activate it, then open **Settings > ZipLogger > Connection**. The setup guide walks through creating the three keys.

## Requirements

WordPress 6.0 or later, PHP 7.4 or later. WooCommerce is optional (the WooCommerce module needs it). The plugin has no Composer dependencies and makes no network request until a key and a module are configured.

## Licence

GPL-2.0-or-later. The bundled browser scripts include the ZipLogger browser SDK and rrweb (MIT) and web-vitals (Apache-2.0); see `plugin/ziplogger/assets/js/THIRD-PARTY-LICENSES.txt`.
