# Plan: Flag sellers whose account was created this year

## Goal
Triggered by a real listing (an Audi A6 whose OLX seller account shows "Pe OLX din martie 2026" —
created this year) that scored 100/100 and passed cleanly, because nothing has ever checked seller
account age. Add that check for both sites.

## What I found (research)
- **Both sites expose this on the same ad page already being fetched** for damage/consumption —
  zero extra HTTP cost:
  - Autovit: `__NEXT_DATA__.props.pageProps.advert.seller.featuresBadges`, an entry with
    `"code":"registration-date"` and a label like `"Vânzător pe Autovit.ro din 2025"`. Confirmed
    live on the Kia Optima ad (registered 2025).
  - OLX: `[data-testid="member-since"]` renders `"Pe OLX din martie 2026"` (Romanian month name +
    year). Confirmed live on the Audi A6 the user linked (registered March 2026 — this year).
- Both reduce to the same thing needed: the **year** a seller registered. A trailing
  `\b(19|20)\d{2}\b` regex on the label/text extracts it from either format without needing to
  parse Romanian month names.

## What I will do
1. **Migration** — add nullable `listings.seller_registered_year` (integer).
2. **`AutovitDetailFetcher`** — extract the year from the `registration-date` badge; add
   `sellerRegisteredYear: ?int` to `fetch()`'s return array.
3. **`OlxDetailFetcher`** — extract the year from `[data-testid="member-since"]`'s text; same new
   return key. (OLX's title-only damage shortcut can't know this — falls through to a real fetch
   whenever seller age matters, same as consumption already does.)
4. **`ScrapeAutovit`/`ScrapeOlx`** — populate `$attributes['seller_registered_year']` everywhere
   `is_damaged`/`fuel_consumption_l_100km` are already set (the fetched branch, the reused-from-
   existing branch, and the skipped-by-cap branch, where it's `null`).
5. **`config/car_knowledge.php`** — `new_seller_account.penalty` (default 20 — a moderate,
   statistical-suspicion signal like low-mileage-for-age or below-market-price, not a hard reject
   like damage; a genuinely new private seller isn't automatically a scammer, but combined with
   another red flag it now correctly stacks toward rejection instead of scoring 100 clean).
6. **`App\Services\Reliability\Rules\NewSellerAccountEvaluator`** (new) — flags when
   `seller_registered_year` equals the current calendar year. Registered in `ReliabilityScorer`.
7. **Tests**: fetcher-level parsing tests for both sites (current-year label, older-year label,
   missing/unparseable label → null), evaluator tests (flags this year, doesn't flag an older year
   or null), and one command-level integration test per scraper proving the wiring end-to-end.
8. **`.env.example`**, **`CHANGELOG.md`**, branch `feature/seller-account-age`, merge, push.

## Files
- `database/migrations/..._add_seller_registered_year_to_listings_table.php` — new
- `app/Services/Scraping/AutovitDetailFetcher.php` — changed
- `app/Services/Scraping/OlxDetailFetcher.php` — changed
- `app/Console/Commands/ScrapeAutovit.php` — changed
- `app/Console/Commands/ScrapeOlx.php` — changed
- `config/car_knowledge.php` — changed
- `app/Services/Reliability/Rules/NewSellerAccountEvaluator.php` — new
- `app/Services/Reliability/ReliabilityScorer.php` — changed
- `tests/Unit/AutovitDetailFetcherTest.php`, `tests/Unit/OlxDetailFetcherTest.php` — changed
- `tests/Feature/ReliabilityScorerTest.php` — changed
- `tests/Feature/ScrapeAutovitCommandTest.php`, `tests/Feature/ScrapeOlxCommandTest.php` — changed
- `.env.example`, `CHANGELOG.md` — changed

## Things to know / risks
- **A soft penalty, not a hard reject** — a new account alone is weak evidence. This differs from
  the damage/high-consumption rules (full-reject penalty) on purpose. If you'd rather this be an
  automatic reject like damage, that's a one-line config change (`penalty` to 100+).
- **"This year" is a moving target** — a listing scored in December vs. January treats "this year"
  differently by design (matches what was asked literally); not a rolling "last 12 months" window.
- The real Audi A6 listing already scored 100/0 flags and was emailed before this existed — it
  won't be retroactively re-flagged (same "existing listings aren't retroactively enriched" limit
  as the damage/consumption chapter) unless a future scrape re-fetches its detail page.

## How we'll verify it works
- `php artisan test` — new tests plus full suite.
- Manual: re-fetch the real Audi A6 URL directly and confirm `sellerRegisteredYear` parses to 2026.

## Needs from you
Nothing blocking — penalty defaults to a moderate 20 (stacks with other flags rather than
auto-rejecting alone); say so if you want it to be a hard reject instead.
