<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionRunStatus;
use App\Enums\ListingSource;
use App\Models\IngestionRun;
use Closure;
use Illuminate\Support\Facades\Date;

/**
 * Opens, updates, and closes an `IngestionRun` row for a single scraper
 * execution, so any scraper command can wrap itself with it instead of
 * hand-rolling the same bookkeeping.
 */
class IngestionRunTracker
{
    public function start(ListingSource $source): IngestionRun
    {
        return IngestionRun::create([
            'source' => $source,
            'started_at' => Date::now(),
            'status' => IngestionRunStatus::Running,
            'pages_fetched' => 0,
            'listings_new' => 0,
            'listings_updated' => 0,
        ]);
    }

    public function incrementPages(IngestionRun $run, int $by = 1): void
    {
        $run->increment('pages_fetched', $by);
    }

    public function incrementNew(IngestionRun $run, int $by = 1): void
    {
        $run->increment('listings_new', $by);
    }

    public function incrementUpdated(IngestionRun $run, int $by = 1): void
    {
        $run->increment('listings_updated', $by);
    }

    public function recordError(IngestionRun $run, string $message): void
    {
        $errors = $run->errors ?? [];
        $errors[] = $message;

        $run->update(['errors' => $errors]);
    }

    public function finish(IngestionRun $run, IngestionRunStatus $status): void
    {
        $run->update([
            'finished_at' => Date::now(),
            'status' => $status,
        ]);
    }

    /**
     * Convenience wrapper: starts a run, runs the callback with it, marks it
     * Success on normal completion or Failed (recording the exception
     * message) if the callback throws — then re-throws so the caller still
     * sees the failure.
     */
    public function run(ListingSource $source, Closure $callback): IngestionRun
    {
        $run = $this->start($source);

        try {
            $callback($run);
            $this->finish($run, IngestionRunStatus::Success);
        } catch (\Throwable $e) {
            $this->recordError($run, $e->getMessage());
            $this->finish($run, IngestionRunStatus::Failed);

            throw $e;
        }

        return $run;
    }
}
