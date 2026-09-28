<?php

use App\Enums\IngestionRunStatus;
use App\Enums\ListingSource;
use App\Models\IngestionRun;
use App\Services\Ingestion\IngestionRunTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('start() creates a running row with zeroed counters', function () {
    $run = (new IngestionRunTracker)->start(ListingSource::Olx);

    expect($run->source)->toBe(ListingSource::Olx)
        ->and($run->status)->toBe(IngestionRunStatus::Running)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->toBeNull()
        ->and($run->pages_fetched)->toBe(0)
        ->and($run->listings_new)->toBe(0)
        ->and($run->listings_updated)->toBe(0);
});

test('the increment methods persist and accumulate', function () {
    $tracker = new IngestionRunTracker;
    $run = $tracker->start(ListingSource::Autovit);

    $tracker->incrementPages($run);
    $tracker->incrementPages($run);
    $tracker->incrementNew($run, 3);
    $tracker->incrementUpdated($run, 2);

    expect($run->fresh()->pages_fetched)->toBe(2)
        ->and($run->fresh()->listings_new)->toBe(3)
        ->and($run->fresh()->listings_updated)->toBe(2);
});

test('recordError() appends messages instead of overwriting them', function () {
    $tracker = new IngestionRunTracker;
    $run = $tracker->start(ListingSource::Olx);

    $tracker->recordError($run, 'first problem');
    $tracker->recordError($run, 'second problem');

    expect($run->fresh()->errors)->toBe(['first problem', 'second problem']);
});

test('finish() sets finished_at and the given status', function () {
    $tracker = new IngestionRunTracker;
    $run = $tracker->start(ListingSource::Olx);

    $tracker->finish($run, IngestionRunStatus::Partial);

    expect($run->fresh()->finished_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe(IngestionRunStatus::Partial);
});

test('run() marks the run Success when the callback completes normally', function () {
    $tracker = new IngestionRunTracker;

    $run = $tracker->run(ListingSource::Autovit, function ($run) use ($tracker) {
        $tracker->incrementPages($run);
    });

    expect($run->status)->toBe(IngestionRunStatus::Success)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->fresh()->pages_fetched)->toBe(1);
});

test('run() marks the run Failed, records the exception, and re-throws', function () {
    $tracker = new IngestionRunTracker;

    expect(fn () => $tracker->run(ListingSource::Olx, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $run = IngestionRun::first();

    expect($run->status)->toBe(IngestionRunStatus::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->errors)->toBe(['boom']);
});
