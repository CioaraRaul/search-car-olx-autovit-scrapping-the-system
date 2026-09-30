<?php

use App\Services\Reliability\BodyTypeGuard;

test('rejects a BMW Seria 2 (coupe / Active Tourer are never sedan or estate)', function (string $title) {
    expect((new BodyTypeGuard)->isAcceptable($title))->toBeFalse();
})->with([
    'BMW Seria 2 218i 2015 | Finantare cu 0 avans',
    'BMW Seria 2 automat, 2.0disel, 150cp, an 2015,euro6',
    'BMW Seria 2 Active Tourer 218d',
]);

test('rejects premium brands even with a sedan keyword', function (string $title) {
    expect((new BodyTypeGuard)->rejectionReason($title))->toBe(BodyTypeGuard::PREMIUM_BRAND);
})->with(['Audi A4 sedan 2014', 'Mercedes C220 Combi', 'BMW 320d Touring']);

test('rejects a hatchback-only or unlisted model', function (string $title) {
    expect((new BodyTypeGuard)->isAcceptable($title))->toBeFalse();
})->with(['Vand Volkswagen Polo, an 2020', 'Citroen C5 2.0 Diesel', 'Renault Clio 1.2', 'Toyota Yaris 1.3', 'Opel Corsa 1.2']);

test('rejects an explicit non-sedan body keyword even on a whitelisted model', function () {
    expect((new BodyTypeGuard)->rejectionReason('Skoda Octavia Hatchback 1.6'))
        ->toBe(BodyTypeGuard::REJECTED_BODY);
});

test('accepts models that are only ever sedan or estate', function (string $title) {
    expect((new BodyTypeGuard)->isAcceptable($title))->toBeTrue();
})->with([
    'Dacia Logan 0.9 TCe GPL 2019',
    'Skoda Octavia Facelift 2013 1.6TDi',
    'Toyota Avensis 1.8 benzina',
    'VW Passat B7 1.4 TSI',
    'Hyundai Elantra 1.6',
]);

test('accepts a multi-body model only when the title confirms sedan or estate', function () {
    $guard = new BodyTypeGuard;

    expect($guard->isAcceptable('Ford Focus Combi 1.6 benzina'))->toBeTrue()
        ->and($guard->isAcceptable('Ford Focus 1.6 benzina'))->toBeFalse()
        ->and($guard->rejectionReason('Ford Focus 1.6 benzina'))->toBe(BodyTypeGuard::BODY_UNCONFIRMED)
        ->and($guard->isAcceptable('Mazda 3 Sedan 1.6'))->toBeTrue();
});

test('matches whole words only', function () {
    // "3" must not match inside "2013", and "mini" must not match "minivan" as a premium brand
    expect((new BodyTypeGuard)->rejectionReason('Mazda CX-5 2013'))->toBe(BodyTypeGuard::UNLISTED_MODEL)
        ->and((new BodyTypeGuard)->rejectionReason('Ford Transit minivan'))->toBe(BodyTypeGuard::REJECTED_BODY);
});

test('is case-insensitive and rejects an empty title', function () {
    $guard = new BodyTypeGuard;

    expect($guard->isAcceptable('DACIA LOGAN MCV'))->toBeTrue()
        ->and($guard->isAcceptable(''))->toBeFalse();
});
