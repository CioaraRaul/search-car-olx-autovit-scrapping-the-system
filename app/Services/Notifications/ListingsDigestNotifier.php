<?php

namespace App\Services\Notifications;

use App\Mail\ListingsDigest;
use App\Models\Listing;
use App\Models\SearchCriterion;
use App\Services\Reliability\BodyTypeGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a digest of every not-yet-notified listing, then marks them
 * notified — but only after the send succeeds, so a failed send never
 * loses a listing (it stays unnotified and is picked up by the next run).
 *
 * Defence in depth: listings saved before a filter existed (or before it was
 * tightened) are re-checked here, so the email never contains a car the
 * current rules would reject. Such listings are left unnotified, not deleted.
 */
class ListingsDigestNotifier
{
    public function __construct(private readonly BodyTypeGuard $bodyTypeGuard) {}

    public function send(): int
    {
        $listings = $this->withoutDuplicates($this->passingCurrentFilters(
            Listing::whereNull('notified_at')->orderBy('price')->get(),
        ));

        if ($listings->isEmpty()) {
            return 0;
        }

        Mail::to(config('notifications.recipient_email'))->send(new ListingsDigest($listings));

        Listing::whereIn('id', $listings->pluck('id'))->update(['notified_at' => Date::now()]);

        return $listings->count();
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, Listing>
     */
    private function passingCurrentFilters(Collection $listings): Collection
    {
        $bodyTypeSet = SearchCriterion::where('key', 'body_type')->exists();

        return $listings->filter(
            fn (Listing $listing) => ! $bodyTypeSet || $this->bodyTypeGuard->isAcceptable((string) $listing->title),
        )->values();
    }

    /**
     * The same car is often posted on both OLX and Autovit. Two listings with
     * the same year, mileage and price are treated as one car: the first is
     * kept, and the duplicate is also excluded if an earlier one was already
     * emailed.
     *
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, Listing>
     */
    private function withoutDuplicates(Collection $listings): Collection
    {
        // Without a year and mileage we can't tell two cars apart, so never merge those.
        $key = fn (Listing $l): string => ($l->year === null || $l->mileage_km === null)
            ? 'unique-'.$l->id
            : implode('|', [$l->year, $l->mileage_km, (int) $l->price]);

        $alreadySent = Listing::whereNotNull('notified_at')->get(['id', 'year', 'mileage_km', 'price'])
            ->map($key)
            ->flip();

        return $listings->unique($key)->reject(fn (Listing $l) => $alreadySent->has($key($l)))->values();
    }
}
