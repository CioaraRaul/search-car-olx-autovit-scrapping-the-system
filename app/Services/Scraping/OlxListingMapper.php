<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;

/**
 * Maps one raw "card" array from OlxClient into attributes ready for
 * Listing::updateOrCreate().
 */
class OlxListingMapper
{
    /**
     * @param  array<string, mixed>  $node  A raw card, as returned by OlxClient::fetchPage().
     * @param  string  $currency  The currency search results were requested in (OLX's `currency`
     *                            query param applies to every result on the page, unlike Autovit
     *                            where each listing carries its own currency).
     * @return array<string, mixed>
     */
    public function map(array $node, string $currency): array
    {
        [$year, $mileageKm] = $this->parseYearAndMileage((string) $node['rawYearMileage']);

        return [
            'source' => ListingSource::Olx,
            'external_id' => (string) $node['id'],
            'title' => $node['title'] !== '' ? $node['title'] : null,
            'price' => $this->parsePrice((string) $node['rawPrice']),
            'currency' => $currency,
            'year' => $year,
            'mileage_km' => $mileageKm,
            'engine_capacity_cc' => null,
            'horsepower' => null,
            // OLX's search results don't return body_type, transmission, or fuel_type per
            // listing, even though body_type is filterable server-side (same limitation
            // Autovit has — see AutovitListingMapper).
            'body_type' => null,
            'transmission' => null,
            'fuel_type' => null,
            'city' => $this->parseCity((string) $node['rawLocationDate']),
            'url' => $this->absoluteUrl((string) $node['relativeUrl']),
            'photos' => array_values(array_filter([$node['photoUrl'] ?? null])),
            'description' => null,
        ];
    }

    private function parsePrice(string $rawPrice): int
    {
        return (int) preg_replace('/\D/', '', $rawPrice);
    }

    private function parseCity(string $rawLocationDate): ?string
    {
        $city = trim(explode(' - ', $rawLocationDate)[0] ?? '');

        return $city !== '' ? $city : null;
    }

    /**
     * Parses OLX's card text, e.g. "2015  230 000 km", into [year, mileageKm].
     * Either or both can be missing if the card doesn't show them.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parseYearAndMileage(string $rawYearMileage): array
    {
        if (! preg_match('/(\d{4})\s+([\d\s]+?)\s*km/u', $rawYearMileage, $matches)) {
            return [null, null];
        }

        return [
            (int) $matches[1],
            (int) preg_replace('/\D/', '', $matches[2]),
        ];
    }

    private function absoluteUrl(string $relativeUrl): string
    {
        if ($relativeUrl === '') {
            return '';
        }

        $path = strtok($relativeUrl, '?');

        return config('scraping.olx.base_url').$path;
    }
}
