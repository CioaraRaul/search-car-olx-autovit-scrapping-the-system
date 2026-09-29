# Changelog

Every implemented change gets an entry here, in plain language — what changed and why. This is
separate from git commit messages: commits describe a diff, this describes the project's
progress in a way that's readable without digging through `git log`.

## 2026-09-29

### Added
- **Chapter 9 complete: resilience — backoff, circuit breaker, kill switches.** Four small,
  standalone services under `app/Services/Scraping/`, ready for `ScrapeAutovit`/`ScrapeOlx` to
  adopt as a follow-up (same "build standalone, wire in later" shape as Chapter 4's
  `IngestionRunTracker`):
  - `ScraperKillSwitch` — reads a per-source `enabled` flag from `config/scraping.php`
    (`SCRAPE_AUTOVIT_ENABLED`/`SCRAPE_OLX_ENABLED` in `.env`), defaulting to `true` so a missing or
    typo'd config key fails open (keeps scraping) rather than silently going dark.
  - `ScraperBackoffPolicy` — exponential-backoff math for retrying a failed HTTP request (doubling
    delay per attempt, capped, plus up to 20% jitter so retries don't all land on the same
    instant), and a `shouldRetry()` check that says yes for 429/403/5xx and no for anything else
    (e.g. 404, which retrying can't fix). Designed to plug straight into Laravel's
    `Http::retry($policy->maxAttempts(), fn ($a) => $policy->sleepMilliseconds($a))`.
  - `ScraperCircuitBreaker` — trips after `SCRAPER_CIRCUIT_BREAKER_THRESHOLD` (default 3)
    consecutive failures and stays tripped for `SCRAPER_CIRCUIT_BREAKER_COOLDOWN_MINUTES` (default
    60), so a run stops burning its page budget against a site that's clearly blocking it. State
    lives in the app's cache (`CACHE_STORE=database`, i.e. SQLite), not in memory, because each
    scraper run is a fresh daily-cron process (Chapter 8) with no long-lived process to hold state
    in.
  - `ZeroResultsAlert` — logs a `critical`-level message when a scrape fetched at least one page
    successfully (HTTP 200) but parsed zero listings, which usually means a site's HTML/JSON
    structure changed rather than there being genuinely nothing to find. Just logs for now — no
    email, since Chapter 7's digest already has its own trigger (unnotified listings) and this
    chapter shouldn't invent a second notification path.
  - New `config/scraping.php` keys: `autovit.enabled`, `olx.enabled`, and a `resilience` block
    (`max_consecutive_failures`, `cooldown_minutes`, `backoff.{base_ms,max_ms,max_attempts}`), all
    `env()`-backed with defaults so the app behaves the same as before if none of the new `.env`
    keys are set.
  - Nothing calls these four services yet — wiring them into `ScrapeAutovit`/`ScrapeOlx` is left as
    a small separate follow-up, not bundled into this chapter.

## 2026-09-28

### Added
- **Chapter 8 complete: scheduler wiring.** `routes/console.php` now schedules all three commands
  via Laravel's Task Scheduler: `scrape:autovit` at 07:00 and `scrape:olx` at 07:10 (morning, so
  overnight-posted listings have accumulated by the time they run), and `notify:send` separately
  at 22:00 (night, so the day's matches land in the inbox in the evening instead of first thing in
  the morning). A new `schedule_timezone` config key (`config/app.php`, `SCHEDULE_TIMEZONE` in
  `.env`, defaulting to `Europe/Bucharest`) makes those times mean local Romania time rather than
  UTC — without it `dailyAt('07:00')` would fire at 09:00/10:00 local depending on daylight
  saving. Each command uses `withoutOverlapping(120)` (a 120-minute lock, not Laravel's 24-hour
  default) so a crashed run's stuck lock self-heals before the next day's trigger instead of
  silently blocking it. `tests/Feature/ScheduleTest.php` pins down each event's cron expression,
  timezone, and overlap-lock settings directly against Laravel's `Schedule` object, so a future
  edit to `routes/console.php` can't silently drop or mistime one of the three entries.
  - Laravel's scheduler needs an OS-level "heartbeat" to actually run anything — on Windows
    (no cron) that's a Windows Task Scheduler task running `php artisan schedule:run` every
    minute, with "Run task as soon as possible after a scheduled start is missed" checked (this
    checkbox *is* the catch-up-if-the-PC-was-off behavior `CLAUDE.md` asks for — a native Windows
    feature, not something built in PHP). GUI and `schtasks` setup steps are documented in
    `.claude/plans/2026-09-28-scheduler-wiring.md` for reproducibility; creating the actual
    Windows task is a manual one-time step, not automated by this chapter.

- **Chapter 7 complete: Gmail digest notification.** A new `notify:send` Artisan command emails a
  digest of every listing with `notified_at IS NULL`, then marks them notified — so nothing is ever
  emailed twice, and a failed send leaves listings unnotified so the next run retries them
  naturally. `App\Services\Notifications\ListingsDigestNotifier` holds the logic (fetch → send →
  mark, in that order, only marking notified *after* the send succeeds); `App\Mail\ListingsDigest`
  is a Markdown Mailable (not a `Notification` class — there's no `User`/`Notifiable` model in this
  app, just one fixed recipient) rendered by `resources/views/mail/listings/digest.blade.php`,
  which shows each listing's price (with an EUR-equivalent line when `price_eur` is set), year,
  mileage, city, source, reliability score, and any reliability flags, plus a link to the original
  ad. The recipient address is config-driven (`config/notifications.php`, `NOTIFY_RECIPIENT_EMAIL`
  in `.env`), not hardcoded. If nothing is unnotified, the command sends no email and exits
  cleanly — a quiet day produces zero mail, not an empty digest.
  - Command fails loudly (non-zero exit, no email attempted) if `NOTIFY_RECIPIENT_EMAIL` isn't
    configured, matching the "fail with a clear message" pattern used elsewhere in the project.
  - No queueing: sent synchronously, which is fine for a low-volume once-a-day digest.

- **Chapter 4 complete: ingestion run logging.** A new `ingestion_runs` table (source, started_at,
  finished_at, pages_fetched, listings_new, listings_updated, errors, status) gives every scraper
  execution an auditable record instead of a black box, once wired in. `App\Services\Ingestion\
  IngestionRunTracker` is the small service any scraper command can use: `start()` opens a run,
  `incrementPages()`/`incrementNew()`/`incrementUpdated()`/`recordError()` update it as work
  happens, `finish()` closes it, and `run(source, callback)` is a convenience wrapper that marks a
  run `Success` or `Failed` (recording the exception message) automatically around a callback,
  re-throwing so the caller still sees the failure. Chose a service over a trait so the run-
  tracking state doesn't silently mix into every command class using it, and stays testable in
  isolation. Status is a new backed enum, `IngestionRunStatus`
  (`Running`/`Success`/`Failed`/`Partial`), matching `ListingSource`'s existing pattern.
  - Deliberately standalone, per its own `ROADMAP.md` boundary: nothing calls it yet.
    `ScrapeAutovit`/`ScrapeOlx` (Chapters 1/2, already merged) can adopt it as a follow-up whenever
    that's wanted — this chapter only had to prove the tracker works correctly in isolation, which
    `tests/Feature/IngestionRunTrackerTest.php` does directly against the service rather than a
    real scrape.

- **Chapter 3 complete: price history + `price_eur` normalized comparison currency.** Every time a
  listing's price is observed, `App\Services\Listings\PriceHistoryRecorder` records it in a new
  `listing_price_changes` table if it's the first observation or the price/currency actually
  changed (so "price dropped" history is buildable later), and refreshes a new `price_eur` column
  on `listings` so cars priced in RON and EUR can be filtered/sorted on one consistent scale. The
  conversion uses the National Bank of Romania's (BNR) official daily reference rate, fetched and
  cached once every 24h (`App\Services\ExchangeRates\BnrExchangeRateService`), not per listing.
  - **Correction to `ROADMAP.md`:** the URL it named, `https://www.bnr.ro/nbrfxrates.xml`, is dead
    — BNR moved this feed during a site redesign and that URL now returns their HTML homepage.
    Verified live and replaced with the working feed: `https://curs.bnr.ro/nbrfxrates.xml`
    (subdomain `curs.bnr.ro`), configurable via `.env`'s `BNR_RATES_URL` so a future move is a
    one-line config change, not a code change.
  - A single BNR outage doesn't stop a scrape run: `PriceHistoryRecorder` still records the price
    history row, logs a warning, and leaves `price_eur` stale/null until the next successful fetch
    — proper backoff/circuit-breaker handling is Chapter 9's job, not this one's.
  - Deliberately standalone: this doesn't touch `listings.price`/`listings.currency` (still each
    scraper's own job) and isn't wired into `ScrapeAutovit`/`ScrapeOlx` yet — either can call
    `PriceHistoryRecorder::recordIfChanged()` as a follow-up, per `ROADMAP.md`'s own chapter
    boundary ("Either scraper can call a small hook once this exists").
  - New tests: `tests/Feature/BnrExchangeRateServiceTest.php` (EUR passthrough, RON/USD/multiplier
    currency conversion math, cached-fetch-at-most-once) and
    `tests/Feature/PriceHistoryRecorderTest.php` (first observation, no duplicate row on an
    unchanged price, new row + refreshed `price_eur` on a changed price, outage handling).

- **Chapter 2 complete: `scrape:olx`.** OLX's counterpart to `scrape:autovit` — fetches, filters,
  and hard-gates OLX listings the same way, but the implementation had to differ in a few
  genuinely new ways discovered by inspecting the live site today (not assumed from the two-day-old
  research notes in `ROADMAP.md`):
  - **OLX has no JSON data blob** (unlike Autovit's `__NEXT_DATA__`) — it's plain server-rendered
    HTML. `app/Services/Scraping/OlxClient.php` parses it with `symfony/dom-crawler` +
    `symfony/css-selector` (newly added via Composer), using stable `data-testid` attribute
    selectors rather than the emotion-generated CSS class names, which are build artifacts that can
    change on any deploy.
  - **OLX silently clamps out-of-range pagination** instead of erroring or emptying — requesting a
    page past the real last one just re-serves an earlier page. `ScrapeOlx` detects this by
    comparing each page's set of ad ids to the previous page's: an identical set means it's been
    clamped back, so it stops instead of looping to the page cap and re-saving the same rows
    (verified live: a 30-page run against the real site stored 1,034 listings and stopped around
    page ~20, matching OLX's own "peste 1.000 rezultate" count, rather than continuing to 30).
  - **No TLS/HTTP-version workaround needed after all.** Corrected a stale note in
    `.claude/lessons.md` (2026-09-26) that attributed an OLX CloudFront block to TLS 1.3 — retested
    directly from PHP today and found TLS pinning alone doesn't fix it, while Laravel's `Http`
    facade with zero special options already works, because Guzzle's own default HTTP version
    (1.1) avoids the actual trigger (curl's default HTTP/2 handshake). `OlxClient` needs no special
    curl/TLS code, same shape as `AutovitClient`.
  - With `currency=EUR` requested, every OLX result comes back priced in EUR (verified across all
    52 listings on a live page) — unlike Autovit, there's no mixed-currency problem here.
  - `body_type`/`transmission`/`fuel_type` stay `null` on OLX-sourced listings, same documented
    limitation as Autovit (the search filters server-side by these; per-listing values aren't in
    the search results).
  - 10 new tests (mapper parsing edge cases, price filtering, dedup/update, the pagination-clamp
    stop condition, and the reliability hard-gate) — 49/49 passing project-wide. Verified live:
    real scraped listings landed with sane values, a second run produced 0 duplicates.
- **Chapter 6 (seller rating check) dropped**, per its own pre-written rule in `ROADMAP.md`.
  OLX's search results were checked today: "Firma"/"Persoană fizică" only appear as sidebar filter
  checkbox labels, never as a per-listing badge — so, combined with Chapter 1's earlier finding
  that Autovit only exposes seller *type* (never a rating), **neither site exposes a real seller
  rating to check**. The rule said to drop the chapter in that case, no question needed — its
  `ROADMAP.md` entry is deleted; there's nothing left to build for it.
- **Chapter 5 complete: the car-knowledge reliability filter — built as a hard gate, per your
  explicit decision.** A listing must pass both the existing criteria filter *and* a reliability
  check to ever be saved to `listings`; anything scoring below the threshold (default 60/100) is
  discarded at scrape time, not stored-and-flagged.
  - `reliability_rules` table (DB-editable, no deploy needed) seeded with three starting rules:
    VW Group 1.6/2.0 TDI (EA189), Ford 1.6 TDCi + PowerShift, BMW N47 diesel (matched by model
    badge, since sellers rarely write the engine code itself).
  - Two generic statistical rules: suspiciously low mileage for a car's age, and price far below
    the median of comparable already-saved listings (same currency, similar year — a real sample,
    not the listing being judged).
  - `App\Services\Reliability\ReliabilityScorer` composes three small evaluator classes; wired
    into `scrape:autovit` right before it decides to save. A saved listing keeps its
    `reliability_score`/`reliability_flags` so later chapters (the email digest) can show *why* a
    car is a good pick, not just that it was one.
  - `reliability:rescore` command for re-scoring already-saved listings after the ruleset changes.
  - 18 new tests (scorer logic, the command, and the scrape-time rejection gate) — 39/39 passing
    project-wide. Verified live: real scraped listings now carry a real `reliability_score`.
  - Adapted from a plan another concurrent session had already researched and written — the
    scoring engine design is unchanged; only *when* it's called (hard gate vs. soft annotation)
    changed, per your explicit choice.
- **Chapter 1 complete: `scrape:autovit`.** Fetches Autovit search results (respecting
  `robots.txt` via `RobotsTxtGuard`), maps them into `listings` via `AutovitListingMapper`, and
  upserts via `Listing::updateOrCreate` — verified against the real live site: first run stored 3
  new listings, second run updated those same 3 with 0 duplicates. Price filtering only applies
  when a listing's currency matches the `price_currency` criterion (EUR) — a listing priced in RON
  is stored without a price judgement rather than being wrongly compared as if the numbers were
  the same unit; real currency normalization is Chapter 3's job, not built yet.
- `AutovitListingMapper` unit-tested against a real captured listing node.
- `ScrapeAutovitCommandTest` verifies: correct store/filter behavior across currencies, no
  duplicates + correct price updates on a second run, and that the actual request URL never
  contains `_price` or `[order]=` (the disallowed `robots.txt` patterns).

### Changed
- Autovit plan: the `robots.txt` fix is now a general, reusable `RobotsTxtGuard` service (fetches,
  caches, and parses any site's `robots.txt`, checked at runtime before every request) instead of
  a one-time manual check baked into the URL-building logic. Both the Autovit scraper (Chapter 1,
  builds it) and the future OLX scraper (Chapter 2, reuses it) call the same method — one shared
  implementation, called once per site with that site's own base URL.
- Scraping frequency: twice a day → **once a day at 07:00** (morning). Each run now needs to be
  thorough (higher page-coverage defaults) since there's no second run to catch what the first
  missed. Updated in `CLAUDE.md`, `ROADMAP.md` Chapter 8, and the Autovit scraper plan.
- The Autovit scraper plan no longer uses the `search[filter_float_price:to]` or
  `search[order]=...` URL parameters — checked Autovit's actual `robots.txt` and found both match
  `Disallow` patterns (`*_price*` and `*[order]=*`) under `User-agent: *`. Price filtering and
  sorting now happen in PHP after fetching, not via the URL. OLX's `robots.txt` has no such
  restriction. This was caught and fixed before any scraper code was written.

### Added
- `CLAUDE.md` rule 10 now explicitly ends the branch workflow with `git push origin development`
  — previously implied, now written down so it's never skipped.
- Resolved every open decision in `ROADMAP.md` so no chapter needs a question answered before
  starting: OLX's confirmed query parameters and field-name differences from Autovit (Chapter 2),
  the BNR daily-rate feed as the RON→EUR source (Chapter 3), the seeded known-problem-engine list
  (Chapter 5), a concrete fallback rule for the seller-rating chapter if no site exposes one
  (Chapter 6), and the Windows Task Scheduler + `schedule:run`-every-minute pattern with its
  native missed-run checkbox as the catch-up mechanism, plus default run times (Chapter 8).
- `listings` table: one row per scraped car ad, unique on `(source, external_id)` so the same ad
  never gets stored twice across scrape runs.
- `search_criteria` table + a code-defined parameter catalog (`app/Support/CriteriaCatalog.php`)
  — the source of truth for which search parameters exist and what values they accept.
- `criteria:set <name> <value>` and `criteria:help` Artisan commands.
- Starting search criteria set: `price_max=7000` (EUR), `year_min=2013`, `km_max=230000`,
  `engine_capacity_max=2.0` (liters), `body_type=sedan,break`.
- `tests/Pest.php` — required for Pest's functional `test()`/`it()` syntax to actually boot the
  Laravel app; the installer's `--pest` scaffold didn't create it (see `.claude/lessons.md`).
- `CHANGELOG.md` and the standing rule to keep it updated with every change (`CLAUDE.md` rule 9).
- Researched and wrote the Autovit scraper plan (`.claude/plans/2026-09-28-autovit-scraper.md`),
  verified live against the real site: no currency filter exists on Autovit (price is per-seller,
  not a toggle), confirmed working filter fields for price/year/mileage/engine size/body type,
  confirmed newest-first sorting, confirmed `body_type`/`transmission` aren't returned in search
  results (a real data limitation, not an oversight).
- `ROADMAP.md` — a chaptered breakdown of all remaining work (OLX scraper, price history,
  ingestion logging, the car-knowledge reliability filter, seller-rating check, Gmail digest,
  scheduler wiring, resilience/kill-switches), each chapter written to be independently
  buildable without depending on the others being done first.

### Fixed
- Functional Pest tests were silently not booting the app (missing `tests/Pest.php`).

## 2026-09-26

### Added
- Laravel 13 (PHP 8.5) scaffolded, SQLite for storage, Pest 5 for testing, no auth scaffolding.
- SQLite configured for WAL mode + busy timeout (5000ms) + `synchronous=NORMAL`, verified live via
  `PRAGMA`.
- Gmail SMTP mail configured in `.env`/`.env.example` and verified with a real test send.
- `.claude/best-practices.md` — living Laravel/PHP conventions doc, read before writing code.
- `.githooks/pre-commit` — blocks committing a real `.env` file (allows `.env.example`).
- Git branching workflow: `main` (releases only) / `development` (integration) /
  `feature`-`fix`-`docs` branches per task, merged with `--no-ff`.

### Fixed
- Fresh Laravel install on this machine (Herd on Windows) shipped `APP_URL` with a duplicated
  port (`http://localhost:8000:8000`), breaking every `artisan` command. Corrected to a single
  port (see `.claude/lessons.md`).
