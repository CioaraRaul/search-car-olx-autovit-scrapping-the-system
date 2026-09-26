# Car Finder

Scrapes used-car listings from Autovit and OLX twice a day, filters them by user-set criteria plus "car knowledge" rules, stores them in SQLite, and emails new matches to the user via Gmail. Full idea: `car-finder-handoff.md`.

## Decided stack
- Laravel (Artisan commands + Scheduler), SQLite (WAL mode + busy_timeout)
- Notifications: email via Gmail SMTP (app password)
- Runs via Windows Task Scheduler, 2x/day, with catch-up if the PC was off. Must stay free.
- No frontend for now; keep the backend API-ready for a possible Angular app later.
- Search criteria are dynamic: user sets them by parameter name; a "help" command lists every available parameter.

## How to work in this project
1. **Plan first.** Use the `plan-first` skill before any code change, install, or config change. Wait for approval.
2. **Up-to-date information.** Use the Context7 MCP tools to check current docs for Laravel, PHP, and every library before writing code. Don't rely on memory for APIs or versions.
3. **Best practices.** Follow framework conventions (Laravel structure, migrations, config files, `.env` for secrets, typed code, small focused classes). Never commit secrets.
4. **Explain everything.** The user wants to learn: explain what each piece does and why, and define technical terms the first time they appear.
5. **Learn from mistakes.** Read `.claude/lessons.md` before starting work. When something fails or the user corrects you, add a lesson there (what happened → the lesson → how to apply it).

## Environment notes
- Windows 11. PHP 8.5 (Herd), Composer 2.9, Node 24 — PHP is on the PowerShell PATH, not the Git Bash PATH.
- Scratch/temp files go in the session scratchpad, never in the project folder.
