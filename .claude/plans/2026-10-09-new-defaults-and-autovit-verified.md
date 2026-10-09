# Plan: New default limits + Autovit "verified" marker

## Goal
Change the default search limits (engine min 1.3 L, mileage max 250,000 km, price max 7,500 EUR) and
give Autovit ads a "verified / not verified" signal: verified ads keep 100 points, unverified ads get
a warning triangle in the email and score 90.

## What I will do
1. **Limits.** Engine minimum 1.3 L: `min_engine_cc` default 1140 -> 1240 cc (real 1.3 engines report
   1248-1299 cc; same margin trick as before). Mileage and price live in the database as saved
   search criteria, so I set them with `php artisan criteria:set km_max 250000` and
   `price_max 7500` (no code change). I will also update the changelog/docs that quote old numbers.
2. **Autovit "verified".** Read the verification badge from the ad page (`AutovitDetailFetcher`, same
   `__NEXT_DATA__` JSON it already parses), store it in a new nullable `autovit_verified` column on
   `listings`, and add a reliability rule `UnverifiedAutovitEvaluator` that deducts 10 points
   (100 -> 90) from an Autovit ad that is not verified. 90 equals the reject threshold, so it still
   passes alone, but any additional flag will reject it. OLX ads are untouched.
3. **Triangle.** The email row for an unverified Autovit ad gets a warning triangle with a short
   "not verified by Autovit" note.

## Files
- `config/car_knowledge.php` — changed — min engine default, new `unverified_autovit` penalty (10)
- `app/Services/Scraping/AutovitDetailFetcher.php` — changed — read the verified badge
- `database/migrations/..._add_autovit_verified_to_listings_table.php` — new
- `app/Services/Reliability/Rules/UnverifiedAutovitEvaluator.php` — new, registered in the scorer
- `app/Console/Commands/ScrapeAutovit.php`, `app/Models/Listing.php` — changed — save the field
- `resources/views/mail/listings...` — changed — triangle
- tests for each, `CHANGELOG.md`

## Best practices applied
Same patterns as the existing damage / seller-age rules (evaluator class + config penalty + migration
column). Docs to be checked via Context7 before coding (Laravel migrations, Mailables).

## Things to know / risks
- I have NOT yet confirmed what Autovit calls "verified" in its page data — I need to inspect a live
  ad (the network has been flaky: OLX/Autovit connection errors in the logs).
- Lowering strictness (price up to 7,500, km up to 250k) will make the 10-01 stored rows and future
  scrapes include more cars; existing rows are re-checked by the current rules automatically.
- Already-saved Autovit rows have no verified data (null) and would be treated as "unknown", not
  penalised, until re-checked.

## How we'll verify it works
`php artisan test`; `criteria:help`/DB shows new values; a live Autovit ad parsed for the badge;
`notify:send` dry check of the email view.

## Needs from you
What does "verified" mean for you on Autovit — the "Anunț verificat / Verified by Autovit" badge on the
ad, or the verified-seller / "Autovit Verified car" label? Please confirm (or send a verified ad link).

---

# Addition: two-stage check — model first, then the ad

## Goal
For every car found: (1) first decide whether that specific model (make + model + engine) has a good
reliability reputation; only if it does, (2) decide whether the post looks serious rather than a scam.

## What I will do
1. **Stage 1 — model reputation (before any ad page is fetched).** A new DB-editable table
   `model_reputations` (make, model, optional engine/years, verdict: recommended / acceptable / avoid,
   reason), seeded by me from the `used-car-evaluator` knowledge and the buyer profile. A model marked
   "avoid" is dropped right at the search-results step, so it costs none of the 100 daily ad-page
   fetches; "acceptable" passes with a small penalty; models not in the table are kept but flagged
   "unknown model" for review. This extends today's `KnownIssueEvaluator`, which only matches bad-engine
   keywords, not whole models.
2. **Stage 2 — serious ad / scam check (only for models that passed).** Reuses the existing signals
   (too-cheap price, implausible/low mileage, new seller account, damaged flag) and adds scam text
   patterns in the description (asks for advance payment/transfer, "abroad/shipping", only WhatsApp,
   price in ad title far below market, stolen-photo style wording). The Autovit verified marker from the
   plan above becomes one more signal here.
3. Email shows the model verdict and any scam warnings per car.

## Risks
- The model list is judgement-based, not live data; I'd seed it conservatively and you can edit it.
- Scam text patterns are heuristics: they raise suspicion, they can't prove a scam.

## Needs from you
Should an unknown model (not in my table) be (a) kept with a "model not reviewed" note, or (b) dropped?
I recommend (a).

---

# Decisions (2026-10-09)
1. **Unknown / missing model -> dropped.** An ad with no identifiable make+model is never saved. I will
   classify every model sold in Romania in the price range (not just a short list) and only model
   names I cannot place are dropped (listed in the scrape output so you can see what was skipped).
2. **Autovit "verified" = the "verified details" label on the ad.** Verified details -> 100 points;
   missing -> warning triangle in the email and 90 points. First implementation step: inspect a live
   ad's `__NEXT_DATA__` to find the exact field (not yet confirmed), and show you before coding on it.
