<?php

use App\Enums\ListingSource;
use App\Mail\ListingsDigest;
use App\Models\Listing;
use App\Services\Scraping\AdPhotoFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function reviewListing(array $overrides = []): Listing
{
    return Listing::create(array_merge([
        'source' => ListingSource::Olx,
        'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'title' => 'Dacia Logan',
        'price' => 5500,
        'currency' => 'EUR',
        'year' => 2016,
        'mileage_km' => fake()->unique()->numberBetween(50000, 200000),
        'url' => 'https://www.olx.ro/d/oferta/logan-IDabc.html',
        'reliability_score' => 100,
    ], $overrides));
}

// --- Storing a review ---

test('listings:looks-set stores the score, notes and time', function () {
    $listing = reviewListing();

    $this->artisan('listings:looks-set', ['id' => $listing->id, 'score' => 82, 'notes' => 'Clean paint, tidy interior'])
        ->assertExitCode(0);

    $fresh = $listing->fresh();
    expect($fresh->looks_score)->toBe(82)
        ->and($fresh->looks_notes)->toBe('Clean paint, tidy interior')
        ->and($fresh->looks_scored_at)->not->toBeNull();
});

test('listings:looks-set rejects a score outside 0-100 and an unknown id', function () {
    $listing = reviewListing();

    $this->artisan('listings:looks-set', ['id' => $listing->id, 'score' => 150])->assertExitCode(1);
    $this->artisan('listings:looks-set', ['id' => $listing->id, 'score' => 'abc'])->assertExitCode(1);
    $this->artisan('listings:looks-set', ['id' => 99999, 'score' => 50])->assertExitCode(1);

    expect($listing->fresh()->looks_score)->toBeNull();
});

// --- The shortlist ---

test('listings:shortlist lists unreviewed cars that pass the filters and skips reviewed or rejected ones', function () {
    $unreviewed = reviewListing(['title' => 'Dacia Logan Unreviewed']);
    reviewListing(['title' => 'Dacia Logan Reviewed', 'looks_score' => 80]);
    reviewListing(['title' => 'Dacia Logan Low Score', 'reliability_score' => 50]);

    $this->artisan('listings:shortlist')
        ->expectsOutputToContain('Dacia Logan Unreviewed')
        ->doesntExpectOutputToContain('Dacia Logan Reviewed')
        ->doesntExpectOutputToContain('Dacia Logan Low Score')
        ->assertExitCode(0);

    expect($unreviewed->looks_score)->toBeNull();
});

// --- The email ---

test('the email never includes a car reviewed below the looks minimum, but keeps unreviewed ones', function () {
    config(['notifications.recipient_email' => 'me@example.test']);
    Mail::fake();
    $ugly = reviewListing(['title' => 'Ugly Logan', 'looks_score' => 30]);
    $nice = reviewListing(['title' => 'Nice Logan', 'looks_score' => 85]);
    $unreviewed = reviewListing(['title' => 'Unreviewed Logan']);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertSent(ListingsDigest::class, function (ListingsDigest $mail) use ($ugly, $nice, $unreviewed) {
        $ids = $mail->listings->pluck('id');

        return $ids->contains($nice->id) && $ids->contains($unreviewed->id) && ! $ids->contains($ugly->id);
    });
});

test('the email shows the looks review and engine details', function () {
    $listing = reviewListing([
        'looks_score' => 88, 'looks_notes' => 'Clean, no rust', 'fuel_type' => 'petrol',
        'engine_capacity_cc' => 999, 'horsepower' => 73,
    ]);
    $unreviewed = reviewListing(['title' => 'Other']);

    $html = (new ListingsDigest(Listing::whereIn('id', [$listing->id, $unreviewed->id])->get()))->render();

    expect($html)->toContain('88/100')->toContain('Clean, no rust')->toContain('999 cc')
        ->toContain('not reviewed yet');
});

// --- Ad photos ---

test('photoUrls keeps one URL per file in page order, resized, up to the maximum', function () {
    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/*' => Http::response(
            '<img src="https://frankfurt.apollo.olxcdn.com:443/v1/files/aaa111-RO/image;s=768x1024">'
            .'<img srcset="https://frankfurt.apollo.olxcdn.com:443/v1/files/aaa111-RO/image;s=389x272 420w">'
            .'<img src="https://frankfurt.apollo.olxcdn.com:443/v1/files/bbb222-RO/image;s=2000x1500">'
            .'<img src="https://frankfurt.apollo.olxcdn.com:443/v1/files/ccc333-RO/image;s=800x600">',
            200,
        ),
    ]);

    $urls = (new AdPhotoFetcher)->photoUrls(ListingSource::Olx, 'https://www.olx.ro/d/oferta/x-IDabc.html', 2);

    expect($urls)->toBe([
        'https://frankfurt.apollo.olxcdn.com/v1/files/aaa111-RO/image;s=1024x768',
        'https://frankfurt.apollo.olxcdn.com/v1/files/bbb222-RO/image;s=1024x768',
    ]);
});

test('photoUrls understands JSON-escaped URLs (Autovit embeds them that way)', function () {
    Http::fake([
        'https://www.autovit.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.autovit.ro/*' => Http::response(
            '{"img":"https:\/\/ireland.apollo.olxcdn.com\/v1\/files\/eyJabc.def.ghi\/image;s=1200x0"}',
            200,
        ),
    ]);

    $urls = (new AdPhotoFetcher)->photoUrls(ListingSource::Autovit, 'https://www.autovit.ro/autoturisme/anunt/x-ID1.html');

    expect($urls)->toBe(['https://ireland.apollo.olxcdn.com/v1/files/eyJabc.def.ghi/image;s=1024x768']);
});

test('listings:photos downloads the gallery into the chosen folder', function () {
    $listing = reviewListing();
    $dir = sys_get_temp_dir().'/looks-test-'.uniqid();

    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/*' => Http::response('<img src="https://x.apollo.olxcdn.com/v1/files/aaa111-RO/image;s=800x600">', 200),
        'https://x.apollo.olxcdn.com/*' => Http::response('fake-jpeg-bytes', 200),
    ]);

    $this->artisan('listings:photos', ['id' => $listing->id, '--dir' => $dir])->assertExitCode(0);

    expect(file_get_contents($dir.'/01.jpg'))->toBe('fake-jpeg-bytes');

    @unlink($dir.'/01.jpg');
    @rmdir($dir);
});

test('listings:photos fails clearly when the ad has no gallery photos', function () {
    $listing = reviewListing();

    Http::fake([
        'https://www.olx.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
        'https://www.olx.ro/d/oferta/*' => Http::response('<html>nothing</html>', 200),
    ]);

    $this->artisan('listings:photos', ['id' => $listing->id])->assertExitCode(1);
});
