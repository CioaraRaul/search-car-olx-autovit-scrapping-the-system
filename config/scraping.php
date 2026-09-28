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
        'base_url' => 'https://www.autovit.ro',
        'search_path' => '/autoturisme',
        'max_pages' => env('SCRAPER_AUTOVIT_MAX_PAGES', 25),
    ],

    'olx' => [
        'base_url' => 'https://www.olx.ro',
        'search_path' => '/auto-masini-moto-ambarcatiuni/autoturisme/',
        'max_pages' => env('SCRAPER_OLX_MAX_PAGES', 25),
    ],

];
