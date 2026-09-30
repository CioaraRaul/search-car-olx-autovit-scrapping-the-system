<?php

use App\Enums\ListingSource;
use App\Models\Listing;
use App\Models\ReliabilityRule;
use App\Services\Reliability\ReliabilityScorer;
use Database\Seeders\ReliabilityRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// These tests use diesel/2.0 titles to exercise other rules; the needs-fit rule is covered by
// tests/Unit/NeedsFitEvaluatorTest.php and its own scorer test below.
beforeEach(fn () => config(['car_knowledge.needs_fit.penalty' => 0]));

function makeReliabilityListing(array $overrides = []): Listing
{
    return Listing::create(array_merge([
        'source' => ListingSource::Autovit,
        'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'price' => 5000,
        'currency' => 'EUR',
        'url' => 'https://example.test/listing',
    ], $overrides));
}

// --- Known-issue keyword matching ---

test('flags a listing whose title matches a seeded known-issue rule', function () {
    ReliabilityRule::create([
        'name' => 'test-known-issue',
        'keywords' => [['vw', '1.6', 'tdi']],
        'penalty' => 30,
        'message' => 'Test known-issue engine.',
    ]);

    $score = (new ReliabilityScorer)->score(['title' => 'VW Golf 1.6 TDI', 'description' => '']);

    expect($score->flags)->toHaveCount(1)
        ->and($score->flags[0]->rule)->toBe('test-known-issue')
        ->and($score->score)->toBe(70);
});

test('does not flag a listing that does not match any keyword group', function () {
    ReliabilityRule::create([
        'name' => 'test-known-issue',
        'keywords' => [['vw', '1.6', 'tdi']],
        'penalty' => 30,
        'message' => 'Test known-issue engine.',
    ]);

    $score = (new ReliabilityScorer)->score(['title' => 'Toyota Corolla 1.6 Petrol', 'description' => '']);

    expect($score->flags)->toBeEmpty()
        ->and($score->score)->toBe(100);
});

test('ignores an inactive rule', function () {
    ReliabilityRule::create([
        'name' => 'test-inactive',
        'keywords' => [['vw', '1.6', 'tdi']],
        'penalty' => 30,
        'message' => 'Test.',
        'active' => false,
    ]);

    $score = (new ReliabilityScorer)->score(['title' => 'VW Golf 1.6 TDI', 'description' => '']);

    expect($score->flags)->toBeEmpty();
});

// --- Low mileage for age ---

test('flags suspiciously low mileage on an old car', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Old car', 'description' => '',
        'year' => (int) date('Y') - 10, 'mileage_km' => 5000,
    ]);

    expect($score->flags)->toHaveCount(1)
        ->and($score->flags[0]->rule)->toBe('low-mileage-for-age');
});

test('does not flag low mileage on a young car', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'New car', 'description' => '',
        'year' => (int) date('Y'), 'mileage_km' => 500,
    ]);

    expect($score->flags)->toBeEmpty();
});

test('does not flag mileage when year or mileage is missing', function () {
    $score = (new ReliabilityScorer)->score(['title' => 'Unknown', 'description' => '', 'year' => null, 'mileage_km' => null]);

    expect($score->flags)->toBeEmpty();
});

// --- Below market price ---

test('flags a price far below the median of enough comparable listings', function () {
    $year = 2018;

    for ($i = 0; $i < 5; $i++) {
        makeReliabilityListing(['year' => $year, 'currency' => 'EUR', 'price' => 10000]);
    }

    $score = (new ReliabilityScorer)->score([
        'title' => 'Suspiciously cheap', 'description' => '',
        'year' => $year, 'currency' => 'EUR', 'price' => 2000,
    ]);

    expect($score->flags)->toHaveCount(1)
        ->and($score->flags[0]->rule)->toBe('below-market-price');
});

test('does not flag below-market price with too few comparables', function () {
    makeReliabilityListing(['year' => 2018, 'currency' => 'EUR', 'price' => 10000]);
    makeReliabilityListing(['year' => 2018, 'currency' => 'EUR', 'price' => 10000]);

    $score = (new ReliabilityScorer)->score([
        'title' => 'Cheap', 'description' => '',
        'year' => 2018, 'currency' => 'EUR', 'price' => 2000,
    ]);

    expect($score->flags)->toBeEmpty();
});

test('excludes listings in a different currency from the comparison', function () {
    for ($i = 0; $i < 5; $i++) {
        makeReliabilityListing(['year' => 2018, 'currency' => 'RON', 'price' => 50000]);
    }

    $score = (new ReliabilityScorer)->score([
        'title' => 'Cheap EUR car', 'description' => '',
        'year' => 2018, 'currency' => 'EUR', 'price' => 2000,
    ]);

    expect($score->flags)->toBeEmpty();
});

// --- Damaged vehicle (populated by AutovitDetailFetcher/OlxDetailFetcher; null when unknown) ---

test('rejects a listing marked as damaged', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Cheap car', 'description' => '', 'is_damaged' => true,
    ]);

    expect($score->flags)->toHaveCount(1)
        ->and($score->flags[0]->rule)->toBe('damaged-vehicle')
        ->and($score->score)->toBeLessThan((int) config('car_knowledge.reject_below_score'));
});

test('does not flag a listing explicitly marked as not damaged', function () {
    $score = (new ReliabilityScorer)->score(['title' => 'Clean car', 'description' => '', 'is_damaged' => false]);

    expect($score->flags)->toBeEmpty();
});

test('does not flag a listing where damage status is unknown (e.g. OLX)', function () {
    $score = (new ReliabilityScorer)->score(['title' => 'OLX car', 'description' => '', 'is_damaged' => null]);

    expect($score->flags)->toBeEmpty();
});

// --- High fuel consumption (populated by AutovitDetailFetcher/OlxDetailFetcher; null when unknown) ---

test('rejects a listing with fuel consumption above the threshold', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Thirsty car', 'description' => '', 'fuel_consumption_l_100km' => 9.5,
    ]);

    expect($score->flags)->toHaveCount(1)
        ->and($score->flags[0]->rule)->toBe('high-fuel-consumption')
        ->and($score->score)->toBeLessThan((int) config('car_knowledge.reject_below_score'));
});

test('does not flag consumption at or below the threshold', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Efficient car', 'description' => '', 'fuel_consumption_l_100km' => 8.0,
    ]);

    expect($score->flags)->toBeEmpty();
});

test('does not flag fuel consumption when it is unknown (e.g. OLX)', function () {
    $score = (new ReliabilityScorer)->score(['title' => 'OLX car', 'description' => '', 'fuel_consumption_l_100km' => null]);

    expect($score->flags)->toBeEmpty();
});

// --- New seller account ---

test('flags a seller account registered this year', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Suspiciously new seller', 'description' => '', 'seller_registered_year' => (int) date('Y'),
    ]);

    expect($score->flags)->toHaveCount(1)
        ->and($score->flags[0]->rule)->toBe('new-seller-account');
});

test('does not flag a seller account registered in a previous year', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Established seller', 'description' => '', 'seller_registered_year' => (int) date('Y') - 2,
    ]);

    expect($score->flags)->toBeEmpty();
});

test('does not flag when the seller registration year is unknown', function () {
    $score = (new ReliabilityScorer)->score(['title' => 'Unknown seller', 'description' => '', 'seller_registered_year' => null]);

    expect($score->flags)->toBeEmpty();
});

test('a moderate new-seller penalty does not reject on its own, but stacks with another flag', function () {
    $aloneScore = (new ReliabilityScorer)->score([
        'title' => 'New seller, otherwise clean', 'description' => '', 'seller_registered_year' => (int) date('Y'),
    ]);

    expect($aloneScore->score)->toBeGreaterThanOrEqual((int) config('car_knowledge.reject_below_score'));

    $stackedScore = (new ReliabilityScorer)->score([
        'title' => 'New seller, also low mileage', 'description' => '',
        'seller_registered_year' => (int) date('Y'),
        'year' => (int) date('Y') - 10, 'mileage_km' => 5000,
    ]);

    expect($stackedScore->flags)->toHaveCount(2)
        ->and($stackedScore->score)->toBeLessThan($aloneScore->score);
});

// --- Scoring composition ---

test('sums multiple penalties and floors the score at 0', function () {
    ReliabilityRule::create([
        'name' => 'big-penalty-1',
        'keywords' => [['badengine']],
        'penalty' => 60,
        'message' => 'Test.',
    ]);
    ReliabilityRule::create([
        'name' => 'big-penalty-2',
        'keywords' => [['badengine']],
        'penalty' => 60,
        'message' => 'Test.',
    ]);

    $score = (new ReliabilityScorer)->score(['title' => 'Badengine car', 'description' => '']);

    expect($score->flags)->toHaveCount(2)
        ->and($score->score)->toBe(0);
});

// --- Implausible mileage (typo / scam) ---

test('hard-rejects a used car advertised with an impossibly low mileage like 350 km', function () {
    $score = (new ReliabilityScorer)->score([
        'title' => 'Ford Mondeo 2015', 'description' => '',
        'year' => 2015, 'mileage_km' => 350,
    ]);

    expect(collect($score->flags)->pluck('rule'))->toContain('implausible-mileage')
        ->and($score->score)->toBe(0)
        ->and($score->score)->toBeLessThan((int) config('car_knowledge.reject_below_score'));
});

test('does not treat a plausible mileage or a current-year car as implausible', function () {
    $scorer = new ReliabilityScorer;

    $plausible = $scorer->score(['title' => 'Logan', 'description' => '', 'year' => (int) date('Y') - 4, 'mileage_km' => 60000]);
    $brandNew = $scorer->score(['title' => 'New', 'description' => '', 'year' => (int) date('Y'), 'mileage_km' => 50]);

    expect($plausible->flags)->toBeEmpty()
        ->and($brandNew->flags)->toBeEmpty();
});

// --- The strict threshold ---

test('the reject threshold is 90, so any flag of 11+ points rejects', function () {
    expect(config('car_knowledge.reject_below_score'))->toBe(90);

    $lowMileage = (new ReliabilityScorer)->score([
        'title' => 'Old car', 'description' => '', 'year' => (int) date('Y') - 10, 'mileage_km' => 5000,
    ]);

    expect($lowMileage->score)->toBeLessThan(90);
});

// --- Seeded engine/gearbox rules ---

test('the seeded rules flag well-known problem engines and gearboxes but not good ones', function (string $title, bool $flagged) {
    $this->seed(ReliabilityRuleSeeder::class);

    $score = (new ReliabilityScorer)->score(['title' => $title, 'description' => '']);

    expect($score->score < 90)->toBe($flagged);
})->with([
    'DQ200 dry DSG' => ['Skoda Rapid 1.2 TSI DSG', true],
    'PureTech' => ['Peugeot 301 1.2 PureTech', true],
    'Renault TCe 1.2' => ['Renault Megane 1.2 TCe combi', true],
    'Dacia EDC' => ['Dacia Logan Renault EDC', true],
    'Opel 1.4 turbo' => ['Opel Astra 1.4 Turbo sedan', true],
    'Good: Logan 0.9 TCe' => ['Dacia Logan 0.9 TCe GPL', false],
    'Good: Toyota Avensis 1.8' => ['Toyota Avensis 1.6 benzina', false],
    'Good: Logan 1.2 16v' => ['Dacia Logan 1.2 16v', false],
    'Good: Skoda Rapid manual' => ['Skoda Rapid 1.2 TSI manual', false],
]);
