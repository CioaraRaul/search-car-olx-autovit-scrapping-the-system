<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;
use Illuminate\Contracts\Cache\Repository;

/**
 * Trips after too many consecutive scraper failures and stays tripped for a
 * cooldown period, so a run doesn't burn its whole page budget hammering a
 * site that's clearly blocking us.
 *
 * State is stored in the app's cache (backed by SQLite, see config/cache.php)
 * rather than kept in memory, because each scraper run is a fresh process
 * (Chapter 8's daily cron) — there's no long-lived process to hold it.
 */
class ScraperCircuitBreaker
{
    /**
     * How long a failure streak is remembered before it resets on its own,
     * even without a success in between. Deliberately longer than the
     * cooldown itself so a tripped breaker's streak doesn't get wiped out by
     * its own cooldown expiring.
     */
    private const FAILURE_STREAK_TTL_MINUTES = 60 * 24;

    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function isOpen(ListingSource $source): bool
    {
        return $this->cache->has($this->openUntilKey($source));
    }

    public function recordFailure(ListingSource $source): void
    {
        $failuresKey = $this->failuresKey($source);

        $failures = (int) $this->cache->get($failuresKey, 0) + 1;

        $this->cache->put($failuresKey, $failures, now()->addMinutes(self::FAILURE_STREAK_TTL_MINUTES));

        if ($failures >= (int) config('scraping.resilience.max_consecutive_failures')) {
            $cooldownMinutes = (int) config('scraping.resilience.cooldown_minutes');

            $this->cache->put($this->openUntilKey($source), true, now()->addMinutes($cooldownMinutes));
        }
    }

    public function recordSuccess(ListingSource $source): void
    {
        $this->cache->forget($this->failuresKey($source));
        $this->cache->forget($this->openUntilKey($source));
    }

    private function failuresKey(ListingSource $source): string
    {
        return "scraper:{$source->value}:failures";
    }

    private function openUntilKey(ListingSource $source): string
    {
        return "scraper:{$source->value}:open-until";
    }
}
