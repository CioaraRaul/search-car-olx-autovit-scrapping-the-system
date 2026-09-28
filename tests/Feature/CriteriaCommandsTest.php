<?php

use App\Models\SearchCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('criteria:set persists a known parameter', function () {
    $this->artisan('criteria:set', ['name' => 'price_max', 'value' => '7000'])
        ->assertExitCode(0);

    expect(SearchCriterion::where('key', 'price_max')->value('value'))->toBe('7000');
});

test('criteria:set overwrites a previously set value', function () {
    $this->artisan('criteria:set', ['name' => 'year_min', 'value' => '2010'])->assertExitCode(0);
    $this->artisan('criteria:set', ['name' => 'year_min', 'value' => '2013'])->assertExitCode(0);

    expect(SearchCriterion::where('key', 'year_min')->count())->toBe(1)
        ->and(SearchCriterion::where('key', 'year_min')->value('value'))->toBe('2013');
});

test('criteria:set rejects an unknown parameter name', function () {
    $this->artisan('criteria:set', ['name' => 'nonsense_param', 'value' => '5'])
        ->assertExitCode(1);

    expect(SearchCriterion::where('key', 'nonsense_param')->exists())->toBeFalse();
});

test('criteria:set rejects a body_type value outside the allowed list', function () {
    $this->artisan('criteria:set', ['name' => 'body_type', 'value' => 'suv'])
        ->assertExitCode(1);
});

test('criteria:set accepts a comma-separated body_type within the allowed list', function () {
    $this->artisan('criteria:set', ['name' => 'body_type', 'value' => 'sedan,break'])
        ->assertExitCode(0);

    expect(SearchCriterion::where('key', 'body_type')->value('value'))->toBe('sedan,break');
});

test('criteria:set rejects a non-numeric value for an int parameter', function () {
    $this->artisan('criteria:set', ['name' => 'km_max', 'value' => 'lots'])
        ->assertExitCode(1);
});

test('criteria:help lists every catalog parameter including unset ones', function () {
    $this->artisan('criteria:set', ['name' => 'price_max', 'value' => '7000'])->assertExitCode(0);

    Illuminate\Support\Facades\Artisan::call('criteria:help');
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($output)
        ->toContain('price_max')
        ->toContain('7000')
        ->toContain('brand');
});
