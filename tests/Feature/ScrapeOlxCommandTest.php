<?php

use App\Models\Listing;
use App\Models\ReliabilityRule;
use App\Models\SearchCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    SearchCriterion::query()->delete();
    SearchCriterion::create(['key' => 'price_max', 'value' => '7000']);
    SearchCriterion::create(['key' => 'price_currency', 'value' => 'EUR']);
    SearchCriterion::create(['key' => 'year_min', 'value' => '2013']);
    SearchCriterion::create(['key' => 'km_max', 'value' => '230000']);
    SearchCriterion::create(['key' => 'engine_capacity_max', 'value' => '2.0']);
    SearchCriterion::create(['key' => 'body_type', 'value' => 'sedan,break']);

    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
    ]);
});

function fakeOlxSearchPage(string $fixture): void
{
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path("tests/Fixtures/{$fixture}")),
            200,
        ),
    ]);
}

test('stores listings within price_max and skips the one over budget', function () {
    fakeOlxSearchPage('olx_search_page.html');

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::count())->toBe(2)
        ->and(Listing::where('external_id', '309897773')->value('price'))->toBe(6350)
        ->and(Listing::where('external_id', '304105803')->exists())->toBeTrue()
        ->and(Listing::where('external_id', '309726208')->exists())->toBeFalse(); // 7900 EUR, over budget
});

test('running it again does not duplicate rows, and updates a changed price', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page.html')), 200)
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page_price_changed.html')), 200),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);
    expect(Listing::count())->toBe(2);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::count())->toBe(2) // still 2, not 4
        ->and(Listing::where('external_id', '309897773')->value('price'))->toBe(6000); // updated
});

test('stops paginating once OLX clamps back to an already-seen page instead of re-saving it', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page.html')), 200)
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page_clamped.html')), 200)
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page_clamped.html')), 200),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 5])->assertExitCode(0);

    // Only page 1's real listings are stored; the clamped "page 2" is detected and never fetched
    // a third time (Http::sequence() would throw if a 4th request were attempted).
    expect(Listing::count())->toBe(2);

    Http::assertSentCount(3); // robots.txt + page 1 + page 2 (which turns out to be the clamp)
});

test('rejects a listing that scores below the reliability threshold, and never saves it', function () {
    ReliabilityRule::create([
        'name' => 'test-bad-engine',
        'keywords' => [['badengine']],
        'penalty' => 50, // pushes a 100 base score to 50, below the default reject_below_score of 60
        'message' => 'Test known-problem engine.',
    ]);

    fakeOlxSearchPage('olx_search_page_reliability.html');

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9100000001')->exists())->toBeFalse();
});

test('saves a listing that passes the reliability check, with its score attached', function () {
    fakeOlxSearchPage('olx_search_page.html');

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    $listing = Listing::where('external_id', '309897773')->first();

    expect($listing)->not->toBeNull()
        ->and($listing->reliability_score)->toBe(100)
        ->and($listing->reliability_flags)->toBe([])
        ->and($listing->reliability_scored_at)->not->toBeNull();
});

test('builds the request URL with the confirmed OLX query params', function () {
    fakeOlxSearchPage('olx_search_page.html');

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    Http::assertSent(function ($request) {
        if (! str_starts_with((string) $request->url(), 'https://www.olx.ro/auto-masini-moto-ambarcatiuni')) {
            return true; // ignore the robots.txt request
        }

        $decoded = rawurldecode((string) $request->url());

        return str_contains($decoded, 'currency=EUR')
            && str_contains($decoded, 'filter_float_price:to]=7000')
            && str_contains($decoded, 'filter_float_year:from]=2013')
            && str_contains($decoded, 'filter_float_rulaj_pana:to]=230000')
            && str_contains($decoded, 'filter_enum_car_body][0]=sedan')
            && str_contains($decoded, 'filter_enum_car_body][1]=estate-car')
            && str_contains($decoded, 'filter_float_enginesize:to]=2000');
    });
});
