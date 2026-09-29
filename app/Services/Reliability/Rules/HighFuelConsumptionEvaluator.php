<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

/**
 * Flags fuel consumption above the configured threshold, read from
 * Autovit's own ad page (see AutovitDetailFetcher). OLX exposes no
 * equivalent field, so `fuel_consumption_l_100km` is always null there and
 * this rule never fires for OLX listings.
 */
class HighFuelConsumptionEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $consumption = $listing['fuel_consumption_l_100km'] ?? null;

        if ($consumption === null) {
            return [];
        }

        $config = config('car_knowledge.high_fuel_consumption');

        if ((float) $consumption <= $config['threshold_l_100km']) {
            return [];
        }

        return [new ReliabilityFlag(
            'high-fuel-consumption',
            "Fuel consumption ({$consumption} l/100km) is above the {$config['threshold_l_100km']} l/100km threshold.",
            (int) $config['penalty'],
        )];
    }
}
