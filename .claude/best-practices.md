# Laravel best practices — Car Finder

Read this before writing any code in this project. It's a living document, not a one-time
snapshot: append to it (don't rewrite it) whenever a Context7 lookup surfaces a new relevant
convention for a library we're actually using. Each section notes when it was last checked
against current docs, so staleness is visible.

## Project baseline (checked via Context7, 2026-09-26)
- **Laravel 13.x**, **PHP 8.5**, **Pest 5** for testing — confirmed current via Context7 during setup.
- SQLite in WAL mode is configured through `config/database.php`'s native `busy_timeout` /
  `journal_mode` / `synchronous` keys (Laravel 13 supports these directly — no manual `PRAGMA`
  statements needed). Don't hand-roll a `DB::statement('PRAGMA ...')` boot listener; set the
  `.env` vars (`DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`) instead.

## Code style
- **Typed everything.** Property types, parameter types, and return types on every method —
  including `void`. PHP 8.5 supports it everywhere Laravel's own codebase uses it; untyped code
  is a lint failure here, not a style choice.
- **Constructor property promotion** for simple DTOs and service classes:
  ```php
  public function __construct(
      private readonly ListingRepository $listings,
      private readonly int $maxResults = 50,
  ) {}
  ```
- **`readonly` properties** wherever a value shouldn't change after construction (config objects,
  value objects, DTOs from scraper responses).
- **Small, single-responsibility classes.** A scraper class scrapes; a filter class filters; a
  notifier class notifies. If a class needs "and" to describe what it does, split it.
- **Enums, not string/int constants**, for fixed sets of values (e.g. `ListingSource::Autovit`,
  `ListingSource::Olx`) — native PHP enums, backed where the value needs to round-trip to/from
  the database.
- Follow **PSR-12** formatting; run `vendor/bin/pint` before considering code finished (Laravel
  ships Pint by default — it's already in `composer.json`).

## Laravel conventions
- **Form Requests for validation**, not inline `$request->validate()` in controllers, once a
  route has more than one or two rules:
  ```
  php artisan make:request StoreCriteriaRequest
  ```
  Type-hint it in the controller method; Laravel validates automatically before the method body
  runs.
- **Artisan commands live in `app/Console/Commands`**, one command = one responsibility (e.g. a
  `ScrapeAutovit` command and a separate `ScrapeOlx` command, not one combined "scrape everything"
  command). Keep the `handle()` method thin — delegate to a service class so the logic is
  testable without invoking the console.
- **Scheduling goes in `routes/console.php`** (or `bootstrap/app.php`'s `withSchedule()` for
  app-wide schedule config) — not inside the command classes themselves.
- **Config, not hardcoded values.** Anything that could plausibly change per environment (API
  endpoints, scrape intervals, thresholds) belongs in a `config/*.php` file reading from `.env`,
  never inline in a class.
- **`.env` for secrets, `.env.example` for placeholders** — every `.env` key must have a
  matching (empty/placeholder) entry in `.env.example` so the required configuration is
  self-documenting. Never commit real secrets to either file that gets tracked by git — only
  `.env.example` is tracked.
- **Eloquent models in `app/Models`**, one model per table, relationships defined as typed
  methods (`public function listings(): HasMany`).

## Testing (Pest)
- **Every new feature needs a test before it's considered done** — this is a standing project
  rule, not a suggestion. Run `php artisan test` (or `vendor/bin/pest`) before reporting work as
  finished.
- Prefer **Pest's functional syntax** (`test('...', function () { ... })` / `it('...')`) over
  PHPUnit-style test classes — it's what this project was scaffolded with.
- Use `RefreshDatabase` (or SQLite's speed advantage — an in-memory or throwaway file DB per test
  run) so tests never depend on leftover state from a previous run.
- For scraper code specifically: don't hit live OLX/Autovit in tests. Record a fixture response
  once (respecting the TLS 1.2 / `__NEXT_DATA__` parsing lessons in `lessons.md`), then test
  against the fixture. Live-network tests are flaky and slow, and risk the anti-bot blocks
  documented in `lessons.md`.

## Things to double-check with Context7 before relying on memory
- Any Guzzle/HTTP client config for the scrapers (retry, timeout, TLS options) — confirm current
  Guzzle API before writing.
- Laravel's `Schedule::command()` / `withSchedule()` syntax if it's been a while since last
  checked — scheduler API details are easy to misremember across versions.
- Mail/Notification class structure (`Mailable` vs `Notification` + `toMail()`) before building
  the daily-email feature — pick whichever fits, confirmed against 13.x docs, not older habits.
