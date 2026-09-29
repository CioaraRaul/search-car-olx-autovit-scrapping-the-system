# Plan: Damage/fuel-consumption checking for OLX too

## Goal
The damage/high-consumption rejection built earlier today only worked for Autovit, because that's
the only site with a structured field for it. Per explicit instruction — every car-quality check
should apply to both sites unless told otherwise — bring OLX to parity using the data OLX actually
has: free-text descriptions, checked with a keyword matcher instead of a structured field.

## What I found (research)
- Sampled 12+ real OLX ad pages today. Two concrete findings:
  - One listing's **title** stated damage outright: *"Renault Talisman ... // Avariat Lovit
    Spate"* — some sellers self-disclose right in the title, which is already scraped for every
    listing at zero extra cost.
  - One listing's **description** (a dealer ad, `GARANTIE 12 LUNI` / `Posibilitate Finanțare`)
    contained a full spec block: *"Consumul de combustibil - urban 5.8 l/100km ... extra-urban 4.3
    l/100km ... mixt 4.9 l/100km"* — same labels Autovit uses, plus a **combined ("mixt") figure
    Autovit doesn't even give directly** (Autovit only has urban/extra-urban separately, averaged).
    Clearly copy-pasted from a spec sheet the seller also uses elsewhere (maybe the same car is
    cross-posted on Autovit) — not universal, but real and parseable when present.
- **robots.txt allows fetching individual OLX ad pages** — checked live via `RobotsTxtGuard`,
  same as Autovit.
- **The real risk is false positives, not missed detections.** Romanian ads routinely say things
  like *"fără accident"* (no accident) or *"neaccidentată"* — a naive substring match on
  "accident" would flag those as damaged, rejecting good, honestly-described cars. PHP's PCRE2
  supports variable-length lookbehind in principle, but it's fragile to rely on for this. The
  robust fix: **strip known negation phrases out of the text first** (`fara accident`, `fără
  accident`, `neaccidentat(a)`, `neavariat(a)`, `fara/fără daune`, `fara/fără avarii`, `nu a fost
  accidentat(a)`), then check what's left for damage keywords (`avariat(a)`, `accidentat(a)`,
  `lovit(a)`, `daune majore`, `avarii`). Simple, testable, no regex lookbehind needed.
- **`is_damaged`/`fuel_consumption_l_100km`/`detail_checked_at` are already generic columns on
  `listings`**, not Autovit-specific — and `DamagedVehicleEvaluator`/`HighFuelConsumptionEvaluator`
  already just read those two fields regardless of source. **No evaluator changes needed at all** —
  only OLX needs to start populating the same two fields, the same way Autovit already does.

## What I will do
1. **`App\Services\Scraping\OlxDetailFetcher`** (new, mirrors `AutovitDetailFetcher`'s shape) —
   `fetch(string $url): array{damaged: ?bool, fuelConsumptionL100km: ?float}`. Fetches the ad's own
   page (through `RobotsTxtGuard`), extracts `[data-testid="ad_description"]` text with embedded
   `<style>` tags stripped first (hit this bug live during research — emotion CSS-in-JS pollutes
   naive text extraction), then:
   - Damage: strip negation phrases, then check for damage keywords in what's left. `null` if
     neither an explicit clean-phrase nor a damage-phrase is found (most ads simply don't mention
     it — that's "unknown," not "confirmed clean").
   - Consumption: regex for a `mixt`/`mixed` figure first (preferred — it's already combined);
     falls back to averaging urban + extra-urban if only those are present, same as
     `AutovitDetailFetcher`. `null` if none are present (most ads).
2. **`ScrapeOlx::handle()`** — add the same detail-fetch step as `ScrapeAutovit` has, in the same
   position (after the price filter, before reliability scoring), **also checking the listing's
   title first** via the same keyword matcher before deciding whether the detail-page fetch is
   even worth it (title-only self-disclosure like the "Avariat Lovit Spate" case needs no HTTP
   request at all). Same four safeguards as Autovit, mirrored exactly: random 3–8s delay, a daily
   cap, skipping listings already checked (reuses the existing generic `detail_checked_at`
   column), and stopping the run immediately on a 403/429.
3. **`config/scraping.php`** (`olx` array) — the same three keys Autovit has:
   `detail_fetch_delay_min_ms` (3000), `detail_fetch_delay_max_ms` (8000),
   `detail_fetch_daily_cap` (100).
4. **Tests**: `OlxDetailFetcherTest` (fixture-based — clean description untouched, "fără accident"
   correctly NOT flagged, "avariată" correctly flagged, mixed-consumption regex, urban/extra-urban
   fallback), plus the same 4 safeguard tests added to `ScrapeOlxCommandTest` that
   `ScrapeAutovitCommandTest` already has (daily cap, reuse, immediate-stop-on-403, non-403/429
   still fails loudly), plus a title-only-disclosure test proving no HTTP request happens when the
   title alone already answers the question.
5. **`.env.example`** — the three new OLX keys.
6. **`CHANGELOG.md`** entry, explicit about the weaker/best-effort nature of free-text detection
   versus Autovit's structured field.
7. Branch `feature/olx-damage-fuel-parity`, test, merge `--no-ff`, push.

## Files
- `app/Services/Scraping/OlxDetailFetcher.php` — new
- `app/Console/Commands/ScrapeOlx.php` — changed
- `config/scraping.php` — changed
- `tests/Unit/OlxDetailFetcherTest.php` — new
- `tests/Feature/ScrapeOlxCommandTest.php` — changed
- `.env.example` — changed
- `CHANGELOG.md` — changed

## Things to know / risks
- **Free-text detection is inherently weaker than Autovit's structured field.** It only catches
  what a seller happened to write, in a form the matcher recognizes. A damaged car whose seller
  says nothing about it won't be caught (stays `null`, not flagged) — this narrows the gap with
  Autovit, it doesn't close it.
- **Negation handling is a fixed phrase list, not real language understanding.** Uncommon phrasings
  could still slip through in either direction. Documented as a known limitation, not silently
  presented as equivalent to Autovit's reliable structured data.
- **Same load consideration as Autovit's version**: one extra request per listing that survives
  price filtering and isn't already resolved by title alone or a cached prior check — same four
  safeguards apply for the same reason.

## How we'll verify it works
- `php artisan test` — new tests plus full existing suite.
- Manual: run `scrape:olx` for real afterward and spot-check a few listings'
  `is_damaged`/`fuel_consumption_l_100km` against their real OLX pages, including trying to find a
  listing similar to the "Avariat Lovit Spate" one to confirm it gets rejected end-to-end.

## Needs from you
Nothing blocking — this directly follows your instruction to make damage/fuel-consumption checks
apply to both sites, using OLX's own available data (free text) rather than something that doesn't
exist there.
