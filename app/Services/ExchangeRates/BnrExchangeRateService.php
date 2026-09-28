<?php

namespace App\Services\ExchangeRates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches and caches the National Bank of Romania's (BNR) daily reference
 * exchange rates, and converts an amount in any of those currencies to EUR.
 *
 * The only class in the app that knows the shape of BNR's XML feed.
 */
class BnrExchangeRateService
{
    /**
     * @return array<string, float> currency code => RON per one unit of that currency
     */
    public function rates(): array
    {
        return Cache::remember(
            config('exchange_rates.cache_key'),
            config('exchange_rates.cache_ttl'),
            fn () => $this->fetchAndParse(),
        );
    }

    public function toEur(int|float $amount, string $currency): float
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return (float) $amount;
        }

        $rates = $this->rates();

        if (! isset($rates[$currency], $rates['EUR'])) {
            throw new \RuntimeException("No BNR rate available for currency [{$currency}].");
        }

        $amountInRon = $amount * $rates[$currency];

        return $amountInRon / $rates['EUR'];
    }

    /**
     * @return array<string, float>
     */
    private function fetchAndParse(): array
    {
        $response = Http::timeout(10)->get(config('exchange_rates.bnr_url'));

        if (! $response->successful()) {
            throw new \RuntimeException('Could not fetch the BNR exchange rate feed: HTTP '.$response->status());
        }

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new \RuntimeException('Could not parse the BNR exchange rate feed as XML.');
        }

        $xml->registerXPathNamespace('b', 'https://www.bnr.ro/xsd');

        $rates = ['RON' => 1.0];

        foreach ($xml->xpath('//b:Cube/b:Rate') as $rateNode) {
            $currency = (string) $rateNode->attributes()['currency'];
            $multiplier = (float) ($rateNode->attributes()['multiplier'] ?? 1);
            $value = (float) $rateNode;

            $rates[$currency] = $value / $multiplier;
        }

        return $rates;
    }
}
