<?php

return [
    'bnr_url' => env('BNR_RATES_URL', 'https://curs.bnr.ro/nbrfxrates.xml'),
    'cache_ttl' => env('BNR_RATES_CACHE_TTL', 86400), // 24h, in seconds
    'cache_key' => 'bnr_exchange_rates',
];
