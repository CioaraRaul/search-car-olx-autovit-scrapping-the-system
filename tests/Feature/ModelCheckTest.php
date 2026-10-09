<?php

use App\Enums\ListingSource;
use App\Mail\ListingsDigest;
use App\Models\Listing;
use App\Models\ModelReputation;
use App\Services\Listings\ListingShortlist;
use App\Services\Reliability\ModelCheck;
use App\Services\Reliability\ReliabilityScorer;
use Database\Seeders\ModelReputationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(ModelReputationSeeder::class));

test('recognises a known model from the ad title', function (string $title, string $model, string $verdict) {
    $row = app(ModelCheck::class)->find($title);

    expect($row->model)->toBe($model)->and($row->verdict)->toBe($verdict);
})->with([
    ['Dacia Logan 1.4 MPI 2015', 'Logan', 'recommended'],
    ['Volkswagen Passat B7 1.8 TSI', 'Passat', 'acceptable'],
    ['VW Golf Variant 1.6', 'Golf Variant', 'acceptable'],
    ['Mazda 6 2.0 benzina', 'Mazda 6', 'recommended'],
    ['Renault Laguna 2.0', 'Laguna', 'avoid'],
    ['Skoda Octavia Combi', 'Octavia', 'acceptable'],
]);

test('a title that names no known model is not acceptable', function (string $title) {
    $check = app(ModelCheck::class);

    expect($check->find($title))->toBeNull()
        ->and($check->rejectionReason($title))->toBe('unknown-model')
        ->and($check->isAcceptable($title))->toBeFalse();
})->with(['Masina de vanzare 2015 impecabila', 'Mazda 2013 benzina 150000 km', '', 'Autoturism']);

test('a model marked avoid is rejected', function () {
    expect(app(ModelCheck::class)->rejectionReason('Renault Laguna 2.0 benzina'))->toBe('avoid-model');
});

test('a bare number in the title is not mistaken for a model', function () {
    // "3" appears as a mileage digit group; only "Mazda 3" as a phrase names the model.
    expect(app(ModelCheck::class)->find('Opel Astra 3 proprietari'))->not->toBeNull()
        ->and(app(ModelCheck::class)->find('Opel Astra 3 proprietari')->model)->toBe('Astra');
});

test('an inactive reputation row is ignored', function () {
    ModelReputation::where('model', 'Logan')->update(['active' => false]);

    expect(app(ModelCheck::class)->find('Dacia Logan'))->toBeNull();
});

test('the email shortlist drops cars of unknown or avoid models', function () {
    $make = fn (string $id, string $title) => Listing::create([
        'source' => ListingSource::Olx, 'external_id' => $id, 'title' => $title, 'price' => 6000,
        'currency' => 'EUR', 'year' => 2015, 'mileage_km' => 120000 + (int) $id, 'url' => "https://x.test/{$id}",
        'description' => 'berlina', 'detail_checked_at' => now(), 'reliability_score' => 100,
    ]);

    $good = $make('1', 'Dacia Logan berlina');
    $make('2', 'Renault Laguna berlina');
    $make('3', 'Masina necunoscuta berlina');

    expect(app(ListingShortlist::class)->filter(Listing::all())->pluck('id')->all())->toBe([$good->id]);
});

test('an unverified Autovit ad scores 90, a verified one 100, and OLX ads are untouched', function () {
    config(['car_knowledge.reject_below_score' => 90]);
    $score = fn (array $l) => app(ReliabilityScorer::class)->score($l + ['title' => 'Dacia Logan'])->score;

    expect($score(['source' => ListingSource::Autovit, 'autovit_verified' => false]))->toBe(90)
        ->and($score(['source' => 'autovit', 'autovit_verified' => false]))->toBe(90)
        ->and($score(['source' => ListingSource::Autovit, 'autovit_verified' => true]))->toBe(100)
        ->and($score(['source' => ListingSource::Autovit, 'autovit_verified' => null]))->toBe(100)
        ->and($score(['source' => ListingSource::Olx, 'autovit_verified' => false]))->toBe(100);
});

test('an unverified Autovit ad is flagged and shown with a warning triangle in the email', function () {
    $listing = Listing::create([
        'source' => ListingSource::Autovit, 'external_id' => 'a1', 'title' => 'Dacia Logan berlina', 'price' => 6000,
        'currency' => 'EUR', 'url' => 'https://x.test/a1', 'autovit_verified' => false, 'reliability_score' => 90,
        'reliability_flags' => [['rule' => 'unverified-autovit-details', 'message' => 'Autovit has not verified the details of this ad.', 'penalty' => 10]],
    ]);

    $html = (new ListingsDigest(Listing::whereKey($listing->id)->get()))->render();

    expect($html)->toContain('Not verified by Autovit')->toContain('Logan');
});

test('scam wording is rejected but a seller abroad or financing talk is not', function (string $description, bool $flagged) {
    $score = app(ReliabilityScorer::class)->score(['title' => 'Dacia Logan', 'description' => $description])->score;

    expect($score < 90)->toBe($flagged);
})->with([
    ['Trimiteti avans prin transfer si va livrez masina', true],
    ['Plata in avans, masina este la firma de transport', true],
    ['Accept doar Western Union', true],
    ['Livrez masina prin curier, nu este nevoie sa o vedeti', true],
    ['Masina impecabila, ITP valid, carte service', false],
    ['Sunt plecat in strainatate dar masina se poate vedea la ruda mea', false],
    ['Posibilitate finantare in rate, avans 20%', false],
]);
