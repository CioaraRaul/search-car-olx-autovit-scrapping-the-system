# Plan: Chapter 8 — Scheduler + Windows Task Scheduler wiring

## Goal
Wire up Laravel's Task Scheduler (a built-in system for saying "run this command
daily/hourly/etc.") so that once the scraper and notification commands exist (Chapters 1/2/7),
they automatically run on their own schedules, Romania time: the scrapers once a day at 07:00
(morning, so overnight-posted listings have accumulated), and `notify:send` separately at 22:00
(night, so the day's matches land in the inbox in the evening) — and document the one-time
Windows setup step that makes Laravel's scheduler tick at all, with the "catch up if the PC was
off" behaviour CLAUDE.md asks for.

**Correction (2026-09-28, after this plan was first drafted):** the original draft staggered all
three commands a few minutes apart starting at 07:00. The user has since decided the notification
digest should fire separately at night (22:00), not alongside the morning scrapers — see
`CLAUDE.md`'s "Decided stack" and this roadmap's own Chapter 8 entry, both updated accordingly.
`notify:send` already sends nothing when there's nothing unnotified, so a quiet day produces no
email regardless of what time it runs.

## Background: how this actually works
Laravel's scheduler doesn't run anything by itself — it needs something external to "poke" it
every minute. On Linux that's a single cron entry; on Windows (no cron) it's a **Windows Task
Scheduler** task that runs `php artisan schedule:run` every minute, forever. Each time that runs,
Laravel checks its own schedule (defined in PHP, in `routes/console.php`) and only actually
executes a command if it's due right now. So:
- `routes/console.php` = *what* runs and *when* (once a day at 07:00, etc.) — this is what this
  chapter adds.
- Windows Task Scheduler = the *heartbeat* that lets Laravel check that schedule every minute —
  a one-time OS setup step, not code.

The "catch-up if the PC was off" requirement (from `CLAUDE.md`) is a native Windows Task Scheduler
checkbox — "Run task as soon as possible after a scheduled start is missed" — not something we
build in PHP. If the PC is off at 07:00 and 07:10 and 07:20, Windows itself will fire the missed
`schedule:run` trigger as soon as the PC is back on; Laravel will then see those commands are
overdue and run them.

## What I will do

1. **Add a `schedule_timezone` config key.** Laravel's scheduler runs in the app's `timezone`
   config (currently `'UTC'` in `config/app.php`) unless a separate `schedule_timezone` is set —
   Laravel 13 added this specifically so the rest of the app (timestamps, `now()`, etc.) can stay
   on UTC while only the *scheduler* uses a human timezone. Without it, `dailyAt('07:00')` would
   fire at 07:00 UTC, i.e. 09:00 or 10:00 in Romania depending on daylight saving — not the
   "before the day starts" morning run the roadmap wants.
   - `config/app.php`: add `'schedule_timezone' => env('SCHEDULE_TIMEZONE', 'Europe/Bucharest'),`
     next to the existing `'timezone'` key.
   - `.env` and `.env.example`: add `SCHEDULE_TIMEZONE=Europe/Bucharest`, per the "config not
     hardcoded, `.env.example` documents every key" rule in `best-practices.md`.
   - Caveat (documented, not solved): Laravel's own docs note that daylight-saving transitions can
     make a timezone-based schedule run twice or skip once, twice a year. For a once-a-day hobby
     scrape this is a non-issue (worst case: one run at 06:00 or 08:00 local, twice a year) so I'm
     not adding extra logic to work around it — just calling it out so it's not a surprise.

2. **Add the three scheduled commands to `routes/console.php`.** The two scrapers stay staggered
   10 minutes apart in the morning so they never compete for SQLite's single writer lock at the
   same second; `notify:send` runs on its own at night, well clear of the scrapers, per the
   user's decision that the digest should arrive in the evening rather than at 07:00:
   ```php
   Schedule::command('scrape:autovit')->dailyAt('07:00')->withoutOverlapping(120);
   Schedule::command('scrape:olx')->dailyAt('07:10')->withoutOverlapping(120);
   Schedule::command('notify:send')->dailyAt('22:00')->withoutOverlapping(120);
   ```
   - `withoutOverlapping()` stops a second run from starting while a slow one is still going (e.g.
     if the previous day's run somehow ran long) — required by the roadmap. It works by taking a
     lock in the cache (this app's cache driver is `database`, confirmed in `.env`/`config/cache.php`,
     and the `cache` table migration already exists, so no extra setup is needed for this).
   - I'm passing an explicit **120-minute** lock expiry instead of the 24-hour default. Reasoning:
     if a run ever crashes without releasing its lock, a 24h expiry would block the *entire next
     day's* run too (since these run once every ~24h). 120 minutes is generous for a scraper run
     but still expires well before tomorrow's 07:00, so a stuck lock self-heals within one day
     without needing manual intervention (`schedule:clear-cache` still works if needed sooner).
   - These three commands (`scrape:autovit`, `scrape:olx`, `notify:send`) don't exist yet
     (Chapters 1/2/7 aren't built). This is expected and explicitly allowed by the roadmap
     ("can be built now and simply won't do much until scraper/notify commands exist"). Laravel
     doesn't validate that a scheduled command exists until the moment it's actually due to run —
     so defining the schedule now is safe; it just won't fire successfully until those commands
     exist. I'll verify this with `php artisan schedule:list`, which prints the schedule table
     without needing the commands to exist.

3. **Write a test** (`tests/Feature/ScheduleTest.php`) that inspects Laravel's schedule directly
   (`app(Illuminate\Console\Scheduling\Schedule::class)->events()`) and asserts, for each of the
   three commands: the command string, the cron expression matches the intended time (`0 7 * * *`,
   `10 7 * * *`, `0 22 * * *`), the timezone is `Europe/Bucharest`, and `withoutOverlapping` is on
   with a 120-minute expiry. This is a config-shape test (it doesn't run the scrapers), but it
   pins down the schedule so a future edit to `routes/console.php` can't silently drop or
   mistime one of the three entries.

4. **Document the Windows Task Scheduler setup** in this plan file (the roadmap's own instruction:
   "documented in this chapter's plan, so they're reproducible if the PC is ever reconfigured").
   See the section below. This is a one-time manual OS step — I won't run it myself without your
   go-ahead (see "Needs from you"), since it changes Windows state outside the git repo/this
   project, not just files in it.

### Windows Task Scheduler setup — actually done (2026-09-28)

Created via PowerShell's `ScheduledTasks` module rather than the GUI, with the user's go-ahead.
Task name: **Car Finder Scheduler**. Action: `C:\Users\cioara\.config\herd\bin\php85\php.exe
artisan schedule:run`, working directory `C:\a.coding\olx`. Trigger: once, repeating every 1
minute for 3650 days (10 years — `RepetitionDuration` rejects `[TimeSpan]::MaxValue` as
out-of-range for the underlying task XML schema, so a large-but-finite value stands in for
"indefinitely"; renewing this in 2036 is a future problem, not a real one for a hobby project).
`StartWhenAvailable` is on (the catch-up behaviour) and `ExecutionTimeLimit` is 1 hour (a hung
`schedule:run` gets killed rather than lingering forever).

**Deviation from the original draft:** the logon type is `Interactive`, not `S4U`. `S4U` (or any
logon type that lets a task run *whether the user is logged on or not*) requires the "Log on as a
batch job" right, which `Register-ScheduledTask` can only grant from an **elevated** (Administrator)
PowerShell session — this session's shell reported `BUILTIN\Administrators ... Group used for deny
only`, i.e. not elevated, and elevating isn't something achievable non-interactively (UAC needs a
human click, and typing a Windows account password into an automated session isn't something this
should do). `Interactive` logon needs no elevation and no stored password, but means the task only
runs while `cioara` is logged in (locked screen is fine; a full log-off or unattended reboot without
auto-login is not).
- **Practical impact:** none, for how this PC is normally used (left logged in). If that ever
  changes, re-registering with `S4U` from an elevated PowerShell (or via the GUI's Settings tab,
  which prompts for the password itself) upgrades this later — no code change needed, just
  re-running the registration with admin rights.
- **Verified working:** `Start-ScheduledTask` run manually →
  `Get-ScheduledTaskInfo` showed `LastTaskResult: 0` (`0x0`, success) and a `NextRunTime` exactly
  one minute later, confirming the every-minute repetition is live.

### Windows Task Scheduler setup (original draft, manual/GUI alternative — superseded by the above,
kept for reference/reproducibility if the task ever needs recreating from scratch)

**GUI steps:**
1. Open **Task Scheduler** (`taskschd.msc`) → **Create Task** (not "Basic Task" — we need the
   missed-run checkbox, which the Basic wizard hides).
2. **General tab:** name it e.g. `Car Finder Scheduler`. Check **"Run whether user is logged on
   or not"** so it works even if you're not logged in.
3. **Triggers tab → New:** Begin the task **On a schedule** → **Daily**, recur every 1 day, start
   at any time (e.g. 00:00) → check **Repeat task every: 1 minute**, **for a duration of:
   Indefinitely**.
4. **Settings tab:** check **"Run task as soon as possible after a scheduled start is missed"**
   — this is the catch-up behaviour. Leave "Stop the task if it runs longer than" reasonable
   (e.g. 1 hour) so a hung `schedule:run` doesn't linger forever.
5. **Actions tab → New:** Action = **Start a program**. Program/script = full path to `php.exe`
   (Herd's PHP, e.g. `C:\Users\cioara\.config\herd\bin\php85\php.exe` — confirm the exact path on
   this machine before setting it up). Arguments = `artisan schedule:run`. Start in = the project
   directory (`C:\a.coding\olx`) — required, since `artisan` is a relative path.

**Equivalent one-line `schtasks` command** (same result, for reproducibility/scripting):
```powershell
schtasks /create /tn "Car Finder Scheduler" /tr "'<path-to-php.exe>' artisan schedule:run" /sc minute /mo 1 /sd 01/01/2026 /st 00:00 /ru "" /it
```
(`/ru ""` + `/it` runs as the current interactive user; the "run whether logged on or not" and
"catch up on missed start" options aren't exposed as `schtasks` flags — those two still need to be
set via the GUI's Settings tab after creation, or via `Register-ScheduledTask` in PowerShell with
a `-Settings` object. I'll use the GUI approach when we actually do this, since it's a one-time
setup and the checkboxes are more reliable to get right by hand than a script.)

## Files
- `config/app.php` — changed — add `schedule_timezone` key.
- `.env`, `.env.example` — changed — add `SCHEDULE_TIMEZONE=Europe/Bucharest`.
- `routes/console.php` — changed — add the three `Schedule::command()` entries.
- `tests/Feature/ScheduleTest.php` — new — asserts the schedule's shape.

## Best practices applied
- Scheduling defined in `routes/console.php`, not inside command classes — per
  `best-practices.md` and confirmed current for Laravel 13.x via Context7
  (`/websites/laravel_13_x`, "Task Scheduling" — schedules "are typically defined in the
  `routes/console.php` file").
- Config over hardcoding: the timezone is an `.env`-backed config key, not a literal string in
  `routes/console.php`.
- `.env.example` gets the same new key as `.env`, keeping required config self-documenting.
- Confirmed via Context7 that `withoutOverlapping()`, `dailyAt()`, and `schedule_timezone` are all
  current Laravel 13.x scheduler APIs (not deprecated/renamed) before using them.

## Things to know / risks
- The three scheduled commands don't exist yet — this chapter only wires the schedule; it has
  nothing to actually run correctly until Chapters 1, 2, and 7 are built. `schedule:list` will
  show them as scheduled either way.
- DST transitions (~2 days/year) can shift the actual run time by an hour, or in rare cases skip/
  double-run — acceptable for a once-daily hobby scrape, not worth extra code to fully solve.
- The Windows Task Scheduler part is a manual OS-level setup step outside git's reach — nothing to
  test automatically; verified by observing the task actually fire (see below).
- `withoutOverlapping()`'s lock lives in the `cache` table (SQLite) — same database file the rest
  of the app uses. This is fine at this scale (once-a-day, three short-lived lock rows) and
  doesn't need a separate cache store.

## How we'll verify it works
- `php artisan schedule:list` — confirms all three entries appear with the right times/timezone
  without erroring, even though the underlying commands don't exist yet.
- `php artisan test` — the new `ScheduleTest.php` passes, along with the full existing suite.
- Manual (after Windows Task Scheduler is set up, whenever that happens): check Task Scheduler's
  "Last Run Result" for the task is `0x0`, and that `storage/logs/laravel.log` shows scheduler
  activity around each trigger time.

## Needs from you
1. Confirm `Europe/Bucharest` is the right timezone (vs. hardcoding a fixed UTC offset) — I'm
   assuming yes since Romania observes EET/EEST and that's what a proper IANA timezone name
   handles correctly.
2. Should I actually create the Windows Task Scheduler task now (I'd run the GUI steps or the
   `schtasks` command via PowerShell, which requires knowing Herd's exact `php.exe` path — I'll
   confirm that path first), or do you want to do that part yourself using the documented steps
   above? Either way the steps stay written down here for reproducibility.
