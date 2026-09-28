# Changelog

Every implemented change gets an entry here, in plain language — what changed and why. This is
separate from git commit messages: commits describe a diff, this describes the project's
progress in a way that's readable without digging through `git log`.

## 2026-09-28

### Added
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
