# Car Finder — Implementation Roadmap

Each chapter below is a self-contained unit of work: it lists what it depends on, and every
chapter is written to depend only on what's **already merged into `development`** — never on
another chapter in this file. That's deliberate, so any chapter can be picked up and implemented
on its own, by any session, in any order, without waiting on the others.

**How this doc is used:** say "do chapter N" and only that chapter gets built. Starting a chapter
still goes through the normal process in `CLAUDE.md` (plan-first → branch → test → changelog →
merge) — this file is the menu, not a substitute for planning each one properly. When a chapter
is finished and merged into `development`, **its entry gets deleted from this file** — the
permanent record lives in `CHANGELOG.md` and the branch's plan file under `.claude/plans/`, so
nothing is lost, this file just always shows what's left.

Credit where due: the shape of several chapters below (incremental/polite crawling, run logging,
price history, resilience flags) was adapted from a reference ingestion plan the user shared,
originally written for a different stack (NestJS/BullMQ/Redis/Postgres/React). The architectural
ideas carried over; the tech choices didn't — see `CLAUDE.md`'s "Decided stack" for why (SQLite
not Postgres, no Redis/queue infra since this must stay free and only runs twice a day, PHP/
Laravel not Node, no frontend yet).

---

## Chapter 1 — Autovit scraper
**Status:** planned and ready — `.claude/plans/2026-09-28-autovit-scraper.md`, awaiting go-ahead.
**Depends on:** `listings` + `search_criteria` schema (done).
**Builds:** `scrape:autovit` — parses Autovit's `__NEXT_DATA__` JSON, applies your saved criteria
as live search filters, upserts into `listings`.

## Chapter 2 — OLX scraper
**Status:** not planned yet — needs its own research pass (OLX renders listings as HTML, not a
JSON blob, so this needs `symfony/dom-crawler` + `symfony/css-selector` and real selector
discovery; also needs re-verifying the TLS 1.2 requirement from PHP/Guzzle specifically, not just
PowerShell). The `currency=EUR` query parameter is already confirmed working live.
**Depends on:** `listings` + `search_criteria` schema (done).
**Builds:** `scrape:olx`.

## Chapter 3 — Price history + normalized comparison currency
**Status:** not started.
**Depends on:** `listings` table (done) — does not need either scraper to exist first.
**Builds:** a `listing_price_changes` table (listing_id, price, currency, recorded_at) and a
`price_eur` column on `listings` (using a cached daily RON→EUR rate) so listings priced in
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
(e.g. VW EA189, Ford PowerShift, BMW N47 — already named in `car-finder-handoff.md`) plus a
reliability score.
**Depends on:** `listings` table (done) — can be built and tested against seeded fixture rows,
doesn't require a real scraper to exist first.
**Builds:** a rules table or config-based ruleset, a scoring service, applied as a soft filter on
top of whatever hard-filtered listings already exist.

## Chapter 6 — Seller rating check
**Status:** blocked on research, not independently buildable yet. Autovit's search results do
expose a seller type (`private`/`dealer`) but no rating value was seen in initial reconnaissance
— unclear if either site exposes a real rating at all. Likely folds into Chapter 5's scoring
rather than becoming fully separate.
**Depends on:** whichever scraper(s) exist, since it needs to see real seller data first.
**Builds:** TBD, pending that research.

## Chapter 7 — Gmail digest notification
**Status:** not started.
**Depends on:** `listings` table + `notified_at` column (done), Gmail SMTP (done, tested).
**Builds:** `notify:send` — selects listings with `notified_at IS NULL`, emails a digest, marks
them notified. Written to work whether or not Chapter 5 (reliability filter) exists yet — with
it, notify only the good ones; without it, notify all unnotified matches.

## Chapter 8 — Scheduler + Windows Task Scheduler wiring
**Status:** not started.
**Depends on:** nothing functionally — can be built now and simply won't do much until scraper/
notify commands exist to schedule.
**Builds:** `routes/console.php` schedule entries (twice daily) and a documented Windows Task
Scheduler setup running `php artisan schedule:run`, with the catch-up-if-the-PC-was-off behavior
`CLAUDE.md` calls for — needs proper research into Laravel's scheduler options for this, not
assumed.

## Chapter 9 — Resilience: backoff, circuit breaker, kill switches
**Status:** not started, cross-cutting.
**Depends on:** nothing beyond config — buildable as a small shared service now, adopted by
Chapters 1/2 whenever they run.
**Builds:** `.env` kill-switch flags (e.g. `SCRAPE_AUTOVIT_ENABLED`), a backoff helper for
repeated 429/403 responses, and a "got a 200 but parsed zero listings" alert — that usually means
a site's structure changed, not that there's nothing to find.
