# Plan: Bootstrap the Laravel project + a living best-practices doc

## Goal
Turn this empty-of-code folder into a working Laravel 13 application matching the decided stack in `CLAUDE.md` (SQLite in WAL mode, Gmail SMTP mail, Artisan Scheduler), with a test runner wired up, and a `.claude/best-practices.md` file that gets checked before writing code and updated as we confirm new tips through Context7.

## What I will do

1. **Check the Laravel installer's real flags first.**
   Run `laravel new --help` (installing the installer via `composer global require laravel/installer` if it's not already on PATH). I'm not guessing flag names from memory — Context7's docs snippets only confirmed `laravel new <name>` and `--using=<starter-kit>`; the SQLite/Pest/git flags need to be read from `--help` output before I rely on them.

2. **Scaffold Laravel into a temp folder, then merge it into the project root.**
   This folder already has `.claude/`, `.mcp.json`, and `car-finder-handoff.md`. Composer's `create-project` (which the installer uses under the hood) refuses to run in a non-empty directory. So:
   - Run `laravel new` inside the scratchpad directory (e.g. `laravel new car-finder --pest --database=sqlite` if `--help` confirms those flags, otherwise the closest equivalent).
   - Move every generated file/folder into `C:\a.coding\olx` **except** it must not overwrite `.claude/`, `.mcp.json`, or `car-finder-handoff.md`.
   - Result: one project rooted at `C:\a.coding\olx`, not a nested subfolder — so `php artisan` and Composer commands work from the directory you already `cd` into.

3. **Initialize git.**
   `Is a git repository: false` right now. Laravel's installer normally does `git init` + first commit for you. I'll let it do that (or run it manually if the merge step above interferes), then add a `.gitignore` (Laravel ships one — it already excludes `.env`, `/vendor`, `database/*.sqlite`, etc.). No commits get pushed anywhere; this is purely local.

4. **Configure SQLite the way `CLAUDE.md` asks for (WAL mode + busy_timeout).**
   I'll read the actual generated `config/database.php` for this Laravel version rather than assume — recent Laravel versions added native `busy_timeout`, `journal_mode`, and `synchronous` keys to the `sqlite` connection array, but I'll confirm they exist in this scaffold before relying on them. Plan:
   - `.env`: `DB_CONNECTION=sqlite`, `DB_DATABASE=<absolute path to database/database.sqlite>`.
   - If the config array has the WAL/busy_timeout keys: set `journal_mode=WAL`, `busy_timeout` to a sane value (e.g. 5000 ms) via `.env` vars.
   - If those keys don't exist in this version: fall back to a `DB::statement('PRAGMA journal_mode=WAL')` + `PRAGMA busy_timeout=5000` executed on connection (e.g. via a small `AppServiceProvider::boot()` listener), and I'll explain why in the "what differs" note afterward.

5. **Configure mail for Gmail SMTP (no secrets committed).**
   - `.env.example`: placeholders for `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME=`, `MAIL_PASSWORD=`, `MAIL_ENCRYPTION=tls`, `MAIL_FROM_ADDRESS=`, `MAIL_FROM_NAME="Car Finder"`.
   - `.env` (gitignored): same keys, filled in — but I need the actual Gmail **app password** from you (see "Needs from you" below). I will not invent or store it anywhere but `.env`.
   - I will not send a real test email until you confirm the app password is in place; I'll verify the mail config loads correctly and, if you want, send one test notification to confirm end-to-end delivery.

6. **Wire up testing.**
   Use Pest (Laravel 13's current default test framework — cleaner syntax than PHPUnit, and what the docs now lead with) unless you'd rather stick with PHPUnit. Verify the example test passes with `php artisan test` right after scaffolding, before any of our own code exists — this proves the toolchain (PHP 8.5, SQLite testing DB) works before we build on it.

7. **Create `.claude/best-practices.md`.**
   A living doc — not a one-time snapshot — with:
   - Current Laravel 13 / PHP 8.5 conventions relevant to this project (typed properties/return types, constructor property promotion, form requests, Eloquent conventions, Artisan command structure, `config/` vs `.env` usage, small single-responsibility classes).
   - A short "checked via Context7 on <date>" line per section, so staleness is visible.
   - I'll append to it (not rewrite from scratch) whenever a Context7 lookup surfaces a new relevant convention, and read it before writing code in future sessions — same pattern as `.claude/lessons.md`.

8. **Add one line to `CLAUDE.md`'s "How to work in this project" section** pointing at the new file (e.g. "6. **Check best practices.** Read `.claude/best-practices.md` before writing code; append new confirmed tips there."), so the instruction survives context resets, matching how rule 5 already does this for `lessons.md`.

## Files
- `C:\a.coding\olx\*` — new — full Laravel 13 skeleton (`app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/`, `artisan`, `composer.json`, etc.), merged in from the temp scaffold
- `.env`, `.env.example` — new — SQLite + Gmail SMTP config (`.env` gitignored, `.env.example` committed with placeholders)
- `.claude/best-practices.md` — new — living best-practices doc, read before each coding session
- `.claude/CLAUDE.md` — changed — one new numbered rule pointing at the best-practices doc
- `config/database.php` and/or `app/Providers/AppServiceProvider.php` — changed if needed — WAL mode / busy_timeout for SQLite

## Best practices applied
- Laravel 13.x docs (Context7, checked today): installer usage, SQLite env-based config, Artisan scheduling location (`routes/console.php` or `bootstrap/app.php`'s `withSchedule`), Mail configuration (`config/mail.php`, mailer-specific keys), Pest as the current recommended test runner.
- Secrets only ever go in `.env` (gitignored), never in `.env.example`, code, or committed config — per `CLAUDE.md` rule 3.
- Everything gets explained in-line as we go (rule 4), and any surprise goes into `.claude/lessons.md` (rule 5).

## Things to know / risks
- Merging a fresh Laravel scaffold into a non-empty directory is a manual step (move files, not `composer create-project .`) — small risk of a stray leftover temp folder; I'll clean up the scratchpad copy after verifying the merge, and I will not touch `.claude/`, `.mcp.json`, or `car-finder-handoff.md`.
- I don't yet know if this Laravel version's SQLite config natively supports `busy_timeout`/`journal_mode` — step 4 has a fallback if it doesn't, and I'll tell you which path was actually used.
- Gmail SMTP requires a Google **App Password** (not your normal Gmail password) — this only works if 2-Step Verification is on for the account. I can't generate this for you.
- No scraping or filtering logic is in this plan — this is purely "get a runnable, tested Laravel skeleton with the right config in place." Scraper/notification logic will be its own follow-up plan.

## How we'll verify it works
- `php artisan --version` and `php artisan about` run cleanly (confirms PHP/Laravel/env wiring).
- `php artisan test` (or `vendor/bin/pest`) passes on the default example test, using the SQLite test database.
- `php artisan migrate` runs against `database/database.sqlite` without errors.
- Manually inspect `.env` to confirm no real secrets got committed (`git status` / `git diff` before any commit).
- Read back `config/database.php` after scaffolding to confirm which WAL/busy_timeout approach applies, and confirm via `PRAGMA journal_mode;` / `PRAGMA busy_timeout;` (e.g. through `php artisan tinker` or a quick `DB::select`) that WAL mode is actually active.

## Needs from you
1. **Gmail app password** for `cioararaul08@gmail.com` (or a different sending address, your call) — a 16-character Google App Password, not your login password. I'll only ever put it in the local `.env` file.
2. **Pest vs PHPUnit** — I'm defaulting to Pest as the current Laravel-recommended choice; say so if you'd rather use PHPUnit.
3. **OK to `git init` locally** as part of scaffolding? (No pushes anywhere — purely local version control, which the Laravel installer does by default.)
4. **OK to add the one-line pointer to `CLAUDE.md`** for the best-practices doc, same pattern as the existing `lessons.md` rule?
