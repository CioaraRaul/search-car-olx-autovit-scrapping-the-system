<?php

namespace Database\Seeders;

use App\Models\ReliabilityRule;
use Illuminate\Database\Seeder;

/**
 * Starting set of known-problem-engine rules, from car-finder-handoff.md plus
 * general knowledge of which model badges carried the affected engines. This
 * is a starting point, not a final list — expand it over time by adding rows,
 * no deploy needed.
 */
class ReliabilityRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name' => 'vw-group-tdi-ea189',
                'keywords' => [
                    ['vw', '1.6', 'tdi'],
                    ['vw', '2.0', 'tdi'],
                    ['volkswagen', '1.6', 'tdi'],
                    ['volkswagen', '2.0', 'tdi'],
                    ['skoda', '1.6', 'tdi'],
                    ['skoda', '2.0', 'tdi'],
                    ['seat', '1.6', 'tdi'],
                    ['seat', '2.0', 'tdi'],
                    ['audi', '2.0', 'tdi'],
                    ['ea189'],
                ],
                'penalty' => 30,
                'message' => 'VW Group 1.6/2.0 TDI (EA189) — affected by the 2015 emissions-cheating scandal; check for the official recall/fix.',
            ],
            [
                'name' => 'ford-1.6-tdci-powershift',
                'keywords' => [
                    ['ford', '1.6', 'tdci', 'powershift'],
                    ['ford', 'powershift'],
                ],
                'penalty' => 25,
                'message' => 'Ford 1.6 TDCi with PowerShift dual-clutch automatic — known reliability issues with this gearbox.',
            ],
            [
                'name' => 'bmw-n47-diesel',
                'keywords' => [
                    ['116d'], ['118d'], ['120d'], ['318d'], ['320d'], ['520d'],
                ],
                'penalty' => 25,
                'message' => 'Likely BMW N47 diesel (matched by model badge — sellers rarely write the engine code itself) — known timing chain failure risk.',
            ],
        ];

        foreach ($rules as $rule) {
            ReliabilityRule::updateOrCreate(['name' => $rule['name']], $rule);
        }
    }
}
