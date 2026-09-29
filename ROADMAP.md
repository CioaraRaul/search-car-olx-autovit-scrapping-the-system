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

No chapters remain in this file — everything planned so far has been built and merged into
`development`. See `CHANGELOG.md` for the full history.
