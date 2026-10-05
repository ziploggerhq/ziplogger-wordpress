# Building the plugin ZIP, reproducibly

Everything runs in containers, so the result does not depend on what is installed on your machine. You need Docker (with Compose) and Git; nothing else.

```
wordpress_ziplogger/
  plugin/ziplogger/      the plugin: PHP source, docs, readme.txt, and assets/js (built)
  frontend/              readable sources of the two browser scripts + their tests (not shipped)
  bin/                   build-zip.php, verify-zip.php, refresh-e2e.ps1
  tests/                 phpunit, e2e (real WordPress + real browser), perf
  docker/, docker-compose.*.yml
  dist/ziplogger.zip     the output (its folder is named after the WordPress.org slug and text domain, ziplogger-error-monitoring-session-replay)
```

## 1. Build the browser scripts

```bash
docker run --rm -v "$PWD:/work" -w /work/frontend node:22-alpine sh -c "npm ci --no-audit --no-fund && node build.mjs"
```

This writes `plugin/ziplogger/assets/js/ziplogger.min.js`, `ziplogger-recorder.min.js`, `manifest.json` (sizes, SHA-256, exact dependency versions) and `THIRD-PARTY-LICENSES.txt`.

It is deterministic: the versions of `@ziplogger/browser`, `@rrweb/record`, `web-vitals`, `esbuild` and `jsdom` are exact in `frontend/package.json` and locked in `package-lock.json`; the output contains no timestamps or absolute paths; line endings are normalised. Running it twice on the same lockfile gives byte-identical files (the manifest's hashes are how you check).

## 2. Build the ZIP

```bash
docker run --rm -v "$PWD:/w" -w /w ziplogger-ci:php8.3-wplatest sh -c "php bin/build-zip.php && php bin/verify-zip.php dist/ziplogger.zip"
```

(`ziplogger-ci` is the test image; see below. Any PHP CLI with the `zip` extension works: `php bin/build-zip.php`.)

`build-zip.php` sorts the entries, fixes every timestamp (`SOURCE_DATE_EPOCH`, default 2026-08-31), normalises permissions and uses forward slashes, so **building the same tree twice gives byte-identical archives**. It never includes dotfiles, `node_modules`, `tests` or logs. `verify-zip.php` checks the layout (a single top-level `ziplogger/` folder), the plugin header, the presence of every required file, and that no development file leaked in.

On Windows PowerShell, `bin/refresh-e2e.ps1` does steps 1 (without `npm ci`) and 2.

## 3. Verify the ZIP in a clean WordPress

```bash
docker compose -f docker-compose.e2e.yml up -d
node --test tests/e2e/m1-logs.test.mjs        # installs the ZIP with WP-CLI, activates it, checks nothing is sent on activation
```

Every end-to-end suite starts by installing `dist/ziplogger.zip` into a real WordPress (never the working tree), so what is tested is what ships.

## Test images

```bash
# WordPress + the WordPress PHPUnit library + PHPCS (WPCS, PHPCompatibility) for one matrix cell
PHP_VERSION=8.3 WP_VERSION=latest docker compose -f docker-compose.test.yml build phpunit
# the same, with WooCommerce next to the tests
PHP_VERSION=8.3 WP_VERSION=latest WC_VERSION=11.1.2 WC_TAG=-wc11.1.2 docker compose -f docker-compose.test.yml build phpunit
```

See [TESTING.md](TESTING.md) for how to run each suite.

## Changing the browser scripts

Edit `frontend/src`, run `npm test` in `frontend` (unit tests in jsdom, plus checks on the built files: size budgets, no secrets, no `eval`, no regex look-behind), rebuild (step 1), rebuild the ZIP (step 2). Dependency upgrades are deliberate: change the pinned version, run `npm install` to refresh the lockfile, and read `frontend/test/client.test.mjs`, which pins the behaviour of the ZipLogger SDK that the plugin relies on.

## Translations

`plugin/ziplogger/languages/ziplogger-error-monitoring-session-replay.pot` is generated from the source with WP-CLI's `i18n make-pot` (it is a source file that ships in the ZIP like any other; it is not generated during the build, so a rebuild stays byte-identical). Regenerate it after changing user-visible strings:

```bash
docker compose -f docker-compose.e2e.yml --profile tools run --rm -T -v "$PWD/plugin/ziplogger:/src" wpcli \
  wp i18n make-pot /src /src/languages/ziplogger-error-monitoring-session-replay.pot --slug=ziplogger-error-monitoring-session-replay --domain=ziplogger-error-monitoring-session-replay \
  --package-name="ZipLogger: Error Monitoring & Session Replay" --headers='{"Report-Msgid-Bugs-To":"https://ziplogger.ai/"}' --exclude=languages,assets/js
```

The file records the generation time, so it changes on every run even when no string did; regenerate it when strings change, not on every build.
