<?php

namespace App\Support;

final class CriteriaCatalog
{
    /**
     * The full set of search-criteria parameters the app understands.
     *
     * @return array<string, array{type: string, description: string, allowed?: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            'price_max' => [
                'type' => 'int',
                'description' => 'Maximum price you are willing to pay.',
            ],
            'price_min' => [
                'type' => 'int',
                'description' => 'Minimum price — cheaper listings are skipped (too-cheap cars are usually scams or hidden problems).',
            ],
            'price_currency' => [
                'type' => 'string',
                'description' => 'Currency price_min and price_max are expressed in (e.g. EUR).',
            ],
            'year_min' => [
                'type' => 'int',
                'description' => 'Minimum manufacturing year.',
            ],
            'km_max' => [
                'type' => 'int',
                'description' => 'Maximum mileage in kilometers.',
            ],
            'engine_capacity_max' => [
                'type' => 'float',
                'description' => 'Maximum engine capacity in liters (e.g. 2.0).',
            ],
            'body_type' => [
                'type' => 'string',
                'description' => 'Allowed body types (comma-separated to allow more than one).',
                'allowed' => ['sedan', 'break'],
            ],
            'brand' => [
                'type' => 'string',
                'description' => 'Preferred brand (optional).',
            ],
            'model' => [
                'type' => 'string',
                'description' => 'Preferred model (optional).',
            ],
            'fuel_type' => [
                'type' => 'string',
                'description' => 'Preferred fuel type (optional).',
            ],
            'transmission' => [
                'type' => 'string',
                'description' => 'Preferred transmission (optional).',
            ],
            'city' => [
                'type' => 'string',
                'description' => 'Preferred city or zone (optional).',
            ],
        ];
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    /**
     * @return array{type: string, description: string, allowed?: array<int, string>}|null
     */
    public static function get(string $name): ?array
    {
        return self::definitions()[$name] ?? null;
    }
}
