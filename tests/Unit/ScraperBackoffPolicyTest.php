<?php

use App\Enums\ListingSource;
use App\Services\Scraping\ScraperBackoffPolicy;

beforeEach(function () {
    config([
        'scraping.resilience.backoff.base_ms' => 1000,
        'scraping.resilience.backoff.max_ms' => 30000,
        'scraping.resilience.backoff.max_attempts' => 4,
    ]);
});

test('forSource builds a policy from config', function () {
    $policy = ScraperBackoffPolicy::forSource(ListingSource::Autovit);

    expect($policy->maxAttempts())->toBe(4);
});

test('the delay doubles per attempt, plus up to 20% jitter', function () {
    $policy = ScraperBackoffPolicy::forSource(ListingSource::Autovit);

    expect($policy->sleepMilliseconds(1))->toBeGreaterThanOrEqual(1000)->toBeLessThanOrEqual(1200)
        ->and($policy->sleepMilliseconds(2))->toBeGreaterThanOrEqual(2000)->toBeLessThanOrEqual(2400)
        ->and($policy->sleepMilliseconds(3))->toBeGreaterThanOrEqual(4000)->toBeLessThanOrEqual(4800);
});

test('the delay is capped at max_ms before jitter is added', function () {
    $policy = ScraperBackoffPolicy::forSource(ListingSource::Autovit);

    // base_ms(1000) * 2^(7-1) = 64000, far past the 30000 cap.
    expect($policy->sleepMilliseconds(7))
        ->toBeGreaterThanOrEqual(30000)
        ->toBeLessThanOrEqual(36000);
});

test('shouldRetry is true for rate limits, possible blocks, and server errors', function () {
    $policy = ScraperBackoffPolicy::forSource(ListingSource::Autovit);

    expect($policy->shouldRetry(429))->toBeTrue()
        ->and($policy->shouldRetry(403))->toBeTrue()
        ->and($policy->shouldRetry(500))->toBeTrue()
        ->and($policy->shouldRetry(503))->toBeTrue();
});

test('shouldRetry is false for success and client errors that retrying cannot fix', function () {
    $policy = ScraperBackoffPolicy::forSource(ListingSource::Autovit);

    expect($policy->shouldRetry(200))->toBeFalse()
        ->and($policy->shouldRetry(404))->toBeFalse();
});
