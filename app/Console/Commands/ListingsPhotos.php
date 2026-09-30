<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Scraping\AdPhotoFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

#[Signature('listings:photos {id : Listing id} {--max=8 : Maximum photos} {--dir= : Folder to save into (default storage/app/looks/<id>)}')]
#[Description('Download the gallery photos of one listing so they can be inspected (used by the car-looks-evaluator skill).')]
class ListingsPhotos extends Command
{
    public function handle(AdPhotoFetcher $fetcher): int
    {
        $listing = Listing::find($this->argument('id'));

        if ($listing === null) {
            $this->error("No listing with id {$this->argument('id')}.");

            return self::FAILURE;
        }

        $directory = $this->option('dir') ?: storage_path('app/looks/'.$listing->id);

        try {
            $urls = $fetcher->photoUrls($listing->source, $listing->url, (int) $this->option('max'));
        } catch (RequestException $e) {
            $this->error("Could not load the ad page (HTTP {$e->response->status()}).");

            return self::FAILURE;
        }

        if ($urls === []) {
            $this->error('No gallery photos found on the ad page.');

            return self::FAILURE;
        }

        $saved = $fetcher->download($urls, $directory);

        $this->info(count($saved).' photo(s) saved for listing '.$listing->id.' ('.$listing->title.'):');

        foreach ($saved as $path) {
            $this->line($path);
        }

        return $saved === [] ? self::FAILURE : self::SUCCESS;
    }
}
