<?php

use App\Mail\ListingsDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_email' => 'cioararaul08@gmail.com']);
    Mail::fake();
});

test('sends a digest of unnotified listings and marks them notified, leaving already-notified ones alone', function () {
    $unnotified = makeListing(['external_id' => 'unnotified-1']);
    $alreadyNotified = makeListing(['external_id' => 'already-notified-1', 'notified_at' => now()]);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertSent(ListingsDigest::class, function (ListingsDigest $mail) use ($unnotified, $alreadyNotified) {
        $ids = $mail->listings->pluck('id');

        return $ids->contains($unnotified->id) && ! $ids->contains($alreadyNotified->id);
    });

    expect($unnotified->fresh()->notified_at)->not->toBeNull()
        ->and($alreadyNotified->fresh()->notified_at->timestamp)->toBe($alreadyNotified->notified_at->timestamp);
});

test('does nothing when every listing is already notified', function () {
    makeListing(['external_id' => 'already-notified-2', 'notified_at' => now()]);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertNothingSent();
});

test('fails with a clear message and sends nothing when NOTIFY_RECIPIENT_EMAIL is not configured', function () {
    config(['notifications.recipient_email' => null]);
    makeListing(['external_id' => 'unnotified-2']);

    $this->artisan('notify:send')->assertExitCode(1);

    Mail::assertNothingSent();
});
