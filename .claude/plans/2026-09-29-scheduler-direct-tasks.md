# Plan: Replace every-minute scheduler polling with 3 direct Windows tasks

## Goal
Stop launching a PHP process every minute just to check "is it time yet?" Instead, have Windows
Task Scheduler fire each command directly at its own time, with no polling in between.

## What I found (research)
- The current "Car Finder Scheduler" Windows task already runs silently (hidden via a VBScript
  wrapper, `run-scheduler-hidden.vbs`) — no visible window was flashing. Confirmed via
  `schtasks /Query ... /XML`.
- It fires `php artisan schedule:run` every 60 seconds, 1,440 times/day. 1,437 of those do nothing
  but check a cron-style table in `routes/console.php`; only 3 checks a day actually launch real
  work (07:00 `scrape:autovit`, 07:10 `scrape:olx`, 22:00 `notify:send`).
- The machine's OS timezone is "FLE Standard Time" (UTC+2, Helsinki/Kyiv/Sofia/Vilnius family) —
  same DST rules as Romania. So a Windows Task Scheduler trigger set to a local wall-clock time
  fires at the correct Bucharest time without any extra config, unlike Laravel's own clock (which
  needed the `schedule_timezone` config key specifically because its default is UTC).
- `schedule_timezone` (`config/app.php`, `SCHEDULE_TIMEZONE` in `.env`/`.env.example`) is used
  *only* by the `Schedule::command(...)` calls being removed — grepped the whole repo, no other
  reference. Safe to remove as dead config once those calls are gone.
- Laravel's `withoutOverlapping(120)` (a 120-minute lock preventing the same command from running
  twice at once) has a direct Windows-native equivalent already used by the current task:
  `MultipleInstancesPolicy = IgnoreNew`. Each new task gets this too, so overlap protection isn't
  lost, just moved from Laravel's lock to Windows'.

## What I will do
1. **`routes/console.php`** — remove the three `Schedule::command(...)` lines (keep the unrelated
   `inspire` command). Nothing will call `php artisan schedule:run` anymore, so an empty schedule
   is correct, not a bug.
2. **`config/app.php`, `.env.example`, `.env`** — remove `schedule_timezone` / `SCHEDULE_TIMEZONE`
   (dead config once nothing reads it).
3. **`tests/Feature/ScheduleTest.php`** — delete. It asserts against Laravel's `Schedule` object,
   which will be empty by design; there's nothing left to test in PHP. (Windows Task Scheduler
   config itself was never PHPUnit-testable — Chapter 8 documented it manually for the same
   reason.)
4. **`run-scheduler-hidden.vbs`** — replace with a parameterized version,
   `run-artisan-hidden.vbs`, that takes the artisan command as an argument
   (`wscript run-artisan-hidden.vbs "scrape:autovit"`) so the 3 new tasks share one wrapper
   instead of 3 near-duplicate files.
5. **Windows Task Scheduler (system-level, outside git):**
   - Delete "Car Finder Scheduler".
   - Create 3 tasks, each: daily trigger at its time, "Run task as soon as possible after a
     scheduled start is missed" checked (the catch-up-if-PC-was-off behavior `CLAUDE.md`
     requires), `MultipleInstancesPolicy=IgnoreNew`, 2-hour execution time limit (matching the
     120-minute window the removed `withoutOverlapping(120)` used), action running
     `run-artisan-hidden.vbs` with the right command:
     - "Car Finder - Scrape Autovit" — 07:00 — `scrape:autovit`
     - "Car Finder - Scrape OLX" — 07:10 — `scrape:olx`
     - "Car Finder - Notify" — 22:00 — `notify:send`
6. **`CHANGELOG.md`** entry explaining the switch and why (fewer background checks, at your
   request).
7. Branch `fix/scheduler-direct-tasks` off `development`, test, merge `--no-ff`, push — per
   `CLAUDE.md`'s standing workflow.

## Files
- `routes/console.php` — changed — remove `Schedule::command(...)` calls
- `config/app.php` — changed — remove `schedule_timezone`
- `.env`, `.env.example` — changed — remove `SCHEDULE_TIMEZONE`
- `tests/Feature/ScheduleTest.php` — deleted
- `run-scheduler-hidden.vbs` — deleted, replaced by `run-artisan-hidden.vbs`
- `CHANGELOG.md` — changed
- Windows Task Scheduler — 1 task deleted, 3 created (system state, not in git)

## Things to know / risks
- **Losing Laravel's single source of truth for the schedule.** Previously `routes/console.php`
  was the one place timing lived, testable in PHP. Now timing lives in 3 Windows tasks instead —
  harder to see at a glance from the repo, but this project runs on exactly one machine, so it's
  not a coordination problem in practice.
- **`withoutOverlapping`'s 120-minute *expiry* isn't identical to Windows' `IgnoreNew`.** Laravel's
  version also auto-expires a stuck lock after 120 minutes even if the process never exits
  cleanly; Windows' `ExecutionTimeLimit` (set to 2h) plus `IgnoreNew` gives an equivalent
  safety net — Windows will terminate a run past its time limit, and won't start a new instance
  while one is active.

## How we'll verify it works
- `php artisan test` — full suite still passes minus the deleted `ScheduleTest`.
- `schtasks /Query /TN "Car Finder - Scrape Autovit" /FO LIST /V` (and the other two) confirm each
  task exists, is enabled, and has the missed-run catch-up checkbox on.
- Manual: leave it running and confirm tomorrow's 07:00/07:10/22:00 runs still fire (can't force a
  same-session real-time proof beyond checking the task configuration itself).

## Needs from you
Nothing blocking — you already confirmed the concern (too many background checks) and asked me to
build the fix.
