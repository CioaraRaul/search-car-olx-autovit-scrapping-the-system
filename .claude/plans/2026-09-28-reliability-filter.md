# Plan: Chapter 5 — Car-knowledge reliability filter

**Revision note (2026-09-28, later same day):** this plan started as a *soft* filter (score
everything, exclude nothing) — that original design and all of its research below still hold. The
user then explicitly decided this should be a **hard filter** instead: a car that scores below
the threshold is never saved to `listings` at all, not stored-and-flagged. Sections below are
updated for that; the core scoring engine (rules, evaluators, DTOs) is unchanged from the original
design, since good code doesn't need to change just because *when* it's called changed.

## Goal
Give every candidate listing a **reliability score** (0–100) computed from (a) a small, DB-editable
list of known-problem-engine rules, and (b) two generic statistical rules (suspiciously low mileage
for the car's age, price far below the market median). **This is a hard filter**: it's evaluated at
scrape time, before saving — a listing scoring below the configured threshold is discarded, not
stored. A listing that *is* saved keeps its score + flags on the row, so the email digest (Chapter
7) can still show *why* something was a good pick, not just that it was one.

## Context this plan is built on
- `listings` table has no structured `make`/`model`/`engine_code` columns — only a free-text
  `title` and `description`. So "known problem engine" matching has to work against free text
  (case-insensitive keyword matching), not structured data. This is a real limitation, not an
  oversight — I call it out below and in code comments where it matters.
- The Autovit scraper (Chapter 1) is now built and merged into `development`
  (`app/Console/Commands/ScrapeAutovit.php`). It currently saves every listing that passes the
  hard numeric/body-type criteria (price/year/km/engine/body-type — filtered server-side or
  client-side, see its plan) via `Listing::updateOrCreate(...)`. This chapter inserts the
  reliability check into that same flow, right before the `updateOrCreate` call — a listing must
  pass *both* the existing criteria filter *and* this reliability check to be saved. OLX (Chapter
  2, not built yet) will need the identical integration once it exists.
- `App\Support\CriteriaCatalog` already defines *user* search criteria (`brand`, `model`, etc.) —
  unrelated to this chapter; reliability rules are about the *car itself*, not what the user asked
  for.
- Existing command style (`CriteriaSet`, `CriteriaHelp`) uses PHP 8 attributes
  (`#[Signature(...)]`, `#[Description(...)]`) from `Illuminate\Console\Attributes` instead of a
  `protected $signature` string — I'll follow the same style.
- Existing models use the `#[Fillable([...])]` attribute instead of `protected $fillable` — same
  pattern, I'll follow it.
- Confirmed via Context7 (Laravel 13.x docs, `eloquent-mutators.md` and `seeding.md`):
  - `'array'` in a model's `casts()` method round-trips a JSON column to/from a PHP array — no
    custom cast class needed for the JSON columns here.
  - A seeder calls other seeders via `$this->call([...])` from `DatabaseSeeder::run()`.

## What I will do

1. **Migration: `reliability_rules` table.** Columns: `id`, `name` (string, unique — e.g.
   `vw-group-tdi-ea189`), `keywords` (json), `penalty` (unsigned tiny int, 0–255), `message`
   (string, shown to the user), `active` (boolean, default true — lets a bad rule be turned off
   without deleting it), timestamps.
   - `keywords` stores a list of keyword-*groups*, e.g.
     `[["vw","1.6","tdi"], ["volkswagen","1.6","tdi"], ["skoda","2.0","tdi"]]`. A listing matches
     the rule if **all** keywords in **any one group** are found (case-insensitive substring) in
     the listing's title+description. This is "OR of ANDs" — it's what lets one rule row cover
     brand-name variants (`vw` vs `volkswagen`) without needing multiple near-duplicate rows, and
     it's still just data anyone can edit later (DB row), no code deploy needed, satisfying the
     roadmap's "table preferred, editable without a deploy."

2. **Migration: add reliability columns to `listings`.** `reliability_score` (nullable unsigned
   tiny int), `reliability_flags` (nullable json — array of `{rule, message, penalty}`),
   `reliability_scored_at` (nullable timestamp). Persisted (not computed on the fly every time) so
   later chapters (Gmail digest, a future API) can read/sort/filter without recomputing.

3. **`App\Models\ReliabilityRule`.** `#[Fillable(['name','keywords','penalty','message','active'])]`,
   casts: `keywords` → `array`, `active` → `bool`.

4. **Update `App\Models\Listing`.** Add `reliability_score`, `reliability_flags`,
   `reliability_scored_at` to the `#[Fillable]` list and casts (`reliability_flags` → `array`,
   `reliability_scored_at` → `datetime`).

5. **`config/car_knowledge.php`** — thresholds for the generic rules, all overridable via `.env`
   (documented in `.env.example`, not secrets so safe to show real defaults there):
   ```php
   return [
       'base_score' => 100,
       'low_mileage' => [
           'expected_km_per_year' => (int) env('CAR_KNOWLEDGE_EXPECTED_KM_PER_YEAR', 15000),
           'suspicious_ratio' => (float) env('CAR_KNOWLEDGE_LOW_MILEAGE_RATIO', 0.3),
           'min_age_years' => (int) env('CAR_KNOWLEDGE_LOW_MILEAGE_MIN_AGE', 2),
           'penalty' => (int) env('CAR_KNOWLEDGE_LOW_MILEAGE_PENALTY', 15),
       ],
       'below_market_price' => [
           'year_range' => (int) env('CAR_KNOWLEDGE_PRICE_YEAR_RANGE', 1),
           'min_sample_size' => (int) env('CAR_KNOWLEDGE_PRICE_MIN_SAMPLE', 5),
           'ratio_threshold' => (float) env('CAR_KNOWLEDGE_PRICE_RATIO_THRESHOLD', 0.5),
           'penalty' => (int) env('CAR_KNOWLEDGE_PRICE_PENALTY', 20),
       ],
   ];
   ```
   *Why config, not more DB rows:* these two rules are **computed** (they compare a listing
   against other listings / against its own age), not "does this text contain X" — they don't fit
   the `reliability_rules` row shape. The roadmap groups them under "generic rules to include from
   the start" separately from the DB-seeded engine list, so hardcoding the *logic* with
   *configurable* thresholds matches that distinction.

   Also, since this is now a hard gate: `'reject_below_score' => (int) env('CAR_KNOWLEDGE_REJECT_BELOW_SCORE', 60)`.
   A listing with `score < 60` is discarded rather than saved. Reasoning for the default: a single
   major known-issue rule (EA189 -30, PowerShift -25, N47 -25) alone leaves a score of 70–75 —
   still saved (one red flag isn't necessarily disqualifying on its own for a cheap car). Two
   compounding flags (e.g. a known-issue engine *and* suspiciously low mileage) pushes it under 60
   and it's rejected. This is a starting number, easy to tune via `.env` without touching code.

6. **DTOs** (small, `readonly`, in `app/Services/Reliability/`):
   - `ReliabilityFlag` — `rule: string`, `message: string`, `penalty: int`, plus `toArray()` for
     JSON storage.
   - `ReliabilityScore` — `score: int`, `flags: array<ReliabilityFlag>`.

7. **Rule evaluators** (`app/Services/Reliability/Rules/`), one interface + three small classes —
   each independently unit-testable, following the project's "a filter class filters" rule:
   - `ReliabilityRuleEvaluator` interface: `evaluate(Listing $listing): array` (returns
     `ReliabilityFlag[]`, zero or more).
   - `KnownIssueEvaluator` — loads active `ReliabilityRule` rows, matches keyword groups against
     `strtolower($listing->title.' '.$listing->description)`, returns one flag per matching rule.
   - `LowMileageEvaluator` — skips if `year` or `mileage_km` is null, or the car is younger than
     `min_age_years`. Otherwise: `expected_km = age_years * expected_km_per_year`; flags if
     `mileage_km < expected_km * suspicious_ratio`.
   - `BelowMarketPriceEvaluator` — skips if fewer than `min_sample_size` *other* listings exist
     with the same `currency` and a `year` within `year_range` of this one (too small a sample is
     noise, not signal). Otherwise computes the median `price` of that comparable set and flags if
     this listing's price is below `median * ratio_threshold`.
     - **Known limitation, stated explicitly**: this compares raw `price`, not a currency-
       normalized value. Chapter 3 (not built yet) will add a `price_eur` column; restricting the
       comparison set to same-`currency` listings is the safeguard until then. Noting this instead
       of silently ignoring it.

8. **`App\Services\Reliability\ReliabilityScorer`** — composes the three evaluators, sums their
   flags' penalties, returns `ReliabilityScore` with `score = max(0, base_score - total_penalty)`.

9. **`database/seeders/ReliabilityRuleSeeder`** — seeds the roadmap's starting ruleset via
   `updateOrCreate(['name' => ...], [...])` (idempotent — safe to re-run):
   - `vw-group-tdi-ea189` (VW/Škoda/SEAT/Audi 1.6 & 2.0 TDI, emissions-scandal engines), penalty 30.
   - `ford-1.6-tdci-powershift` (Ford 1.6 TDCi + PowerShift dual-clutch automatic), penalty 25.
   - `bmw-n47-diesel` (BMW N47 diesel — matched via common affected model badges: 116d/118d/120d/
     318d/320d/520d, since "N47" itself is rarely written by sellers), penalty 25.
   Registered in `DatabaseSeeder::run()` via `$this->call([ReliabilityRuleSeeder::class])`.
   Documented as a **starting point**, matching the roadmap's own wording ("expand the list over
   time").

10. **Wire it into `ScrapeAutovit` as a hard gate.** In `app/Console/Commands/ScrapeAutovit.php`,
    right before the existing `Listing::updateOrCreate(...)` call (after the existing price-
    comparability filter), compute `ReliabilityScorer::score($attributes)` — note this scores the
    *mapped attributes array*, not a saved `Listing` model, since at this point the listing hasn't
    been saved yet (or may not exist in the DB at all if it's brand new). If
    `$score->score < config('car_knowledge.reject_below_score')`, skip saving it, count it as
    "rejected by reliability filter", and continue to the next listing. Otherwise, merge
    `reliability_score`/`reliability_flags`/`reliability_scored_at` into `$attributes` before the
    `updateOrCreate` call, so the saved row carries its own score. Command summary line gains a
    fourth count: "N new, N updated, N filtered by price, N rejected by reliability."
11. **Artisan command `reliability:rescore`** (`App\Console\Commands\RescoreListings`) — thin
    `handle()`, delegates to `ReliabilityScorer`, re-scores *already-saved* listings (e.g. after
    the ruleset changes — a newly added known-issue rule should be able to update scores on
    existing rows). `--all` re-scores every listing; without it, only listings whose
    `reliability_scored_at` predates the newest active rule's `updated_at`. This does **not**
    delete previously-saved listings that would now score below the threshold under a new rule —
    removing something a human may have already seen isn't this command's job; it just keeps the
    displayed score honest. Named `rescore` (not `score`) to make clear it's for *already-saved*
    rows, distinct from the scrape-time gate.

12. **Tests** (Pest, `RefreshDatabase`, following `ListingTest.php`'s local-factory-function style
    since there's no `ListingFactory` yet):
    - `tests/Feature/ReliabilityScorerTest.php` — known-issue keyword matching (positive and
      negative case), low-mileage flag (old+low-km flagged, new+low-km not flagged, missing
      year/mileage not flagged), below-market-price flag (5+ comparables triggers it, too few
      comparables doesn't, different currency excluded from the comparison), and that
      `ReliabilityScorer` sums penalties and floors the score at 0.
    - `tests/Feature/RescoreListingsCommandTest.php` — running `reliability:rescore` sets the
      reliability columns on stale/unscored listings only; `--all` re-scores everything.
    - Extend `tests/Feature/ScrapeAutovitCommandTest.php` — a fixture listing whose title matches a
      seeded known-issue rule (combined with another minor flag) gets rejected and never appears in
      `listings`; a clean fixture listing is saved with its `reliability_score` populated. The
      command's summary line reports the rejection count.

13. **Docs.** Add a `CHANGELOG.md` entry. Delete Chapter 5's entry from `ROADMAP.md` (per that
    file's own stated rule: finished chapters are removed, the record lives in the changelog and
    this plan file).

## Files
- `database/migrations/2026_09_28_2xxxxx_create_reliability_rules_table.php` — new
- `database/migrations/2026_09_28_2xxxxx_add_reliability_columns_to_listings_table.php` — new
- `app/Models/ReliabilityRule.php` — new
- `app/Models/Listing.php` — changed (fillable + casts for new columns)
- `config/car_knowledge.php` — new (includes `reject_below_score`)
- `.env.example` — changed (documents the new optional `CAR_KNOWLEDGE_*` keys, defaults shown)
- `app/Services/Reliability/ReliabilityFlag.php` — new
- `app/Services/Reliability/ReliabilityScore.php` — new
- `app/Services/Reliability/Rules/ReliabilityRuleEvaluator.php` — new (interface)
- `app/Services/Reliability/Rules/KnownIssueEvaluator.php` — new
- `app/Services/Reliability/Rules/LowMileageEvaluator.php` — new
- `app/Services/Reliability/Rules/BelowMarketPriceEvaluator.php` — new
- `app/Services/Reliability/ReliabilityScorer.php` — new
- `database/seeders/ReliabilityRuleSeeder.php` — new
- `database/seeders/DatabaseSeeder.php` — changed (call the new seeder)
- `app/Console/Commands/RescoreListings.php` — new
- `app/Console/Commands/ScrapeAutovit.php` — changed (reliability gate wired in before save)
- `tests/Feature/ReliabilityScorerTest.php` — new
- `tests/Feature/RescoreListingsCommandTest.php` — new
- `tests/Feature/ScrapeAutovitCommandTest.php` — changed (rejection + score-on-save coverage)
- `CHANGELOG.md` — changed
- `ROADMAP.md` — changed (Chapter 5 entry removed)

## Best practices applied
- Typed properties/params/returns everywhere, `readonly` DTOs, constructor promotion — per
  `.claude/best-practices.md`.
- Small single-responsibility classes (one evaluator per rule *kind*, scorer only composes) rather
  than one large "ReliabilityService" god-class.
- Config for anything environment-tunable (thresholds), not hardcoded — per best-practices.
- `'array'` cast for JSON columns, confirmed current for Laravel 13.x via Context7 (no custom cast
  class needed since these are plain arrays, not value objects).
- Seeder composition (`$this->call([...])`) confirmed current for Laravel 13.x via Context7.
- Follows this project's existing conventions already in the repo: attribute-based commands
  (`#[Signature]`/`#[Description]`), attribute-based fillable (`#[Fillable]`), Pest functional
  tests with `RefreshDatabase` and a local `makeListing()`-style helper.

## Things to know / risks
- Keyword matching against free text is inherently approximate — a title that doesn't mention
  engine size/fuel (common on real listings) won't trigger the known-issue rules. This is a
  starting point per the roadmap, expected to improve once real scraped titles/descriptions are
  seen.
- The BMW N47 rule matches by model badge (320d, etc.), not the engine code itself, since sellers
  essentially never write "N47" — flagged as a known approximation in the seeder's comments.
- The below-market-price rule needs at least 5 same-currency, similar-year listings in the DB to
  say anything — right now (Autovit scraper already ran once) there are only 3 real listings
  stored, so this rule won't fire yet on real data. That's correct behavior (no false signal from
  too small a sample), not a bug — will naturally start working as more listings accumulate. Since
  this is now a hard gate evaluated *before* saving, there's a subtlety: a listing being scored
  isn't part of the comparison sample yet (it doesn't exist in the DB), which is exactly right —
  it should be compared against what's already there, not against itself.
- **The hard-filter tradeoff, as you chose it:** a car scoring below the threshold is never saved.
  There's no record of what got rejected or why — if the threshold or a rule turns out to be too
  aggressive later, past runs' rejections aren't recoverable, only future ones will be scored
  differently. Accepted tradeoff per your decision; not solved here.
- Persisting the score on *saved* rows (vs. computing on demand) means it can go stale if the
  ruleset changes later; that's what `reliability:rescore --all` is for.

## How we'll verify it works
- `php artisan test` (all new Pest tests pass, plus the full existing suite stays green).
- `php artisan migrate` runs cleanly on the dev SQLite DB.
- `php artisan db:seed --class=ReliabilityRuleSeeder`, then run `php artisan scrape:autovit` for
  real and confirm the summary line's rejection count makes sense, and that a spot-checked saved
  listing has a sane `reliability_score`.
- `vendor/bin/pint` before calling it done (PSR-12, per best-practices).

## Needs from you
Nothing required to start. One thing worth a nod: the BMW N47 / EA189 keyword lists above are my
best-effort starting set from the handoff doc plus general knowledge of which model badges carried
those engines — if you already know of specific extra models/trims to include (or want any of the
starting three dropped), tell me and I'll fold it in before I seed it; otherwise I'll go with what's
above since the roadmap explicitly says this is a starting point to expand later, not final.
