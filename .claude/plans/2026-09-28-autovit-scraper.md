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
- **`robots.txt` blocks two of the URL params this plan originally relied on.** I checked
  Autovit's actual `robots.txt` (not assumed) and found, under `User-agent: *`:
  `Disallow: *[order]=*` and `Disallow: *_price*`. Our planned `search[order]=created_at_first:desc`
  (newest-first sort) and `search[filter_float_price:to]=7000` (price filter) both match these
  patterns — the `_price` disallow matches because `filter_float_price` contains that substring.
  OLX's `robots.txt` has no equivalent restriction (checked separately, for Chapter 2). Respecting
  this isn't optional — it's the whole point of the "don't get blocked" requirement. **Fix:** drop
  both params from the request URL. Fetch pages in the site's default (relevance) order, still
  filtered server-side by year/mileage/engine/body-type (none of those match a disallowed
  pattern), and do price filtering **and** sorting ourselves in PHP after parsing each page, before
  deciding what to store. Trade-off: no more guaranteed strict newest-first fetch order from the
  site itself — acceptable, since coverage now comes from paging thoroughly through each single
  daily run (see below) rather than from sort order.
- **This becomes a real, general, runtime-enforced check — not a one-time manual read.** Rather
  than just hand-fixing this one plan and hoping nothing else gets missed (here, or on OLX, or if
  Autovit changes its `robots.txt` later), this plan builds a shared `RobotsTxtGuard` service:
  fetch a site's `robots.txt` (cached, so it's not re-fetched every page), parse its `Disallow`/
  `Allow` rules for `User-agent: *`, and expose `isAllowed(string $url): bool`. Both
  `AutovitClient` (this plan) and the future `OlxClient` (Chapter 2) call it before every request
  and refuse to fetch a disallowed URL — the same method, called once per site with that site's
  own base URL, since the parsing logic is identical even though the two sites' actual rules
  differ. This is Autovit's plan because Autovit is being built first, but the class itself isn't
  Autovit-specific.
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
2. **`app/Services/Scraping/RobotsTxtGuard.php`** — general-purpose, not Autovit-specific:
   `RobotsTxtGuard::for(string $baseUrl)` fetches and caches that site's `robots.txt` (24h TTL —
   sites don't change these often, no need to refetch every run), parses `Disallow`/`Allow` for
   `User-agent: *` (simple wildcard matching — the same algorithm Google's crawler uses: longest/
   most-specific matching rule wins, `Allow` wins ties), and `->isAllowed(string $url): bool`
   checks one URL against those rules. No network call needed to *use* it beyond the first cached
   fetch.
3. **`app/Services/Scraping/AutovitClient.php`** — builds the query string from current
   `SearchCriterion` values (via the existing `CriteriaCatalog`) — **excluding** price and sort
   order, per the `robots.txt` finding above — calls `RobotsTxtGuard::for('https://www.autovit.ro')
   ->isAllowed($url)` before every request and throws rather than fetching if it's ever false (a
   safety net, since we've already designed the URL to be allowed — this catches the case where
   Autovit's rules change later without anyone noticing), fetches one search-results page via
   Laravel's `Http` facade, extracts `__NEXT_DATA__` (robust regex — the naive one from
   `lessons.md` broke on this exact page because of an extra `nonce`/`crossorigin` attribute;
   noting the fix), walks `pageProps.urqlState` for the entry containing `advertSearch`, and
   returns the parsed listings + pagination info for that page.
4. **`app/Services/Scraping/AutovitListingMapper.php`** — maps one raw Autovit listing node to the
   `Listing` model's attributes (field mapping table above), and exposes the parsed price so the
   command can apply `price_max` client-side.
5. **`app/Console/Commands/ScrapeAutovit.php`** (`scrape:autovit {--pages=}`) — loops pages
   (site's default order) up to the configured/given max, maps each listing, **discards any whose
   price exceeds `price_max`** (client-side, since the site can't filter this for us anymore), and
   `Listing::updateOrCreate(['source' => ..., 'external_id' => ...], [...])` for the rest — inserts
   new ones, updates existing ones (e.g. a price change), never duplicates. Prints a summary
   (created vs. updated vs. price-filtered-out count) at the end.
6. **Tests (Pest)**, using a saved real (trimmed) fixture — no live network calls in tests, per
   `best-practices.md`:
   - `RobotsTxtGuard` correctly allows/disallows URLs against a fixture `robots.txt` containing
     both a plain path disallow and a wildcard one (covering the `*_price*`/`*[order]=*` shape).
   - The mapper correctly converts one real captured listing node into `Listing` attributes.
   - Running the command against a faked HTTP response (`Http::fake()`) creates the right rows.
   - Running it twice with the same fixture doesn't create duplicates; a changed price in the
     second fixture updates the existing row instead.
   - `body_type`/`transmission` come back `null` (documenting the known limitation, not silently
     losing test coverage of it).
7. **Branch + changelog** per rules 9–10: `feature/autovit-scraper` off `development`, commit,
   `CHANGELOG.md` entry, merge `--no-ff`, delete branch.

## Files
- `config/scraping.php` — new
- `app/Services/Scraping/RobotsTxtGuard.php` — new — general-purpose, reused by OLX later
- `app/Services/Scraping/AutovitClient.php` — new
- `app/Services/Scraping/AutovitListingMapper.php` — new
- `app/Console/Commands/ScrapeAutovit.php` — new
- `tests/Fixtures/autovit_search_page.html` — new — a real, trimmed 2-listing capture
- `tests/Fixtures/robots_with_wildcards.txt` — new — a small fixture for `RobotsTxtGuard` tests
- `tests/Unit/RobotsTxtGuardTest.php` — new
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
- **Runs once a day (morning, 07:00 — see `CLAUDE.md`), so this run needs to be thorough**, not
  quick. Default page cap: **25 pages (~800 listings)** per run — roughly the same total daily
  request volume as the originally-considered "10 pages × 2 runs/day", just consolidated into one
  run, so this isn't a bigger daily footprint on Autovit than before. The command stops early if a
  page comes back with fewer than a full page of results (means we've reached the end of what
  matches the server-side criteria) rather than always exhausting the full cap. No "stop at
  last-seen" optimization — every run re-walks the same page range and upserts everything;
  `updateOrCreate` makes this harmless (idempotent), just a bit of repeated work, which is fine at
  once-a-day frequency.
- Politeness: a short delay between page requests, and a realistic User-Agent, to behave
  reasonably — not a guarantee against future blocking. `robots.txt` is now fully respected (see
  above) rather than assumed compatible.

## How we'll verify it works
- `php artisan test` — new unit + feature tests pass, using the recorded fixture.
- Manually run `php artisan scrape:autovit --pages=1` against the **real** site and confirm rows
  land in `listings` with sane values (spot-check a couple against the live page).
- Run it a second time immediately after and confirm no duplicate rows (`source`+`external_id`
  unique constraint from the previous phase already guarantees this at the DB level, but a
  visible count check confirms the command's upsert logic works too).

## Needs from you
Nothing blocking. Page cap (25/run), schedule (once daily, 07:00), and the `robots.txt`-driven
approach change are all decided above.
