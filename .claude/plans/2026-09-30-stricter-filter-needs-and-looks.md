# Plan: Stricter filter — reliability ≥ 90, scam-mileage rejection, "my needs" fit, and looks

Split into 3 phases; each is its own branch/commit/test cycle. Nothing is built until you approve.

## Goal
Only cars that are (1) reliable (score ≥ 90), (2) not obviously scams/typos, (3) right for *your*
use (`car-buyer-profile`: short Oradea trips, Tulca once a week, cheap RCA/tax/parts) and (4)
good-looking end up in the 22:00 email.

## What I found (why the current filter lets junk through)
- `reject_below_score` is 60 and the scorer is **penalty-only** (everything starts at 100). Result
  in the DB today: **1383 of 1522 listings already score ≥ 90**, only 139 score lower. Simply
  raising the threshold to 90 would remove almost nothing. The scorer barely discriminates because
  only **3 known-issue rules** exist (VW-group TDI EA189, Ford 1.6 TDCi Powershift, BMW N47).
- **Mileage scam/typo:** 10 saved listings have `mileage_km < 1000` (e.g. 2014 Dacia Logan at
  53 km, 2015 Mondeo at 270 km, 2013 Jetta at 280 km). The current `LowMileageEvaluator` only
  takes 15 points off, so they stay at 85 and pass.
- **Needs data is missing:** 872 of 1522 rows have **no fuel type** (OLX search results never give
  fuel, engine size, horsepower, body type or transmission — `OlxListingMapper` sets them to
  null). A "fits my needs" check can't work on OLX until we fill those from the ad's own page
  (`OlxDetailFetcher` already fetches it for damage/consumption).
- **Your body_type setting conflicts with your profile:** criteria are `sedan,break`, but the
  profile says the best fit is a small/compact hatchback (and the last filter we built *rejects*
  hatchback-only models like Polo, Clio, Yaris, i20).
- **Looks:** the scrapers run unattended in Task Scheduler. A Claude Code *skill* only runs inside
  a Claude session, so it cannot judge photos at 07:00 by itself. Automatic photo analysis would
  need a vision-model API (paid → breaks "must stay free").

## Phase 0 — Never let a wrong body type through again (added 2026-09-30, build FIRST)
**Why the BMW Seria 2 passed (3 listings in the DB: ids 1414, 1590, 2831):**
1. The current body-type check is a **blacklist** of 23 hatchback-only models (Polo, Clio, ...).
   BMW is not on it, and a Seria 2 is never a sedan/estate (it's a coupe, Active Tourer or
   Gran Tourer), so nothing stopped it. A blacklist can never be complete.
2. The sites' own `body_type` filter just trusts what the seller ticked. `body_type` is stored as
   **null** for every listing, so we never verify anything ourselves.
3. Nothing else caught it: no premium-brand rule (Phase 2), no known-issue rule, so it scored 100.
4. Same ad posted on both OLX and Autovit (ids 1414 and 2831: same price, km, Autovit id
   `ID7HMb8O`) → it would be emailed twice.

**Fix — flip to a whitelist:** a car is accepted only if its make+model is on a
`sedan_or_estate_models` list (Skoda Octavia/Superb/Rapid sedan..., Dacia Logan/Logan MCV,
Toyota Corolla/Avensis, Honda Civic sedan/Accord, Hyundai Elantra/i30 wagon, Kia Ceed SW, Mazda 3/6,
Ford Focus/Mondeo estate, Opel Astra/Insignia, VW Passat/Jetta/Golf Variant, ...) — **unknown or
unlisted model = rejected**, with the reason logged. Applied at scrape time before the detail
fetch, and also when `notify:send` builds the digest. Also: a title keyword check (e.g. "coupe",
"hatchback", "Active Tourer", "Gran Coupe", "Sportback", "5 usi") rejects regardless.
Plus: read the body type from the ad's detail page where available (OLX "Caroserie", Autovit
param) and store it in `body_type`, so the stored value is finally real. Plus a de-duplicate step
so a car cross-posted on both sites is one listing. Existing rows: re-run the new check over all
1522 and hide the failures from the email.
Decision for you: whitelist (strict, may miss an unlisted-but-valid model until I add it; misses
are visible in a "rejected: unknown model" counter so I can extend the list) — **recommended**.

## Phase 1 — Scam mileage + real reliability ≥ 90 (build first)
1. **Hard reject for implausible mileage.** New `ImplausibleMileageEvaluator`: mileage below a
   configurable floor (default **1,000 km**) on a car older than 1 year → penalty 100 (auto
   reject, like the damaged-car rule). Catches "350" meant as "350k". Also decide what to do with
   missing mileage (proposal: reject, since we can't verify it). Replaces the soft 15-point case
   for that range; the existing ratio rule stays for "low but plausible" (e.g. 20k km on a 2012).
2. **Threshold 60 → 90** (`CAR_KNOWLEDGE_REJECT_BELOW_SCORE`, default in `config/car_knowledge.php`).
3. **Make the score meaningful.** Because 90 means "at most one 10-point flag", the rules must
   actually fire. Two changes, pick in "Needs from you":
   - (a) expand `reliability_rules` from 3 to a researched list of problem engines/gearboxes
     (dry DSG DQ200, PureTech 1.2 wet belt, Renault 1.2 TCe, Opel/Fiat 1.3 CDTI, THP 1.6, etc.)
     via the `used-car-evaluator` method + web research, each with year ranges; **and/or**
   - (b) an **"unvetted model" penalty**: a car whose make/model isn't on a short vetted list
     (Toyota, Honda, Dacia Logan/Sandero LPG, Suzuki, Skoda Fabia/Octavia petrol, Hyundai/Kia
     petrol, etc.) loses e.g. 15 points, so only recognised-reliable cars can reach ≥ 90.
4. **Gate the email.** `ListingsDigestNotifier` only sends listings with `reliability_score ≥ 90`
   (defence in depth — also covers the 139 already-saved lower ones and future threshold changes).
5. **Rescore + clean existing data.** Run `reliability:rescore --all` after the change (fix its
   input array first: it currently doesn't pass `is_damaged`, consumption or seller year, so a
   rescore would *wrongly raise* scores). Listings that fall below 90 are kept but never emailed
   (not deleted — reversible).
6. Tests: unit per evaluator, scorer integration, notifier test excluding < 90; CHANGELOG entry.

## Phase 2 — "Only cars for my needs" (`needs_fit`)
1. New `NeedsFitEvaluator` (config in `config/car_knowledge.php` → `needs_fit`) implementing the
   `car-buyer-profile` rules as deterministic PHP, applied as a **hard filter** like price:
   - Fuel: petrol / petrol+LPG / hybrid pass; **diesel rejected** (DPF + short trips) unless you
     say otherwise.
   - Engine ≤ 1.6 L and ≤ ~130 HP (RCA + road tax); > 150 HP rejected.
   - Size/brand: reject large cars and premium brands with expensive parts (BMW/Audi/Mercedes/
     Volvo/Land Rover list in config).
   - Price ≤ budget minus ~10–15% reserve (your `price_max` 7000 → effective ~6000–6300).
   - Transmission: reject dry dual-clutch; manual or torque-converter auto pass.
2. **Fill the missing data on OLX** by extending `OlxDetailFetcher` to read fuel, engine cc, HP,
   body type and transmission from the ad page (already fetched once per listing, reused after).
   Unknown value after that = not rejected but flagged "unverified" (so we don't discard good
   cars because of a parsing gap) — confirm in "Needs from you".
3. New column `needs_fit` (+ reasons JSON) on `listings`; shown in the digest.
4. Tests + CHANGELOG.

## Phase 3 — "Looks good"
Recommended split, because unattended scoring of photos isn't free:
1. **Free, automatic:** a `style` tag on the curated model list (e.g. "modern/clean", "dated") plus
   cheap proxies (≥ 6 photos, facelift/newer trim keywords). Adds a small bonus/penalty and shows
   in the email. Subjective by nature — you'd review the list of tags.
2. **New skill `car-looks-evaluator`** (`.claude/skills/car-looks-evaluator/SKILL.md`): when you
   run it in Claude Code on the latest digest shortlist, I download each car's photos and judge
   exterior condition (paint/panel gaps/rust/dents), interior cleanliness, and overall
   presentability for going out with your girlfriend; output `looks_score` 0–100 with reasons,
   pairing with `used-car-evaluator` and `car-buyer-profile`. Manual, free, run on the ≤ ~10 cars
   that already passed Phases 1–2.
3. (Optional, **not recommended**) automatic vision scoring at scrape time via a paid API.

## Files (overview)
- `config/car_knowledge.php` — changed (threshold, mileage floor, needs_fit, vetted models)
- `app/Services/Reliability/Rules/ImplausibleMileageEvaluator.php`, `NeedsFitEvaluator.php` — new
- `app/Services/Reliability/ReliabilityScorer.php`, `app/Console/Commands/RescoreListings.php` — changed
- `app/Services/Scraping/OlxDetailFetcher.php`, `OlxListingMapper.php`, `ScrapeOlx.php`/`ScrapeAutovit.php` — changed
- `app/Services/Notifications/ListingsDigestNotifier.php`, digest mail view — changed
- migrations: `needs_fit` (+ reasons) columns; seeder for extra `reliability_rules`
- `.claude/skills/car-looks-evaluator/SKILL.md` — new; tests; `CHANGELOG.md`

## Best practices applied
Existing evaluator interface + config/env pattern; hard filters before the detail fetch to save the
daily budget; migrations for schema; tests for every rule (`php artisan test`). No new libraries,
so no Context7 lookup is needed for Phase 1–2 (plain PHP/Eloquent already in use); Phase 2's OLX
parsing will follow the existing `OlxDetailFetcher` approach after inspecting a live ad page.

## Things to know / risks
- **Much stricter = far fewer emails**, possibly zero some days. That's the intent, but say if you
  want a "closest misses" section.
- Model reliability knowledge must be researched per engine/year, not from memory; I'll research
  each rule and cite it in the seeder.
- OLX markup can change; the extra fields are best-effort.
- A mileage floor of 1,000 km could wrongly reject a genuinely nearly-new car — unlikely at your
  ≤ 7000 EUR budget.
- Looks from photos can't be scored automatically for free; Phase 3 is semi-manual by design.

## How we'll verify
`php artisan test`; `reliability:rescore --all` then count listings ≥ 90 (expect a large drop from
1383); confirm the 10 sub-1000 km rows are rejected; run `notify:send` on a test DB copy
(after a WAL checkpoint, per lessons.md) and read the email.

## Needs from you
1. **Body type:** keep `sedan,break` only, or allow compact hatchbacks (profile's best fit)?
2. **Diesel:** reject all (profile advice), or allow exceptionally documented ones?
3. **Engine/power caps:** ≤ 1.6 L and ≤ 130 HP, or keep 2.0 L?
4. **Reliability approach:** (a) more researched rules, (b) vetted-model whitelist, or both
   (I recommend both)?
5. **Missing data** (unknown fuel/engine after OLX fetch): reject or keep-but-flag?
6. **Missing mileage:** reject?
7. **Transmission, max age, max km, disliked brands, radius** from the profile's blank list.
8. **Looks:** OK with the free semi-manual approach (tags + skill) instead of automatic paid
   vision?
