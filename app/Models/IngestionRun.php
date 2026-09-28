<?php

namespace App\Models;

use App\Enums\IngestionRunStatus;
use App\Enums\ListingSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source', 'started_at', 'finished_at', 'pages_fetched', 'listings_new',
    'listings_updated', 'errors', 'status',
])]
class IngestionRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ListingSource::class,
            'status' => IngestionRunStatus::class,
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
