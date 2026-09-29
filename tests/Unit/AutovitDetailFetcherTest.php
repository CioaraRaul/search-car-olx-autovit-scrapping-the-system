<?php

use App\Services\Scraping\AutovitDetailFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Builds a minimal Autovit ad page around a given `details` array, matching
 * the real shape found at __NEXT_DATA__.props.pageProps.advert.details.
 *
 * @param  array<int, array<string, mixed>>  $details
 */
function autovitAdPageHtml(array $details): string
{
    $nextData = json_encode([
        'props' => [
            'pageProps' => [
                'advert' => [
                    'details' => $details,
                ],
            ],
        ],
    ]);

    return <<<HTML
        <html><body><script id="__NEXT_DATA__" type="application/json">{$nextData}</script></body></html>
        HTML;
}

function fakeAutovitAdPage(string $url, array $details): void
{
    Http::fake([
        $url => Http::response(autovitAdPageHtml($details), 200),
    ]);
}

beforeEach(function () {
    Cache::flush();
});

test('reads damaged=true from a "Da" value', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/damaged-car.html';
    fakeAutovitAdPage($url, [
        ['key' => 'damaged', 'value' => 'Da'],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['damaged'])->toBeTrue();
});

test('reads damaged=false from a "Nu" value', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/clean-car.html';
    fakeAutovitAdPage($url, [
        ['key' => 'damaged', 'value' => 'Nu'],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['damaged'])->toBeFalse();
});

test('damaged is null when the field is absent', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/no-damage-field.html';
    fakeAutovitAdPage($url, [
        ['key' => 'make', 'value' => 'Kia'],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['damaged'])->toBeNull();
});

test('averages urban and extra-urban consumption', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/thirsty-car.html';
    fakeAutovitAdPage($url, [
        ['key' => 'urban_consumption', 'value' => '5.6 l/100km'],
        ['key' => 'extra_urban_consumption', 'value' => '4.1 l/100km'],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['fuelConsumptionL100km'])->toBe(4.85);
});

test('uses whichever consumption figure is present when only one exists', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/partial-consumption.html';
    fakeAutovitAdPage($url, [
        ['key' => 'urban_consumption', 'value' => '7.2 l/100km'],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['fuelConsumptionL100km'])->toBe(7.2);
});

test('fuelConsumptionL100km is null when neither figure is present', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/no-consumption.html';
    fakeAutovitAdPage($url, [
        ['key' => 'make', 'value' => 'Kia'],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['fuelConsumptionL100km'])->toBeNull();
});
