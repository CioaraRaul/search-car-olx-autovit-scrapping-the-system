<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\SearchCriterion;
use App\Services\Reliability\ReliabilityScorer;
use App\Services\Scraping\OlxClient;
use App\Services\Scraping\OlxListingMapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scrape:olx {--pages=}')]
#[Description('Scrape OLX for cars matching the saved search criteria and store them in listings.')]
class ScrapeOlx extends Command
{
    public function handle(OlxClient $client, OlxListingMapper $mapper, ReliabilityScorer $scorer): int
    {
        $criteria = SearchCriterion::query()->pluck('value', 'key')->all();
        $priceMax = isset($criteria['price_max']) ? (int) $criteria['price_max'] : null;
        $priceCurrency = $criteria['price_currency'] ?? 'EUR';
        $maxPages = (int) ($this->option('pages') ?? config('scraping.olx.max_pages'));
        $rejectBelowScore = (int) config('car_knowledge.reject_below_score');

        $created = 0;
        $updated = 0;
        $filteredByPrice = 0;
        $rejectedByReliability = 0;

        $previousIds = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $client->fetchPage($criteria, $page);

            if ($result['listings'] === []) {
                break;
            }

            $currentIds = array_map(fn (array $node) => $node['id'], $result['listings']);

            // OLX doesn't 404 or empty out past the real last page — it silently re-serves an
            // earlier page instead. A page whose ad ids are identical to the previous page's
            // means we've been clamped back, not that there's genuinely more content.
            if ($previousIds !== null && $currentIds === $previousIds) {
                break;
            }

            $previousIds = $currentIds;

            foreach ($result['listings'] as $node) {
                $attributes = $mapper->map($node, $priceCurrency);

                $comparable = $attributes['currency'] === $priceCurrency;

                if ($priceMax !== null && $comparable && $attributes['price'] > $priceMax) {
                    $filteredByPrice++;

                    continue;
                }

                $reliability = $scorer->score($attributes);

                if ($reliability->score < $rejectBelowScore) {
                    $rejectedByReliability++;

                    continue;
                }

                $attributes['reliability_score'] = $reliability->score;
                $attributes['reliability_flags'] = $reliability->flagsToArray();
                $attributes['reliability_scored_at'] = now();

                $listing = Listing::updateOrCreate(
                    ['source' => $attributes['source'], 'external_id' => $attributes['external_id']],
                    $attributes,
                );

                $listing->wasRecentlyCreated ? $created++ : $updated++;
            }

            usleep((int) config('scraping.request_delay_ms') * 1000);
        }

        $this->info(
            "OLX: {$created} new, {$updated} updated, {$filteredByPrice} filtered out by price, "
            ."{$rejectedByReliability} rejected by reliability filter."
        );

        return self::SUCCESS;
    }
}
