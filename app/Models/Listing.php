<?php

namespace App\Models;

use App\Enums\ListingSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source', 'external_id', 'title', 'price', 'currency', 'price_eur', 'year', 'mileage_km',
    'engine_capacity_cc', 'horsepower', 'body_type', 'transmission', 'fuel_type',
    'city', 'url', 'photos', 'description', 'notified_at',
    'reliability_score', 'reliability_flags', 'reliability_scored_at',
    'is_damaged', 'fuel_consumption_l_100km', 'detail_checked_at',
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
            'reliability_flags' => 'array',
            'reliability_scored_at' => 'datetime',
            'price_eur' => 'decimal:2',
            'is_damaged' => 'boolean',
            'fuel_consumption_l_100km' => 'decimal:1',
            'detail_checked_at' => 'datetime',
        ];
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(ListingPriceChange::class);
    }
}
