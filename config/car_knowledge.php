<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reliability scoring
    |--------------------------------------------------------------------------
    |
    | Every candidate listing starts at base_score and loses points for each
    | reliability flag it triggers. A listing scoring below reject_below_score
    | is discarded at scrape time — never saved to `listings` at all.
    |
    */

    'base_score' => 100,

    'reject_below_score' => env('CAR_KNOWLEDGE_REJECT_BELOW_SCORE', 90),

    // Absolute exclusion: a used car showing fewer than min_km is a typo (350
    // meant 350,000) or a scam — the real mileage is unknown, so reject it.
    'implausible_mileage' => [
        'min_km' => (int) env('CAR_KNOWLEDGE_IMPLAUSIBLE_MILEAGE_MIN_KM', 1000),
        'penalty' => 100,
    ],

    'low_mileage' => [
        'expected_km_per_year' => (int) env('CAR_KNOWLEDGE_EXPECTED_KM_PER_YEAR', 15000),
        'suspicious_ratio' => (float) env('CAR_KNOWLEDGE_LOW_MILEAGE_RATIO', 0.3),
        'min_age_years' => (int) env('CAR_KNOWLEDGE_LOW_MILEAGE_MIN_AGE', 2),
        'penalty' => (int) env('CAR_KNOWLEDGE_LOW_MILEAGE_PENALTY', 15),
    ],

    'below_market_price' => [
        'year_range' => (int) env('CAR_KNOWLEDGE_PRICE_YEAR_RANGE', 1),
        'min_sample_size' => (int) env('CAR_KNOWLEDGE_PRICE_MIN_SAMPLE', 5),
        'ratio_threshold' => (float) env('CAR_KNOWLEDGE_PRICE_RATIO_THRESHOLD', 0.5),
        'penalty' => (int) env('CAR_KNOWLEDGE_PRICE_PENALTY', 20),
    ],

    // Populated by AutovitDetailFetcher (structured field) and OlxDetailFetcher
    // (best-effort keyword detection in free text) — see each class for how
    // reliable the source data actually is per site. Both penalties default to
    // guaranteeing rejection on their own, since "no damaged cars"/"high
    // consumption is bad" are absolute exclusions, not a soft scoring nudge.
    'damaged_vehicle' => [
        'penalty' => (int) env('CAR_KNOWLEDGE_DAMAGED_PENALTY', 100),
    ],

    'high_fuel_consumption' => [
        'threshold_l_100km' => (float) env('CAR_KNOWLEDGE_HIGH_FUEL_CONSUMPTION_THRESHOLD', 8.0),
        'penalty' => (int) env('CAR_KNOWLEDGE_HIGH_FUEL_CONSUMPTION_PENALTY', 100),
    ],

    // A statistical suspicion signal, not proof of a scam — a moderate penalty
    // (unlike the two absolute-exclusion rules above) so it stacks with other
    // flags toward rejection rather than auto-rejecting a genuine first-time
    // seller on its own.
    'new_seller_account' => [
        'penalty' => (int) env('CAR_KNOWLEDGE_NEW_SELLER_ACCOUNT_PENALTY', 10),
    ],

    // Site body-type filters (Autovit/OLX) just forward whatever category the
    // SELLER picked when posting the ad, so they can't be trusted — a BMW Seria 2
    // coupe/Active Tourer passed a sedan/break filter. So we keep our OWN
    // WHITELIST: a listing is accepted only if its title names a make+model that
    // is (a) really sold as a sedan or estate, and (b) a sensible buy for this
    // buyer (reliable, cheap to repair/insure in Romania — see the
    // car-buyer-profile skill). Anything unlisted is rejected, and the run
    // summary counts it so a valid model can be added here. Keyword groups match
    // as whole words, case-insensitive; every keyword in a group must be present.
    'body_type_guard' => [
        // Repair/parts/RCA costs are far higher than mainstream brands — never
        // recommended, even if a future whitelist edit would otherwise match.
        'premium_brands' => [
            'bmw', 'audi', 'mercedes', 'mercedes-benz', 'volvo', 'lexus', 'porsche', 'jaguar',
            'land rover', 'range rover', 'infiniti', 'maserati', 'alfa romeo', 'mini', 'tesla',
            'cadillac', 'bentley', 'saab', 'jeep',
        ],

        // A title containing any of these is rejected outright (not a sedan/estate).
        'rejected_keywords' => [
            'hatchback', 'hatch', 'coupe', 'cabrio', 'cabriolet', 'roadster', 'sportback',
            'spaceback', 'gran coupe', 'gran tourer', 'active tourer', 'suv', 'crossover',
            'monovolum', 'minivan', 'mpv', '3 usi', '5 usi', '3 uși', '5 uși',
        ],

        // Words that confirm a sedan or estate body in the title.
        'body_keywords' => [
            'sedan', 'limuzina', 'combi', 'kombi', 'break', 'estate', 'wagon', 'variant',
            'touring', 'tourer', 'sw', 'caravan', 'grandtour', 'turnier', 'sports tourer',
            'sportstourer',
        ],

        // Models that are only ever sold as sedan/estate in this market (year >= 2013).
        'always_sedan_or_estate' => [
            ['dacia', 'logan'],
            ['skoda', 'octavia'],
            ['skoda', 'rapid'],
            ['toyota', 'avensis'],
            ['toyota', 'auris', 'touring'],
            ['honda', 'accord'],
            ['mazda', '6'],
            ['hyundai', 'elantra'],
            ['hyundai', 'i40'],
            ['kia', 'optima'],
            ['vw', 'jetta'],
            ['volkswagen', 'jetta'],
            ['vw', 'passat'],
            ['volkswagen', 'passat'],
            ['renault', 'fluence'],
            ['renault', 'symbol'],
            ['peugeot', '301'],
            ['citroen', 'c-elysee'],
            ['citroen', 'elysee'],
        ],

        // Models sold in several body styles: accepted only when the title also
        // says sedan/estate (a body keyword), otherwise we can't tell and reject.
        'needs_body_keyword' => [
            ['toyota', 'corolla'],
            ['mazda', '3'],
            ['hyundai', 'i30'],
            ['kia', 'ceed'],
            ['kia', "cee'd"],
            ['vw', 'golf'],
            ['volkswagen', 'golf'],
            ['ford', 'focus'],
            ['ford', 'mondeo'],
            ['opel', 'astra'],
            ['renault', 'megane'],
            ['skoda', 'fabia'],
            ['fiat', 'tipo'],
            ['mitsubishi', 'lancer'],
        ],
    ],

];
