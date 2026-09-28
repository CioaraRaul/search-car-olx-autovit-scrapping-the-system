<?php

use App\Enums\ListingSource;
use App\Services\Scraping\OlxListingMapper;

function olxRawCard(array $overrides = []): array
{
    return array_merge([
        'id' => '309897773',
        'title' => 'Citroen C5 2.0 Diesel 163 CP fabricatie 07.2014',
        'relativeUrl' => '/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html?search_reason=search%7Cpromoted',
        'rawPrice' => '6 350 €',
        'photoUrl' => 'https://frankfurt.apollo.olxcdn.com:443/v1/files/slc149erhung2-RO/image;s=216x152;q=50',
        'rawLocationDate' => 'Oradea - 27 septembrie 2026',
        'rawYearMileage' => '2015  230 000 km',
    ], $overrides);
}

test('maps a real captured OLX card to Listing attributes', function () {
    $mapped = (new OlxListingMapper)->map(olxRawCard(), 'EUR');

    expect($mapped)->toMatchArray([
        'source' => ListingSource::Olx,
        'external_id' => '309897773',
        'title' => 'Citroen C5 2.0 Diesel 163 CP fabricatie 07.2014',
        'price' => 6350,
        'currency' => 'EUR',
        'year' => 2015,
        'mileage_km' => 230000,
        'engine_capacity_cc' => null,
        'horsepower' => null,
        'body_type' => null,
        'transmission' => null,
        'fuel_type' => null,
        'city' => 'Oradea',
        'url' => 'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html',
        'description' => null,
    ])
        ->and($mapped['photos'])->toBe([
            'https://frankfurt.apollo.olxcdn.com:443/v1/files/slc149erhung2-RO/image;s=216x152;q=50',
        ]);
});

test('strips the tracking query string when building the absolute url', function () {
    $mapped = (new OlxListingMapper)->map(olxRawCard(), 'EUR');

    expect($mapped['url'])->not->toContain('search_reason');
});

test('handles a card missing optional fields gracefully', function () {
    $mapped = (new OlxListingMapper)->map(olxRawCard([
        'title' => '',
        'photoUrl' => null,
        'rawLocationDate' => '',
        'rawYearMileage' => '',
        'relativeUrl' => '',
    ]), 'EUR');

    expect($mapped['title'])->toBeNull()
        ->and($mapped['photos'])->toBe([])
        ->and($mapped['city'])->toBeNull()
        ->and($mapped['year'])->toBeNull()
        ->and($mapped['mileage_km'])->toBeNull()
        ->and($mapped['url'])->toBe('');
});

test('parses a single-space year/mileage separator the same as OLX\'s double-space cards', function () {
    $mapped = (new OlxListingMapper)->map(olxRawCard([
        'rawYearMileage' => '2013 178 000 km',
    ]), 'EUR');

    expect($mapped['year'])->toBe(2013)
        ->and($mapped['mileage_km'])->toBe(178000);
});
