<?php

namespace App\Console\Commands;

use App\Services\Notifications\ListingsDigestNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notify:send')]
#[Description('Email a digest of every not-yet-notified listing, then mark them notified.')]
class NotifySend extends Command
{
    public function handle(ListingsDigestNotifier $notifier): int
    {
        if (! config('notifications.recipient_email')) {
            $this->error('NOTIFY_RECIPIENT_EMAIL is not configured. Set it in .env before running notify:send.');

            return self::FAILURE;
        }

        $count = $notifier->send();

        if ($count === 0) {
            $this->info('No new listings to notify about.');
        } else {
            $this->info("Emailed {$count} new listing(s).");
        }

        return self::SUCCESS;
    }
}
