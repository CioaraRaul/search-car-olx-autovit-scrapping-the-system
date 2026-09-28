<?php

namespace App\Services\Listings;

use App\Models\Listing;
use App\Services\ExchangeRates\BnrExchangeRateService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Records a listing's price history (one row per distinct price/currency
 * ever observed) and keeps its `price_eur` column up to date. Owns nothing
 * else about a listing — the scraper's own upsert is still what writes
 * `listings.price`/`listings.currency`.
 */
class PriceHistoryRecorder
{
    public function __construct(
        private readonly BnrExchangeRateService $exchangeRates,
    ) {}

    public function recordIfChanged(
        Listing $listing,
        int $price,
        string $currency,
        ?CarbonInterface $observedAt = null,
    ): void {
        $observedAt ??= Date::now();

        $latest = $listing->priceChanges()->latest('recorded_at')->first();

        if ($latest === null || $latest->price !== $price || $latest->currency !== $currency) {
            $listing->priceChanges()->create([
                'price' => $price,
                'currency' => $currency,
                'recorded_at' => $observedAt,
            ]);
        }

        try {
            $listing->price_eur = $this->exchangeRates->toEur($price, $currency);
            $listing->save();
        } catch (\Throwable $e) {
            Log::warning('Could not refresh price_eur: '.$e->getMessage(), [
                'listing_id' => $listing->id,
            ]);
        }
    }
}
