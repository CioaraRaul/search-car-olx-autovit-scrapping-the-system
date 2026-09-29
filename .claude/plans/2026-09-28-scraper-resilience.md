# Plan: Resilience — backoff, circuit breaker, kill switches (Roadmap Chapter 9)

## Goal
Build a small set of shared, source-agnostic safety mechanisms that any scraper (Autovit, OLX)
will plug into: a way to turn a scraper off from `.env` without deploying code, a policy for
retrying failed HTTP requests without hammering a site, a "stop calling a site that's clearly
blocking us" breaker, and a check that notices when a scrape "succeeded" (HTTP 200) but came back
suspiciously empty — usually a sign the site's HTML/JSON structure changed, not that there's
nothing to find.

This chapter is cross-cutting infrastructure. **Update (2026-09-29):** Chapters 1/2 (Autovit/OLX
scrapers) are now merged into `development`, so this claim in the original plan draft is stale —
but the roadmap's own chapter description still scopes this chapter as "buildable as a small
shared service now, adopted by Chapters 1/2 whenever they run," and Chapter 4 (ingestion run
logging, already merged) set the precedent for exactly this shape: build + test the service
standalone, leave wiring it into `ScrapeAutovit`/`ScrapeOlx` as a small separate follow-up rather
than bundling it into this chapter. Following that same precedent here, so this chapter's
deliverable stays the services + config themselves, fully tested standalone — not editing
`ScrapeAutovit`/`ScrapeOlx` today.

## Terms used below
- **Kill switch**: a flag you can flip in `.env` (no code change, no redeploy) to stop a specific
  scraper from running at all — for when a site changes its terms, blocks us, or you just want to
  pause it.
- **Exponential backoff**: instead of retrying a failed request instantly (or at a fixed interval),
  wait longer after each consecutive failure (e.g. 1s, 2s, 4s, 8s...) so a struggling site gets
  breathing room instead of a hammering.
- **Jitter**: a small random amount added to a backoff delay so, if this ever ran on a schedule
  that could overlap another process, retries don't all land on the same instant.
- **Circuit breaker**: a pattern borrowed from electrical circuit breakers — after too many
  failures in a row, "trip" (stop sending requests entirely) for a cooldown period, instead of
  continuing to retry a site that's clearly not going to respond. Prevents wasting a whole run's
  page budget hammering a site that returned 403 on page 1.

## What I found (research)
- **Laravel's `Http` client already has retry support built in** (confirmed via Context7 against
  the 13.x docs): `Http::retry($times, $sleepMsOrClosure, $when = null, $throw = true)`. By
  default it retries on connection exceptions and on responses Laravel considers failed (4xx/5xx),
  throwing `Illuminate\Http\Client\RequestException` if every attempt fails — unless you pass
  `throw: false`, in which case the last response is returned instead of an exception (a
  `ConnectionException` is still thrown even with `throw: false`, since there's no response object
  to return in that case). The sleep-per-attempt argument can be a closure `fn (int $attempt) =>
  $milliseconds`, which is how the exponential-backoff math plugs in.
- **The global `retry()` helper** (not HTTP-specific) supports the same shape — useful if a future
  piece of code needs retry-with-backoff around something that isn't an HTTP call.
- **Cache-based state needs to survive between runs, not just within one.** The scheduler (Chapter
  8) runs each scraper once a day as a fresh process — there's no long-lived process to hold
  circuit-breaker state in memory. The app's cache store is `database` (`config/cache.php`,
  `.env`'s `CACHE_STORE=database`), which persists in SQLite, so `Cache::get/put` survives across
  separate `php artisan scrape:...` invocations. That's the right tool here — if Autovit 403's
  today, the breaker should still remember tomorrow morning, not reset because it's a new process.
- **Skipping `Cache::increment()` for the failure counter.** Laravel's docs note `increment()`
  atomicity isn't guaranteed on every driver (only Redis/Memcached/DynamoDB guarantee it). The
  `database` driver doesn't. Since this only ever runs from one process at a time (single daily
  cron, `withoutOverlapping()` per Chapter 8), atomicity isn't actually needed — a plain
  `get()` + `put()` read-modify-write is simpler and portable, so that's what I'll use instead of
  reaching for a lock.
- **`ListingSource` enum already exists** (`app/Enums/ListingSource.php`: `Autovit`/`Olx`). Every
  service below takes a `ListingSource` rather than a raw string, per `best-practices.md`'s "enums,
  not string constants" rule, and uses `->value` internally where a plain string is needed (e.g.
  as part of a cache key or a `config()` path).

## What I will do
1. **`config/scraping.php`** — add, without touching existing Autovit keys:
   - `'olx' => ['enabled' => env('SCRAPE_OLX_ENABLED', true)]` — a minimal stub; Chapter 2 will
     extend this array with OLX's other settings (base URL, etc.) when it's built. Also add
     `'enabled' => env('SCRAPE_AUTOVIT_ENABLED', true)` inside the existing `'autovit'` array.
   - A new `'resilience'` array: `max_consecutive_failures` (default 3), `cooldown_minutes`
     (default 60), and a `backoff` sub-array: `base_ms` (1000), `max_ms` (30000), `max_attempts`
     (4). All `env()`-backed, per `best-practices.md`'s "config, not hardcoded values."
2. **`app/Services/Scraping/ScraperKillSwitch.php`** — one method,
   `isEnabled(ListingSource $source): bool`, reads `config("scraping.{$source->value}.enabled")`
   (defaults to `true` if somehow missing, so a config typo fails open to "runs as normal" rather
   than silently never scraping). A scraper command calls this first thing in `handle()` and exits
   early (logging why) if it's `false`.
3. **`app/Services/Scraping/ScraperBackoffPolicy.php`** — encapsulates the retry math so it's
   testable without making real HTTP calls:
   - `ScraperBackoffPolicy::forSource(ListingSource $source): self` — builds one from
     `config('scraping.resilience.backoff')`.
   - `maxAttempts(): int`.
   - `sleepMilliseconds(int $attempt): int` — exponential: `base_ms * 2^(attempt-1)`, capped at
     `max_ms`, plus up to 20% random jitter on top.
   - `shouldRetry(int $status): bool` — true for 429 (rate-limited), 403 (blocked — worth a couple
     of retries in case it's transient, before the circuit breaker gives up entirely), and 5xx
     (server-side trouble). False for everything else (e.g. 404 retrying won't help).
   - A scraper's HTTP call will look like:
     `Http::retry($policy->maxAttempts(), fn (int $a) => $policy->sleepMilliseconds($a))->get($url)`
     — the exact wiring happens in Chapter 1/2, not here.
4. **`app/Services/Scraping/ScraperCircuitBreaker.php`** — constructor-injected
   `Illuminate\Contracts\Cache\Repository`, two cache keys per source
   (`scraper:{source}:failures`, `scraper:{source}:open-until`):
   - `isOpen(ListingSource $source): bool` — true if the "open-until" key is set and in the
     future.
   - `recordFailure(ListingSource $source): void` — reads the failure count, `+1`s it, writes it
     back with a short TTL (so an old failure streak doesn't linger forever once things recover);
     if the new count reaches `resilience.max_consecutive_failures`, sets the "open" key with a
     TTL of `resilience.cooldown_minutes`.
   - `recordSuccess(ListingSource $source): void` — clears both keys (a clean response resets the
     streak).
   - A scraper command checks `isOpen()` before paging further and stops early (logging why) if
     it's tripped, instead of burning the rest of its page budget against a site that's clearly
     blocking it.
5. **`app/Services/Scraping/ZeroResultsAlert.php`** — one method,
   `check(ListingSource $source, int $pagesFetchedOk, int $listingsParsed): void`. If
   `$pagesFetchedOk > 0` (we got at least one real 200 response) and `$listingsParsed === 0`, logs
   a `critical`-level message via the `Log` facade naming the source and page count. Deliberately
   just logging for now, not emailing — Chapter 7 (Gmail digest) doesn't exist yet, and this
   chapter shouldn't invent notification infrastructure to do that job. `critical` is chosen
   specifically so it's easy to `grep`/alert on later, and so it stands out from routine `info`
   scrape-summary logging.
6. **Tests (Pest)** — all four classes are pure logic / cache reads, no real HTTP or scraper needed
   to test them, in line with `best-practices.md`'s "no live-network tests":
   - `ScraperKillSwitchTest` — enabled by default when config key is absent; respects `false`.
   - `ScraperBackoffPolicyTest` — delay grows per attempt and is capped at `max_ms`; `shouldRetry`
     true for 429/403/500-range, false for 200/404.
   - `ScraperCircuitBreakerTest` — stays closed under the failure threshold; opens exactly at the
     threshold; `isOpen` reports true while the cooldown hasn't elapsed (using `Carbon::setTestNow`
     / `travel()` to move time forward) and false after; `recordSuccess` clears an open breaker.
   - `ZeroResultsAlertTest` — asserts (via `Log::spy()`) a `critical` log is written when pages > 0
     and listings = 0; asserts nothing is logged when listings > 0, and nothing is logged when
     pages = 0 (e.g. the kill switch or circuit breaker already stopped the run — that's not a
     "site structure changed" situation and shouldn't be reported as one).
7. **`.env.example`** — add the new keys with sensible defaults (all optional; the app works if
   they're absent, per the config defaults above): `SCRAPE_AUTOVIT_ENABLED`, `SCRAPE_OLX_ENABLED`,
   `SCRAPER_CIRCUIT_BREAKER_THRESHOLD`, `SCRAPER_CIRCUIT_BREAKER_COOLDOWN_MINUTES`,
   `SCRAPER_BACKOFF_BASE_MS`, `SCRAPER_BACKOFF_MAX_MS`, `SCRAPER_BACKOFF_MAX_ATTEMPTS`.
8. **Branch + changelog** per `CLAUDE.md` rules 9–10: `feature/scraper-resilience` off
   `development`, commit, `CHANGELOG.md` entry, `git merge --no-ff` into `development`, delete the
   branch, push `development` to origin.
9. **Delete this chapter's entry from `ROADMAP.md`** once merged, per the roadmap file's own rule
   ("when a chapter is finished and merged, its entry gets deleted — the record lives in
   `CHANGELOG.md`").

## Files
- `config/scraping.php` — changed — kill-switch flags + `resilience` config block
- `app/Services/Scraping/ScraperKillSwitch.php` — new
- `app/Services/Scraping/ScraperBackoffPolicy.php` — new
- `app/Services/Scraping/ScraperCircuitBreaker.php` — new
- `app/Services/Scraping/ZeroResultsAlert.php` — new
- `tests/Unit/ScraperKillSwitchTest.php` — new
- `tests/Unit/ScraperBackoffPolicyTest.php` — new
- `tests/Unit/ScraperCircuitBreakerTest.php` — new
- `tests/Unit/ZeroResultsAlertTest.php` — new
- `.env.example` — changed
- `CHANGELOG.md` — changed
- `ROADMAP.md` — changed (Chapter 9 entry removed once merged)

## Best practices applied
- Laravel 13.x `Http::retry()` semantics confirmed via Context7 (`/laravel/docs`) today rather than
  assumed from memory — including the `throw: false` / `ConnectionException` edge case.
- Config over hardcoding (`best-practices.md`): every threshold is `env()`-backed with a default.
- Enums, not raw strings, for the fixed `autovit`/`olx` source set (`ListingSource`).
- Small, single-responsibility classes: kill switch, backoff math, circuit-breaker state, and the
  zero-results check are four separate, independently testable classes rather than one "resilience
  manager" god class.
- Typed properties/params/returns throughout; constructor-promoted, `readonly` where the value
  doesn't change after construction.
- No live-network tests; these classes don't need HTTP at all to be tested, which keeps the suite
  fast and deterministic.

## Things to know / risks
- **Nothing calls these yet.** Even though `ScrapeAutovit`/`ScrapeOlx` already exist and are merged,
  this chapter deliberately doesn't edit them — same standalone-first shape as Chapter 4's
  `IngestionRunTracker`. A follow-up edit to those two commands is what actually calls
  `ScraperKillSwitch::isEnabled()`, wraps their `Http` calls with `ScraperBackoffPolicy`, checks
  `ScraperCircuitBreaker::isOpen()` between pages, and calls `ZeroResultsAlert::check()` at the end
  of a run. Since nothing calls these yet, nothing will fail loudly if that follow-up is skipped —
  worth flagging explicitly so it doesn't get forgotten the way a silent gap could.
- **Circuit-breaker state isn't atomic** (explained above) — acceptable because this app only ever
  runs one scraper process at a time by design (`withoutOverlapping()`, once-daily schedule), not
  because concurrency doesn't matter in general.
- **403 is treated as retryable** (up to the configured attempts) before the circuit breaker gives
  up — a deliberate choice, since a single 403 can be transient (e.g. a momentary WAF hiccup)
  rather than a full block; the circuit breaker is the actual "stop entirely" mechanism once
  failures repeat, not the first 403.

## How we'll verify it works
- `php artisan test` — all four new unit test files pass, plus the full existing suite still
  passes (no regressions to `criteria:set`/`criteria:help` or the schema tests).
- Manual sanity check: `php artisan tinker` — flip `SCRAPE_AUTOVIT_ENABLED=false` in `.env`, confirm
  `app(App\Services\Scraping\ScraperKillSwitch::class)->isEnabled(App\Enums\ListingSource::Autovit)`
  returns `false`; flip it back and confirm `true`.

## Needs from you
Nothing blocking — every decision (config shape, class boundaries, default thresholds, where the
zero-results alert goes for now) is made above per `ROADMAP.md`'s own instruction that chapters
shouldn't need a question answered before starting.
