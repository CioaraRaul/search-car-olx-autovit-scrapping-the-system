<?php

use App\Services\ExchangeRates\BnrExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    Http::fake([
        config('exchange_rates.bnr_url') => Http::response(
            file_get_contents(base_path('tests/Fixtures/bnr_nbrfxrates.xml')),
            200,
            ['Content-Type' => 'text/xml'],
        ),
    ]);
});

test('EUR conversion is a passthrough', function () {
    $service = new BnrExchangeRateService;

    expect($service->toEur(100, 'EUR'))->toBe(100.0);
});

test('converts RON to EUR using the RON/EUR rate', function () {
    $service = new BnrExchangeRateService;

    expect($service->toEur(5278.6, 'RON'))->toEqualWithDelta(1000.0, 0.01);
});

test('converts a third currency (USD) to EUR via RON', function () {
    $service = new BnrExchangeRateService;

    $expected = (100 * 4.6397) / 5.2786;

    expect($service->toEur(100, 'USD'))->toEqualWithDelta($expected, 0.0001);
});

test('divides a multiplier currency rate correctly', function () {
    $service = new BnrExchangeRateService;

    $expected = (1000 * (1.4350 / 100)) / 5.2786;

    expect($service->toEur(1000, 'HUF'))->toEqualWithDelta($expected, 0.0001);
});

test('fetches the BNR feed at most once per cache TTL', function () {
    $service = new BnrExchangeRateService;

    $service->toEur(10, 'USD');
    $service->toEur(20, 'USD');
    (new BnrExchangeRateService)->toEur(30, 'RON');

    Http::assertSentCount(1);
});
