<?php

use App\Enums\ListingSource;
use App\Models\Listing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeListing(array $overrides = []): Listing
{
    return Listing::create(array_merge([
        'source' => ListingSource::Olx,
        'external_id' => 'abc123',
        'price' => 5000,
        'currency' => 'EUR',
        'url' => 'https://example.test/listing/abc123',
        'photos' => ['https://example.test/photo1.jpg'],
    ], $overrides));
}

test('source and external_id together must be unique', function () {
    makeListing();

    expect(fn () => makeListing())->toThrow(QueryException::class);
});

test('the same external_id from a different source is allowed', function () {
    makeListing(['source' => ListingSource::Olx]);
    $second = makeListing(['source' => ListingSource::Autovit]);

    expect($second->exists)->toBeTrue();
});

test('source is cast to the ListingSource enum', function () {
    $listing = makeListing();

    expect($listing->fresh()->source)->toBe(ListingSource::Olx);
});

test('photos round-trip as an array through the JSON cast', function () {
    $listing = makeListing(['photos' => ['a.jpg', 'b.jpg']]);

    expect($listing->fresh()->photos)->toBe(['a.jpg', 'b.jpg']);

    $raw = DB::table('listings')->where('id', $listing->id)->value('photos');
    expect($raw)->toBeString();
});
