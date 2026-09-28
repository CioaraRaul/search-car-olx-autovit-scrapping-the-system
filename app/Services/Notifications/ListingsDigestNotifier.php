<?php

namespace App\Services\Notifications;

use App\Mail\ListingsDigest;
use App\Models\Listing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a digest of every not-yet-notified listing, then marks them
 * notified — but only after the send succeeds, so a failed send never
 * loses a listing (it stays unnotified and is picked up by the next run).
 */
class ListingsDigestNotifier
{
    public function send(): int
    {
        $listings = Listing::whereNull('notified_at')->orderBy('price')->get();

        if ($listings->isEmpty()) {
            return 0;
        }

        Mail::to(config('notifications.recipient_email'))->send(new ListingsDigest($listings));

        Listing::whereIn('id', $listings->pluck('id'))->update(['notified_at' => Date::now()]);

        return $listings->count();
    }
}
