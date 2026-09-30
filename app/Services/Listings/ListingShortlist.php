<?php

namespace App\Services\Listings;

use App\Models\Listing;
use App\Models\SearchCriterion;
use App\Services\Reliability\BodyTypeGuard;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single place that decides which saved listings are still good enough to
 * show the buyer, under the CURRENT rules. Listings saved before a rule existed
 * (or before it was tightened) are re-checked here, so neither the email nor the
 * looks-review shortlist ever contains a car today's rules would reject.
 */
class ListingShortlist
{
    public function __construct(private readonly BodyTypeGuard $bodyTypeGuard) {}

    /**
     * @param  Collection<int, Listing>  $listings
     * @param  bool  $skipAlreadyEmailedTwins  drop a car whose cross-posted twin was already emailed
     * @return Collection<int, Listing>
     */
    public function filter(Collection $listings, bool $skipAlreadyEmailedTwins = true): Collection
    {
        return $this->withoutDuplicates($this->passingCurrentFilters($listings), $skipAlreadyEmailedTwins);
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, Listing>
     */
    private function passingCurrentFilters(Collection $listings): Collection
    {
        $bodyTypeSet = SearchCriterion::where('key', 'body_type')->exists();
        $priceMin = SearchCriterion::where('key', 'price_min')->value('value');
        $priceCurrency = SearchCriterion::where('key', 'price_currency')->value('value');

        // Listings saved before price_min existed may be cheaper than it now allows.
        $listings = $listings->reject(
            fn (Listing $l) => $priceMin !== null
                && $l->currency === $priceCurrency
                && $l->price < (int) $priceMin,
        );

        // A listing whose own ad page was never checked (the daily fetch cap ran out) has no
        // damage/consumption/seller/fuel data, so it is held back until a later scrape checks it.
        return $listings->filter(
            fn (Listing $listing) => $listing->detail_checked_at !== null
                && $this->meetsReliabilityThreshold($listing)
                && $this->looksAreAcceptable($listing)
                && (! $bodyTypeSet || $this->bodyTypeGuard->isAcceptable((string) $listing->title)),
        )->values();
    }

    /** A scored listing below the current threshold is never shown (unscored rows pass). */
    private function meetsReliabilityThreshold(Listing $listing): bool
    {
        return $listing->reliability_score === null
            || $listing->reliability_score >= (int) config('car_knowledge.reject_below_score');
    }

    /** A car reviewed as ugly/poorly kept is dropped; one not reviewed yet passes. */
    private function looksAreAcceptable(Listing $listing): bool
    {
        return $listing->looks_score === null
            || $listing->looks_score >= (int) config('car_knowledge.looks.min_score');
    }

    /**
     * The same car is often posted on both OLX and Autovit. Two listings with
     * the same year, mileage and price are treated as one car: the first is kept.
     *
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, Listing>
     */
    private function withoutDuplicates(Collection $listings, bool $skipAlreadyEmailedTwins): Collection
    {
        // Without a year and mileage we can't tell two cars apart, so never merge those.
        $key = fn (Listing $l): string => ($l->year === null || $l->mileage_km === null)
            ? 'unique-'.$l->id
            : implode('|', [$l->year, $l->mileage_km, (int) $l->price]);

        $unique = $listings->unique($key);

        if (! $skipAlreadyEmailedTwins) {
            return $unique->values();
        }

        $alreadySent = Listing::whereNotNull('notified_at')->get(['id', 'year', 'mileage_km', 'price'])
            ->map($key)
            ->flip();

        return $unique->reject(fn (Listing $l) => $alreadySent->has($key($l)))->values();
    }
}
