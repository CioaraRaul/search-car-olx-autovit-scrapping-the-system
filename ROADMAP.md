# Car Finder — Implementation Roadmap

Each chapter below is a self-contained unit of work: it lists what it depends on, and every
chapter is written to depend only on what's **already merged into `development`** — never on
another chapter in this file. That's deliberate, so any chapter can be picked up and implemented
on its own, by any session, in any order, without waiting on the others.

**How this doc is used:** say "do chapter N" and only that chapter gets built. Starting a chapter
still goes through the normal process in `CLAUDE.md` (plan-first → branch → test → changelog →
merge → push `development` to origin) — this file is the menu, not a substitute for planning each
one properly. Every open decision each chapter needs has already been made below — starting one
shouldn't require asking a question first; the remaining "research" noted in some chapters is
implementation discovery (e.g. reading a page's real HTML), not a decision left for you to make.
When a chapter is finished and merged into `development`, **its entry gets deleted from this
file** — the permanent record lives in `CHANGELOG.md` and the branch's plan file under
`.claude/plans/`, so nothing is lost, this file just always shows what's left.

Credit where due: the shape of several chapters below (incremental/polite crawling, run logging,
price history, resilience flags) was adapted from a reference ingestion plan the user shared,
originally written for a different stack (NestJS/BullMQ/Redis/Postgres/React). The architectural
ideas carried over; the tech choices didn't — see `CLAUDE.md`'s "Decided stack" for why (SQLite
not Postgres, no Redis/queue infra since this must stay free and only runs twice a day, PHP/
Laravel not Node, no frontend yet).

---

## Chapter 2 — OLX scraper
**Status:** not planned yet, but the approach is decided. OLX renders listings as real HTML
(`data-testid="l-card"` elements, confirmed live — no `__NEXT_DATA__`/JSON blob like Autovit), so
this uses `symfony/dom-crawler` + `symfony/css-selector` (added via Composer) instead of JSON
parsing. Reuses `App\Services\Scraping\RobotsTxtGuard` — already built and merged as part of the
Autovit scraper, general-purpose — `RobotsTxtGuard::for('https://www.olx.ro')->isAllowed($url)` —
rather than rebuilding robots.txt parsing. OLX's own `robots.txt` was already checked (during the
Autovit scraper's research) and has no restriction matching this chapter's planned query params.
Confirmed live query parameters (user-supplied, verified working):
`https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/?currency=EUR&search[filter_float_price:to]=7000&search[filter_float_year:from]=2013&search[filter_float_rulaj_pana:to]=230000&search[filter_enum_car_body][0]=sedan&search[filter_enum_car_body][1]=estate-car&search[filter_float_enginesize:to]=2000`
— note OLX's field names differ from Autovit's (`filter_float_rulaj_pana` not `filter_float_mileage`,
`filter_enum_car_body` not `filter_enum_body_type`, values `sedan`/`estate-car` not `sedan`/`combi`),
and OLX has a genuine top-level `currency=EUR` parameter, unlike Autovit. Decided defaults:
reuse `config/scraping.php` (already built, same User-Agent/delay/page-cap pattern), same
`updateOrCreate` upsert approach into `listings`, and the same currency-comparability rule the
Autovit scraper uses (only price-filter a listing when its currency matches the `price_currency`
criterion — real cross-currency conversion is Chapter 3's job). TLS: re-verify the 1.2-pinning requirement from
PHP/`Http` specifically (not assumed from the PowerShell/curl findings in `lessons.md`) — if
still needed, use `Http::withOptions(['curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2]])`.
Exact CSS selectors for price/title/year/km/etc. inside each `l-card` still need discovering from
the real page — that's implementation research done when this chapter starts, not a decision
needing your input first.
**Depends on:** `listings` + `search_criteria` schema (done).
**Builds:** `scrape:olx`.

## Chapter 3 — Price history + normalized comparison currency
**Status:** not started, decided. RON→EUR rate source: the National Bank of Romania's (BNR) free
public daily rate feed (`https://www.bnr.ro/nbrfxrates.xml`) — no API key, no auth, one official
rate per day. Fetch and cache it once per day (e.g. Laravel's cache with a 24h TTL), don't call it
per-listing.
**Depends on:** `listings` table (done) — does not need either scraper to exist first.
**Builds:** a `listing_price_changes` table (listing_id, price, currency, recorded_at) and a
`price_eur` column on `listings`, computed via the cached BNR rate, so listings priced in
different currencies can still be filtered/sorted consistently. Either scraper can call a small
"record if price changed" hook once this exists.

## Chapter 4 — Ingestion run logging
**Status:** not started.
**Depends on:** nothing beyond the current schema — buildable standalone, wired into scrapers
later.
**Builds:** an `ingestion_runs` table (source, started_at, finished_at, pages_fetched,
listings_new, listings_updated, errors, status) and a small trait/service any scraper command can
wrap itself with, so unattended twice-daily runs are auditable instead of a black box.

## Chapter 5 — Car-knowledge reliability filter
**Status:** not started — this is the "car expert" feature: hardcoded known-problem-engine rules
plus a reliability score. Starting ruleset (from `car-finder-handoff.md`, to seed
`config/car_knowledge.php` or a `reliability_rules` table — table preferred, since it's editable
without a deploy): VW Group 1.6/2.0 TDI EA189 (emissions-scandal engines), Ford 1.6 TDCi with
PowerShift dual-clutch automatic (known reliability issues), BMW N47 diesel (timing chain
failure). Expand the list over time; this is the starting point, not the final one. Generic rules
to include from the start: flag mileage suspiciously low for the car's age, flag price far below
the market median for similar year/model.
**Depends on:** `listings` table (done) — can be built and tested against seeded fixture rows,
doesn't require a real scraper to exist first.
**Builds:** a `reliability_rules` table, a scoring service, applied as a soft filter on top of
whatever hard-filtered listings already exist.

## Chapter 6 — Seller rating check
**Status:** not independently buildable yet — folded into whichever of Chapters 1/2 turns out to
expose seller data, decided as follows so no question is needed later: Autovit's search results
already confirmed (Chapter 1's research) to expose only seller *type* (`private`/`dealer`), no
numeric rating — so Autovit alone doesn't support a real rating check. If Chapter 2's (OLX) HTML
research finds an actual rating/score on seller profiles, build the check then, folded into
Chapter 5's scoring rather than as separate infrastructure. **If neither site exposes a real
rating**, this chapter is dropped: delete this entry from `ROADMAP.md`, note the reason in
`CHANGELOG.md`, and fall back to the seller-type flag (private/dealer) alone as a minor signal in
Chapter 5 instead of a hard check. No need to ask before doing this — the decision rule is already
made here.
**Depends on:** Chapter 2 (OLX) research, since Chapter 1 already answered this for Autovit
(negatively).
**Builds:** TBD by the above rule — either a real rating check or nothing at all.

## Chapter 7 — Gmail digest notification
**Status:** not started.
**Depends on:** `listings` table + `notified_at` column (done), Gmail SMTP (done, tested).
**Builds:** `notify:send` — selects listings with `notified_at IS NULL`, emails a digest, marks
them notified. Written to work whether or not Chapter 5 (reliability filter) exists yet — with
it, notify only the good ones; without it, notify all unnotified matches.

## Chapter 8 — Scheduler + Windows Task Scheduler wiring
**Status:** not started, decided (confirmed via Context7 against Laravel 13.x docs). Laravel has
no built-in "catch up if the PC was off" feature — the standard pattern (used identically on
Windows and Linux) is a single OS-level trigger that runs `php artisan schedule:run` every
minute; Laravel's own schedule definition decides what actually executes each time. On Windows
(no cron), that OS-level trigger is a Windows Task Scheduler task: action = run
`php artisan schedule:run` from the project directory, trigger = repeat every 1 minute
indefinitely, **with "Run task as soon as possible after a scheduled start is missed" checked in
the task's Settings tab** — that checkbox *is* the catch-up mechanism CLAUDE.md asks for; it's a
native Windows Task Scheduler feature, not something to build. **Once a day, in the morning:**
`Schedule::command('scrape:autovit')->dailyAt('07:00')` (same pattern for `scrape:olx` and
`notify:send`, staggered a few minutes apart so they don't compete for the SQLite write lock at
the exact same second) — 07:00 chosen so overnight-posted listings have accumulated and results
are ready before the day starts. Since there's only one run a day now, each scraper's own page
cap is set generously (see each scraper's plan) rather than relying on a second run to catch what
the first missed. Use `->withoutOverlapping()` on each scheduled command so a slow run never
overlaps the next trigger.
**Depends on:** nothing functionally — can be built now and simply won't do much until scraper/
notify commands exist to schedule.
**Builds:** `routes/console.php` schedule entries and written Windows Task Scheduler setup steps
(documented in this chapter's plan, so they're reproducible if the PC is ever reconfigured).

## Chapter 9 — Resilience: backoff, circuit breaker, kill switches
**Status:** not started, cross-cutting.
**Depends on:** nothing beyond config — buildable as a small shared service now, adopted by
Chapters 1/2 whenever they run.
**Builds:** `.env` kill-switch flags (e.g. `SCRAPE_AUTOVIT_ENABLED`), a backoff helper for
repeated 429/403 responses, and a "got a 200 but parsed zero listings" alert — that usually means
a site's structure changed, not that there's nothing to find.
