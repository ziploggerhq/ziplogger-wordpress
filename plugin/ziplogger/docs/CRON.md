# Background delivery, WP-Cron and system cron

## How delivery works

Nothing is sent from the request that produced an event. Events (logs, order and payment events, trace spans) are written to a small table in your database at the end of the request, and a background job sends them in batches:

1. The job runs from **WP-Cron** (hook `ziplogger_deliver`), at most about 20 seconds and 5 batches per run.
2. Each batch is claimed with a lease, so two overlapping runs never send the same batch.
3. A failed batch is retried with exponential backoff and random jitter (30 seconds up to 30 minutes), honouring `Retry-After`. After ten failed attempts, or after 48 hours, it is dropped and counted.
4. A batch keeps its identity across retries: the payload is byte-identical and carries the same idempotency key, so a retry after a timeout is recognised by ZipLogger. Delivery is **at-least-once**, not exactly-once.

An hourly watchdog (`ziplogger_watchdog`) removes expired items and re-schedules delivery if something is waiting and no run is planned.

## The WP-Cron caveat

WP-Cron does not run on a clock. WordPress checks for due jobs **when a page is requested**. On a busy site that is effectively immediate. On a quiet site, a job due at 10:00 may run at 10:47, when someone visits. Two things follow:

- Events can wait longer than expected on a quiet site. They are not lost; they are delivered at the next run.
- Sites that set `DISABLE_WP_CRON` (many hosts and caching setups do) run **no** WP-Cron jobs at all unless a system cron calls them. Without one, nothing is delivered.

The plugin tells you when this is happening. The **Overview** tab shows the worker's last run and marks delivery **overdue** when items have waited more than ten minutes past their due time, and says whether `DISABLE_WP_CRON` is set.

## Recommended: a system cron

On any site that matters, let the operating system trigger WordPress cron every minute:

```cron
* * * * *  cd /var/www/html && php wp-cron.php >/dev/null 2>&1
```

or, with WP-CLI:

```cron
* * * * *  cd /var/www/html && wp cron event run --due-now --quiet
```

or, to trigger only ZipLogger's delivery directly:

```cron
* * * * *  cd /var/www/html && wp ziplogger flush --quiet
```

If you use one of the first two, add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` so visitors' requests stop also triggering the job.

## Flushing by hand

- **Settings, ZipLogger, Overview: "Deliver queued items now"** runs one bounded pass immediately, ignoring backoff.
- `wp ziplogger flush` does the same from the command line (it needs collection switched on) and prints how many events were delivered, how many are still pending and how many were dropped as undeliverable. `--max-batches` and `--max-seconds` bound a run.
- `wp ziplogger status` shows, per signal (logs, events, traces): items waiting, the last success, the last sanitized error, failure streak, and whether the worker is overdue.

## Reading the status

| What you see | What it means |
| --- | --- |
| "Worker last ran … ago" is recent | Cron is running |
| **Overdue** | Items are waiting and no run has happened for more than ten minutes past due: WP-Cron is not running (see above), or the site is very quiet |
| "Paused until …" with an authentication message | ZipLogger rejected the key (401/403). Nothing is retried until you fix the key: the plugin will not burn retries on a wrong key |
| "Paused" with a limit message | The plan's limit was reached (429): waiting is the only answer |
| Items **held** | The key or endpoint changed after they were collected; choose what to do with them on the Overview tab |

## What is *not* affected by cron

Browser data (errors, analytics, replay) and browser trace spans are sent **straight from the visitor's browser** and never wait for WP-Cron. Only what the server collects (PHP errors and events, order/payment events, server spans) goes through the queue.
