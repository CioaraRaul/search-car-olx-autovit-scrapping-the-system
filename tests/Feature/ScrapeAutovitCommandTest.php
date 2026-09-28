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
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
    ]);
});

function fakeAutovitSearchPage(string $fixture): void
{
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme*' => Http::response(
            file_get_contents(base_path("tests/Fixtures/{$fixture}")),
            200,
        ),
    ]);
}

test('stores EUR listings within price_max, stores RON listings regardless, skips EUR listings over price_max', function () {
    fakeAutovitSearchPage('autovit_search_page.html');

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::count())->toBe(2)
        ->and(Listing::where('external_id', '9000000001')->value('price'))->toBe(4500) // EUR, under budget: kept
        ->and(Listing::where('external_id', '9000000002')->exists())->toBeTrue() // RON: kept, not comparable yet
        ->and(Listing::where('external_id', '9000000003')->exists())->toBeFalse(); // EUR, over budget: filtered out
});

test('running it again does not duplicate rows, and updates a changed price', function () {
    // A second Http::fake() call for the same URL pattern doesn't override an
    // already-matched one within a test — use a sequence so each artisan() call
    // consumes the next response in order, matching two real, separate runs.
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme*' => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')), 200)
            ->push(file_get_contents(base_path('tests/Fixtures/autovit_search_page_price_changed.html')), 200),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);
    expect(Listing::count())->toBe(2);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::count())->toBe(2) // still 2, not 4
        ->and(Listing::where('external_id', '9000000001')->value('price'))->toBe(4200); // updated
});

test('rejects a listing that scores below the reliability threshold, and never saves it', function () {
    ReliabilityRule::create([
        'name' => 'test-bad-engine',
        'keywords' => [['badengine']],
        'penalty' => 50, // pushes a 100 base score to 50, below the default reject_below_score of 60
        'message' => 'Test known-problem engine.',
    ]);

    fakeAutovitSearchPage('autovit_search_page_reliability.html');

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9100000001')->exists())->toBeFalse();
});

test('saves a listing that passes the reliability check, with its score attached', function () {
    fakeAutovitSearchPage('autovit_search_page.html');

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    $listing = Listing::where('external_id', '9000000001')->first();

    expect($listing)->not->toBeNull()
        ->and($listing->reliability_score)->toBe(100) // no rules seeded in this test, nothing to flag
        ->and($listing->reliability_flags)->toBe([])
        ->and($listing->reliability_scored_at)->not->toBeNull();
});

test('builds the request URL without price or order params, per robots.txt', function () {
    fakeAutovitSearchPage('autovit_search_page.html');

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    Http::assertSent(function ($request) {
        if (! str_starts_with((string) $request->url(), 'https://www.autovit.ro/autoturisme')) {
            return true; // ignore the robots.txt request
        }

        $decoded = rawurldecode((string) $request->url());

        return ! str_contains($decoded, '_price')
            && ! str_contains($decoded, '[order]=')
            && str_contains($decoded, 'filter_float_year:from]=2013')
            && str_contains($decoded, 'filter_enum_body_type][0]=sedan')
            && str_contains($decoded, 'filter_enum_body_type][1]=combi');
    });
});
