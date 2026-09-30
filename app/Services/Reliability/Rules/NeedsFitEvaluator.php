<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

/**
 * Rejects cars that don't fit this buyer's driving pattern (see the
 * car-buyer-profile skill): mostly short city trips, a 23-year-old driver
 * (RCA insurance and road tax grow with engine size/power), low running costs.
 *
 *  - Diesel: DPF/EGR clog on short trips, and ~8,000 km/year never earns back
 *    the higher repair risk.
 *  - Engine bigger than max_engine_cc or stronger than max_horsepower: pricier
 *    RCA, road tax and fuel.
 *
 * Uses the structured fields when known (Autovit always; OLX once its ad page
 * has been read) and falls back to the title/description text, because OLX
 * search results carry no fuel/engine data. A value that is unknown everywhere
 * is NOT rejected — a parsing gap must not discard a good car.
 *
 * Every flag is an absolute exclusion (penalty 100), like the damaged-car rule.
 */
class NeedsFitEvaluator implements ReliabilityRuleEvaluator
{
    /** Engine-code style words: safe to look for anywhere in title or description. */
    private const DIESEL_ENGINE_WORDS = '/(?<![\p{L}\p{N}])(tdi|tdci|dci|blue\s?dci|cdti|crdi|hdi|bluehdi|d-?4d|multijet|jtdm?|dtec|i-?dtec|skyactiv-?d|dpf|fap)(?![\p{L}\p{N}])/iu';

    /** A displacement immediately followed by "d", e.g. "1.5D" or "1.6 d" (diesel). */
    private const DIESEL_DISPLACEMENT_D = '/(?<![\d.,])\d[.,]\d\s?d(?![\p{L}\p{N}])/iu';

    /** Plain fuel words: only trusted in the title (a description may say "no diesel smell"). */
    private const DIESEL_TITLE_WORDS = '/(?<![\p{L}\p{N}])(diesel|dizel|motorina|motorină)(?![\p{L}\p{N}])/iu';

    public function evaluate(array $listing): array
    {
        $config = config('car_knowledge.needs_fit');

        // penalty 0 switches the rule off (set CAR_KNOWLEDGE_NEEDS_FIT_PENALTY=0).
        if ($config['penalty'] <= 0) {
            return [];
        }

        $title = (string) ($listing['title'] ?? '');
        $text = $title.' '.($listing['description'] ?? '');
        $flags = [];

        if ($this->isDiesel($listing, $title, $text)) {
            $flags[] = new ReliabilityFlag(
                'diesel-unsuited-to-short-trips',
                'Diesel — the DPF/EGR clog on short city trips and ~8,000 km a year never repays the higher repair risk. Not suited to this buyer.',
                $config['penalty'],
            );
        }

        $cc = $this->engineCapacityCc($listing, $title);

        if ($cc !== null && $cc > $config['max_engine_cc']) {
            $flags[] = new ReliabilityFlag(
                'engine-too-large',
                "Engine {$cc} cc is bigger than {$config['max_engine_cc']} cc — higher RCA insurance, road tax and fuel for a young driver.",
                $config['penalty'],
            );
        }

        $hp = $this->horsepower($listing, $text);

        if ($hp !== null && $hp > $config['max_horsepower']) {
            $flags[] = new ReliabilityFlag(
                'too-powerful',
                "{$hp} HP is above {$config['max_horsepower']} HP — RCA insurance rises sharply with power for a young driver.",
                $config['penalty'],
            );
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function isDiesel(array $listing, string $title, string $text): bool
    {
        $fuel = $listing['fuel_type'] ?? null;

        if ($fuel !== null) {
            return $fuel === 'diesel';
        }

        return preg_match(self::DIESEL_ENGINE_WORDS, $text) === 1
            || preg_match(self::DIESEL_DISPLACEMENT_D, $title) === 1
            || preg_match(self::DIESEL_TITLE_WORDS, $title) === 1;
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function engineCapacityCc(array $listing, string $title): ?int
    {
        if (($listing['engine_capacity_cc'] ?? null) !== null) {
            return (int) $listing['engine_capacity_cc'];
        }

        // "1.6", "2.0" or "1,4" in the title, not part of a longer number like "85.913".
        if (preg_match('/(?<![\d.,])([1-6])[.,]([0-9])(?![\d])/', $title, $m) === 1) {
            return ((int) $m[1]) * 1000 + ((int) $m[2]) * 100;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function horsepower(array $listing, string $text): ?int
    {
        if (($listing['horsepower'] ?? null) !== null) {
            return (int) $listing['horsepower'];
        }

        if (preg_match('/(?<![\d])(\d{2,3})\s*(?:cp|hp|cai)(?![\p{L}])/iu', $text, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
