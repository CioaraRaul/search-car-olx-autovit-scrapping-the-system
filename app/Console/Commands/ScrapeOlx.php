<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\SearchCriterion;
use App\Services\Reliability\BodyTypeGuard;
use App\Services\Reliability\ReliabilityScorer;
use App\Services\Scraping\OlxClient;
use App\Services\Scraping\OlxDetailFetcher;
use App\Services\Scraping\OlxListingMapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Signature('scrape:olx {--pages=}')]
#[Description('Scrape OLX for cars matching the saved search criteria and store them in listings.')]
class ScrapeOlx extends Command
{
    public function handle(
        OlxClient $client,
        OlxListingMapper $mapper,
        OlxDetailFetcher $detailFetcher,
        ReliabilityScorer $scorer,
        BodyTypeGuard $bodyTypeGuard,
    ): int {
        $criteria = SearchCriterion::query()->pluck('value', 'key')->all();
        $priceMax = isset($criteria['price_max']) ? (int) $criteria['price_max'] : null;
        $priceCurrency = $criteria['price_currency'] ?? 'EUR';
        // No default page cap: --pages limits a run explicitly (e.g. for a quick manual
        // check); otherwise this scans every page OLX actually has, stopping only when
        // it detects the real end (an empty page, or a clamped repeat — see below).
        $maxPages = $this->option('pages') !== null ? (int) $this->option('pages') : null;
        $rejectBelowScore = (int) config('car_knowledge.reject_below_score');

        $created = 0;
        $updated = 0;
        $filteredByPrice = 0;
        $filteredByBodyTypeMismatch = 0;
        $rejectedByReliability = 0;
        $detailReused = 0;
        $detailResolvedByTitle = 0;
        $detailSkippedByCap = 0;
        $stoppedEarly = false;

        $detailFetchCountKey = 'olx:detail-fetch-count:'.now()->toDateString();
        $detailFetchDailyCap = (int) config('scraping.olx.detail_fetch_daily_cap');

        $previousIds = null;

        for ($page = 1; $maxPages === null || $page <= $maxPages; $page++) {
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

                // Site body-type filters just forward whatever category the seller picked —
                // no independent verification — so a mislabeled listing (e.g. a hatchback
                // tagged "sedan") can pass a body_type=sedan,break criterion. Checked here,
                // before the detail-page fetch, so a listing we're rejecting anyway never
                // spends part of the daily detail-fetch budget.
                if (isset($criteria['body_type']) && $bodyTypeGuard->isAcceptable($attributes['title'] ?? '') === false) {
                    $filteredByBodyTypeMismatch++;

                    continue;
                }

                // OLX has no structured damage/consumption field (unlike Autovit) — best-effort
                // keyword detection on the title (free) or the ad's own description (one extra
                // request), through the same safeguards ScrapeAutovit uses: reuse an
                // already-checked listing's stored values, a daily cap, and stopping immediately
                // on a 403/429.
                $existing = Listing::where('source', $attributes['source'])
                    ->where('external_id', $attributes['external_id'])
                    ->first();

                if ($existing !== null && $existing->detail_checked_at !== null) {
                    $attributes['is_damaged'] = $existing->is_damaged;
                    $attributes['fuel_consumption_l_100km'] = $existing->fuel_consumption_l_100km;
                    $attributes['seller_registered_year'] = $existing->seller_registered_year;
                    $attributes['detail_checked_at'] = $existing->detail_checked_at;
                    $detailReused++;
                } elseif ($detailFetcher->detectDamaged($attributes['title'] ?? '') === true) {
                    // Self-disclosed right in the title — no request needed to know this
                    // listing will be rejected anyway. Seller age is unknowable from the
                    // title alone, but it no longer matters once damage alone rejects it.
                    $attributes['is_damaged'] = true;
                    $attributes['fuel_consumption_l_100km'] = null;
                    $attributes['seller_registered_year'] = null;
                    $attributes['detail_checked_at'] = now();
                    $detailResolvedByTitle++;
                } elseif ((int) Cache::get($detailFetchCountKey, 0) >= $detailFetchDailyCap) {
                    // Daily cap reached: leave detail_checked_at unset so a future day's
                    // run still tries this listing instead of skipping it forever.
                    $attributes['is_damaged'] = null;
                    $attributes['fuel_consumption_l_100km'] = null;
                    $attributes['seller_registered_year'] = null;
                    $detailSkippedByCap++;
                } else {
                    try {
                        $details = $detailFetcher->fetch($attributes['url']);
                        $attributes['is_damaged'] = $details['damaged'];
                        $attributes['fuel_consumption_l_100km'] = $details['fuelConsumptionL100km'];
                        $attributes['seller_registered_year'] = $details['sellerRegisteredYear'];
                        $attributes['detail_checked_at'] = now();

                        Cache::put(
                            $detailFetchCountKey,
                            (int) Cache::get($detailFetchCountKey, 0) + 1,
                            now()->endOfDay(),
                        );
                    } catch (RequestException $e) {
                        $status = $e->response->status();

                        if ($status === 403 || $status === 429) {
                            Log::warning("scrape:olx stopping early: detail-page fetch got HTTP {$status}, likely blocked.", [
                                'url' => $attributes['url'],
                            ]);
                            $this->warn("OLX returned HTTP {$status} fetching a listing's own page — stopping this run early.");
                            $stoppedEarly = true;

                            break 2;
                        }

                        throw $e;
                    }

                    usleep(random_int(
                        (int) config('scraping.olx.detail_fetch_delay_min_ms'),
                        (int) config('scraping.olx.detail_fetch_delay_max_ms'),
                    ) * 1000);
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
            ."{$filteredByBodyTypeMismatch} filtered out by body-type mismatch, "
            ."{$rejectedByReliability} rejected by reliability filter, {$detailReused} detail fetches "
            ."reused, {$detailResolvedByTitle} resolved by title, {$detailSkippedByCap} skipped by daily cap"
            .($stoppedEarly ? ', stopped early after a possible block.' : '.')
        );

        return self::SUCCESS;
    }
}
