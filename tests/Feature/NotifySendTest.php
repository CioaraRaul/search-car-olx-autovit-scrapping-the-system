<?php

use App\Enums\ListingSource;
use App\Mail\ListingsDigest;
use App\Models\SearchCriterion;
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

test('never emails a listing the current body-type whitelist rejects, e.g. a BMW Seria 2', function () {
    SearchCriterion::create(['key' => 'body_type', 'value' => 'sedan,break']);
    $bmw = makeListing(['external_id' => 'bmw-1', 'title' => 'BMW Seria 2 218i 2015']);
    $logan = makeListing(['external_id' => 'logan-1', 'title' => 'Dacia Logan 0.9 TCe GPL']);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertSent(ListingsDigest::class, function (ListingsDigest $mail) use ($bmw, $logan) {
        $ids = $mail->listings->pluck('id');

        return $ids->contains($logan->id) && ! $ids->contains($bmw->id);
    });
    expect($bmw->fresh()->notified_at)->toBeNull();
});

test('emails a car cross-posted on OLX and Autovit only once', function () {
    $olx = makeListing(['external_id' => 'x-olx', 'year' => 2015, 'mileage_km' => 184000, 'price' => 7000]);
    $autovit = makeListing([
        'source' => ListingSource::Autovit, 'external_id' => 'x-autovit',
        'year' => 2015, 'mileage_km' => 184000, 'price' => 7000,
    ]);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertSent(ListingsDigest::class, fn (ListingsDigest $mail) => $mail->listings->count() === 1);
});

test('does not email a duplicate of a car that was already emailed', function () {
    makeListing(['external_id' => 'sent', 'year' => 2015, 'mileage_km' => 184000, 'price' => 7000, 'notified_at' => now()]);
    makeListing([
        'source' => ListingSource::Autovit, 'external_id' => 'dupe',
        'year' => 2015, 'mileage_km' => 184000, 'price' => 7000,
    ]);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertNothingSent();
});

test('never emails a listing whose reliability score is below the threshold', function () {
    $bad = makeListing(['external_id' => 'bad-1', 'reliability_score' => 70]);
    $good = makeListing(['external_id' => 'good-1', 'reliability_score' => 95]);

    $this->artisan('notify:send')->assertExitCode(0);

    Mail::assertSent(ListingsDigest::class, function (ListingsDigest $mail) use ($bad, $good) {
        $ids = $mail->listings->pluck('id');

        return $ids->contains($good->id) && ! $ids->contains($bad->id);
    });
});
