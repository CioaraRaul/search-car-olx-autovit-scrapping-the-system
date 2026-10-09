<?php

namespace Database\Seeders;

use App\Models\ModelReputation;
use Illuminate\Database\Seeder;

/**
 * The starting list of sedan/estate models the buyer might meet at 5,000-7,500 EUR, with a reliability
 * verdict for each. Edit the model_reputations table (or this list and re-run the seeder) to change a
 * verdict. A model that is NOT listed is treated as unknown and dropped, so add any model you want to see.
 * Hatchbacks/SUVs/premium brands are not listed here — BodyTypeGuard already rejects those.
 *
 * Verdicts are general used-car knowledge (parts cost, typical failures, long-term durability), not live data.
 */
class ModelReputationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->models() as [$make, $model, $makeWords, $modelWords, $verdict, $reason]) {
            $groups = [];

            foreach ($makeWords as $makeWord) {
                foreach ($modelWords as $modelWord) {
                    // A bare number ("3", "301") is only meaningful next to its make — as a phrase, so a
                    // mileage or year elsewhere in the title can't be mistaken for the model.
                    $groups[] = ctype_digit($modelWord) ? ["{$makeWord} {$modelWord}"] : [$makeWord, $modelWord];
                }
            }

            ModelReputation::updateOrCreate(
                ['make' => $make, 'model' => $model],
                ['keywords' => $groups, 'verdict' => $verdict, 'reason' => $reason, 'active' => true],
            );
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: array<int, string>, 3: array<int, string>, 4: string, 5: string}>
     */
    private function models(): array
    {
        $r = ModelReputation::RECOMMENDED;
        $a = ModelReputation::ACCEPTABLE;
        $x = ModelReputation::AVOID;
        $vw = ['vw', 'volkswagen'];

        return [
            // Dacia
            ['Dacia', 'Logan', ['dacia'], ['logan'], $r, 'Simple, cheap parts, durable petrol engines; the best value in this class.'],

            // Skoda
            ['Skoda', 'Octavia', ['skoda'], ['octavia'], $a, 'Roomy and durable; prefer 1.4/1.6 MPI or 1.8 TSI, be careful with 1.2 TSI and DSG gearboxes.'],
            ['Skoda', 'Rapid', ['skoda'], ['rapid'], $a, 'Cheap to run; 1.6 MPI is the safe pick, 1.2 TSI has chain/oil issues.'],
            ['Skoda', 'Fabia', ['skoda'], ['fabia'], $a, 'Good basic car (Combi for an estate); avoid the 1.2 TSI.'],
            ['Skoda', 'Superb', ['skoda'], ['superb'], $a, 'Very roomy; check gearbox and engine variant, parts dearer than Octavia.'],

            // Volkswagen
            ['Volkswagen', 'Passat', $vw, ['passat'], $a, 'Solid body and comfort; petrol 1.4/1.8 TSI have timing/oil-use risks, check history.'],
            ['Volkswagen', 'Jetta', $vw, ['jetta'], $a, 'Sedan Golf underneath; reliable with the 1.4/1.6 petrol engines.'],
            ['Volkswagen', 'Golf Variant', $vw, ['golf variant', 'golf combi'], $a, 'Estate Golf; good if maintained, avoid early 1.4 TSI and DSG without service history.'],

            // Toyota
            ['Toyota', 'Avensis', ['toyota'], ['avensis'], $r, 'Among the most durable used cars; 1.8 Valvematic/2.0 petrol are the safe picks.'],
            ['Toyota', 'Corolla', ['toyota'], ['corolla'], $r, 'Excellent durability and low running costs.'],
            ['Toyota', 'Auris', ['toyota'], ['auris'], $r, 'Touring Sports estate is reliable; 1.6 petrol is the sound choice.'],
            ['Toyota', 'Camry', ['toyota'], ['camry'], $a, 'Reliable but thirsty and rare in this price range.'],

            // Honda
            ['Honda', 'Accord', ['honda'], ['accord'], $r, 'Very durable petrol engines; check for rust and neglected maintenance.'],
            ['Honda', 'Civic', ['honda'], ['civic'], $r, 'Reliable, economical; sedan/Tourer variants are the ones that fit.'],

            // Mazda
            ['Mazda', 'Mazda 3', ['mazda'], ['3'], $r, 'SkyActiv petrol engines are reliable; sedan version fits.'],
            ['Mazda', 'Mazda 6', ['mazda'], ['6'], $r, 'Durable 2.0 petrol, comfortable; check rust.'],

            // Hyundai / Kia
            ['Hyundai', 'Elantra', ['hyundai'], ['elantra'], $a, 'Reliable petrol sedan, simple mechanics.'],
            ['Hyundai', 'i30', ['hyundai'], ['i30'], $r, 'Reliable petrol engines; the CW is the estate version.'],
            ['Hyundai', 'i40', ['hyundai'], ['i40'], $a, 'Comfortable estate/sedan; 1.6 GDI petrol is fine, watch the 1.7 diesel.'],
            ['Hyundai', 'Sonata', ['hyundai'], ['sonata'], $a, 'Large and simple; fuel use is high.'],
            ['Kia', 'Ceed', ['kia'], ['ceed', "cee'd"], $a, 'Sporty-wagon (SW) is reliable with the 1.4/1.6 petrol.'],
            ['Kia', 'Optima', ['kia'], ['optima'], $a, 'Spacious sedan; simple petrol engines.'],
            ['Kia', 'Rio', ['kia'], ['rio'], $a, 'Cheap and simple; sedan version fits.'],

            // Ford
            ['Ford', 'Focus', ['ford'], ['focus'], $a, 'Good drive and cheap parts; Combi estate; avoid the 1.0 EcoBoost.'],
            ['Ford', 'Mondeo', ['ford'], ['mondeo'], $a, 'Roomy; prefer the 1.6/2.0 petrol, avoid 1.6 EcoBoost.'],

            // Opel
            ['Opel', 'Astra', ['opel'], ['astra'], $a, 'Cheap parts; sedan/Sports Tourer; avoid the 1.4 turbo.'],
            ['Opel', 'Insignia', ['opel'], ['insignia'], $a, 'Comfortable; petrol 1.6/1.8 preferred, check timing chain on the 2.0 turbo.'],

            // Renault
            ['Renault', 'Megane', ['renault'], ['megane'], $a, 'Grandtour estate / sedan; fine petrol engines, check electronics.'],
            ['Renault', 'Fluence', ['renault'], ['fluence'], $a, 'Simple 1.6 petrol sedan, cheap to run.'],
            ['Renault', 'Symbol', ['renault'], ['symbol'], $a, 'Logan-like simplicity; durable.'],
            ['Renault', 'Talisman', ['renault'], ['talisman'], $a, 'Comfortable; petrol TCe engines have mixed record.'],
            ['Renault', 'Laguna', ['renault'], ['laguna'], $x, 'Known electronics, gearbox and engine problems; poor reliability.'],

            // Peugeot / Citroen
            ['Peugeot', '301', ['peugeot'], ['301'], $a, 'Simple budget sedan, cheap to maintain.'],
            ['Peugeot', '308', ['peugeot'], ['308'], $a, 'SW estate; avoid the 1.6 THP turbo (timing chain, oil use).'],
            ['Peugeot', '508', ['peugeot'], ['508'], $a, 'Spacious; check the 1.6 THP carefully.'],
            ['Citroen', 'C-Elysee', ['citroen'], ['c-elysee', 'elysee', 'c elysee'], $a, 'Simple budget sedan, cheap to maintain.'],
            ['Citroen', 'C4', ['citroen'], ['c4'], $a, 'Comfortable; avoid the early 1.6 THP.'],
            ['Citroen', 'C5', ['citroen'], ['c5'], $x, 'Complex suspension and electronics; expensive failures.'],

            // Seat / Fiat / others
            ['Seat', 'Toledo', ['seat'], ['toledo'], $a, 'Skoda Rapid twin; simple and cheap.'],
            ['Seat', 'Leon', ['seat'], ['leon'], $a, 'ST estate; same caveats as VW Group TSI engines.'],
            ['Seat', 'Exeo', ['seat'], ['exeo'], $a, 'Audi A4 B7 underneath; sturdy but parts are dearer.'],
            ['Fiat', 'Tipo', ['fiat'], ['tipo'], $a, 'Simple, cheap; the newer model has little history.'],
            ['Fiat', 'Linea', ['fiat'], ['linea'], $a, 'Budget sedan with basic mechanics.'],
            ['Fiat', 'Croma', ['fiat'], ['croma'], $x, 'Poor reliability and rare parts.'],
            ['Nissan', 'Almera', ['nissan'], ['almera'], $a, 'Simple, reliable petrol sedan.'],
            ['Mitsubishi', 'Lancer', ['mitsubishi'], ['lancer'], $a, 'Sturdy and simple; parts are not as cheap as European brands.'],
            ['Subaru', 'Legacy', ['subaru'], ['legacy'], $a, 'Strong, but head-gasket risk on older boxers and high fuel use.'],
            ['Subaru', 'Impreza', ['subaru'], ['impreza'], $a, 'Strong, but high fuel use and boxer-engine maintenance.'],
            ['Chevrolet', 'Cruze', ['chevrolet'], ['cruze'], $a, 'Cheap; prefer the 1.6 petrol, avoid the early 1.8 and the diesels.'],
            ['Chevrolet', 'Aveo', ['chevrolet'], ['aveo'], $a, 'Basic and cheap; sedan version fits.'],
            ['Chevrolet', 'Epica', ['chevrolet'], ['epica'], $x, 'Weak build, thirsty and hard-to-find parts.'],
            ['Lada', 'Granta/Vesta', ['lada'], ['granta', 'vesta'], $x, 'Weak build quality and low resale.'],
            ['Chrysler', '300/Sebring', ['chrysler'], ['300', 'sebring'], $x, 'Costly repairs, thirsty and scarce parts.'],
        ];
    }
}
