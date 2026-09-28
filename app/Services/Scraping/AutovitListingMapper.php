<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;
use Illuminate\Support\Collection;

/**
 * Maps one raw "node" object from Autovit's advertSearch GraphQL payload
 * into attributes ready for Listing::updateOrCreate().
 */
class AutovitListingMapper
{
    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function map(array $node): array
    {
        $parameters = collect($node['parameters'] ?? [])->keyBy('key');

        return [
            'source' => ListingSource::Autovit,
            'external_id' => (string) $node['id'],
            'title' => $node['title'] ?? null,
            'price' => (int) ($node['price']['amount']['units'] ?? 0),
            'currency' => $node['price']['amount']['currencyCode'] ?? null,
            'year' => $this->intParameter($parameters, 'year'),
            'mileage_km' => $this->intParameter($parameters, 'mileage'),
            'engine_capacity_cc' => $this->intParameter($parameters, 'engine_capacity'),
            'horsepower' => $this->intParameter($parameters, 'engine_power'),
            // Autovit's search results don't return body_type or transmission per listing,
            // even though both are filterable server-side (see the Chapter 1 plan for why).
            'body_type' => null,
            'transmission' => null,
            'fuel_type' => $parameters->get('fuel_type')['value'] ?? null,
            'city' => $node['location']['city']['name'] ?? null,
            'url' => $node['url'] ?? '',
            'photos' => array_values(array_filter([
                $node['thumbnail']['x1'] ?? null,
                $node['thumbnail']['x2'] ?? null,
            ])),
            'description' => $node['shortDescription'] ?? null,
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $parameters
     */
    private function intParameter(Collection $parameters, string $key): ?int
    {
        $value = $parameters->get($key)['value'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
