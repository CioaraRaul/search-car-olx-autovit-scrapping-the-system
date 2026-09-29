<?php

use App\Enums\ListingSource;
use App\Services\Scraping\ScraperKillSwitch;

test('a source is enabled by default when no config key is set', function () {
    config(['scraping.autovit' => []]);

    expect((new ScraperKillSwitch)->isEnabled(ListingSource::Autovit))->toBeTrue();
});

test('a source is enabled when its config flag is true', function () {
    config(['scraping.autovit.enabled' => true]);

    expect((new ScraperKillSwitch)->isEnabled(ListingSource::Autovit))->toBeTrue();
});

test('a source is disabled when its config flag is false', function () {
    config(['scraping.olx.enabled' => false]);

    expect((new ScraperKillSwitch)->isEnabled(ListingSource::Olx))->toBeFalse();
});

test('disabling one source does not affect another', function () {
    config(['scraping.autovit.enabled' => false]);
    config(['scraping.olx.enabled' => true]);

    expect((new ScraperKillSwitch)->isEnabled(ListingSource::Autovit))->toBeFalse()
        ->and((new ScraperKillSwitch)->isEnabled(ListingSource::Olx))->toBeTrue();
});
