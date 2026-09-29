# Plan: Safeguards on the Autovit detail-page fetch

## Goal
The damage/fuel-consumption filter (merged earlier today) added one extra HTTP request per
listing that survives the price filter — roughly 32x more Autovit requests per run than before.
Add four safeguards to keep that polite and bounded, as requested: a random 3–8s delay between
detail requests, a daily cap on how many get fetched, skipping ones already checked before, and
stopping the whole run immediately on a 403/429.

## What I will do
1. **Migration** — add nullable `listings.detail_checked_at` (timestamp). Marks "we successfully
   fetched this listing's own page and recorded whatever damage/consumption data it had" —
   distinct from `is_damaged`/`fuel_consumption_l_100km` being `null`, which is itself a valid,
   already-observed outcome (not every ad publishes those fields) and can't be used as the "already
   checked" signal on its own.
2. **`config/scraping.php`** (`autovit` array) — three new `env()`-backed keys:
   `detail_fetch_delay_min_ms` (3000), `detail_fetch_delay_max_ms` (8000),
   `detail_fetch_daily_cap` (100).
3. **`ScrapeAutovit::handle()`** — restructure the detail-fetch step, per listing that survives the
   price filter:
   - Look up an existing `Listing` row for this `source`+`external_id`. If it exists and
     `detail_checked_at` is already set, reuse its stored `is_damaged`/`fuel_consumption_l_100km`
     instead of fetching again — a car's own page doesn't change between runs, so there's nothing
     to gain from re-fetching it.
   - Otherwise, check a daily counter (`Cache`, key `autovit:detail-fetch-count:{date}`, TTL to
     end of day — same "state must survive across separate daily cron processes" reasoning
     Chapter 9 used for the circuit breaker). If today's cap is already reached, skip the fetch for
     this listing too (leaves `is_damaged`/`fuel_consumption_l_100km` null, `detail_checked_at`
     unset so a future day's run will still try it).
   - Otherwise, fetch it: on success, store the two fields, set `detail_checked_at = now()`,
     increment the daily counter, then sleep `random_int(min_ms, max_ms)` milliseconds (replacing
     the old fixed `request_delay_ms` sleep for this specific step only — page-to-page pagination
     delay is unchanged).
   - **On HTTP 403 or 429** (caught via `Illuminate\Http\Client\RequestException`, no change
     needed to `AutovitDetailFetcher` itself since `$response->throw()` already carries the status):
     log a warning naming the URL and status, then `break 2` out of both loops — stop the run
     immediately rather than continuing to the next listing or page. The triggering listing isn't
     saved this run; it'll be picked up again once unblocked. Any other HTTP failure (5xx, timeout,
     a genuinely changed page structure) is **not** caught here and keeps failing the command
     loudly, same as today — 403/429 specifically means "we're being blocked," everything else
     means "something is actually broken and should be investigated," and conflating the two would
     hide real bugs.
4. **Tests** (`tests/Feature/ScrapeAutovitCommandTest.php`): daily cap reached mid-run stops
   further detail fetches (asserted via `Http::assertSentCount`) without failing the command;
   an already-checked listing's second run doesn't re-fetch its detail page and keeps its stored
   values; a 403 on one listing's detail fetch stops the run before any later listing in the same
   results page is processed; a non-403/429 failure (e.g. 500) still fails the command instead of
   being silently caught, to lock in that distinction.
5. **`.env.example`** — the three new keys, commented with defaults, matching the existing
   pattern.
6. **`CHANGELOG.md`** entry.
7. Branch `fix/autovit-detail-fetch-safeguards`, test, merge `--no-ff`, push.

## Files
- `database/migrations/..._add_detail_checked_at_to_listings_table.php` — new
- `config/scraping.php` — changed
- `app/Console/Commands/ScrapeAutovit.php` — changed
- `tests/Feature/ScrapeAutovitCommandTest.php` — changed
- `.env.example` — changed
- `CHANGELOG.md` — changed

## Things to know / risks
- The daily cap is tracked in the `database` cache store (same mechanism Chapter 9 designed for
  exactly this: state that must survive across separate daily cron processes, not just within one
  run).
- A 100/day default cap is a guess at "generous but bounded" — actual daily new-listing counts
  have been in the single-to-low-double digits in testing so far, so this shouldn't bind in normal
  operation; it exists as a hard ceiling for an unusual day, not a routine limiter.
- Not touching `AutovitClient`'s own search-page fetching or the resilience services from Chapter 9
  (`ScraperBackoffPolicy`/`ScraperCircuitBreaker`) — this is a narrower, directly-requested fix
  scoped to the new detail-fetch step specifically, not a general resilience wiring pass.

## How we'll verify it works
- `php artisan test` — new tests plus full existing suite.
- Manual: run `scrape:autovit` for real and confirm the log/summary reflects skipped-by-cap /
  reused-from-cache counts sensibly.

## Needs from you
Nothing blocking — the four safeguards and their shape are exactly what you specified.
