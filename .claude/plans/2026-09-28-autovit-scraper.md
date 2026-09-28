# Plan: Autovit scraper (Phase 2a — Autovit only, OLX separately)

## Goal
Build `scrape:autovit`, an Artisan command that fetches car ads from Autovit matching your saved
search criteria and stores them in the `listings` table, without duplicating anything already
stored. This is Autovit only — OLX needs its own investigation (different site, different TLS
quirk already logged in `lessons.md`) and will be a separate plan once this one is proven out.

## What I found by actually inspecting the live site
(Per `CLAUDE.md` rule 2 — not relying on the 2-day-old note in `lessons.md` without re-checking.)

- **No currency filter/toggle exists anywhere on Autovit.** I searched the site's entire filter
  schema (615KB of filter definitions) for anything currency-related — zero matches. Price is
  whatever currency the seller entered (I observed a live mix: 30 EUR-priced, 2 RON-priced, in
  one 32-ad batch). Our `price_max` filter still works correctly regardless — see below.
- **The search itself does real server-side filtering**, confirmed by inspecting the "applied
  filters" the site echoes back after each request:
  | Our criterion | Autovit's filter field | Verified live? |
  |---|---|---|
  | `price_max` | `filter_float_price:to` | Yes — result count dropped appropriately |
  | `year_min` | `filter_float_year:from` | Yes |
  | `km_max` | `filter_float_mileage:to` | Yes |
  | `engine_capacity_max` (liters) | `filter_float_engine_capacity:to` (**cc**, so ×1000) | Yes |
  | `body_type` | `filter_enum_body_type` (multi-value) | Yes — `sedan` and `combi` both confirmed applied, count dropped from 2124 → 751 |

  **Important mapping:** Autovit's internal code for what you called "break" is `combi` (their
  own filter description literally says "ex. Skoda Octavia break, Audi A4 Avant, BMW 5 Touring").
  The scraper will translate our `body_type=sedan,break` criterion into Autovit's
  `sedan`/`combi` codes — this translation table lives in the Autovit-specific code, not the
  shared criteria catalog, since OLX will likely use different codes.
- **Sorting newest-first works** (`search[order]=created_at_first:desc`, confirmed — results came
  back in strict descending timestamp order). This matters because the app runs twice a day and
  should reliably catch new listings, not whatever a "relevance" algorithm ranks highest.
- **Data available per listing in the search results themselves:** id, title, short description,
  URL, city, price + currency, year, mileage, engine capacity, horsepower, fuel type, two
  thumbnail photo URLs, seller type (private/dealer).
- **Data NOT available in search results (a real limitation, not an oversight):** `body_type` and
  `transmission` aren't returned per-listing, even though they're filterable. This means we can
  filter the *search* by body type, but we can't record which body type each stored listing
  actually is — that field will stay `null` from this scraper. Getting it would require fetching
  each listing's individual detail page (one extra HTTP request per ad — expensive, and not
  needed since we already filtered correctly server-side). Same limitation applies to
  `transmission`. Flagging this now rather than silently leaving the column empty unexplained.
- Since the search already filters server-side by all your numeric/body-type criteria, **this
  scraper *is* the hard-filtering step** for those criteria — there's no separate "apply filters
  to already-stored listings" phase needed for price/year/km/engine/body-type. (`brand`, `model`,
  `fuel_type`, `transmission`, `city` are still unset criteria, and would need a different
  approach if set later, since not all are validated live yet.)

## What I will do

1. **`config/scraping.php`** — base URL, a realistic User-Agent string, default max pages per run,
   and a delay between page requests (politeness/rate-limiting) — configurable, not hardcoded.
2. **`app/Services/Scraping/AutovitClient.php`** — builds the query string from current
   `SearchCriterion` values (via the existing `CriteriaCatalog`), fetches one search-results page
   via Laravel's `Http` facade, extracts `__NEXT_DATA__` (robust regex — the naive one from
   `lessons.md` broke on this exact page because of an extra `nonce`/`crossorigin` attribute; noting
   the fix), walks `pageProps.urqlState` for the entry containing `advertSearch`, and returns the
   parsed listings + pagination info for that page.
3. **`app/Services/Scraping/AutovitListingMapper.php`** — maps one raw Autovit listing node to the
   `Listing` model's attributes (field mapping table above).
4. **`app/Console/Commands/ScrapeAutovit.php`** (`scrape:autovit {--pages=}`) — loops pages
   (newest-first) up to the configured/given max, maps each listing, and
   `Listing::updateOrCreate(['source' => ..., 'external_id' => ...], [...])` — inserts new ones,
   updates existing ones (e.g. a price change), never duplicates. Prints a summary (created vs.
   updated count) at the end.
5. **Tests (Pest)**, using a saved real (trimmed) fixture — no live network calls in tests, per
   `best-practices.md`:
   - The mapper correctly converts one real captured listing node into `Listing` attributes.
   - Running the command against a faked HTTP response (`Http::fake()`) creates the right rows.
   - Running it twice with the same fixture doesn't create duplicates; a changed price in the
     second fixture updates the existing row instead.
   - `body_type`/`transmission` come back `null` (documenting the known limitation, not silently
     losing test coverage of it).
6. **Branch + changelog** per rules 9–10: `feature/autovit-scraper` off `development`, commit,
   `CHANGELOG.md` entry, merge `--no-ff`, delete branch.

## Files
- `config/scraping.php` — new
- `app/Services/Scraping/AutovitClient.php` — new
- `app/Services/Scraping/AutovitListingMapper.php` — new
- `app/Console/Commands/ScrapeAutovit.php` — new
- `tests/Fixtures/autovit_search_page.html` — new — a real, trimmed 2-listing capture
- `tests/Unit/AutovitListingMapperTest.php` — new
- `tests/Feature/ScrapeAutovitCommandTest.php` — new
- `CHANGELOG.md` — changed

## Best practices applied
- Laravel 13.x docs (Context7, checked today): `Http` facade for outbound requests (`Http::get()`,
  `Http::withOptions()` for low-level Guzzle/curl options if ever needed, `Http::fake()` for
  testing) instead of instantiating Guzzle directly.
- Small focused classes: fetching (`AutovitClient`), mapping (`AutovitListingMapper`), and
  orchestration (the command) are separate, each independently testable.
- Config over hardcoding: base URL, User-Agent, page limits, and delay all live in
  `config/scraping.php`.
- No live-network tests — a real fixture, captured once, drives the test suite.

## Things to know / risks
- **Site structure will drift eventually** (it's an unofficial integration) — when it breaks, the
  fix is re-inspecting `__NEXT_DATA__` the same way this plan was researched, not guessing.
- **`body_type`/`transmission` stay `null`** on Autovit-sourced listings, as explained above. If
  that turns out to matter later (e.g. for the future reliability-scoring phase), the fix is
  fetching detail pages for a smaller shortlist, not every search result — a separate decision
  for later, not built now.
- **No "stop at last-seen" optimization yet** — each run pages through up to the configured max
  (default proposed: 10 pages / ~320 listings) newest-first and upserts everything, rather than
  stopping exactly at the last listing seen on the previous run. Simpler for a first version;
  `updateOrCreate` makes re-fetching harmless (idempotent), just slightly wasteful. Can optimize
  later if 10 pages twice a day isn't enough coverage.
- Politeness: a short delay between page requests, and a realistic User-Agent, to behave
  reasonably — not a guarantee against future blocking.

## How we'll verify it works
- `php artisan test` — new unit + feature tests pass, using the recorded fixture.
- Manually run `php artisan scrape:autovit --pages=1` against the **real** site and confirm rows
  land in `listings` with sane values (spot-check a couple against the live page).
- Run it a second time immediately after and confirm no duplicate rows (`source`+`external_id`
  unique constraint from the previous phase already guarantees this at the DB level, but a
  visible count check confirms the command's upsert logic works too).

## Needs from you
1. Default page cap of **10 pages (~320 listings) per run** — fine, or do you want it higher/lower
   to start?
2. Nothing else blocking — the currency and body-type questions from before are resolved above.
