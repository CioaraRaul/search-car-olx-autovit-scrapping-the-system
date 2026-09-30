<?php

use App\Enums\ListingSource;
use App\Models\Listing;
use App\Models\ReliabilityRule;
use App\Models\SearchCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // The real 3-8s random delay between detail-page fetches (config default) would
    // make this whole suite take minutes; keep the mechanism but make it instant here.
    config([
        'scraping.autovit.detail_fetch_delay_min_ms' => 0,
        'scraping.autovit.detail_fetch_delay_max_ms' => 0,
    ]);

    SearchCriterion::query()->delete();
    SearchCriterion::create(['key' => 'price_max', 'value' => '7000']);
    SearchCriterion::create(['key' => 'price_currency', 'value' => 'EUR']);
    SearchCriterion::create(['key' => 'year_min', 'value' => '2013']);
    SearchCriterion::create(['key' => 'km_max', 'value' => '230000']);
    SearchCriterion::create(['key' => 'engine_capacity_max', 'value' => '2.0']);

    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
    ]);
});

function fakeAutovitSearchPage(string $fixture): void
{
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        // Registered before the broader search-page pattern below, since Http::fake()
        // matches in registration order — every surviving listing's detail-page fetch
        // (AutovitDetailFetcher) needs its own response, not the search page's.
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
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
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::sequence()
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

test('rejects and never saves a listing marked as damaged on its own ad page', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/9000000001.html' => Http::response(
            autovitAdPageHtml([['key' => 'damaged', 'value' => 'Da']]),
            200,
        ),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9000000001')->exists())->toBeFalse()
        ->and(Listing::where('external_id', '9000000002')->exists())->toBeTrue(); // unaffected
});

test('rejects and never saves a listing with fuel consumption above the threshold', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/9000000001.html' => Http::response(
            autovitAdPageHtml([
                ['key' => 'urban_consumption', 'value' => '12.0 l/100km'],
                ['key' => 'extra_urban_consumption', 'value' => '10.0 l/100km'],
            ]),
            200,
        ),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9000000001')->exists())->toBeFalse();
});

test('saves a clean listing with is_damaged=false and its fuel consumption', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/9000000001.html' => Http::response(
            autovitAdPageHtml([
                ['key' => 'damaged', 'value' => 'Nu'],
                ['key' => 'urban_consumption', 'value' => '5.6 l/100km'],
                ['key' => 'extra_urban_consumption', 'value' => '4.1 l/100km'],
            ]),
            200,
        ),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    $listing = Listing::where('external_id', '9000000001')->first();

    expect($listing->is_damaged)->toBeFalse()
        ->and((float) $listing->fuel_consumption_l_100km)->toBe(4.9);
});

test('flags (but does not auto-reject) a listing whose seller registered this year', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/9000000001.html' => Http::response(
            autovitAdPageHtml([], seller: [
                'featuresBadges' => [
                    ['code' => 'registration-date', 'label' => 'Vânzător pe Autovit.ro din '.date('Y')],
                ],
            ]),
            200,
        ),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    $listing = Listing::where('external_id', '9000000001')->first();

    expect($listing)->not->toBeNull() // moderate penalty alone doesn't reject
        ->and($listing->seller_registered_year)->toBe((int) date('Y'))
        ->and(collect($listing->reliability_flags)->pluck('rule'))->toContain('new-seller-account');
});

test('skips detail fetches once the daily cap is reached, but still saves listings', function () {
    config(['scraping.autovit.detail_fetch_daily_cap' => 0]);

    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        // If this were ever fetched despite the cap, the listing would come back
        // damaged and get rejected — so a saved, non-damaged listing here proves
        // the fetch never happened.
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(
            autovitAdPageHtml([['key' => 'damaged', 'value' => 'Da']]),
            200,
        ),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/autoturisme/anunt/'));

    $listing = Listing::where('external_id', '9000000001')->first();

    expect($listing)->not->toBeNull()
        ->and($listing->is_damaged)->toBeNull()
        ->and($listing->detail_checked_at)->toBeNull();
});

test('reuses an already-checked listing\'s stored detail data instead of re-fetching', function () {
    Listing::create([
        'source' => ListingSource::Autovit,
        'external_id' => '9000000001',
        'title' => 'Old title',
        'price' => 4500,
        'currency' => 'EUR',
        'url' => 'https://www.autovit.ro/autoturisme/anunt/9000000001.html',
        'is_damaged' => false,
        'fuel_consumption_l_100km' => 5.5,
        'detail_checked_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        // If this were fetched despite already being checked, is_damaged would
        // flip to true (and get rejected) instead of keeping the stored false.
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(
            autovitAdPageHtml([['key' => 'damaged', 'value' => 'Da']]),
            200,
        ),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    // The fixture's other listing (9000000002) was never checked before, so it
    // legitimately still fetches its own detail page — only 9000000001's is skipped.
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/autoturisme/anunt/9000000001.html'));

    $listing = Listing::where('external_id', '9000000001')->first();

    expect($listing->is_damaged)->toBeFalse()
        ->and((float) $listing->fuel_consumption_l_100km)->toBe(5.5);
});

test('stops the run immediately on a 403 while fetching a detail page', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/9000000001.html' => Http::response('Blocked', 403),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9000000001')->exists())->toBeFalse()
        ->and(Listing::where('external_id', '9000000002')->exists())->toBeFalse(); // never reached: run stopped
});

test('a non-403/429 detail-fetch failure still fails the command loudly', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/9000000001.html' => Http::response('Server error', 500),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page.html')),
            200,
        ),
    ]);

    expect(fn () => $this->artisan('scrape:autovit', ['--pages' => 1])->run())
        ->toThrow(RequestException::class);
});

test('filters out a hatchback-only model when body_type restricts to sedan/break', function () {
    SearchCriterion::create(['key' => 'body_type', 'value' => 'sedan,break']);

    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page_body_type.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9200000001')->exists())->toBeFalse() // Polo: not on the sedan/estate whitelist
        ->and(Listing::where('external_id', '9200000002')->exists())->toBeTrue(); // Octavia: whitelisted
});

test('does not filter by body-type mismatch when no body_type criterion is set', function () {
    SearchCriterion::where('key', 'body_type')->delete();

    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/autoturisme/anunt/*' => Http::response(autovitAdPageHtml([]), 200),
        'https://www.autovit.ro/autoturisme?*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/autovit_search_page_body_type.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '9200000001')->exists())->toBeTrue();
});

test('builds the request URL without price or order params, per robots.txt', function () {
    SearchCriterion::create(['key' => 'body_type', 'value' => 'sedan,break']);
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

test('skips EUR listings cheaper than price_min', function () {
    SearchCriterion::create(['key' => 'price_min', 'value' => '7000']);

    fakeAutovitSearchPage('autovit_search_page.html');

    $this->artisan('scrape:autovit', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('currency', 'EUR')->count())->toBe(0);
});
