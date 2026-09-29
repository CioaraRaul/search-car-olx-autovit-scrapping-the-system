<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\SearchCriterion;
use App\Services\Reliability\ReliabilityScorer;
use App\Services\Scraping\AutovitClient;
use App\Services\Scraping\AutovitDetailFetcher;
use App\Services\Scraping\AutovitListingMapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scrape:autovit {--pages=}')]
#[Description('Scrape Autovit for cars matching the saved search criteria and store them in listings.')]
class ScrapeAutovit extends Command
{
    public function handle(
        AutovitClient $client,
        AutovitListingMapper $mapper,
        AutovitDetailFetcher $detailFetcher,
        ReliabilityScorer $scorer,
    ): int {
        $criteria = SearchCriterion::query()->pluck('value', 'key')->all();
        $priceMax = isset($criteria['price_max']) ? (int) $criteria['price_max'] : null;
        $priceCurrency = $criteria['price_currency'] ?? null;
        $maxPages = (int) ($this->option('pages') ?? config('scraping.autovit.max_pages'));
        $rejectBelowScore = (int) config('car_knowledge.reject_below_score');

        $created = 0;
        $updated = 0;
        $filteredByPrice = 0;
        $rejectedByReliability = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $client->fetchPage($criteria, $page);

            foreach ($result['listings'] as $node) {
                $attributes = $mapper->map($node);

                // Only compare price when currencies match — there's no exchange-rate
                // conversion yet (that's a separate, not-yet-built piece of work), so a
                // listing in a different currency is stored without a price judgement
                // rather than being wrongly compared as if the numbers were the same unit.
                $comparable = $priceCurrency !== null && $attributes['currency'] === $priceCurrency;

                if ($priceMax !== null && $comparable && $attributes['price'] > $priceMax) {
                    $filteredByPrice++;

                    continue;
                }

                // Damage/consumption data only exists on the ad's own page (see
                // AutovitDetailFetcher), not in search results — fetched here, after the
                // price filter, so a listing that's already out on price never costs an
                // extra request.
                $details = $detailFetcher->fetch($attributes['url']);
                $attributes['is_damaged'] = $details['damaged'];
                $attributes['fuel_consumption_l_100km'] = $details['fuelConsumptionL100km'];

                usleep((int) config('scraping.request_delay_ms') * 1000);

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

            $isLastPage = count($result['listings']) < $result['pageSize'];

            if ($isLastPage) {
                break;
            }

            usleep((int) config('scraping.request_delay_ms') * 1000);
        }

        $this->info(
            "Autovit: {$created} new, {$updated} updated, {$filteredByPrice} filtered out by price, "
            ."{$rejectedByReliability} rejected by reliability filter."
        );

        return self::SUCCESS;
    }
}
