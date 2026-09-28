<?php

namespace App\Services\Reliability\Rules;

use App\Models\Listing;
use App\Services\Reliability\ReliabilityFlag;

/**
 * Flags a price far below the median of comparable already-saved listings
 * (same currency, similar year). Needs a minimum sample size to say anything —
 * too small a sample is noise, not signal.
 *
 * Known limitation: compares raw price, not a currency-normalized value.
 * Restricting the comparison set to the same currency is the safeguard until
 * Chapter 3 (price_eur normalization) exists.
 */
class BelowMarketPriceEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $price = $listing['price'] ?? null;
        $currency = $listing['currency'] ?? null;
        $year = $listing['year'] ?? null;

        if ($price === null || $currency === null || $year === null) {
            return [];
        }

        $config = config('car_knowledge.below_market_price');

        $comparablePrices = Listing::query()
            ->where('currency', $currency)
            ->whereBetween('year', [$year - $config['year_range'], $year + $config['year_range']])
            ->when(isset($listing['id']), fn ($query) => $query->where('id', '!=', $listing['id']))
            ->pluck('price')
            ->all();

        if (count($comparablePrices) < $config['min_sample_size']) {
            return [];
        }

        $median = $this->median($comparablePrices);

        if ($median <= 0 || $price >= $median * $config['ratio_threshold']) {
            return [];
        }

        return [new ReliabilityFlag(
            'below-market-price',
            "Price ({$price} {$currency}) is far below the median of comparable listings ({$median} {$currency}).",
            $config['penalty'],
        )];
    }

    /**
     * @param  array<int, int>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return (float) $values[$middle];
    }
}
