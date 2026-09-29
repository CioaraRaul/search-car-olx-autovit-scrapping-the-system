# Plan: Reject listings whose model can't actually be the body type they claim

## Goal
You set `body_type=sedan,break` so you never see hatchbacks. A VW Polo — a model that has never been sold as a sedan or estate in this market — got through because OLX's body-type filter is just whatever category the *seller* picked when posting the ad, and this seller mis-tagged it "Berlina" (sedan). We can't fix OLX's data, but we can catch the specific, common case of a well-known hatchback-only model slipping through under a wrong self-reported tag.

## Background (what I found)
- I fetched the real listing page. Its embedded data reads `"Caroserie":"Berlina"` → `"normalizedValue":"sedan"`. That's the seller's own input, not a verified fact about the car.
- Our `OlxClient`/`AutovitClient` just forward your `body_type` criterion as a server-side filter param (`filter_enum_car_body` / `filter_enum_body_type`) and trust whatever the site returns — there's no independent check.
- This is the same category of gap already documented for damage/mileage self-reported fields, just showing up on body type.
- A fully general fix (verifying the *true* body shape of every model) isn't realistic without a car-model database. A **targeted heuristic** — a short list of models that are unambiguously never sold as sedan or estate here (VW Polo/Golf, Ford Fiesta, Renault Clio, Opel Corsa, Toyota Yaris/Aygo, Hyundai i10/i20, Kia Picanto/Rio, Seat Ibiza, Skoda Fabia, Peugeot 108/208, Citroën C3, Suzuki Swift) — catches this exact, common failure mode without false-positiving on models that legitimately come in multiple body styles (Golf Variant exists, so Golf stays off an "estate-only" list, but it's still hatchback-only, never sedan/estate-as-default... to be safe I'll keep the list to models with genuinely **no** sedan/estate factory variant sold in Romania).

## What I will do
1. Add a new config array `car_knowledge.body_type_mismatch.hatchback_only_models` — keyword groups (brand + model, matched like `KnownIssueEvaluator` already does) for the models listed above. Editable later without code changes, same pattern as the existing `reliability_rules` table for known engine issues, but this one is a fixed built-in list rather than DB-editable, since it's core domain knowledge rather than something you'd tune per-search.
2. Add `app/Services/Reliability/BodyTypeMismatchDetector.php` with one method, `isHatchbackOnlyModel(string $title): bool`, reusing the same "all keywords in a group must match" logic as `KnownIssueEvaluator`.
3. Wire it into **both** `ScrapeAutovit.php` and `ScrapeOlx.php`, right after the existing price filter and before the detail-page fetch (so a listing we're about to reject anyway never wastes a detail-fetch request against the daily cap):
   ```php
   if (isset($criteria['body_type']) && $detector->isHatchbackOnlyModel($attributes['title'] ?? '')) {
       $filteredByBodyTypeMismatch++;
       continue;
   }
   ```
   This mirrors the price filter's placement and reasoning exactly, not the `ReliabilityScorer` — it's enforcing your explicit search criterion (like `price_max`), not judging the car's reliability, so it belongs alongside the other criteria filters rather than inside the scoring engine. It only activates when you've actually set a `body_type` restriction; if you clear that criterion, hatchbacks are allowed again immediately, no config change needed.
4. Add the new counter to each command's summary output line (next to the existing `filtered by price` count) so a scrape run's console output shows how many were caught this way.
5. Tests: unit tests for `BodyTypeMismatchDetector` (VW Polo title → true; a sedan-only title → false; a title with no match → false), and one feature test per scraper command confirming a fixture listing titled "Volkswagen Polo ..." is silently skipped when `body_type=sedan,break` is set, and passes through untouched when no `body_type` criterion exists.

## Files
- `config/car_knowledge.php` — changed — new `body_type_mismatch.hatchback_only_models` array
- `app/Services/Reliability/BodyTypeMismatchDetector.php` — new
- `app/Console/Commands/ScrapeAutovit.php` — changed — new filter step + counter
- `app/Console/Commands/ScrapeOlx.php` — changed — new filter step + counter
- `tests/Unit/BodyTypeMismatchDetectorTest.php` — new
- `tests/Feature/ScrapeAutovitCommandTest.php` / `ScrapeOlxCommandTest.php` — changed — new cases
- `CHANGELOG.md` — changed — entry explaining the gap and the fix

## Best practices applied
- Reuses the existing keyword-group matching convention (`KnownIssueEvaluator`) instead of inventing a new matching style.
- Keeps the filter symmetric across Autovit and OLX by default, per your standing instruction that every car-quality/criteria check applies to both sites unless you say otherwise.
- Filters before the detail-page fetch, not after, so a rejected listing never consumes part of the daily detail-fetch budget — consistent with how the price filter is already ordered.

## Things to know / risks
- This is a heuristic, not a guarantee. It only catches the specific models on the list — a hatchback under an obscure or rare nameplate not on the list would still slip through mislabeled. I'll keep the list conservative (only genuinely hatchback-only models) to avoid ever wrongly rejecting a real sedan/estate.
- It does not retroactively remove the Polo (or similar) listings already sitting in your inbox/DB — only affects new scrapes going forward, same as every other filter added this session, unless you want a one-off cleanup pass.
- The list will need occasional manual additions as you notice more mislabeled models — I can add to it any time you report one, the same way this one came about.

## How we'll verify it works
- `php artisan test` — all existing tests plus new ones passing.
- Manually check the VW Polo listing's title against `BodyTypeMismatchDetector` directly to confirm it flags true.

## Needs from you
Nothing — just your go-ahead to implement this as described. If you'd rather have this be a full hard-reject reliability flag (visible in the digest with a reason) instead of a silent scrape-time skip, let me know and I'll adjust the design before I start.
