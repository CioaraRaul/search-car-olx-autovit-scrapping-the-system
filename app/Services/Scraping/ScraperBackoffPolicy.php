<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;

/**
 * Exponential backoff math for retrying a failed scraper HTTP request.
 *
 * Kept separate from the actual HTTP call so it's testable without a real
 * network request: `sleepMilliseconds()` is pure math, `shouldRetry()` is a
 * pure status-code check.
 */
class ScraperBackoffPolicy
{
    public function __construct(
        private readonly int $baseMs,
        private readonly int $maxMs,
        private readonly int $maxAttempts,
    ) {}

    public static function forSource(ListingSource $source): self
    {
        return new self(
            baseMs: (int) config('scraping.resilience.backoff.base_ms'),
            maxMs: (int) config('scraping.resilience.backoff.max_ms'),
            maxAttempts: (int) config('scraping.resilience.backoff.max_attempts'),
        );
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Delay before retry attempt $attempt (1-indexed), doubling each time and
     * capped at $maxMs, plus up to 20% random jitter so retries don't all
     * land on the same instant.
     */
    public function sleepMilliseconds(int $attempt): int
    {
        $delay = min($this->baseMs * 2 ** ($attempt - 1), $this->maxMs);

        $jitter = (int) ($delay * (random_int(0, 20) / 100));

        return $delay + $jitter;
    }

    /**
     * Whether a response with this HTTP status is worth retrying: rate
     * limited (429), possibly transiently blocked (403), or server-side
     * trouble (5xx). Anything else (e.g. 404) won't be fixed by retrying.
     */
    public function shouldRetry(int $status): bool
    {
        return $status === 429 || $status === 403 || $status >= 500;
    }
}
