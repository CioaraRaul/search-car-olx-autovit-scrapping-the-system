<?php

namespace App\Services\Reliability\Rules;

use App\Enums\ListingSource;
use App\Services\Reliability\ReliabilityFlag;

/**
 * An Autovit ad without Autovit's "verified details" mark (advert.verifiedCar, read by
 * AutovitDetailFetcher) loses a few points: 100 -> 90, which still passes the default
 * threshold on its own but not together with any other flag. Verified ads, ads whose
 * flag is unknown (not read yet) and OLX ads are untouched.
 */
class UnverifiedAutovitEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $source = $listing['source'] ?? null;
        $source = $source instanceof ListingSource ? $source->value : $source;

        if ($source !== ListingSource::Autovit->value || ($listing['autovit_verified'] ?? null) !== false) {
            return [];
        }

        return [new ReliabilityFlag(
            'unverified-autovit-details',
            'Autovit has not verified the details of this ad.',
            (int) config('car_knowledge.unverified_autovit.penalty'),
        )];
    }
}
