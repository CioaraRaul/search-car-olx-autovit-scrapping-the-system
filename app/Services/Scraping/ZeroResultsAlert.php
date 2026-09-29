<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;
use Illuminate\Support\Facades\Log;

/**
 * Flags the "got a 200 but parsed zero listings" case, which usually means a
 * site's HTML/JSON structure changed, not that there's genuinely nothing to
 * find.
 */
class ZeroResultsAlert
{
    public function check(ListingSource $source, int $pagesFetchedOk, int $listingsParsed): void
    {
        if ($pagesFetchedOk > 0 && $listingsParsed === 0) {
            Log::critical("Scraper for {$source->value} fetched {$pagesFetchedOk} page(s) successfully but parsed zero listings — possible site structure change.");
        }
    }
}
