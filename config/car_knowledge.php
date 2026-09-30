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

    // Buyer's rule: no engine over 2.0 L, and diesel is unsuited to short trips. There is NO
    // power limit by default (max_horsepower 0 = off) — engine quality is judged by the
    // reliability rules instead. All flags here are absolute exclusions.
    'needs_fit' => [
        'max_engine_cc' => (int) env('CAR_KNOWLEDGE_MAX_ENGINE_CC', 2000),
        'max_horsepower' => (int) env('CAR_KNOWLEDGE_MAX_HORSEPOWER', 0),
        'penalty' => (int) env('CAR_KNOWLEDGE_NEEDS_FIT_PENALTY', 100),
    ],

    // Looks can't be judged for free at scrape time, so a photo review (the
    // car-looks-evaluator skill) stores looks_score per car. A reviewed car scoring
    // below min_score (ugly, dented, rusty, dirty interior) is never emailed; a car
    // not reviewed yet is still sent, marked "not reviewed".
    'looks' => [
        'min_score' => (int) env('CAR_KNOWLEDGE_LOOKS_MIN_SCORE', 60),
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

    // Site body-type filters (Autovit/OLX) just forward whatever category the SELLER picked, so
    // they can't be trusted — a BMW Seria 2 coupe/Active Tourer once passed a sedan/break filter.
    // BodyTypeGuard therefore checks the car itself. Any make/model is allowed EXCEPT:
    //   1. premium brands (repair/parts/RCA cost far above mainstream brands);
    //   2. titles with a non-sedan word (coupe, hatchback, SUV, ...);
    //   3. models that have never been a sedan/estate (hatchbacks, SUVs, MPVs, vans) unless the
    //      title names an estate version (e.g. "Golf Variant", "Fabia Combi");
    //   4. cars where neither the title NOR the ad description says sedan/berlina/estate/combi/...
    //      (skipped for models that are only ever sedan/estate, e.g. Dacia Logan).
    // Matching is whole-word, case-insensitive and accent-insensitive; every keyword in a group
    // must be present.
    'body_type_guard' => [
        'premium_brands' => [
            'bmw', 'audi', 'mercedes', 'mercedes-benz', 'volvo', 'lexus', 'porsche', 'jaguar',
            'land rover', 'range rover', 'infiniti', 'maserati', 'alfa romeo', 'mini', 'tesla',
            'cadillac', 'bentley', 'saab', 'jeep',
        ],

        'rejected_keywords' => [
            'hatchback', 'hatch', 'coupe', 'cabrio', 'cabriolet', 'roadster', 'sportback',
            'spaceback', 'gran coupe', 'gran tourer', 'active tourer', 'suv', 'crossover',
            'monovolum', 'minivan', 'mpv', '3 usi', '5 usi',
        ],

        // Words that make a title mean an estate version — lets "Golf Variant" / "Clio Break" through
        // the hatchback-only list below.
        'estate_keywords' => [
            'combi', 'kombi', 'break', 'estate', 'wagon', 'variant', 'touring', 'sw', 'caravan',
            'grandtour', 'turnier', 'sports tourer', 'sportstourer', 'mcv',
        ],

        // Words that confirm a sedan/estate body in the title or the description. The estate words
        // above count too. "berlina" is what Romanian sellers write for a sedan.
        'sedan_keywords' => ['sedan', 'limuzina', 'berlina'],

        // Models that are hatchbacks/SUVs/MPVs/vans — never a sedan or estate — rejected even when
        // the ad says "berlina" (sellers mislabel), unless the title names an estate version.
        'hatchback_only_models' => [
            ['vw', 'polo'], ['volkswagen', 'polo'], ['vw', 'golf'], ['volkswagen', 'golf'],
            ['vw', 'up'], ['volkswagen', 'up'], ['vw', 'lupo'], ['vw', 'fox'],
            ['vw', 'tiguan'], ['volkswagen', 'tiguan'], ['vw', 'touran'], ['volkswagen', 'touran'],
            ['vw', 't-roc'], ['vw', 'sharan'], ['vw', 'caddy'],
            ['ford', 'fiesta'], ['ford', 'ka'], ['ford', 'puma'], ['ford', 'kuga'], ['ford', 'ecosport'],
            ['ford', 'b-max'], ['ford', 'c-max'], ['ford', 's-max'], ['ford', 'galaxy'], ['ford', 'fusion'],
            ['renault', 'clio'], ['renault', 'twingo'], ['renault', 'captur'], ['renault', 'kadjar'],
            ['renault', 'scenic'], ['renault', 'modus'], ['renault', 'kangoo'],
            ['opel', 'corsa'], ['opel', 'adam'], ['opel', 'meriva'], ['opel', 'mokka'], ['opel', 'zafira'],
            ['opel', 'agila'], ['opel', 'crossland'], ['opel', 'grandland'],
            ['toyota', 'yaris'], ['toyota', 'aygo'], ['toyota', 'auris'], ['toyota', 'c-hr'],
            ['toyota', 'rav4'], ['toyota', 'verso'], ['toyota', 'iq'],
            ['hyundai', 'i10'], ['hyundai', 'i20'], ['hyundai', 'ix20'], ['hyundai', 'ix35'],
            ['hyundai', 'tucson'], ['hyundai', 'kona'], ['hyundai', 'santa fe'], ['hyundai', 'getz'],
            ['kia', 'picanto'], ['kia', 'rio'], ['kia', 'venga'], ['kia', 'sportage'], ['kia', 'sorento'],
            ['kia', 'soul'], ['kia', 'stonic'], ['kia', 'niro'],
            ['seat', 'ibiza'], ['seat', 'arosa'], ['seat', 'mii'], ['seat', 'ateca'], ['seat', 'arona'],
            ['seat', 'altea'], ['seat', 'alhambra'],
            ['skoda', 'fabia'], ['skoda', 'citigo'], ['skoda', 'yeti'], ['skoda', 'kodiaq'],
            ['skoda', 'karoq'], ['skoda', 'kamiq'], ['skoda', 'roomster'], ['skoda', 'scala'],
            ['peugeot 107'], ['peugeot 108'], ['peugeot 206'], ['peugeot 207'],
            ['peugeot 208'], ['peugeot 2008'], ['peugeot 3008'], ['peugeot 5008'],
            ['peugeot', 'partner'], ['peugeot', 'rifter'],
            ['citroen', 'c1'], ['citroen', 'c2'], ['citroen', 'c3'], ['citroen', 'c4'],
            ['citroen', 'c5 aircross'], ['citroen', 'berlingo'], ['citroen', 'nemo'], ['citroen', 'ds3'],
            ['suzuki', 'swift'], ['suzuki', 'splash'], ['suzuki', 'ignis'], ['suzuki', 'sx4'],
            ['suzuki', 'vitara'], ['suzuki', 'jimny'], ['suzuki', 'alto'],
            ['nissan', 'micra'], ['nissan', 'note'], ['nissan', 'juke'], ['nissan', 'qashqai'],
            ['nissan', 'x-trail'], ['nissan', 'pixo'], ['nissan', 'leaf'],
            ['fiat', 'panda'], ['fiat 500'], ['fiat', 'punto'], ['fiat', 'bravo'], ['fiat', 'doblo'],
            ['fiat', 'qubo'], ['fiat', 'sedici'],
            ['mazda 2'], ['mazda', 'cx-3'], ['mazda', 'cx-5'], ['mazda', 'cx-30'],
            ['honda', 'jazz'], ['honda', 'hr-v'], ['honda', 'cr-v'],
            ['mitsubishi', 'colt'], ['mitsubishi', 'asx'], ['mitsubishi', 'outlander'], ['mitsubishi', 'pajero'],
            ['dacia', 'sandero'], ['dacia', 'duster'], ['dacia', 'spring'], ['dacia', 'lodgy'],
            ['dacia', 'dokker'], ['dacia', 'jogger'],
            ['chevrolet', 'spark'], ['daewoo', 'matiz'], ['lancia', 'ypsilon'], ['smart'],
        ],

        // Models that are ONLY ever sold as sedan/estate: accepted without needing a body word.
        'always_sedan_or_estate' => [
            ['dacia', 'logan'],
            ['skoda', 'octavia'], ['skoda', 'rapid'], ['skoda', 'superb'],
            ['toyota', 'avensis'], ['toyota', 'camry'],
            ['honda', 'accord'],
            ['mazda 6'],
            ['hyundai', 'elantra'], ['hyundai', 'i40'], ['hyundai', 'sonata'],
            ['kia', 'optima'], ['kia', 'magentis'],
            ['vw', 'jetta'], ['volkswagen', 'jetta'], ['vw', 'passat'], ['volkswagen', 'passat'],
            ['opel', 'insignia'],
            ['renault', 'fluence'], ['renault', 'symbol'], ['renault', 'talisman'],
            ['peugeot 301'], ['peugeot 508'],
            ['citroen', 'c-elysee'], ['citroen', 'elysee'],
        ],
    ],

];
