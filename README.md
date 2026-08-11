# moserra/query-watchdog

English | [Türkçe](README.tr.md)

Runtime query gate for [Nette](https://nette.org) applications. Catches the query problems static analysis can't see — while the page is actually running:

- **Duplicate SELECT / N+1 detector** — every query is fingerprinted with literals normalized to `?`, so `WHERE id = 5` and `WHERE id = 8` count as the *same shape*. When the same shape runs N times in one request (default 5), the request **throws in debug mode** with the shape, an example query, and the fix hint (batch with IN-list/JOIN, or memoize). In production it writes a structured Tracy log instead.
- **Exact-duplicate detector** — literals are **kept** (only whitespace is normalized), so the *identical* SQL — same shape *and* same values — running twice returns the same rows: a pure memoization miss. Low threshold (default 2), high precision: `WHERE id = 1` and `WHERE id = 2` never collide. Catches the sub-threshold repeats the shape rule (limit 5) misses — e.g. two code paths fetching the same lookup for the same ids.
- **Per-request query budget** — more than N queries in one request (default 80) throws/logs with the top repeated shapes listed.
- **Slow query log** — queries above a threshold (default 200 ms) are logged in both modes. Never throws: durations are nondeterministic (cold caches), so a slow query must not randomly kill a page.

Transaction control statements (`BEGIN`, `COMMIT`, `SET`, `EXPLAIN`, …) are ignored. The duplicate rule applies to SELECTs only.

### Where the exact-duplicate rule stands down

It assumes *same SQL → same rows*. Two cases break that assumption, and both are handled:

- **A write happened in between.** "Read → write → read again" is a legitimate sequence — the second read really does return different rows. Any non-SELECT statement (transaction bookkeeping aside) opens a new generation, and repeat counting starts over. A CTE-leading read (`WITH … SELECT`) is counted as a write here: that only relaxes the rule, it cannot produce a false report. The **shape** rule deliberately ignores generations — "update the row, read the row" inside a loop is still N+1.
- **The statement isn't deterministic.** Locking reads (`FOR UPDATE`/`FOR SHARE`, `SKIP LOCKED`, advisory locks), sequence reads (`nextval`) and `random()` are exempt: the point is the lock or the side effect, and `SKIP LOCKED` returns *different* rows on every call by design. `now()` is **not** exempt — in PostgreSQL it is the transaction start time and is constant within the transaction.

## Why

Static gates (PHPStan rules, copy-paste detectors) catch query-in-a-loop in the same function. They cannot see a loop hidden behind a service call, or five components each fetching the same settings row. Your Tracy panel shows it — but nobody fails a build on a panel. This package turns "notice it in Tracy" into "the page throws with the exact query and count".

## Supported database layers

Both bridges attach automatically to every connection service found in the DI container (subclasses included):

- **nette/database** — via the `Connection::$onQuery` event
- **nextras/dbal** — via `Connection::addLogger()`

## Installation

```bash
composer config repositories.moserra-query-watchdog vcs https://github.com/iatila/query-watchdog
composer require moserra/query-watchdog
```

Register the extension in your NEON config:

```neon
extensions:
	queryWatchdog: Moserra\QueryWatchdog\DI\QueryWatchdogExtension
```

That's it. Optional configuration (defaults shown):

```neon
queryWatchdog:
	budgetPerRequest: 80
	duplicateSelectLimit: 5      # same shape (literals → ?) N× → N+1
	exactDuplicateLimit: 2       # identical SQL (literals kept) N× → memoization miss
	slowQueryMs: 200
	# strict: true    # omit to follow %debugMode% (dev = throw, prod = log)
```

## What a violation looks like

In debug mode the request dies with `Moserra\QueryWatchdog\QueryWatchdogException`:

```
Duplicate SELECT: same query shape ran 5× in one request — batch it (IN-list/JOIN) or memoize the result.
Shape: SELECT * FROM orders WHERE customer_id = ?
Example: SELECT * FROM orders WHERE customer_id = 42
```

In production the same information goes to the Tracy log as a structured entry
(`query_watchdog.violation` / `query_watchdog.slow_query`) — no exception, no user impact.

## Tuning

If a page legitimately needs more, raise the limits in config — but treat that as the last resort. The violation message tells you which query to batch or memoize; fixing it is almost always cheaper than raising the ceiling.

## License

MIT
