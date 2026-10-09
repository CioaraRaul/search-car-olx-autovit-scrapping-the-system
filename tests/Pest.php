<?php

use App\Models\ModelReputation;
use App\Services\Reliability\ModelCheck;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Lets scraper/e-mail tests use made-up car titles: the real model check drops any model that is
 * not in the model_reputations table (covered by its own tests).
 */
function allowAnyModel(): void
{
    app()->instance(ModelCheck::class, new class extends ModelCheck
    {
        public function find(?string $title): ?ModelReputation
        {
            return null;
        }

        public function isAcceptable(?string $title): bool
        {
            return true;
        }

        public function rejectionReason(?string $title): ?string
        {
            return null;
        }
    });
}
