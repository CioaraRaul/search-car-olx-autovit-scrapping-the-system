<?php

use App\Services\Scraping\RobotsTxtGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

function fakeRobotsTxt(string $baseUrl): void
{
    Http::fake([
        $baseUrl.'/robots.txt' => Http::response(
            file_get_contents(base_path('tests/Fixtures/robots_with_wildcards.txt')),
            200,
        ),
    ]);
}

test('disallows a URL matching a wildcard price pattern', function () {
    fakeRobotsTxt('https://example-autovit.test');

    $guard = RobotsTxtGuard::for('https://example-autovit.test');

    expect($guard->isAllowed('https://example-autovit.test/autoturisme?search%5Bfilter_float_price%3Ato%5D=7000'))
        ->toBeFalse();
});

test('disallows a URL matching the wildcard order pattern', function () {
    fakeRobotsTxt('https://example-autovit.test');

    $guard = RobotsTxtGuard::for('https://example-autovit.test');

    expect($guard->isAllowed('https://example-autovit.test/autoturisme?search%5Border%5D=created_at_first%3Adesc'))
        ->toBeFalse();
});

test('allows a URL with no matching disallow pattern', function () {
    fakeRobotsTxt('https://example-autovit.test');

    $guard = RobotsTxtGuard::for('https://example-autovit.test');

    expect($guard->isAllowed('https://example-autovit.test/autoturisme?search%5Bfilter_float_year%3Afrom%5D=2013'))
        ->toBeTrue();
});

test('disallows a plain prefix-matched path', function () {
    fakeRobotsTxt('https://example-autovit.test');

    $guard = RobotsTxtGuard::for('https://example-autovit.test');

    expect($guard->isAllowed('https://example-autovit.test/api/anything'))->toBeFalse();
});

test('fails open to no rules when robots.txt cannot be fetched', function () {
    Http::fake([
        'https://example-unreachable.test/robots.txt' => Http::response('', 500),
    ]);

    $guard = RobotsTxtGuard::for('https://example-unreachable.test');

    expect($guard->isAllowed('https://example-unreachable.test/anything?filter_float_price=1'))->toBeTrue();
});

test('only fetches robots.txt once per base URL thanks to caching', function () {
    fakeRobotsTxt('https://example-autovit.test');

    RobotsTxtGuard::for('https://example-autovit.test');
    RobotsTxtGuard::for('https://example-autovit.test');
    RobotsTxtGuard::for('https://example-autovit.test');

    Http::assertSentCount(1);
});
