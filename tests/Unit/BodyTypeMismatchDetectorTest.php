<?php

use App\Services\Reliability\BodyTypeMismatchDetector;

test('flags a VW Polo title as hatchback-only', function () {
    $detector = new BodyTypeMismatchDetector;

    expect($detector->isHatchbackOnlyModel('Vand Volkswagen Polo, an 2020'))->toBeTrue();
});

test('does not flag a sedan-only title', function () {
    $detector = new BodyTypeMismatchDetector;

    expect($detector->isHatchbackOnlyModel('Vand Skoda Superb Berlina, an 2019'))->toBeFalse();
});

test('does not flag a title with no matching model', function () {
    $detector = new BodyTypeMismatchDetector;

    expect($detector->isHatchbackOnlyModel('Vand Dacia Logan, an 2018'))->toBeFalse();
});

test('is case-insensitive', function () {
    $detector = new BodyTypeMismatchDetector;

    expect($detector->isHatchbackOnlyModel('VW POLO 1.4 TDI'))->toBeTrue();
});

test('returns false for an empty title', function () {
    $detector = new BodyTypeMismatchDetector;

    expect($detector->isHatchbackOnlyModel(''))->toBeFalse();
});
