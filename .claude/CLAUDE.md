# Car Finder

Scrapes used-car listings from Autovit and OLX once a day, filters them by user-set criteria plus "car knowledge" rules, stores them in SQLite, and emails new matches to the user via Gmail. Full idea: `car-finder-handoff.md`.

## Decided stack
- Laravel (Artisan commands + Scheduler), SQLite (WAL mode + busy_timeout)
- Notifications: email via Gmail SMTP (app password)
- Runs via Windows Task Scheduler, with catch-up if the PC was off. Must stay free. Scraping (`scrape:autovit`/`scrape:olx`) runs once a day at 07:00 (morning), so overnight-posted listings have accumulated before the day starts; the notification digest (`notify:send`) runs separately at 22:00 (night), so the day's finds land in the user's inbox in the evening rather than first thing in the morning. One scrape run a day means each run needs to check thoroughly (see `ROADMAP.md` Chapter 8 for the scheduling mechanism and each scraper's plan for its page-coverage default) rather than relying on a second run to catch what the first missed.
- No frontend for now; keep the backend API-ready for a possible Angular app later.
- Search criteria are dynamic: user sets them by parameter name; a "help" command lists every available parameter.

## How to work in this project
1. **Plan first.** Use the `plan-first` skill before any code change, install, or config change. Wait for approval.
2. **Up-to-date information.** Use the Context7 MCP tools to check current docs for Laravel, PHP, and every library before writing code. Don't rely on memory for APIs or versions.
3. **Best practices.** Follow framework conventions (Laravel structure, migrations, config files, `.env` for secrets, typed code, small focused classes). Never commit secrets.
4. **Explain everything.** The user wants to learn: explain what each piece does and why, and define technical terms the first time they appear.
5. **Learn from mistakes.** Read `.claude/lessons.md` before starting work. When something fails or the user corrects you, add a lesson there (what happened → the lesson → how to apply it).
6. **Check best practices.** Read `.claude/best-practices.md` before writing code. It's a living doc — append newly confirmed Laravel/PHP conventions there (via Context7) rather than relying on memory.
7. **Test everything.** Every code change needs a passing test (`php artisan test`) before it's reported as done.
8. **Never let `.env` reach git.** Before any commit or push, confirm `.env` isn't staged (`git status`). A tracked pre-commit hook (`.githooks/pre-commit`, wired via `git config core.hooksPath .githooks`) blocks it automatically — don't bypass it with `--no-verify` without a specific reason from the user.
9. **Keep `CHANGELOG.md` current.** Every implemented change gets an entry there (what changed and why, in plain language) as part of the same branch/commit that makes the change — not a separate cleanup pass later. This is in addition to, not instead of, descriptive commit messages.
10. **Branch workflow — IMPORTANT, no exceptions.** Never commit directly to `main` or `development`.
   - Plan first (rule 1), then create a branch off `development` named for the task: `feature/<short-task-name>` for new functionality, `fix/<short-task-name>` for bugs, `docs/<short-task-name>` for documentation-only changes.
   - Do the work, test it (rule 7).
   - Commit with a specific, descriptive message — what changed and why, not "update" or "fix stuff".
   - Merge the branch into `development` with `git merge --no-ff` (keeps a visible merge commit per task in history) and delete the branch afterward.
   - Switch to `development` (should already be the current branch after the merge) and `git push origin development` — every finished task ends with `development` pushed to GitHub, not left local-only.
   - `main` only moves when the user explicitly says it's time to release — merge `development` into `main` then, never as a side effect of finishing a task.

## Environment notes
- Windows 11. PHP 8.5 (Herd), Composer 2.9, Node 24 — PHP is on the PowerShell PATH, not the Git Bash PATH.
- Scratch/temp files go in the session scratchpad, never in the project folder.
