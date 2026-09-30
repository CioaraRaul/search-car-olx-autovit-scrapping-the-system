<?php

use App\Services\Reliability\Rules\NeedsFitEvaluator;

function needsFitRules(array $listing): array
{
    return collect((new NeedsFitEvaluator)->evaluate(array_merge(['description' => ''], $listing)))
        ->pluck('rule')->all();
}

test('rejects a diesel by its structured fuel type', function () {
    expect(needsFitRules(['title' => 'Dacia Logan', 'fuel_type' => 'diesel']))
        ->toContain('diesel-unsuited-to-short-trips');
});

test('rejects a diesel recognised from engine-code words in the title when fuel is unknown', function (string $title) {
    expect(needsFitRules(['title' => $title, 'fuel_type' => null]))
        ->toContain('diesel-unsuited-to-short-trips');
})->with(['Dacia Logan 1.5 dCi 90 CP', 'Skoda Octavia 1.6 TDI', 'Opel Astra 1.7 CDTI', 'Peugeot 301 1.6 BlueHDI', 'Toyota Avensis 2.0 D-4D', 'Logan diesel 2015', 'Renault Fluence 1.5D 138000km', 'Skoda Fabia 1.4 d']);

test('does not reject petrol, LPG or hybrid cars', function (string $title, ?string $fuel) {
    expect(needsFitRules(['title' => $title, 'fuel_type' => $fuel]))->toBe([]);
})->with([
    'petrol field' => ['Dacia Logan 0.9 TCe', 'petrol'],
    'lpg field' => ['Dacia Logan 1.0 SCe GPL', 'petrol-lpg'],
    'hybrid field' => ['Toyota Corolla Hybrid combi', 'hybrid'],
    'unknown fuel, petrol title' => ['Dacia Logan 1.2 benzina', null],
    'unknown everything' => ['Dacia Logan MCV', null],
    'petrol with D in name' => ['Dacia Logan 1.2 Dacia', null],
]);

test('the structured fuel type wins over words in the text', function () {
    // A petrol car whose description happens to mention a diesel trim must not be rejected
    expect(needsFitRules(['title' => 'Logan', 'fuel_type' => 'petrol', 'description' => 'nu e dCi, e benzina']))->toBe([]);
});

test('rejects an engine bigger than 2.0 L but accepts 2.0 and below', function () {
    expect(needsFitRules(['title' => 'Ford Mondeo', 'engine_capacity_cc' => 2261, 'fuel_type' => 'petrol']))
        ->toContain('engine-too-large');
    expect(needsFitRules(['title' => 'Mazda 6', 'engine_capacity_cc' => 1998, 'fuel_type' => 'petrol']))->toBe([])
        ->and(needsFitRules(['title' => 'Octavia', 'engine_capacity_cc' => 1598, 'fuel_type' => 'petrol']))->toBe([])
        ->and(needsFitRules(['title' => 'Opel Astra', 'engine_capacity_cc' => 2000, 'fuel_type' => 'petrol']))->toBe([]);
});

test('reads the engine size from the title when the field is missing', function () {
    expect(needsFitRules(['title' => 'Ford Mondeo 2.5 benzina', 'fuel_type' => null]))->toContain('engine-too-large')
        ->and(needsFitRules(['title' => 'Mazda 6 2.0 benzina', 'fuel_type' => null]))->toBe([])
        ->and(needsFitRules(['title' => 'Logan 2014 GPL Km 85.913', 'fuel_type' => null]))->toBe([])
        ->and(needsFitRules(['title' => 'Logan fabricatie 11.2014', 'fuel_type' => null]))->toBe([]);
});

test('has no power limit by default, so a strong petrol engine is not rejected', function () {
    expect(needsFitRules(['title' => 'Toyota Avensis 1.8', 'horsepower' => 147, 'fuel_type' => 'petrol']))->toBe([])
        ->and(needsFitRules(['title' => 'Astra 180 CP benzina', 'fuel_type' => null]))->toBe([]);
});

test('a power limit applies only when one is configured', function () {
    config(['car_knowledge.needs_fit.max_horsepower' => 130]);

    expect(needsFitRules(['title' => 'Astra', 'horsepower' => 140, 'fuel_type' => 'petrol']))->toContain('too-powerful')
        ->and(needsFitRules(['title' => 'Astra 150 CP benzina', 'fuel_type' => null]))->toContain('too-powerful')
        ->and(needsFitRules(['title' => 'Logan 90 CP benzina', 'fuel_type' => null]))->toBe([]);
});
