<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\ReliabilityRule;
use App\Services\Reliability\ReliabilityScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reliability:rescore {--all}')]
#[Description('Re-score already-saved listings against the current reliability ruleset.')]
class RescoreListings extends Command
{
    public function handle(ReliabilityScorer $scorer): int
    {
        $query = Listing::query();

        if (! $this->option('all')) {
            $newestRuleUpdate = ReliabilityRule::query()->where('active', true)->max('updated_at');

            $query->where(function ($query) use ($newestRuleUpdate) {
                $query->whereNull('reliability_scored_at');

                if ($newestRuleUpdate !== null) {
                    $query->orWhere('reliability_scored_at', '<', $newestRuleUpdate);
                }
            });
        }

        $count = 0;

        $query->lazyById()->each(function (Listing $listing) use ($scorer, &$count) {
            $result = $scorer->score([
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'year' => $listing->year,
                'mileage_km' => $listing->mileage_km,
                'price' => $listing->price,
                'currency' => $listing->currency,
                // Detail-page fields too: without them a rescore would silently drop the
                // damaged / high-consumption / new-seller flags and wrongly RAISE scores.
                'is_damaged' => $listing->is_damaged,
                'fuel_consumption_l_100km' => $listing->fuel_consumption_l_100km,
                'seller_registered_year' => $listing->seller_registered_year,
            ]);

            $listing->update([
                'reliability_score' => $result->score,
                'reliability_flags' => $result->flagsToArray(),
                'reliability_scored_at' => now(),
            ]);

            $count++;
        });

        $this->info("Rescored {$count} listing(s).");

        return self::SUCCESS;
    }
}
