<?php

use App\Enums\ListingSource;
use App\Services\Scraping\ScraperCircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    config([
        'scraping.resilience.max_consecutive_failures' => 3,
        'scraping.resilience.cooldown_minutes' => 60,
    ]);
});

test('stays closed while failures are under the threshold', function () {
    $breaker = app(ScraperCircuitBreaker::class);

    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);

    expect($breaker->isOpen(ListingSource::Autovit))->toBeFalse();
});

test('opens exactly when failures reach the threshold', function () {
    $breaker = app(ScraperCircuitBreaker::class);

    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);

    expect($breaker->isOpen(ListingSource::Autovit))->toBeTrue();
});

test('opening one source does not affect another', function () {
    $breaker = app(ScraperCircuitBreaker::class);

    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);

    expect($breaker->isOpen(ListingSource::Autovit))->toBeTrue()
        ->and($breaker->isOpen(ListingSource::Olx))->toBeFalse();
});

test('stays open until the cooldown elapses, then closes on its own', function () {
    $breaker = app(ScraperCircuitBreaker::class);

    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);

    expect($breaker->isOpen(ListingSource::Autovit))->toBeTrue();

    $this->travel(59)->minutes();
    expect($breaker->isOpen(ListingSource::Autovit))->toBeTrue();

    $this->travel(2)->minutes();
    expect($breaker->isOpen(ListingSource::Autovit))->toBeFalse();
});

test('recordSuccess clears an open breaker', function () {
    $breaker = app(ScraperCircuitBreaker::class);

    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);
    $breaker->recordFailure(ListingSource::Autovit);

    expect($breaker->isOpen(ListingSource::Autovit))->toBeTrue();

    $breaker->recordSuccess(ListingSource::Autovit);

    expect($breaker->isOpen(ListingSource::Autovit))->toBeFalse();

    // A fresh failure after a reset shouldn't immediately reopen it.
    $breaker->recordFailure(ListingSource::Autovit);
    expect($breaker->isOpen(ListingSource::Autovit))->toBeFalse();
});
