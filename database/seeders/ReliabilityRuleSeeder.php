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
            // --- Added 2026-09-30 for the strict (>= 90) filter. Every penalty here is >= 15, so
            // a match alone takes a car below 90 and rejects it. Each rule names a well-known
            // engine/gearbox weakness; matched on title+description text, so it only fires when
            // the seller writes the engine/gearbox — the model whitelist and (Phase 2) fuel/size
            // rules cover the rest.
            [
                'name' => 'vw-group-dq200-dry-dsg',
                'keywords' => [
                    ['dsg', '1.2'], ['dsg', '1.4'], ['dsg', '1.6'], ['dsg', 'dq200'], ['dsg', '7 trepte'],
                ],
                'penalty' => 30,
                'message' => 'VW Group 7-speed dry-clutch DSG (DQ200) on a small engine — known mechatronic and clutch failures, expensive to repair.',
            ],
            [
                'name' => 'vw-1.4-tsi-twincharger',
                'keywords' => [
                    ['1.4', 'tsi', 'twincharger'], ['1.4', 'tsi', '160'], ['1.4', 'tsi', '170'],
                ],
                'penalty' => 30,
                'message' => 'VW Group 1.4 TSI twincharger (EA111, 160/170 HP) — timing chain stretch and oil consumption.',
            ],
            [
                'name' => 'psa-puretech-1.2',
                'keywords' => [['puretech']],
                'penalty' => 25,
                'message' => 'PSA 1.2 PureTech — wet timing belt that degrades and clogs the oil pump, plus oil consumption.',
            ],
            [
                'name' => 'psa-thp-1.6',
                'keywords' => [
                    ['thp'], ['peugeot', '1.6', 'turbo'], ['citroen', '1.6', 'turbo'],
                ],
                'penalty' => 25,
                'message' => 'PSA/BMW 1.6 THP turbo petrol — timing chain stretch and high oil consumption.',
            ],
            [
                'name' => 'renault-1.2-tce',
                'keywords' => [['1.2', 'tce']],
                'penalty' => 25,
                'message' => 'Renault 1.2 TCe turbo petrol — timing chain stretch and heavy oil consumption.',
            ],
            [
                'name' => 'renault-dacia-edc-dry-clutch',
                'keywords' => [
                    ['renault', 'edc'], ['dacia', 'edc'], ['edc', 'dublu ambreiaj'],
                ],
                'penalty' => 25,
                'message' => 'Renault/Dacia EDC dual-clutch automatic — jerky, with clutch and actuator failures.',
            ],
            [
                'name' => 'opel-1.4-turbo',
                'keywords' => [
                    ['opel', '1.4', 'turbo'], ['astra', '1.4', 'turbo'],
                ],
                'penalty' => 20,
                'message' => 'Opel 1.4 Turbo (A14NET) — timing chain stretch and water-pump/coolant leaks.',
            ],
            [
                'name' => 'ford-1.0-ecoboost',
                'keywords' => [['1.0', 'ecoboost']],
                'penalty' => 15,
                'message' => 'Ford 1.0 EcoBoost — coolant-system failures and cracked cylinder heads reported on early cars; repairs are costly.',
            ],
            [
                'name' => 'kia-optima-theta-gdi',
                'keywords' => [
                    ['optima', '2.4'], ['optima', '2.0', 'gdi'], ['optima', 'gdi'],
                ],
                'penalty' => 25,
                'message' => 'Kia/Hyundai Theta II GDI engine — recalled for engine seizure / connecting-rod bearing failures.',
            ],
            [
                'name' => 'mitsubishi-nissan-jatco-cvt',
                'keywords' => [['lancer', 'cvt'], ['jatco']],
                'penalty' => 20,
                'message' => 'Jatco CVT automatic (Mitsubishi/Nissan) — overheating and belt/pulley failures.',
            ],
        ];

        foreach ($rules as $rule) {
            ReliabilityRule::updateOrCreate(['name' => $rule['name']], $rule);
        }
    }
}
