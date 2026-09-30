<?php

use App\Services\Reliability\BodyTypeGuard;

test('rejects a BMW Seria 2 even when the seller calls it berlina', function (string $title) {
    expect((new BodyTypeGuard)->isAcceptable($title, 'Berlina, sedan, stare perfecta'))->toBeFalse();
})->with([
    'BMW Seria 2 218i 2015 | Finantare cu 0 avans',
    'BMW Seria 2 automat, 2.0disel, 150cp, an 2015,euro6',
    'BMW Seria 2 Active Tourer 218d',
]);

test('rejects premium brands even with a sedan keyword', function (string $title) {
    expect((new BodyTypeGuard)->rejectionReason($title))->toBe(BodyTypeGuard::PREMIUM_BRAND);
})->with(['Audi A4 sedan 2014', 'Mercedes C220 Combi', 'BMW 320d Touring']);

test('rejects models that are never a sedan or estate, whatever the description says', function (string $title) {
    expect((new BodyTypeGuard)->rejectionReason($title, 'berlina sedan'))->toBe(BodyTypeGuard::HATCHBACK_ONLY);
})->with(['Vand Volkswagen Polo, an 2020', 'Renault Clio 1.2', 'Toyota Yaris 1.3', 'Opel Corsa 1.2', 'Dacia Sandero 0.9', 'Hyundai i20 1.2', 'Nissan Qashqai 1.6']);

test('an estate version of a hatchback model passes the hatchback-only check', function (string $title) {
    expect((new BodyTypeGuard)->hardRejection($title))->toBeNull();
})->with(['VW Golf Variant 1.6', 'Skoda Fabia Combi 1.2 TSI', 'Renault Clio Grandtour', 'Toyota Auris Touring Sports']);

test('rejects an explicit non-sedan body keyword even on an always-sedan model', function () {
    expect((new BodyTypeGuard)->rejectionReason('Skoda Octavia Hatchback 1.6'))->toBe(BodyTypeGuard::REJECTED_BODY);
});

test('models that are only ever sedan or estate need no body word', function (string $title) {
    expect((new BodyTypeGuard)->isAcceptable($title))->toBeTrue();
})->with([
    'Dacia Logan 0.9 TCe GPL 2019', 'Skoda Octavia Facelift 2013 1.6TDi', 'Toyota Avensis 1.8 benzina',
    'VW Passat B7 1.4 TSI', 'Hyundai Elantra 1.6', 'Citroën C-Elysée 1.2',
]);

test('any other model is accepted when the title OR the description says sedan/berlina/estate', function () {
    $guard = new BodyTypeGuard;

    expect($guard->isAcceptable('Ford Focus Combi 1.6 benzina'))->toBeTrue()
        ->and($guard->isAcceptable('Ford Focus 1.6 benzina', 'Vand Ford Focus berlina, unic proprietar'))->toBeTrue()
        ->and($guard->isAcceptable('Mazda 3 1.6', 'Masina tip sedan, service la zi'))->toBeTrue()
        ->and($guard->isAcceptable('Opel Astra 1.4', 'Break, 5 usi, benzina'))->toBeTrue();
});

test('a model with no body word in the title or the description is rejected as unconfirmed', function () {
    $guard = new BodyTypeGuard;

    expect($guard->rejectionReason('Ford Focus 1.6 benzina', 'Masina foarte buna, unic proprietar'))
        ->toBe(BodyTypeGuard::BODY_UNCONFIRMED)
        ->and($guard->rejectionReason('Ford Focus 1.6 benzina'))->toBe(BodyTypeGuard::BODY_UNCONFIRMED);
});

test('matches whole words only and ignores decimals after model numbers', function () {
    $guard = new BodyTypeGuard;

    // "Mazda 6 2.0" must not be mistaken for a Mazda 2; "Peugeot 301 108 000 km" is not a Peugeot 108.
    expect($guard->hardRejection('Mazda 6 2.0 benzina'))->toBeNull()
        ->and($guard->hardRejection('Mazda 2 1.3 benzina'))->toBe(BodyTypeGuard::HATCHBACK_ONLY)
        ->and($guard->hardRejection('Peugeot 301 108 000 km'))->toBeNull()
        ->and($guard->hardRejection('Ford Transit minivan'))->toBe(BodyTypeGuard::REJECTED_BODY);
});

test('is case-insensitive and accent-insensitive', function () {
    $guard = new BodyTypeGuard;

    expect($guard->isAcceptable('DACIA LOGAN MCV'))->toBeTrue()
        ->and($guard->isAcceptable('Ford Mondeo', 'Mașină în stare foarte bună, BERLINĂ'))->toBeTrue();
});
