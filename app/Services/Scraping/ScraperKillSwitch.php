<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;

class ScraperKillSwitch
{
    public function isEnabled(ListingSource $source): bool
    {
        return (bool) config("scraping.{$source->value}.enabled", true);
    }
}
