# Testing

Four layers, each in containers, plus an optional live check. The four layers do not talk to the real ZipLogger service: the receiver in the end-to-end suites is a local stand-in (`tests/e2e/mock-ziplogger.mjs`, `mock-read-model.mjs`) written from the service's own source code. **What that means, and what it does not prove, is spelled out in [LIMITATIONS.md](LIMITATIONS.md).**

| Layer | What it runs | Where | Command |
| --- | --- | --- | --- |
| PHP unit/integration | The WordPress PHPUnit library against real WordPress + MariaDB; WooCommerce present in the WooCommerce cell | `tests/phpunit` | see below |
| JavaScript | Node's test runner + jsdom, against the sources **and** the built bundles | `frontend/test` | `cd frontend && npm test` |
| End-to-end, server | A real WordPress (Apache + PHP + MariaDB) with the built ZIP installed by WP-CLI, plus the local receiver | `tests/e2e/m1-logs`, `m4-tracing`, `m5-commerce` | `node --test tests/e2e/m4-tracing.test.mjs` |
| End-to-end, browser | A real Chromium (Microsoft's Playwright image) driving that WordPress | `tests/e2e/m3-browser`, `m5-commerce`, `m6-dashboard` | `node --test tests/e2e/m3-browser.test.mjs` |

## PHP

```bash
# one matrix cell
PHP_VERSION=8.3 WP_VERSION=latest docker compose -f docker-compose.test.yml run --rm phpunit vendor/bin/phpunit -c phpunit.xml.dist
# multisite
... run --rm -e WP_MULTISITE=1 phpunit vendor/bin/phpunit -c phpunit.xml.dist
# with WooCommerce (its tests skip without it)
WC_VERSION=11.1.2 WC_TAG=-wc11.1.2 ... run --rm phpunit vendor/bin/phpunit -c phpunit.xml.dist
# the WP Consent API tests define wp_has_consent(), which cannot be undone, so they are a run of their own
... run --rm phpunit vendor/bin/phpunit -c phpunit-wp-consent-api.xml.dist
# coding standards and PHP compatibility
... run --rm phpunit vendor/bin/phpcs --standard=phpcs.xml.dist /plugin/ziplogger
```

Suites: redaction, settings and schema, endpoint (SSRF) validation, queue and leases, worker and transport (every HTTP status class, timeouts, `Retry-After`), signals, destination safety, credentials, consent, front-end configuration and the visitor-context endpoint, tracing (context parsing of hostile headers, parent-aware sampling, spans, propagation allowlists, log correlation), commerce (money, order lifecycle, deduplication, identity and consent, HPOS-safe data access, page configuration), dashboard (read client, panels, admin-ajax, no credential rendering), admin screens (authorization, CSRF, escaping). The WP Consent API integration is tested in its own run (9 tests).

## JavaScript

`npm test` runs unit tests for every module (utilities and sanitizing, consent, identity and storage, errors, network wrappers, tracing, analytics, replay eligibility and discard, commerce, performance, boot and duplicate-init) and tests of the **built files** (size budgets, no secret, no `eval`, no regex look-behind, manifest hashes, the bundle boots and reports an error, a second copy does nothing). `client.test.mjs` pins the behaviour of the bundled ZipLogger SDK that the plugin depends on.

## End-to-end

```bash
docker compose -f docker-compose.e2e.yml up -d       # WordPress, MariaDB, receiver
pwsh bin/refresh-e2e.ps1                              # rebuild scripts + ZIP
node --test tests/e2e/m1-logs.test.mjs                # server logs, delivery faults, activation sends nothing
node --test tests/e2e/m3-browser.test.mjs             # browser monitoring, consent, replay masking, isolation, cache
node --test tests/e2e/m4-tracing.test.mjs             # server tracing, browser -> server correlation, propagation
node --test tests/e2e/m5-commerce.test.mjs            # WooCommerce: classic + block checkout, HPOS on/off, webhooks
node --test tests/e2e/m6-dashboard.test.mjs           # the dashboard, credential boundaries
docker compose -f docker-compose.e2e.yml down -v
```

The first browser run installs Playwright into a Docker volume. The browser scenarios live in `tests/e2e/browser/scenarios.mjs`; each returns evidence and the host-side test asserts on it.

Fixtures (`tests/e2e/fixtures/zl-e2e-fixtures.php`, a must-use plugin mounted only in the test stack): PHP problems on demand, JavaScript errors and slow/failing requests, a replay page full of things that must never be recorded, a single-page-app router, a test payment gateway, and a "provider webhook" REST route that finishes, fails or refunds an order without a shopper.

## Performance

```bash
node tests/perf/server.mjs      # per-request PHP time, DB queries and memory for each module
node tests/perf/browser.mjs     # page-load and script cost per module in Chromium
```

Results and how to read them: [PERFORMANCE.md](PERFORMANCE.md).

## Live check

With a key for a test workspace, `node tests/live/live-check.mjs <env file>` runs the built plugin against a real ZipLogger service and prints PASS or FAIL for 24 observations (delivery, the HTTP contract, a bad key, a real browser at another origin, and, with a read key, the dashboard). See `tests/live/README.md`. It was run once, on 2026-09-30, and passed; the read checks were skipped because that key had no read scope.

## Secret scanning

The repository must never contain a credential. `gitleaks detect --source . --config .gitleaks.toml` (or the container: `docker run --rm -v "$PWD:/repo" zricethezav/gitleaks:latest detect --source /repo --config /repo/.gitleaks.toml`) scans the files and, without `--no-git`, the whole history. `.gitleaks.toml` allows only the synthetic test values by pattern. The redaction tests build their provider-shaped sample secrets at run time, so no such string is present in a source file.

## Plugin Check

WordPress's own [Plugin Check](https://wordpress.org/plugins/plugin-check/) runs against the built ZIP in the end-to-end site (it needs network access to install itself):

```bash
docker compose -f docker-compose.e2e.yml up -d
node -e "import('./tests/e2e/lib.mjs').then(m => m.installZip())"      # the ZIP, as a user installs it
W="docker compose -f docker-compose.e2e.yml --profile tools run --rm -T wpcli wp"
$W plugin install plugin-check --activate
$W plugin check ziplogger --format=json --require=/var/www/html/wp-content/plugins/plugin-check/cli.php
# clean up: Plugin Check leaves copies of the plugin's tables under a wp_pc_ prefix
$W plugin deactivate plugin-check && $W plugin delete plugin-check
$W db query "DROP TABLE IF EXISTS wp_pc_ziplogger_meta, wp_pc_ziplogger_queue"
```

The results and what each remaining warning means are in [COMPATIBILITY.md](COMPATIBILITY.md).
