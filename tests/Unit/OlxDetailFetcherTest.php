<?php

use App\Services\Scraping\OlxDetailFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Builds a minimal OLX ad page with the given description text inside
 * [data-testid="ad_description"], optionally with an embedded <style> tag
 * (emotion CSS-in-JS does this on the real site) to prove it gets stripped,
 * and an optional "Pe OLX din <date>" member-since string.
 */
function olxAdPageHtml(string $description, bool $withEmbeddedStyle = false, string $memberSince = ''): string
{
    $style = $withEmbeddedStyle ? '<style>.css-4upmi{text-transform:uppercase}</style>' : '';
    $memberSinceHtml = $memberSince !== '' ? "<p data-testid=\"member-since\">{$memberSince}</p>" : '';

    return <<<HTML
        <html><body><div data-testid="ad_description">{$style}<div>{$description}</div></div>{$memberSinceHtml}</body></html>
        HTML;
}

function fakeOlxAdPage(string $url, string $description, bool $withEmbeddedStyle = false, string $memberSince = ''): void
{
    Http::fake([
        $url => Http::response(olxAdPageHtml($description, $withEmbeddedStyle, $memberSince), 200),
    ]);
}

beforeEach(function () {
    Cache::flush();
});

test('flags a description that self-discloses damage', function () {
    $url = 'https://www.olx.ro/d/oferta/damaged-car.html';
    fakeOlxAdPage($url, 'Vand masina avariata, lovita in spate, pret negociabil.');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['damaged'])->toBeTrue();
});

test('does not flag "fara accident" despite containing the substring "accident"', function () {
    $url = 'https://www.olx.ro/d/oferta/clean-car.html';
    fakeOlxAdPage($url, 'Masina fara accident, neavariata, unic proprietar.');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['damaged'])->toBeFalse();
});

test('damaged is null when the description says nothing about accident history', function () {
    $url = 'https://www.olx.ro/d/oferta/unmentioned.html';
    fakeOlxAdPage($url, 'Masina frumoasa, intretinuta, toate reviziile la zi.');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['damaged'])->toBeNull();
});

test('strips an embedded style tag before reading the description', function () {
    $url = 'https://www.olx.ro/d/oferta/styled.html';
    fakeOlxAdPage($url, 'Fara accident, stare perfecta.', withEmbeddedStyle: true);

    $result = (new OlxDetailFetcher)->fetch($url);

    // Would be null (ambiguous) if the CSS text leaked in and masked the real content.
    expect($result['damaged'])->toBeFalse();
});

test('prefers an explicit combined ("mixt") consumption figure', function () {
    $url = 'https://www.olx.ro/d/oferta/full-spec.html';
    fakeOlxAdPage($url, 'Consumul de combustibil - urban 5.8 l/100km Consumul de combustibil - extra-urban 4.3 l/100km Consumul de combustibil - mixt 4.9 l/100km');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['fuelConsumptionL100km'])->toBe(4.9);
});

test('averages urban and extra-urban when no combined figure is present', function () {
    $url = 'https://www.olx.ro/d/oferta/partial-spec.html';
    fakeOlxAdPage($url, 'Consum urban 5.6 l/100km, consum extra-urban 4.1 l/100km.');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['fuelConsumptionL100km'])->toBe(4.85);
});

test('fuelConsumptionL100km is null when the description says nothing about consumption', function () {
    $url = 'https://www.olx.ro/d/oferta/no-consumption.html';
    fakeOlxAdPage($url, 'Masina frumoasa, intretinuta.');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['fuelConsumptionL100km'])->toBeNull();
});

test('reads the seller registration year from "Pe OLX din <month> <year>"', function () {
    $url = 'https://www.olx.ro/d/oferta/new-seller.html';
    fakeOlxAdPage($url, 'Masina intretinuta.', memberSince: 'Pe OLX din martie 2026');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['sellerRegisteredYear'])->toBe(2026);
});

test('sellerRegisteredYear is null when member-since is absent', function () {
    $url = 'https://www.olx.ro/d/oferta/no-member-since.html';
    fakeOlxAdPage($url, 'Masina intretinuta.');

    $result = (new OlxDetailFetcher)->fetch($url);

    expect($result['sellerRegisteredYear'])->toBeNull();
});

// --- detectDamaged() directly (used on a title, without any HTTP request) ---

test('detectDamaged flags a title that self-discloses damage', function () {
    $result = (new OlxDetailFetcher)->detectDamaged('Renault Talisman 1.6 Dci Automat // 2017 // Avariat Lovit Spate');

    expect($result)->toBeTrue();
});

test('detectDamaged does not flag a clean title', function () {
    $result = (new OlxDetailFetcher)->detectDamaged('VW Golf 7, 1.2 TSI, 105 cp, Euro 5, import Germania');

    expect($result)->toBeNull();
});

test('detectDamaged returns false for an explicit "neaccidentata" title', function () {
    $result = (new OlxDetailFetcher)->detectDamaged('Skoda Octavia 2016 neaccidentata, unic proprietar');

    expect($result)->toBeFalse();
});
