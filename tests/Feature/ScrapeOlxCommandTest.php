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
        'scraping.olx.detail_fetch_delay_min_ms' => 0,
        'scraping.olx.detail_fetch_delay_max_ms' => 0,
    ]);

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
        // Registered before the broader search-page pattern below, since Http::fake()
        // matches in registration order — every surviving listing's detail-page fetch
        // (OlxDetailFetcher) needs its own response, not the search page's.
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
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
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
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
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page.html')), 200)
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page_clamped.html')), 200)
            ->push(file_get_contents(base_path('tests/Fixtures/olx_search_page_clamped.html')), 200),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 5])->assertExitCode(0);

    // Only page 1's real listings are stored; the clamped "page 2" is detected and never fetched
    // a third time (Http::sequence() would throw if a 4th request were attempted).
    expect(Listing::count())->toBe(2);

    // robots.txt + page 1 search + 2 detail fetches (one per saved listing) + page 2 search
    // (which turns out to be the clamp).
    Http::assertSentCount(5);
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

test('rejects and never saves a listing whose own description discloses damage', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response(
            olxAdPageHtml('Vand masina avariata, lovita in fata, pret negociabil.'),
            200,
        ),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '309897773')->exists())->toBeFalse()
        ->and(Listing::where('external_id', '304105803')->exists())->toBeTrue(); // unaffected
});

test('rejects and never saves a listing with fuel consumption above the threshold', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response(
            olxAdPageHtml('Consum mixt 12.0 l/100km, masina puternica.'),
            200,
        ),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '309897773')->exists())->toBeFalse();
});

test('saves a clean listing with is_damaged=false and its fuel consumption', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response(
            olxAdPageHtml('Fara accident, consum mixt 5.0 l/100km.'),
            200,
        ),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    $listing = Listing::where('external_id', '309897773')->first();

    expect($listing->is_damaged)->toBeFalse()
        ->and((float) $listing->fuel_consumption_l_100km)->toBe(5.0);
});

test('flags (but does not auto-reject) a listing whose seller registered this year', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response(
            olxAdPageHtml('Masina intretinuta.', memberSince: 'Pe OLX din ianuarie '.date('Y')),
            200,
        ),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    $listing = Listing::where('external_id', '309897773')->first();

    expect($listing)->not->toBeNull() // moderate penalty alone doesn't reject
        ->and($listing->seller_registered_year)->toBe((int) date('Y'))
        ->and(collect($listing->reliability_flags)->pluck('rule'))->toContain('new-seller-account');
});

test('skips detail fetches once the daily cap is reached, but still saves listings', function () {
    config(['scraping.olx.detail_fetch_daily_cap' => 0]);

    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        // If this were ever fetched despite the cap, the listing would come back
        // damaged and get rejected — so a saved, non-damaged listing here proves
        // the fetch never happened.
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Avariata, lovita.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/d/oferta/'));

    $listing = Listing::where('external_id', '309897773')->first();

    expect($listing)->not->toBeNull()
        ->and($listing->is_damaged)->toBeNull()
        ->and($listing->detail_checked_at)->toBeNull();
});

test('reuses an already-checked listing\'s stored detail data instead of re-fetching', function () {
    Listing::create([
        'source' => ListingSource::Olx,
        'external_id' => '309897773',
        'title' => 'Old title',
        'price' => 6350,
        'currency' => 'EUR',
        'url' => 'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html',
        'is_damaged' => false,
        'fuel_consumption_l_100km' => 5.5,
        'detail_checked_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        // If this were fetched despite already being checked, is_damaged would
        // flip to true (and get rejected) instead of keeping the stored false.
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response(
            olxAdPageHtml('Avariata, lovita.'),
            200,
        ),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb'));

    $listing = Listing::where('external_id', '309897773')->first();

    expect($listing->is_damaged)->toBeFalse()
        ->and((float) $listing->fuel_consumption_l_100km)->toBe(5.5);
});

test('stops the run immediately on a 403 while fetching a detail page', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response('Blocked', 403),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    $this->artisan('scrape:olx', ['--pages' => 1])->assertExitCode(0);

    expect(Listing::where('external_id', '309897773')->exists())->toBeFalse()
        ->and(Listing::where('external_id', '304105803')->exists())->toBeFalse(); // never reached: run stopped
});

test('a non-403/429 detail-fetch failure still fails the command loudly', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/citroen-c5-2-0-diesel-163-cp-fabricatie-07-2014-IDkYizb.html*' => Http::response('Server error', 500),
        'https://www.olx.ro/d/oferta/*' => Http::response(olxAdPageHtml('Masina intretinuta.'), 200),
        'https://www.olx.ro/auto-masini-moto-ambarcatiuni/autoturisme/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/olx_search_page.html')),
            200,
        ),
    ]);

    expect(fn () => $this->artisan('scrape:olx', ['--pages' => 1])->run())
        ->toThrow(RequestException::class);
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
