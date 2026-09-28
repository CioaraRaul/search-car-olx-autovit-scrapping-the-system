<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

/**
 * Flags mileage that's suspiciously low for the car's age — a common sign of
 * an odometer rollback or a listing that's not being fully honest.
 */
class LowMileageEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $year = $listing['year'] ?? null;
        $mileageKm = $listing['mileage_km'] ?? null;

        if ($year === null || $mileageKm === null) {
            return [];
        }

        $config = config('car_knowledge.low_mileage');
        $ageYears = max(0, (int) date('Y') - (int) $year);

        if ($ageYears < $config['min_age_years']) {
            return [];
        }

        $expectedKm = $ageYears * $config['expected_km_per_year'];

        if ($expectedKm <= 0 || $mileageKm >= $expectedKm * $config['suspicious_ratio']) {
            return [];
        }

        return [new ReliabilityFlag(
            'low-mileage-for-age',
            "Mileage ({$mileageKm} km) is suspiciously low for a {$ageYears}-year-old car.",
            $config['penalty'],
        )];
    }
}
