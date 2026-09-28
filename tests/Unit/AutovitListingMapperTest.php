<?php

use App\Enums\ListingSource;
use App\Services\Scraping\AutovitListingMapper;

function autovitListingNode(): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/autovit_listing_node.json')),
        associative: true,
    );
}

test('maps a real captured Autovit listing node to Listing attributes', function () {
    $mapped = (new AutovitListingMapper)->map(autovitListingNode());

    expect($mapped)->toMatchArray([
        'source' => ListingSource::Autovit,
        'external_id' => '7060904828',
        'title' => 'Skoda Kamiq 1.5 TSI DSG Style',
        'price' => 23700,
        'currency' => 'RON',
        'year' => 2025,
        'mileage_km' => 15100,
        'engine_capacity_cc' => 1498,
        'horsepower' => 150,
        'body_type' => null,
        'transmission' => null,
        'fuel_type' => 'petrol',
        'city' => 'Ramnicu Valcea',
        'url' => 'https://www.autovit.ro/autoturisme/anunt/skoda-kamiq-ver-1-5-tsi-dsg-style-ID7HQPTK.html',
        'description' => 'Style, 1.5 tsi, 150 cp, efectiv nouă',
    ])
        ->and($mapped['photos'])->toBe([
            'https://ireland.apollo.olxcdn.com/v1/files/x5l4ar9ltqg73-AUTOVITRO/image;s=320x240',
            'https://ireland.apollo.olxcdn.com/v1/files/x5l4ar9ltqg73-AUTOVITRO/image;s=640x480',
        ]);
});

test('handles a listing missing optional parameters gracefully', function () {
    $node = autovitListingNode();
    $node['parameters'] = [];
    $node['thumbnail'] = [];

    $mapped = (new AutovitListingMapper)->map($node);

    expect($mapped['year'])->toBeNull()
        ->and($mapped['mileage_km'])->toBeNull()
        ->and($mapped['engine_capacity_cc'])->toBeNull()
        ->and($mapped['horsepower'])->toBeNull()
        ->and($mapped['fuel_type'])->toBeNull()
        ->and($mapped['photos'])->toBe([]);
});
