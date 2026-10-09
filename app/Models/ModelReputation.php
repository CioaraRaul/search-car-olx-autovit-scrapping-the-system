<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['make', 'model', 'keywords', 'verdict', 'reason', 'active'])]
class ModelReputation extends Model
{
    public const RECOMMENDED = 'recommended';

    public const ACCEPTABLE = 'acceptable';

    public const AVOID = 'avoid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return "{$this->make} {$this->model}";
    }
}
