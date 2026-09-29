<?php

namespace App\Services\Reliability;

use App\Services\Reliability\Rules\BelowMarketPriceEvaluator;
use App\Services\Reliability\Rules\DamagedVehicleEvaluator;
use App\Services\Reliability\Rules\HighFuelConsumptionEvaluator;
use App\Services\Reliability\Rules\KnownIssueEvaluator;
use App\Services\Reliability\Rules\LowMileageEvaluator;
use App\Services\Reliability\Rules\ReliabilityRuleEvaluator;

class ReliabilityScorer
{
    /** @var array<int, ReliabilityRuleEvaluator> */
    private readonly array $evaluators;

    public function __construct()
    {
        $this->evaluators = [
            new KnownIssueEvaluator,
            new LowMileageEvaluator,
            new BelowMarketPriceEvaluator,
            new DamagedVehicleEvaluator,
            new HighFuelConsumptionEvaluator,
        ];
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    public function score(array $listing): ReliabilityScore
    {
        $flags = [];

        foreach ($this->evaluators as $evaluator) {
            array_push($flags, ...$evaluator->evaluate($listing));
        }

        $totalPenalty = array_sum(array_map(fn (ReliabilityFlag $flag) => $flag->penalty, $flags));
        $score = max(0, (int) config('car_knowledge.base_score') - $totalPenalty);

        return new ReliabilityScore($score, $flags);
    }
}
