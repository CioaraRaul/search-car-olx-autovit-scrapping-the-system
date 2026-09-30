<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

/**
 * Rejects a used car advertised with an impossibly low odometer reading, e.g.
 * "350 km" on a 2015 car. That is either a typo (350,000 km typed as 350) or a
 * scam, and in both cases the real mileage is unknown — so it's an absolute
 * exclusion (penalty 100), not a soft nudge like LowMileageEvaluator's
 * "low but plausible" ratio rule.
 *
 * A car from the current year is allowed a tiny reading (it may genuinely be
 * nearly new). An unknown mileage is left to other rules, not judged here.
 */
class ImplausibleMileageEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $year = $listing['year'] ?? null;
        $mileageKm = $listing['mileage_km'] ?? null;

        if ($year === null || $mileageKm === null) {
            return [];
        }

        $config = config('car_knowledge.implausible_mileage');

        if ((int) $year >= (int) date('Y') || $mileageKm >= $config['min_km']) {
            return [];
        }

        return [new ReliabilityFlag(
            'implausible-mileage',
            "Mileage ({$mileageKm} km) is impossibly low for a {$year} car — likely a typo (e.g. thousands omitted) or a scam.",
            $config['penalty'],
        )];
    }
}
