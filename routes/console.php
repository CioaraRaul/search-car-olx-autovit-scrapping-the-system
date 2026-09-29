<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// scrape:autovit (07:00), scrape:olx (07:10), and notify:send (22:00) are
// triggered directly by their own Windows Task Scheduler entries instead of
// Laravel's Schedule — see .claude/plans/2026-09-29-scheduler-direct-tasks.md
// for why (avoids a php process launching every minute just to check the
// time, which a once-a-day schedule doesn't need).
