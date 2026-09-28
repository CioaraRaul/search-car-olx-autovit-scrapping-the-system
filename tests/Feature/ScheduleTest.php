<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $commandName): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, $commandName));

    expect($event)->not->toBeNull("No scheduled event found for '{$commandName}'.");

    return $event;
}

test('scrape:autovit runs daily at 07:00 Europe/Bucharest, without overlapping', function () {
    $event = scheduledEvent('scrape:autovit');

    expect($event->expression)->toBe('0 7 * * *')
        ->and($event->timezone)->toBe('Europe/Bucharest')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120);
});

test('scrape:olx runs daily at 07:10 Europe/Bucharest, without overlapping', function () {
    $event = scheduledEvent('scrape:olx');

    expect($event->expression)->toBe('10 7 * * *')
        ->and($event->timezone)->toBe('Europe/Bucharest')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120);
});

test('notify:send runs daily at 22:00 Europe/Bucharest, separate from the morning scrapers, without overlapping', function () {
    $event = scheduledEvent('notify:send');

    expect($event->expression)->toBe('0 22 * * *')
        ->and($event->timezone)->toBe('Europe/Bucharest')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120);
});
