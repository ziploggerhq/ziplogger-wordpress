# Performance: what each module costs, measured

Every number on this page was produced by the scripts in `tests/perf` against the built `dist/ziplogger.zip`, installed in a real WordPress, on 2026-09-30. Nothing is estimated, and nothing is taken from another site. The raw results are committed next to the scripts (`tests/perf/results-server.json`, `results-browser.json`), and the tables below are generated from them (`node tests/perf/report.mjs`), not typed.

**Read these as relative costs on one machine, not as promises for your hosting.**

## The setup

| | |
| --- | --- |
| Machine | A Windows 11 developer machine running Docker Desktop (WSL2): 16 logical CPUs and 15.5 GiB of memory available to Docker. Not a production server; no CPU pinning, other software was running |
| Site | WordPress 7.1.2 on PHP 8.3.35 (Apache `mod_php`, OPcache on), MariaDB 11, WooCommerce 11.1.2, the Twenty Twenty-Five block theme, the front page |
| Receiver | A local stand-in, so ZipLogger's own response time is not part of any number (the plugin never waits for it during a page request) |
| Browser | Chromium from the Playwright 1.63.0 image, desktop viewport, no throttling |

## How it was measured, and why

- **Interleaved, not one configuration after another.** Every configuration is requested (or loaded) once in every round, in a shuffled order, so slow drift of the machine lands on all of them equally. An earlier version measured configurations one after another; on this machine the drift between blocks (tens of milliseconds) was larger than the effects being measured and produced numbers like "browser monitoring costs 50 ms of PHP time". That version was discarded.
- **One configuration is one request (or one browser session).** A test-only must-use plugin swaps the plugin's settings for that request, or removes the plugin from the active list for the baseline. Same site, same database, same OPcache, same everything. The stored settings never change during a run.
- **PHP measures its own time.** The server figures are taken by PHP at the very end of the request, after the plugin's shutdown work (queue insert, span write): milliseconds since PHP received the request, database queries, and peak memory in use. They exclude the network, Docker and the client. The client's wall-clock time was recorded as well (in the JSON).
- **No background delivery inside a measurement.** WP-Cron is not spawned during measured requests. Delivery is a separate background request; it is not free, but it is not paid by a visitor.
- **The browser figures come from Chromium itself** (script execution time, main-thread task time, JS heap) and Resource Timing (bytes on the wire), for cold loads in fresh browser contexts, followed by three seconds of settling during which consent is granted.
- The Apache in this stack compresses with gzip; "bytes on the wire" are the compressed sizes.

### Server: one page request (250 requests per row, interleaved, after 12 warm-up rounds; measured 2026-09-30)

| Configuration | PHP time, median (ms) | p95 (ms) | Added to the median (ms) | DB queries, median | Added queries | Peak memory in use, median (MiB) | Added memory (MiB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Plugin deactivated (WordPress + WooCommerce only) | 102.2 | 143.0 | baseline | 53 | baseline | 12.62 | baseline |
| Plugin active, collection off | 103.0 | 143.1 | +0.8 | 53 | +0 | 12.72 | +0.10 |
| Server logs on (PHP error collector, plugin/theme and update collectors) | 103.6 | 141.9 | +1.4 | 53 | +0 | 12.77 | +0.16 |
| Logs + browser monitoring | 103.1 | 141.6 | +1.0 | 53 | +0 | 12.78 | +0.16 |
| Logs + analytics | 103.6 | 144.1 | +1.4 | 53 | +0 | 12.78 | +0.16 |
| Logs + session replay | 103.3 | 141.9 | +1.1 | 53 | +0 | 12.78 | +0.16 |
| Logs + tracing, server spans sampled at 10% | 103.4 | 140.3 | +1.2 | 53 | +0 | 12.78 | +0.16 |
| Logs + tracing, server spans sampled at 100% | 104.9 | 146.8 | +2.8 | 57 | +4 | 12.78 | +0.16 |
| Logs + WooCommerce events | 103.9 | 142.8 | +1.7 | 53 | +0 | 12.77 | +0.16 |
| All modules on (tracing at 10%) | 103.7 | 143.1 | +1.6 | 53 | +0 | 12.78 | +0.16 |
| Logs on, and this request itself logs one warning | 104.8 | 142.0 | +2.7 | 56 | +3 | 12.78 | +0.16 |

### Browser: one cold page load in Chromium (30 loads per row, interleaved, after 2 warm-up rounds; measured 2026-09-30)

| Configuration | Script execution, median (ms) | Main-thread task time, median (ms) | Added task time (ms) | Longest task, median (ms) | JS heap, median (MB) | Plugin bytes on the wire | Requests to ZipLogger, median | Load event, median (ms) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Plugin deactivated | 28.7 | 93.3 | baseline | 0.0 | 2.7 | 0 | 0 | 167 |
| Plugin active, every browser module off | 29.2 | 94.4 | +1.1 | 0.0 | 2.7 | 0 | 0 | 170 |
| Browser monitoring (errors, requests, timing, Core Web Vitals at 100%) | 37.5 | 101.9 | +8.6 | 0.0 | 3.0 | 22,792 | 1 | 181 |
| Analytics (consented, page views + SPA) | 35.2 | 106.6 | +13.3 | 0.0 | 3.0 | 22,792 | 1 | 181 |
| Browser tracing (100%) | 35.2 | 99.9 | +6.6 | 0.0 | 2.9 | 22,792 | 0 | 181 |
| Session replay (consented, sampled at 100%: recorder loaded and recording) | 35.1 | 142.1 | +48.8 | 0.0 | 5.8 | 45,792 | 2 | 177 |
| Everything on and consented (replay at 100%) | 38.5 | 150.6 | +57.3 | 0.0 | 6.0 | 45,792 | 4 | 178 |

## What the numbers say

### On the server

- **The plugin loaded and idle costs about 1 ms and 0.1 MiB per page request, with no extra database query.** That is the price of having the classes available (the loader is lazy) and the hooks registered.
- **Server logs add about another 0.6 ms** (the error handlers and collectors). The browser, analytics, replay, tracing (at 10%) and WooCommerce modules add nothing you can distinguish from that on a page view: their server part on a page view is a settings read and, for the browser modules, one `<script>` tag and a small JSON block in the HTML. The differences between those rows (0.3 ms) are smaller than the noise.
- **Tracing every request (100% sampling) is the one server setting with a visible cost: about 1.4 ms and 4 queries more than plain logging** (one request span written to the queue at shutdown). At 10% it is indistinguishable from logging. Server spans are bounded (a per-request span cap, a per-minute budget for inbound "sample this" requests), so this does not grow with traffic patterns you do not control.
- **A request that logs something pays about 1.3 ms and 3 queries more** for storing the event (at shutdown, batched with anything else the request logged). Nothing is sent to ZipLogger during the request.
- **All modules on (tracing at 10%): about 1.6 ms more than the site without the plugin, on a page that takes about 102 ms, with the same number of queries and 0.16 MiB more memory.**
- The page-to-page spread of this site (the p95 is about 140 ms against a 102 ms median) is many times larger than every difference above. Treat anything under about 1 ms as zero.

### In the browser

- **The script that carries browser monitoring, analytics and tracing is 22.8 KB over the wire (gzip; 65.7 KB decoded) and costs about 6 to 9 ms of script execution** on this machine and page. The main-thread task time rises by roughly 7 to 13 ms, which is close to this benchmark's noise floor (the baseline itself ranges over 78 to 107 ms). The load event is about 10 to 14 ms later (deferred scripts run before DOMContentLoaded). One request to ZipLogger is made per page view for the events that were collected.
- **The plugin with every browser module off adds nothing measurable** (no script, no config block in the HTML).
- **Session replay is the expensive module, by design.** For a session that is being recorded (consented, in the sample, allowed by role and page): a second lazy script of 23 KB is fetched (45.8 KB in total), **the main thread does about 49 ms more work in the first seconds** (taking and encoding the page snapshot and observing changes), and the JavaScript heap grows by about 3 MB. Two requests to ZipLogger were made in the measured window. By design the recorder is fetched only when a session is going to be recorded, so sessions that are not sampled, not consented, or excluded load none of it (this benchmark did not measure that split).
- **Everything on and consented: about 57 ms more main-thread work, 3.3 MB more heap, 46 KB transferred**, dominated by replay.
- The scripts are loaded with `defer`, so they do not block HTML parsing. In no configuration did the median load contain a "long task" (over 50 ms) in the settling window: the replay work is spread over many short tasks.

### What was not measured

- Real order flows: an order event costs one queue insert at shutdown of the request that creates or completes the order (about the same as a logged warning above), but that was not timed on a live checkout.
- The background delivery job (one WP-Cron request per batch), the WordPress admin screens and the dashboard panels.
- Pages other than the front page; a page served from a full-page cache (which does not run PHP, so the server modules do not run either).
- Slower devices and networks. A phone with a fraction of this machine's CPU pays proportionally more script time.

## Reproduce

```bash
docker compose -f docker-compose.e2e.yml up -d
pwsh bin/refresh-e2e.ps1                      # the ZIP that is measured
node tests/perf/server.mjs                    # about 6 minutes
node tests/perf/browser.mjs --rounds 30       # about 25 minutes
node tests/perf/report.mjs                    # the tables above
```

Run nothing else on the machine while it runs, and treat differences smaller than the spread of the baseline as zero.
