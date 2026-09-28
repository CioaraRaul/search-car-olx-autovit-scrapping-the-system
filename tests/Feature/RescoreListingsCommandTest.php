<?php

use App\Enums\ListingSource;
use App\Models\Listing;
use App\Models\ReliabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeUnscoredListing(array $overrides = []): Listing
{
    return Listing::create(array_merge([
        'source' => ListingSource::Autovit,
        'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'title' => 'Plain car',
        'price' => 5000,
        'currency' => 'EUR',
        'url' => 'https://example.test/listing',
    ], $overrides));
}

test('scores listings that have never been scored', function () {
    $listing = makeUnscoredListing();

    $this->artisan('reliability:rescore')->assertExitCode(0);

    expect($listing->fresh()->reliability_score)->not->toBeNull()
        ->and($listing->fresh()->reliability_scored_at)->not->toBeNull();
});

test('does not touch an already-scored listing without --all', function () {
    $listing = makeUnscoredListing([
        'reliability_score' => 99,
        'reliability_scored_at' => now(),
    ]);

    $this->artisan('reliability:rescore')->assertExitCode(0);

    expect($listing->fresh()->reliability_score)->toBe(99);
});

test('--all re-scores an already-scored listing', function () {
    $listing = makeUnscoredListing([
        'title' => 'VW Golf 1.6 TDI',
        'reliability_score' => 99,
        'reliability_scored_at' => now(),
    ]);

    ReliabilityRule::create([
        'name' => 'test-known-issue',
        'keywords' => [['vw', '1.6', 'tdi']],
        'penalty' => 30,
        'message' => 'Test.',
    ]);

    $this->artisan('reliability:rescore', ['--all' => true])->assertExitCode(0);

    expect($listing->fresh()->reliability_score)->toBe(70);
});
