<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

interface ReliabilityRuleEvaluator
{
    /**
     * @param  array<string, mixed>  $listing  Candidate or saved listing attributes
     *                                         (title, description, year, mileage_km, price,
     *                                         currency, and optionally id when rescoring a saved row).
     * @return array<int, ReliabilityFlag>
     */
    public function evaluate(array $listing): array;
}
