<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared scraper settings
    |--------------------------------------------------------------------------
    |
    | Used by every source-specific scraper (Autovit, and later OLX) so the
    | politeness/behavior settings live in one place instead of being
    | hardcoded per scraper.
    |
    */

    'user_agent' => env(
        'SCRAPER_USER_AGENT',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
    ),

    'request_delay_ms' => env('SCRAPER_REQUEST_DELAY_MS', 1500),

    'robots_txt_cache_ttl' => env('SCRAPER_ROBOTS_TXT_CACHE_TTL', 60 * 60 * 24),

    'autovit' => [
        'enabled' => env('SCRAPE_AUTOVIT_ENABLED', true),
        'base_url' => 'https://www.autovit.ro',
        'search_path' => '/autoturisme',

        // Safeguards on AutovitDetailFetcher, which costs one extra request per
        // listing that survives the price filter (see ScrapeAutovit).
        'detail_fetch_delay_min_ms' => env('SCRAPER_AUTOVIT_DETAIL_DELAY_MIN_MS', 3000),
        'detail_fetch_delay_max_ms' => env('SCRAPER_AUTOVIT_DETAIL_DELAY_MAX_MS', 8000),
        'detail_fetch_daily_cap' => env('SCRAPER_AUTOVIT_DETAIL_DAILY_CAP', 100),
    ],

    'olx' => [
        'enabled' => env('SCRAPE_OLX_ENABLED', true),
        'base_url' => 'https://www.olx.ro',
        'search_path' => '/auto-masini-moto-ambarcatiuni/autoturisme/',

        // Safeguards on OlxDetailFetcher, which costs one extra request per
        // listing that survives the price filter and isn't already resolved by
        // its title or a prior check (see ScrapeOlx).
        'detail_fetch_delay_min_ms' => env('SCRAPER_OLX_DETAIL_DELAY_MIN_MS', 3000),
        'detail_fetch_delay_max_ms' => env('SCRAPER_OLX_DETAIL_DELAY_MAX_MS', 8000),
        'detail_fetch_daily_cap' => env('SCRAPER_OLX_DETAIL_DAILY_CAP', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilience
    |--------------------------------------------------------------------------
    |
    | Shared safety thresholds for every scraper: how many consecutive
    | failures trip the circuit breaker, how long it stays tripped, and the
    | exponential backoff schedule for retrying a failed HTTP request.
    |
    */

    'resilience' => [
        'max_consecutive_failures' => env('SCRAPER_CIRCUIT_BREAKER_THRESHOLD', 3),
        'cooldown_minutes' => env('SCRAPER_CIRCUIT_BREAKER_COOLDOWN_MINUTES', 60),

        'backoff' => [
            'base_ms' => env('SCRAPER_BACKOFF_BASE_MS', 1000),
            'max_ms' => env('SCRAPER_BACKOFF_MAX_MS', 30000),
            'max_attempts' => env('SCRAPER_BACKOFF_MAX_ATTEMPTS', 4),
        ],
    ],

];
