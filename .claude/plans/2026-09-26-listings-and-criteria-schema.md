# Plan: Listings table + dynamic search-criteria system

## Goal
Build the data foundation everything else depends on: a place to store scraped car ads
("listings"), and a way for you to set your search criteria (budget, brand, max km, etc.) by
parameter name, with a `help` command that lists what's available — exactly as described in
`CLAUDE.md`. No scraping or filtering logic yet; this is purely schema + the commands to manage
criteria.

## What I will do

1. **`listings` migration + model.**
   One row per scraped car ad. Key design choice: `source` + `external_id` together are unique,
   so the same ad (from either site) never gets stored twice, no matter how many times the
   scraper runs.
   - `source` (a PHP **enum** — `App\Enums\ListingSource::Autovit` / `::Olx` — a fixed, typed set
     of values instead of a free-text string, so a typo like `"autoivt"` is impossible)
   - `external_id` (string — the ad's own ID on that site)
   - `title`, `price` (unsigned integer), `currency` (string — set **per listing** by the scraper,
     not assumed globally; see note below)
   - `year` (unsigned small int), `mileage_km` (unsigned int)
   - `engine_capacity_cc`, `horsepower` (both nullable — not every ad lists them)
   - `body_type` (plain string for now — `sedan`/`break`/etc.; becomes an enum once the scraper
     shows the exact raw values these sites use)
   - `transmission`, `fuel_type` (plain strings for now, not enums — the scraper will be the
     first thing to see what raw values these sites actually use, so it's safer to lock them down
     into enums *after* we've seen real data, not before)
   - `city` (string, nullable)
   - `url` (text — link back to the original ad)
   - `photos` (JSON array of photo URLs, cast to a PHP array automatically)
   - `description` (text, nullable — the free-text ad description)
   - `notified_at` (nullable timestamp — null means "not emailed yet")
   - `created_at` / `updated_at` (standard Laravel timestamps — doubles as "first seen" / "last
     seen still listed")
   - Unique index on `['source', 'external_id']`

2. **`search_criteria` migration + model.**
   A simple key → value table: `key` (unique string), `value` (text, nullable), timestamps. This
   is where whatever you set actually gets stored.

3. **A criteria "catalog" in code** (`app/Support/CriteriaCatalog.php`) — the fixed list of
   parameter names the app understands, each with a description and expected type (int/string).
   This is the source of truth for validation and for the `help` command. Adding a new parameter
   later means adding one entry here — the `set`/`help` commands don't need to change.

   Catalog, with your actual starting values (set as part of step 6's manual verification, not
   hardcoded as defaults in code — so they stay changeable without a deploy):
   | Parameter | Type | Your value | Notes |
   |---|---|---|---|
   | `price_max` | int | `7000` | in EUR — see currency note below |
   | `price_currency` | string | `EUR` | which currency `price_max` is expressed in |
   | `year_min` | int | `2013` | |
   | `km_max` | int | `230000` | |
   | `engine_capacity_max` | decimal (liters) | `2.0` | e.g. `2.0` = 2000cc |
   | `body_type` | string, one of `sedan`/`break` | both allowed | "nothing else" per your message — `criteria:set` rejects any other value |
   | `brand` | string | *(not set)* | kept in the catalog for later, no default |
   | `model` | string | *(not set)* | kept in the catalog for later, no default |
   | `fuel_type` | string | *(not set)* | |
   | `transmission` | string | *(not set)* | |
   | `city` | string | *(not set)* | |

   **Currency note:** OLX and Autovit don't always display price in the same currency by
   default — you specifically said to switch OLX's listing to display EUR (Vehicles category →
   price filter set to EUR). That's a *scraper-time* concern (which URL/query-param makes the
   site show EUR, and confirming each scraped listing's price actually is EUR, not mixed) — it
   belongs in the next phase (scraper), not this schema step. This step just gives us
   `price_currency` as a stored, checkable value, and `listings.currency` per-row so a future
   listing scraped in a different currency doesn't silently get compared wrong.

4. **Two Artisan commands**, using Laravel 13's newer attribute-based command syntax
   (`#[Signature(...)]` / `#[Description(...)]` instead of the older `protected $signature`
   property — confirmed current in the 13.x docs):
   - `criteria:set {name} {value}` — checks `{name}` exists in the catalog (rejects unknown
     names with a clear error), then saves it.
   - `criteria:help` — prints a table (name, description, type, current value) for every catalog
     entry, so you always know what's settable and what's currently set.

5. **Tests (Pest).**
   - Listings: the unique constraint actually rejects a duplicate `(source, external_id)` pair;
     `photos` round-trips as an array through the JSON cast.
   - Criteria: `criteria:set budget_max 15000` persists and is readable back; setting an unknown
     parameter name fails with a clear message; `criteria:help` lists all catalog entries.

6. **Branch workflow (per `CLAUDE.md` rule 9).** Branch `feature/listings-schema` off
   `development`, commit with a specific message once tests pass, merge back with `--no-ff`,
   delete the branch.

## Files
- `database/migrations/..._create_listings_table.php` — new
- `database/migrations/..._create_search_criteria_table.php` — new
- `app/Models/Listing.php` — new — casts (`photos` → array, `notified_at` → datetime, `source` →
  enum), `HasMany`-style scopes come later once filtering exists
- `app/Models/SearchCriterion.php` — new — thin model over the key/value table
- `app/Enums/ListingSource.php` — new — backed string enum, `Autovit`/`Olx`
- `app/Support/CriteriaCatalog.php` — new — static catalog of parameter definitions
- `app/Console/Commands/CriteriaSet.php` — new
- `app/Console/Commands/CriteriaHelp.php` — new
- `tests/Feature/ListingTest.php` — new
- `tests/Feature/CriteriaCommandsTest.php` — new

## Best practices applied
- Laravel 13.x docs (Context7, checked today): migration column/index syntax (`$table->unique([...])`
  for composite uniqueness), Eloquent `casts()` method (not the older `$casts` property) including
  native PHP enum casting, and the new attribute-based Artisan command signature.
- Typed everything: enum for `source` (a closed, known set), typed model properties, typed command
  arguments — per `best-practices.md`.
- No secrets involved in this step, so rule 8 (`.env` guard) isn't in play here, but the branch/
  commit/merge workflow (rule 9) is followed throughout.

## Things to know / risks
- `body_type` only accepts `sedan`/`break` right now, per your instruction ("nothing else") — if
  you later want to allow more (hatchback, SUV, etc.), that's a one-line catalog change.
- `engine_capacity_max` is stored in liters (matches how you described it and how these sites'
  own filter UIs usually show it), while `listings.engine_capacity_cc` is in cc — the filtering
  logic (a later phase) will need to convert one to the other; noting it now so it isn't a
  surprise then.
- `transmission`/`fuel_type` are deliberately left as plain strings, not enums, until the scraper
  shows what raw values these sites actually produce — turning them into enums prematurely risks
  guessing wrong values that don't match reality.
- No price-history table yet (mentioned in the original handoff doc as a "nice to have"). Adding
  it now would be speculative before there's a scraper to populate it — proposing it as a later,
  separate plan once real price-change behavior is observed.
- This step alone doesn't do anything visible — no scraping, no emails. It's plumbing. The payoff
  shows up once the scraper (next planned step) has somewhere to write to.

## How we'll verify it works
- `php artisan migrate` runs both new migrations cleanly against the real SQLite DB.
- `php artisan test` — new Pest tests pass (unique constraint, JSON cast round-trip, criteria
  set/validate/help).
- Manually run `php artisan criteria:set budget_max 15000` then `php artisan criteria:help` and
  confirm the value shows up in the table output.
- `php artisan criteria:set nonsense_param 5` correctly errors instead of silently saving.

## Needs from you
Nothing blocking — say "go ahead" and I'll build this. One thing worth a sanity check: does
`price_max=7000` / `EUR` / `year_min=2013` / `km_max=230000` / `engine_capacity_max=2.0` /
`body_type=sedan,break` match what you meant, before it becomes the actual stored state of the
app?
