# Changelog

Every implemented change gets an entry here, in plain language — what changed and why. This is
separate from git commit messages: commits describe a diff, this describes the project's
progress in a way that's readable without digging through `git log`.

## 2026-09-28

### Added
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
