<?php

namespace App\Mail;

use App\Models\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ListingsDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Listing>  $listings
     */
    public function __construct(
        public readonly Collection $listings,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $count = $this->listings->count();

        return new Envelope(
            subject: "Car Finder — {$count} new listing".($count === 1 ? '' : 's'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.listings.digest',
            with: ['listings' => $this->listings],
        );
    }
}
