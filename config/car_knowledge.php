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

    'reject_below_score' => env('CAR_KNOWLEDGE_REJECT_BELOW_SCORE', 60),

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
        'penalty' => (int) env('CAR_KNOWLEDGE_NEW_SELLER_ACCOUNT_PENALTY', 20),
    ],

];
