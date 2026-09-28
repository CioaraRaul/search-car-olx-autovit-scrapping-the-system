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
