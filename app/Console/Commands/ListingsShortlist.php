<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Listings\ListingShortlist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('listings:shortlist {--limit=15 : Maximum cars to list} {--reviewed : Include cars whose looks were already reviewed}')]
#[Description('List the cars that pass every current filter and have not had their looks reviewed yet (input for the car-looks-evaluator skill).')]
class ListingsShortlist extends Command
{
    public function handle(ListingShortlist $shortlist): int
    {
        $query = Listing::query()->orderBy('reliability_score', 'desc')->orderBy('price');

        if (! $this->option('reviewed')) {
            $query->whereNull('looks_score');
        }

        $cars = $shortlist->filter($query->get(), skipAlreadyEmailedTwins: false)
            ->take((int) $this->option('limit'));

        if ($cars->isEmpty()) {
            $this->info('No cars to review.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Source', 'Title', 'Year', 'km', 'Price', 'Fuel', 'cc', 'HP', 'Reliab.', 'Looks'],
            $cars->map(fn (Listing $l) => [
                $l->id, $l->source->value, mb_strimwidth((string) $l->title, 0, 45, '…'), $l->year,
                $l->mileage_km, $l->price.' '.$l->currency, $l->fuel_type ?? '?', $l->engine_capacity_cc ?? '?',
                $l->horsepower ?? '?', $l->reliability_score, $l->looks_score ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
