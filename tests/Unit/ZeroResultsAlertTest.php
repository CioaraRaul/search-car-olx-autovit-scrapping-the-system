<?php

use App\Enums\ListingSource;
use App\Services\Scraping\ZeroResultsAlert;
use Illuminate\Support\Facades\Log;

test('logs a critical alert when pages were fetched but nothing was parsed', function () {
    Log::spy();

    (new ZeroResultsAlert)->check(ListingSource::Autovit, pagesFetchedOk: 3, listingsParsed: 0);

    Log::shouldHaveReceived('critical')->once();
});

test('logs nothing when listings were parsed', function () {
    Log::spy();

    (new ZeroResultsAlert)->check(ListingSource::Autovit, pagesFetchedOk: 3, listingsParsed: 12);

    Log::shouldNotHaveReceived('critical');
});

test('logs nothing when no pages were fetched at all', function () {
    Log::spy();

    (new ZeroResultsAlert)->check(ListingSource::Autovit, pagesFetchedOk: 0, listingsParsed: 0);

    Log::shouldNotHaveReceived('critical');
});
