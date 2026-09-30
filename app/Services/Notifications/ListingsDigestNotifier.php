<?php

namespace App\Services\Notifications;

use App\Mail\ListingsDigest;
use App\Models\Listing;
use App\Services\Listings\ListingShortlist;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a digest of every not-yet-notified listing that still passes the
 * current rules (ListingShortlist), then marks them notified — but only after
 * the send succeeds, so a failed send never loses a listing (it stays
 * unnotified and is picked up by the next run). Listings the current rules
 * reject are left unnotified, not deleted.
 */
class ListingsDigestNotifier
{
    public function __construct(private readonly ListingShortlist $shortlist) {}

    public function send(): int
    {
        $listings = $this->shortlist->filter(
            Listing::whereNull('notified_at')->orderBy('price')->get(),
        );

        if ($listings->isEmpty()) {
            return 0;
        }

        Mail::to(config('notifications.recipient_email'))->send(new ListingsDigest($listings));

        Listing::whereIn('id', $listings->pluck('id'))->update(['notified_at' => Date::now()]);

        return $listings->count();
    }
}
