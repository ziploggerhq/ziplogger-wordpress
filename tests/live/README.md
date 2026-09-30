# Live check against a real ZipLogger service

`live-check.mjs` runs the **built plugin** (`dist/ziplogger.zip`, installed by WP-CLI into the end-to-end test site) against a real ZipLogger endpoint, and prints PASS or FAIL for each observation. It is the counterpart of the suites in `tests/e2e`, which talk to a local stand-in.

```bash
docker compose -f docker-compose.e2e.yml up -d
# a new site needs:  docker compose -f docker-compose.e2e.yml --profile tools run --rm -T wpcli wp core install \
#   --url=http://wordpress --title=live --admin_user=admin --admin_password=e2e-admin-pass-1 --admin_email=admin@example.org --skip-email
node tests/live/live-check.mjs C:\path\outside\the\repo\zl-live-test.env
docker compose -f docker-compose.e2e.yml down          # removes the keys: the test database is in memory
```

The env file (never inside the repository):

```
ZL_ENDPOINT=https://app.ziplogger.ai
ZL_SERVER_KEY=...     # ingestion
ZL_BROWSER_KEY=...    # ingestion only
ZL_READ_KEY=...       # optional: enables the read-interface and dashboard checks
```

With only one key, use `ZL_KEY=...`: it plays the server role in phases A to C and the browser role in phase D (so the plugin's rule that one key may not fill two roles is respected). A single key cannot test the read interface.

**Use a test workspace.** The script writes a few dozen small records tagged `source: wp-plugin-live-test` (logs, events, spans, one replay chunk). Every output line is scrubbed of the keys, and nothing about the keys is written to the repository. Revoke the keys afterwards.

What each phase checks is at the top of the script. What it cannot check: how the data looks in ZipLogger's own screens (look for the source `wp-plugin-live-test`), quota and overload responses (it does not provoke them), and behaviour over days.
