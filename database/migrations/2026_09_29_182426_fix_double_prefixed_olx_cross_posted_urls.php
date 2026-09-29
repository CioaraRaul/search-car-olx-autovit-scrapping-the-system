<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix, not a schema change: OlxListingMapper::absoluteUrl() used to
 * blindly prepend OLX's base URL onto every scraped href, even when OLX
 * aggregated a partner-hosted listing (e.g. Autovit) whose href was already
 * a full absolute URL. That produced broken double-prefixed values like
 * "https://www.olx.rohttps://www.autovit.ro/anunt/...". The mapper itself is
 * fixed separately (OlxListingMapper::absoluteUrl()); this migration repairs
 * the rows already stored with the broken value. Safe to re-run: matches
 * nothing once the affected rows are fixed, and a fresh database never has
 * the broken pattern to begin with.
 */
return new class extends Migration
{
    private const BROKEN_PREFIX = 'https://www.olx.ro';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('listings')
            ->where('url', 'like', self::BROKEN_PREFIX.'http%')
            ->orderBy('id')
            ->get(['id', 'url'])
            ->each(function ($listing) {
                DB::table('listings')
                    ->where('id', $listing->id)
                    ->update(['url' => substr($listing->url, strlen(self::BROKEN_PREFIX))]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: re-introducing the broken double-prefixed URL
        // would only recreate the bug this migration fixes.
    }
};
