<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

/**
 * Flags a listing marked as damaged/accident history on Autovit's own ad
 * page ("Avariata: Da" — see AutovitDetailFetcher). OLX exposes no
 * equivalent field, so `is_damaged` is always null there and this rule never
 * fires for OLX listings.
 */
class DamagedVehicleEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        if (($listing['is_damaged'] ?? null) !== true) {
            return [];
        }

        return [new ReliabilityFlag(
            'damaged-vehicle',
            'Listed as damaged / accident history (Avariata: Da).',
            (int) config('car_knowledge.damaged_vehicle.penalty'),
        )];
    }
}
