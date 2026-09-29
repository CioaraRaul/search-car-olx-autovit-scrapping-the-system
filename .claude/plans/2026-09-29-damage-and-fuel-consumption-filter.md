# Plan: Reject damaged cars and cars with high fuel consumption

## Goal
Two new "hard no" rules, triggered by your Kia Optima report: a listing marked as damaged/crashed,
or with high fuel consumption, should be rejected the same way a known-problem engine already is —
not just noted, actually filtered out before it's ever saved.

## What I found (research)
- **Neither field exists in the data we currently scrape.** Confirmed live: a search-results page
  (what `AutovitClient`/`OlxClient` fetch today) only carries
  `make/fuel_type/mileage/engine_capacity/engine_power/model/year` per listing — no damage status,
  no consumption figure.
- **Autovit's individual ad page has both, as structured data** (same `__NEXT_DATA__` JSON shape
  already parsed for search results, just a different query): confirmed on the Kia Optima you
  linked —
  `{"key":"damaged","label":"Avariata","value":"Da","group":"condition_history"}` and
  `{"key":"urban_consumption",...,"value":"5.6 l/100km","group":"technical_specs"}` /
  `{"key":"extra_urban_consumption",...,"value":"4.1 l/100km",...}`. No single "combined" figure
  exists — I'll average the two when both are present.
- **`robots.txt` allows fetching individual ad pages** — checked via the existing
  `RobotsTxtGuard` against a real ad URL: allowed. Only the search page's `_price`/`[order]=`
  params are disallowed (already known from Chapter 1).
- **OLX does not expose either field.** Checked a live OLX ad page for the same patterns
  (`Avariat`, `daune`, `consum...l/100km`) — none present, structured or in the visible page text.
  So this can only work for Autovit-sourced listings; OLX listings will simply never trigger these
  two new rules (same category of gap as the already-documented "OLX doesn't return body_type/
  transmission" limitation).
- **Existing `Reliability` architecture already fits this exactly** — `ReliabilityScorer` runs a
  list of small `ReliabilityRuleEvaluator` classes, each returning flags with a penalty; a listing
  scoring below `reject_below_score` (60) never gets saved. I don't need a new mechanism — I need
  two new evaluators with a penalty large enough (100) to guarantee rejection on their own,
  matching what "no damaged cars" / "high consumption is bad" mean (an absolute exclusion, not a
  soft ding), the same way `KnownIssueEvaluator` already works for problem engines.

## What I will do
1. **Migration** — add nullable `is_damaged` (boolean) and `fuel_consumption_l_100km` (decimal
   4,1) to `listings`.
2. **`app/Services/Scraping/AutovitDetailFetcher.php`** (new) — `fetch(string $url): array{damaged:
   ?bool, fuelConsumptionL100km: ?float}`. Fetches the ad's own page (through `RobotsTxtGuard`,
   same as the search page), parses its `__NEXT_DATA__` the same way `AutovitClient` already does,
   reads the `damaged`/`urban_consumption`/`extra_urban_consumption` keys. Returns nulls if a field
   is missing rather than guessing.
3. **`ScrapeAutovit::handle()`** — after the existing price filter (so we don't waste a detail-page
   fetch on a listing that's already out on price) and before reliability scoring: call
   `AutovitDetailFetcher::fetch($attributes['url'])`, merge the two new fields into `$attributes`,
   *then* score. One extra HTTP request per listing that survives the price filter, with the same
   `request_delay_ms` politeness pause already used between pages.
4. **`config/car_knowledge.php`** — add:
   - `damaged_vehicle.penalty` (default 100 — guarantees rejection on its own)
   - `high_fuel_consumption.threshold_l_100km` (default 8.0) and `.penalty` (default 100)
5. **`app/Services/Reliability/Rules/DamagedVehicleEvaluator.php`** (new) — flags when
   `$listing['is_damaged'] === true`.
6. **`app/Services/Reliability/Rules/HighFuelConsumptionEvaluator.php`** (new) — flags when
   `$listing['fuel_consumption_l_100km']` is set and exceeds the configured threshold.
7. **`ReliabilityScorer`** — register both new evaluators alongside the existing three.
8. **Tests**: `AutovitDetailFetcherTest` (fixture-based, no live HTTP), `DamagedVehicleEvaluatorTest`,
   `HighFuelConsumptionEvaluatorTest`, and an update to `ScrapeAutovitCommandTest` covering the new
   enrichment step with `Http::fake()`.
9. **`.env.example`** — `CAR_KNOWLEDGE_DAMAGED_PENALTY`,
   `CAR_KNOWLEDGE_HIGH_FUEL_CONSUMPTION_THRESHOLD`, `CAR_KNOWLEDGE_HIGH_FUEL_CONSUMPTION_PENALTY`.
10. **`CHANGELOG.md`** entry, including the OLX limitation.
11. Branch `feature/damage-and-fuel-consumption-filter`, test, merge `--no-ff`, push.

## Files
- `database/migrations/..._add_damage_and_consumption_to_listings_table.php` — new
- `app/Services/Scraping/AutovitDetailFetcher.php` — new
- `app/Console/Commands/ScrapeAutovit.php` — changed
- `config/car_knowledge.php` — changed
- `app/Services/Reliability/Rules/DamagedVehicleEvaluator.php` — new
- `app/Services/Reliability/Rules/HighFuelConsumptionEvaluator.php` — new
- `app/Services/Reliability/ReliabilityScorer.php` — changed
- `tests/Unit/AutovitDetailFetcherTest.php` — new
- `tests/Unit/DamagedVehicleEvaluatorTest.php` — new
- `tests/Unit/HighFuelConsumptionEvaluatorTest.php` — new
- `tests/Feature/ScrapeAutovitCommandTest.php` — changed
- `.env.example` — changed
- `CHANGELOG.md` — changed

## Things to know / risks
- **~32x more Autovit HTTP requests.** One search-results page currently returns ~32 listings in
  one request; this adds one detail-page request *per listing that survives the price filter*.
  Mitigated by filtering on price first and reusing the existing politeness delay, but it does
  meaningfully lengthen a scrape run and increase load on Autovit — worth watching after the first
  real run.
- **OLX is unaffected by design**, not by oversight — the data genuinely isn't there. OLX listings
  will always have `is_damaged`/`fuel_consumption_l_100km` as `null`, and both new evaluators
  simply don't fire on a `null` value, so nothing about OLX scoring changes.
- **Existing 1,034 already-saved listings won't be retroactively enriched.** `reliability:rescore`
  re-runs scoring on already-stored attributes; it doesn't re-fetch each ad's detail page. Doing
  that for all existing listings would mean ~1,000 extra requests in one burst — explicitly not
  doing that as part of this chapter to avoid hammering Autovit; it only applies to *new* scrapes
  going forward. Say the word if you want a one-off backfill for the existing listings too.
- **Fixed penalty (100) rather than a smaller score ding** — matches "no damaged cars"/"high
  consumption is bad" as absolute exclusions, consistent with how a known-problem engine already
  works, not a new pattern.

## How we'll verify it works
- `php artisan test` — new tests plus full existing suite.
- Manual: run `scrape:autovit` for real afterward and check whether the Kia Optima listing (if
  still live) now gets rejected instead of saved, and spot-check a couple of newly-saved listings'
  `is_damaged`/`fuel_consumption_l_100km` values against their real Autovit pages.

## Needs from you
Nothing blocking for the build itself. One thing to flag: OLX listings can't be checked for either
condition (data doesn't exist there) — if that's not acceptable, the alternative is free-text
keyword matching on OLX ad descriptions (weaker signal, same approach as the known-engine rule),
which I can add as a follow-up if you want it.
