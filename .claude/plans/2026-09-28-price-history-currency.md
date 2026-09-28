# Plan: Chapter 3 — Price history + normalized comparison currency

## Goal
Track every price a listing has ever had (so we can later show "price dropped" in
notifications), and compute a `price_eur` value on every listing so cars priced in RON and
cars priced in EUR can be filtered/sorted on one consistent scale, using the National Bank
of Romania's (BNR) official daily exchange rate.

## Important correction to ROADMAP.md
ROADMAP.md names `https://www.bnr.ro/nbrfxrates.xml` as the rate source. I checked it live
(PowerShell `Invoke-WebRequest`, following redirects) and it now returns BNR's HTML homepage,
not XML — BNR moved this feed during a site redesign. I searched for the current URL and
verified it directly: **`https://curs.bnr.ro/nbrfxrates.xml`** (subdomain `curs.bnr.ro`)
returns `Content-Type: text/xml` with today's real rates. I'll build against that URL and
put it in `config/exchange_rates.php` (overridable via `.env`) rather than hardcoding either
URL in a class, so if BNR moves it again it's a one-line `.env` fix, not a code change. I'll
also update the note in `ROADMAP.md`/`CHANGELOG.md` once this ships.

Confirmed feed shape (fetched live today):
```xml
<DataSet xmlns="https://www.bnr.ro/xsd" ...>
  <Body>
    <Subject>Reference rates</Subject>
    <OrigCurrency>RON</OrigCurrency>
    <Cube date="2026-09-28">
      <Rate currency="EUR">5.2786</Rate>
      <Rate currency="USD">4.6397</Rate>
      <Rate currency="HUF" multiplier="100">1.4350</Rate>
      ...
    </Cube>
  </Body>
</DataSet>
```
Every `<Rate>` is "how many RON for one unit of that currency" — except currencies with a
`multiplier` attribute, where it's "how many RON for `multiplier` units" (e.g. 100 HUF), so
the per-unit rate is `value / multiplier`. EUR/USD/RON-adjacent currencies have no multiplier
(treated as 1).

## What I will do

1. **Add a `price_eur` column to `listings`.** A new migration
   `add_price_eur_to_listings_table` adds `price_eur` as a nullable `decimal(10, 2)` — decimal
   (not integer, unlike the existing `price` column) because it's a *computed* conversion and
   should keep cents precision even though the source `price` columns don't. Nullable because
   it can't be filled in until the exchange rate has been fetched at least once.

2. **Create the `listing_price_changes` table.** A new migration
   `create_listing_price_changes_table` with: `id`, `listing_id` (`foreignId` →
   `listings.id`, `cascadeOnDelete()` — if a listing is ever deleted, its price history goes
   with it), `price` (`unsignedInteger`, same shape as `listings.price`), `currency`
   (`string(3)`), `recorded_at` (`timestamp` — when this price was observed, distinct from
   `created_at`/`updated_at` which are just row bookkeeping), plus standard `timestamps()`.
   Composite index on `(listing_id, recorded_at)` since the main query pattern is "latest
   price for this listing."

3. **Two new small models**, one responsibility each (per `best-practices.md`):
   - `App\Models\ListingPriceChange` — `belongsTo(Listing::class)`, casts `recorded_at` to
     `datetime`.
   - `App\Models\Listing` gets a new `priceChanges(): HasMany` relation, and `price_eur` added
     to its fillable list and cast to `decimal:2`.

4. **`config/exchange_rates.php`** — new config file:
   ```php
   return [
       'bnr_url' => env('BNR_RATES_URL', 'https://curs.bnr.ro/nbrfxrates.xml'),
       'cache_ttl' => env('BNR_RATES_CACHE_TTL', 86400), // 24h, in seconds
       'cache_key' => 'bnr_exchange_rates',
   ];
   ```
   `.env.example` gets matching placeholder lines (`BNR_RATES_URL`, `BNR_RATES_CACHE_TTL`),
   per the "config, not hardcoded values" rule in `best-practices.md`.

5. **`App\Services\ExchangeRates\BnrExchangeRateService`** — the only class that knows about
   BNR's XML format:
   - `rates(): array<string, float>` — `Cache::remember()`s the parsed `{currency => RON per
     unit}` map for `cache_ttl` seconds (so BNR is hit at most once a day, per ROADMAP.md,
     not once per listing). Fetches via Laravel's `Http` facade, parses with PHP's built-in
     `simplexml_load_string` (no new Composer package needed — this is core PHP), divides each
     `<Rate>` by its `multiplier` attribute when present.
   - `toEur(int|float $amount, string $currency): float` — `EUR` is a passthrough; anything
     else is converted to RON first via that currency's rate, then to EUR via the RON/EUR
     rate. Throws a plain `\RuntimeException` if the feed can't be fetched/parsed *and*
     nothing is cached yet — deliberately not a custom exception class, since there's exactly
     one place that needs to catch it (next step) and one failure mode to describe.

6. **`App\Services\Listings\PriceHistoryRecorder`** — the "record if price changed" hook
   ROADMAP.md asks for, so either scraper can call one line after it fetches a listing:
   - `recordIfChanged(Listing $listing, int $price, string $currency, ?CarbonInterface
     $observedAt = null): void`
   - Looks up the listing's latest `ListingPriceChange` (by `recorded_at`); if none exists yet,
     or its `price`/`currency` differ from what was just observed, inserts a new
     `ListingPriceChange` row. This also means the very first time a listing is seen, its
     initial price is recorded as history automatically — no separate "seed" step.
   - Separately (regardless of whether the price changed — the EUR rate itself can move day to
     day even when the listing's price doesn't), recomputes `price_eur` via
     `BnrExchangeRateService::toEur()` and saves it on the listing. If the rate service throws
     (BNR unreachable, no cache yet), this is caught here, logged as a warning via `Log::
     warning()`, and skipped — a single BNR outage shouldn't stop a whole scrape run from
     recording listings, it just leaves `price_eur` stale/null until the next successful run.
   This class does **not** touch `listings.price`/`listings.currency` themselves — that stays
   the scraper's job (its own `updateOrCreate` upsert); this class only owns price history and
   `price_eur`, keeping it usable standalone (as ROADMAP.md requires) and unit-testable without
   a scraper existing.

## Files
- `database/migrations/2026_09_28_190000_add_price_eur_to_listings_table.php` — new
- `database/migrations/2026_09_28_190001_create_listing_price_changes_table.php` — new
- `app/Models/ListingPriceChange.php` — new
- `app/Models/Listing.php` — changed: `price_eur` fillable + cast, `priceChanges()` relation
- `config/exchange_rates.php` — new
- `.env.example` — changed: `BNR_RATES_URL`, `BNR_RATES_CACHE_TTL` placeholders
- `app/Services/ExchangeRates/BnrExchangeRateService.php` — new
- `app/Services/Listings/PriceHistoryRecorder.php` — new
- `tests/Feature/BnrExchangeRateServiceTest.php` — new
- `tests/Feature/PriceHistoryRecorderTest.php` — new
- `ROADMAP.md` — Chapter 3 entry deleted once merged (per this file's own rule)
- `CHANGELOG.md` — entry added: what was built + the BNR URL correction

## Best practices applied
- Typed properties/params/returns everywhere, constructor property promotion, `readonly` where
  values don't change after construction (Context7-checked project baseline in
  `best-practices.md`, last verified 2026-09-26 — no new Laravel APIs introduced here that
  need re-checking beyond what's below).
- Confirmed via Context7 (`/laravel/docs`, 13.x): `Cache::remember($key, $seconds, $callback)`
  signature; migration column builder methods (`unsignedInteger`, `decimal`, `foreignId`/
  `constrained`); `Http::fake()` + response sequencing for tests. HTTP client's raw-body
  access (`$response->body()`) and `decimal($column, $total, $places)` are stable, long-
  standing APIs I already know well and Context7 didn't surface anything that contradicts.
- Config-driven, not hardcoded (URL, TTL) — `best-practices.md` rule.
- No live network calls in tests — `Http::fake()` stubs the BNR XML response, per
  `best-practices.md`'s testing section.
- Small single-responsibility classes: the rate service only knows BNR's XML; the recorder
  only knows "did the price change / refresh price_eur." No premature interface — there's one
  exchange-rate source, so no `ExchangeRateSource` abstraction is introduced.

## Things to know / risks
- The BNR URL ROADMAP.md named is dead (confirmed live, see above) — using the verified
  working replacement instead, documented in code comments/config and in `CHANGELOG.md` so
  it's not a silent deviation.
- BNR is a real external dependency with no SLA guarantee. Mitigation here is minimal by
  design (log-and-skip on failure) since proper backoff/circuit-breaker/kill-switch handling
  is explicitly Chapter 9's job, not this chapter's — over-building resilience here would
  duplicate that later work.
- `price_eur` is nullable and can go stale if BNR is down for a while; that's an accepted
  trade-off for a twice-... now once-a-day hobby project, not silently ignored.
- SQLite foreign keys are enforced in this project (`DB_FOREIGN_KEYS=true` in
  `config/database.php`), so `cascadeOnDelete()` will actually behave as written, not just be
  a no-op annotation.

## How we'll verify it works
- `php artisan migrate` runs both new migrations cleanly against the SQLite dev database.
- `php artisan test` (Pest): new tests cover —
  - `BnrExchangeRateService`: parses a fixture XML (the real structure fetched today) via
    `Http::fake()`, correctly converts EUR (passthrough), RON, and a third currency (e.g. USD)
    to EUR; asserts the feed is fetched at most once across two calls within the cache TTL
    (`Http::assertSentCount(1)`).
  - `PriceHistoryRecorder`: first call on a fresh listing creates one history row and sets
    `price_eur`; a second call with the same price/currency creates no new row; a third call
    with a different price creates a second history row and updates `price_eur`; a call where
    the rate service is forced to fail leaves `price_eur` untouched instead of throwing.
- Manual spot check: `php artisan tinker` — call the service directly once against the real
  live URL (not part of the automated test suite) to confirm today's real EUR rate comes back
  sane (~5 RON/EUR).

## Needs from you
Nothing — every decision needed was already resolved by ROADMAP.md except the dead URL, which
I verified and replaced myself as described above.
