<?php

namespace App\Models;

use App\Enums\ListingSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source', 'external_id', 'title', 'price', 'currency', 'year', 'mileage_km',
    'engine_capacity_cc', 'horsepower', 'body_type', 'transmission', 'fuel_type',
    'city', 'url', 'photos', 'description', 'notified_at',
])]
class Listing extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ListingSource::class,
            'photos' => 'array',
            'notified_at' => 'datetime',
        ];
    }
}
