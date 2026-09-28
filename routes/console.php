<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scrapers run once a day in the morning, staggered 10 minutes apart so they
// never compete for SQLite's single writer lock at the same second.
Schedule::command('scrape:autovit')->dailyAt('07:00')->withoutOverlapping(120);
Schedule::command('scrape:olx')->dailyAt('07:10')->withoutOverlapping(120);

// The notification digest runs separately at night, so the day's matches
// land in the inbox in the evening rather than first thing in the morning.
// It already sends nothing when there's nothing unnotified.
Schedule::command('notify:send')->dailyAt('22:00')->withoutOverlapping(120);
