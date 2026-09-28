<?php

use App\Services\Listings\PriceHistoryRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('first call on a fresh listing records history and sets price_eur', function () {
    $listing = makeListing();

    app(PriceHistoryRecorder::class)->recordIfChanged($listing, 6000, 'EUR');

    expect($listing->priceChanges()->count())->toBe(1)
        ->and($listing->fresh()->price_eur)->not->toBeNull();
});

test('a repeat call with the same price/currency does not add a second history row', function () {
    $listing = makeListing();
    $recorder = app(PriceHistoryRecorder::class);

    $recorder->recordIfChanged($listing, 6000, 'EUR');
    $recorder->recordIfChanged($listing, 6000, 'EUR');

    expect($listing->priceChanges()->count())->toBe(1);
});

test('a call with a changed price adds a second history row and updates price_eur', function () {
    $listing = makeListing();
    $recorder = app(PriceHistoryRecorder::class);

    $recorder->recordIfChanged($listing, 6000, 'EUR');
    $firstEur = $listing->fresh()->price_eur;

    $recorder->recordIfChanged($listing, 5500, 'EUR');

    expect($listing->priceChanges()->count())->toBe(2)
        ->and((float) $listing->fresh()->price_eur)->not->toBe((float) $firstEur);
});

test('a BNR outage leaves price_eur untouched instead of throwing', function () {
    Http::fake([
        config('exchange_rates.bnr_url') => Http::response('', 500),
    ]);

    $listing = makeListing();

    app(PriceHistoryRecorder::class)->recordIfChanged($listing, 6000, 'USD');

    expect($listing->priceChanges()->count())->toBe(1)
        ->and($listing->fresh()->price_eur)->toBeNull();
});
