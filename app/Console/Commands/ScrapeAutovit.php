<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\SearchCriterion;
use App\Services\Reliability\BodyTypeGuard;
use App\Services\Reliability\ReliabilityScorer;
use App\Services\Scraping\AutovitClient;
use App\Services\Scraping\AutovitDetailFetcher;
use App\Services\Scraping\AutovitListingMapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Signature('scrape:autovit {--pages=}')]
#[Description('Scrape Autovit for cars matching the saved search criteria and store them in listings.')]
class ScrapeAutovit extends Command
{
    public function handle(
        AutovitClient $client,
        AutovitListingMapper $mapper,
        AutovitDetailFetcher $detailFetcher,
        ReliabilityScorer $scorer,
        BodyTypeGuard $bodyTypeGuard,
    ): int {
        $criteria = SearchCriterion::query()->pluck('value', 'key')->all();
        $priceMax = isset($criteria['price_max']) ? (int) $criteria['price_max'] : null;
        $priceMin = isset($criteria['price_min']) ? (int) $criteria['price_min'] : null;
        $priceCurrency = $criteria['price_currency'] ?? null;
        // No default page cap: --pages limits a run explicitly (e.g. for a quick manual
        // check); otherwise this scans every page Autovit actually has, stopping only
        // when a page comes back short (the real last page, detected below).
        $maxPages = $this->option('pages') !== null ? (int) $this->option('pages') : null;
        $rejectBelowScore = (int) config('car_knowledge.reject_below_score');

        $created = 0;
        $updated = 0;
        $filteredByPrice = 0;
        $filteredByBodyTypeMismatch = 0;
        $rejectedByReliability = 0;
        $detailReused = 0;
        $stoppedAtCap = false;
        $stoppedEarly = false;

        $detailFetchCountKey = 'autovit:detail-fetch-count:'.now()->toDateString();
        $detailFetchDailyCap = (int) config('scraping.autovit.detail_fetch_daily_cap');

        for ($page = 1; $maxPages === null || $page <= $maxPages; $page++) {
            $result = $client->fetchPage($criteria, $page);

            foreach ($result['listings'] as $node) {
                $attributes = $mapper->map($node);

                // Only compare price when currencies match — there's no exchange-rate
                // conversion yet (that's a separate, not-yet-built piece of work), so a
                // listing in a different currency is stored without a price judgement
                // rather than being wrongly compared as if the numbers were the same unit.
                $comparable = $priceCurrency !== null && $attributes['currency'] === $priceCurrency;

                if (($priceMax !== null && $comparable && $attributes['price'] > $priceMax)
                    || ($priceMin !== null && $comparable && $attributes['price'] < $priceMin)) {
                    $filteredByPrice++;

                    continue;
                }

                // Site body-type filters just forward whatever category the seller picked —
                // no independent verification — so a mislabeled listing (e.g. a hatchback
                // tagged "sedan") can pass a body_type=sedan,break criterion. Checked here,
                // before the detail-page fetch, so a listing we're rejecting anyway never
                // spends part of the daily detail-fetch budget.
                if (isset($criteria['body_type']) && $bodyTypeGuard->hardRejection($attributes['title'] ?? '') !== null) {
                    $filteredByBodyTypeMismatch++;

                    continue;
                }

                // Damage/consumption data only exists on the ad's own page (see
                // AutovitDetailFetcher), not in search results — fetched here, after the
                // price filter, so a listing that's already out on price never costs an
                // extra request. A car's own page doesn't change between runs, so an
                // already-checked listing reuses its stored values instead of re-fetching.
                $existing = Listing::where('source', $attributes['source'])
                    ->where('external_id', $attributes['external_id'])
                    ->first();

                if ($existing !== null && $existing->detail_checked_at !== null) {
                    $attributes['is_damaged'] = $existing->is_damaged;
                    $attributes['fuel_consumption_l_100km'] = $existing->fuel_consumption_l_100km;
                    $attributes['seller_registered_year'] = $existing->seller_registered_year;
                    $attributes['description'] = $existing->description;
                    $attributes['detail_checked_at'] = $existing->detail_checked_at;
                    $detailReused++;
                } elseif ((int) Cache::get($detailFetchCountKey, 0) >= $detailFetchDailyCap) {
                    // Daily cap reached: stop the whole run. A car whose own ad page can't be read
                    // is never saved unchecked — it is found again by a later day's run, when the
                    // cap has reset (already-checked cars reuse stored data and cost nothing).
                    $stoppedAtCap = true;
                    $this->warn("Daily detail-fetch cap ({$detailFetchDailyCap}) reached — stopping this run; the rest continues tomorrow.");

                    break 2;
                } else {
                    try {
                        $details = $detailFetcher->fetch($attributes['url']);
                        $attributes['is_damaged'] = $details['damaged'];
                        $attributes['fuel_consumption_l_100km'] = $details['fuelConsumptionL100km'];
                        $attributes['seller_registered_year'] = $details['sellerRegisteredYear'];
                        $attributes['description'] = $details['description'] ?? $attributes['description'];
                        $attributes['detail_checked_at'] = now();

                        Cache::put(
                            $detailFetchCountKey,
                            (int) Cache::get($detailFetchCountKey, 0) + 1,
                            now()->endOfDay(),
                        );
                    } catch (RequestException $e) {
                        $status = $e->response->status();

                        if ($status === 403 || $status === 429) {
                            Log::warning("scrape:autovit stopping early: detail-page fetch got HTTP {$status}, likely blocked.", [
                                'url' => $attributes['url'],
                            ]);
                            $this->warn("Autovit returned HTTP {$status} fetching a listing's own page — stopping this run early.");
                            $stoppedEarly = true;

                            break 2;
                        }

                        throw $e;
                    }

                    usleep(random_int(
                        (int) config('scraping.autovit.detail_fetch_delay_min_ms'),
                        (int) config('scraping.autovit.detail_fetch_delay_max_ms'),
                    ) * 1000);
                }

                // Confirming the body style needs the ad description, so this can only run once
                // the ad page has been read (a car is never saved without that check — see the cap above).
                if (isset($criteria['body_type'])
                    && ($attributes['detail_checked_at'] ?? null) !== null
                    && $bodyTypeGuard->rejectionReason($attributes['title'] ?? '', $attributes['description'] ?? null) !== null) {
                    $filteredByBodyTypeMismatch++;

                    continue;
                }

                $reliability = $scorer->score($attributes);

                if ($reliability->score < $rejectBelowScore) {
                    $rejectedByReliability++;

                    // An already-saved listing that now fails must not keep its old passing
                    // score (the email gate reads it) — record the new score and any detail
                    // data just learned, so it is excluded from now on.
                    $existing?->update([
                        'reliability_score' => $reliability->score,
                        'reliability_flags' => $reliability->flagsToArray(),
                        'reliability_scored_at' => now(),
                        'fuel_type' => $attributes['fuel_type'] ?? $existing->fuel_type,
                        'engine_capacity_cc' => $attributes['engine_capacity_cc'] ?? $existing->engine_capacity_cc,
                        'horsepower' => $attributes['horsepower'] ?? $existing->horsepower,
                    ]);

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
            ."{$filteredByBodyTypeMismatch} filtered out by body-type mismatch, "
            ."{$rejectedByReliability} rejected by reliability filter, {$detailReused} detail fetches "
            .'reused, detail fetches reused'
            .($stoppedAtCap ? ', stopped at the daily detail-fetch cap.' : '')
            .($stoppedEarly ? ', stopped early after a possible block.' : '.')
        );

        return self::SUCCESS;
    }
}
