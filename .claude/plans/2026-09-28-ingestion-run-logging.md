# Plan: Ingestion run logging (Roadmap Chapter 4)

## Goal
Give every scraper run (Autovit, OLX, or any future source) an auditable record in the database:
when it started/finished, how many pages it fetched, how many listings were new vs. updated, what
errors happened, and whether it succeeded — so unattended twice-daily runs aren't a black box. This
is infrastructure only: no scraper exists yet to wire it into, so it's built standalone and proven
with a fake/test command, ready for Chapters 1/2 to adopt later.

## What I will do

1. **Add an `IngestionRunStatus` enum** (`app/Enums/IngestionRunStatus.php`), a *backed enum* — a
   PHP enum whose cases each map to a stored scalar value (here, a string), so it round-trips
   cleanly through Eloquent's cast system the same way `ListingSource` already does. Cases:
   `Running`, `Success`, `Failed`, `Partial` (partial = finished but with some errors — e.g. a few
   pages failed but most listings were still captured). Why an enum and not raw strings: matches
   this project's own documented convention (`.claude/best-practices.md`: "Enums, not string/int
   constants, for fixed sets of values") and prevents typos like `'succes'` from silently creating
   a new status.

2. **Migration: `create_ingestion_runs_table`** (`database/migrations/..._create_ingestion_runs_table.php`).
   Columns, matching the exact shape the roadmap specifies:
   - `id()`
   - `source` (string) — which scraper ran (`'autovit'`, `'olx'`, ...). Stored as a plain string
     column like `listings.source`, cast to the existing `ListingSource` enum on the model — no
     need for a new enum here, it's the same concept.
   - `started_at` (timestamp) — when the run began.
   - `finished_at` (timestamp, nullable) — null while the run is still in progress; set when it
     ends. Nullable is what makes "is this run still running / did it crash without finishing"
     detectable.
   - `pages_fetched` (unsigned integer, default 0)
   - `listings_new` (unsigned integer, default 0)
   - `listings_updated` (unsigned integer, default 0)
   - `errors` (json, nullable) — a list of error message strings collected during the run. JSON
     because there can be zero or many, and we don't need to query inside them individually.
   - `status` (string, default `'running'`) — cast to the new `IngestionRunStatus` enum.
   - `timestamps()` — Laravel's standard `created_at`/`updated_at`, kept for consistency with
     every other table in this project (`listings`, `search_criteria`) even though `started_at`
     covers "when did this begin" — `created_at` is free, automatic, and other code/tools may
     expect it to exist.
   No foreign keys/relationships needed — a run doesn't reference specific `listings` rows, it's a
   per-execution summary.

3. **Model: `IngestionRun`** (`app/Models/IngestionRun.php`). `#[Fillable]` attribute listing every
   column above except `id`/timestamps (same pattern `Listing`/`SearchCriterion` already use — a
   PHP 8 *attribute* is metadata attached to the class via `#[...]` syntax, read by Laravel instead
   of the older `protected $fillable = [...]` array). `casts()` method: `source` →
   `ListingSource::class`, `status` → `IngestionRunStatus::class`, `errors` → `'array'` (Laravel's
   built-in cast that JSON-encodes/decodes automatically), `started_at`/`finished_at` → `'datetime'`.

4. **Service: `IngestionRunTracker`** (`app/Services/Ingestion/IngestionRunTracker.php`). A small,
   single-responsibility class (per `best-practices.md`) that any scraper Artisan command can use
   to open/update/close a run row, instead of every command hand-rolling the same Eloquent calls.
   Chose a *service class* over a *trait* (the roadmap says "trait/service", leaving the choice
   open): a trait would silently mix its own state (the current run's ID) into every command class
   that uses it, making that state harder to test in isolation; a small injectable service keeps
   the run-tracking logic in one testable place and the command just holds a reference to it.
   Methods:
   - `start(ListingSource $source): IngestionRun` — creates a row with `status = Running`,
     `started_at = now()`, all counters at 0, returns the model.
   - `incrementPages(IngestionRun $run, int $by = 1): void`
   - `incrementNew(IngestionRun $run, int $by = 1): void`
   - `incrementUpdated(IngestionRun $run, int $by = 1): void`
   - `recordError(IngestionRun $run, string $message): void` — appends to the `errors` array and
     persists.
   - `finish(IngestionRun $run, IngestionRunStatus $status): void` — sets `finished_at = now()` and
     the final `status`, saves.
   - `run(ListingSource $source, Closure $callback): IngestionRun` — convenience wrapper: calls
     `start()`, runs the callback with the `IngestionRun` passed in, calls `finish()` with
     `Success` if the callback completes, or `Failed` (recording the exception message via
     `recordError`) if it throws, then re-throws so the caller/command still sees the failure.
     This is the "wrap yourself with it" piece the roadmap describes — a future scraper command's
     `handle()` becomes essentially `$this->tracker->run(ListingSource::Autovit, function ($run) {
     ...scrape, calling $this->tracker->incrementPages($run) etc... });`.

5. **Tests** (`tests/Feature/IngestionRunTrackerTest.php`), using Pest + `RefreshDatabase` (project
   standard, confirmed in `CriteriaCommandsTest.php`/`ListingTest.php`). Since no scraper exists
   yet to test against, the tests exercise `IngestionRunTracker` directly:
   - `start()` creates a row with `status = Running`, `started_at` set, `finished_at` null, all
     counters 0.
   - the increment methods persist and accumulate correctly.
   - `recordError()` appends messages (not overwrites) and they round-trip as an array.
   - `finish()` sets `finished_at` and the given status.
   - `run()` marks the run `Success` when the callback completes normally.
   - `run()` marks the run `Failed`, records the exception message, and still re-throws the
     exception when the callback throws.

## Files
- `app/Enums/IngestionRunStatus.php` — new — backed enum: `Running`/`Success`/`Failed`/`Partial`.
- `database/migrations/2026_09_28_HHMMSS_create_ingestion_runs_table.php` — new — the
  `ingestion_runs` table described above.
- `app/Models/IngestionRun.php` — new — Eloquent model with fillable + casts.
- `app/Services/Ingestion/IngestionRunTracker.php` — new — the run-tracking service.
- `tests/Feature/IngestionRunTrackerTest.php` — new — covers all of the above.

## Best practices applied
- Backed PHP enum for a fixed status set, cast via the model's `casts()` method — confirmed against
  current Laravel 13.x docs via Context7 (`eloquent-mutators.md`: `protected function casts(): array
  { return ['status' => ServerStatus::class]; }`), and matches this project's existing
  `ListingSource` enum pattern exactly.
- `#[Fillable]` attribute on the model, matching `Listing`/`SearchCriterion` (this project's already
  -established convention, not the older `protected $fillable` array style).
- Typed properties/parameters/returns everywhere, per `best-practices.md`'s "Typed everything" rule.
- Small single-responsibility service class rather than a fat trait or logic embedded in a command,
  per `best-practices.md`'s "small, single-responsibility classes" rule.
- Migration column choices (`unsignedInteger` with defaults, `json` for a variable-length list,
  nullable `finished_at`) follow the same style already used in `create_listings_table.php`.
- Pest functional tests with `RefreshDatabase`, matching every existing test file in this project.

## Things to know / risks
- This chapter builds infrastructure only — nothing calls `IngestionRunTracker` yet, since no
  scraper command exists in `development` yet (Chapters 1/2 are still unmerged). The roadmap
  explicitly allows this ("buildable standalone, wired into scrapers later"). Tests prove the
  service works correctly in isolation, not that it's wired into a real scrape.
- `Partial` status is defined now but nothing sets it yet — a future scraper's own error-handling
  logic decides when a run counts as "succeeded with some errors" vs. "failed outright"; that
  decision belongs to Chapter 1/2's plan, not this one.
- `errors` stores plain strings, not structured data (no stack traces/codes) — matches the
  roadmap's plain `errors` column name; can be revisited if a future chapter needs more structure.

## How we'll verify it works
1. `php artisan migrate` — confirms the migration runs cleanly against the SQLite dev database.
2. `php artisan test` (all tests, not just the new file) — confirms the new feature test passes
   and nothing existing broke.
3. Manual sanity check via `php artisan tinker`: create a run with the tracker, inspect the row,
   confirm enum casting round-trips (`IngestionRun::first()->status instanceof IngestionRunStatus`).

## Needs from you
Nothing — every decision this chapter needs (trait vs. service, status cases, column shapes) is
either fixed by the roadmap or decided above using this project's existing conventions.
