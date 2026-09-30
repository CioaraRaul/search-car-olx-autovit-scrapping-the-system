<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('listings:looks-set {id : Listing id} {score : Looks score 0-100} {notes? : Short reasons}')]
#[Description('Store the looks score (0-100) and notes from a photo review of one listing.')]
class ListingsLooksSet extends Command
{
    public function handle(): int
    {
        $score = $this->argument('score');

        if (! ctype_digit((string) $score) || (int) $score > 100) {
            $this->error('The score must be a whole number from 0 to 100.');

            return self::FAILURE;
        }

        $listing = Listing::find($this->argument('id'));

        if ($listing === null) {
            $this->error("No listing with id {$this->argument('id')}.");

            return self::FAILURE;
        }

        $listing->update([
            'looks_score' => (int) $score,
            'looks_notes' => $this->argument('notes'),
            'looks_scored_at' => now(),
        ]);

        $minimum = (int) config('car_knowledge.looks.min_score');
        $verdict = (int) $score >= $minimum ? 'will be emailed' : "is below {$minimum}, so it will NOT be emailed";

        $this->info("Looks score {$score}/100 saved for \"{$listing->title}\" — it {$verdict}.");

        return self::SUCCESS;
    }
}
