<?php

use App\Services\Scraping\AutovitDetailFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Builds a minimal Autovit ad page around a given `details` array and an
 * optional `seller` array, matching the real shape found at
 * __NEXT_DATA__.props.pageProps.advert.{details,seller}.
 *
 * @param  array<int, array<string, mixed>>  $details
 * @param  array<string, mixed>  $seller
 */
function autovitAdPageHtml(array $details, array $seller = [], ?string $description = null): string
{
    $nextData = json_encode([
        'props' => [
            'pageProps' => [
                'advert' => [
                    'details' => $details,
                    'seller' => $seller,
                    'description' => $description,
                ],
            ],
        ],
    ]);

    return <<<HTML
        <html><body><script id="__NEXT_DATA__" type="application/json">{$nextData}</script></body></html>
        HTML;
}

function fakeAutovitAdPage(string $url, array $details, array $seller = []): void
{
    Http::fake([
        $url => Http::response(autovitAdPageHtml($details, $seller), 200),
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

test('reads the seller registration year from the registration-date badge', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/new-seller.html';
    fakeAutovitAdPage($url, [], seller: [
        'featuresBadges' => [
            ['code' => 'private-seller', 'label' => 'Persoana fizica'],
            ['code' => 'registration-date', 'label' => 'Vânzător pe Autovit.ro din 2025'],
        ],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['sellerRegisteredYear'])->toBe(2025);
});

test('sellerRegisteredYear is null when the badge is absent', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/no-badge.html';
    fakeAutovitAdPage($url, [], seller: [
        'featuresBadges' => [
            ['code' => 'private-seller', 'label' => 'Persoana fizica'],
        ],
    ]);

    $result = (new AutovitDetailFetcher)->fetch($url);

    expect($result['sellerRegisteredYear'])->toBeNull();
});

test('returns the ad description as plain text', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/desc-ID1.html';
    Http::fake([$url => Http::response(autovitAdPageHtml([], [], '<p>Vand Focus <b>berlina</b>,&nbsp;unic proprietar</p>'), 200)]);

    expect((new AutovitDetailFetcher)->fetch($url)['description'])->toContain('Focus berlina')->not->toContain('<');
});

test('the description is null when the ad has none', function () {
    $url = 'https://www.autovit.ro/autoturisme/anunt/nodesc-ID2.html';
    fakeAutovitAdPage($url, []);

    expect((new AutovitDetailFetcher)->fetch($url)['description'])->toBeNull();
});

test('reads the verified flag from advert.verifiedCar', function (mixed $raw, ?bool $expected) {
    $advert = $raw === 'missing' ? [] : ['verifiedCar' => $raw];
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(
            '<html><script id="__NEXT_DATA__" type="application/json">'.json_encode(['props' => ['pageProps' => ['advert' => $advert + ['details' => []]]]]).'</script></html>',
            200,
        ),
    ]);

    expect((new AutovitDetailFetcher)->fetch('https://www.autovit.ro/autoturisme/anunt/x.html')['verified'])->toBe($expected);
})->with([[true, true], [false, false], ['missing', null]]);
