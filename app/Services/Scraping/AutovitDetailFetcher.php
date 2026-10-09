<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches one Autovit ad's own page and reads fields that aren't present in
 * search results at all: whether it's marked as damaged, its fuel
 * consumption, and the year its seller's account was registered.
 *
 * Unlike the search-results page (parsed by AutovitClient via a GraphQL
 * cache under __NEXT_DATA__.props.pageProps.urqlState), an ad's own page
 * embeds its data directly at __NEXT_DATA__.props.pageProps.advert — a
 * `details` array of {key, label, value, group, ...} objects covering every
 * spec shown on the page, plus a `seller` object with its own badges.
 */
class AutovitDetailFetcher
{
    /**
     * @return array{damaged: ?bool, fuelConsumptionL100km: ?float, sellerRegisteredYear: ?int, description: ?string, verified: ?bool}
     */
    public function fetch(string $url): array
    {
        $guard = RobotsTxtGuard::for(config('scraping.autovit.base_url'));

        if (! $guard->isAllowed($url)) {
            throw new RuntimeException("Autovit's robots.txt disallows this URL, refusing to fetch: {$url}");
        }

        $response = Http::withUserAgent(config('scraping.user_agent'))
            ->timeout(20)
            ->get($url);

        $response->throw();

        $advert = $this->extractAdvert($response->body());
        $details = $advert['details'] ?? [];

        return [
            'damaged' => $this->parseDamaged($details),
            'fuelConsumptionL100km' => $this->parseFuelConsumption($details),
            'sellerRegisteredYear' => $this->parseSellerRegisteredYear($advert['seller'] ?? []),
            'description' => $this->cleanDescription($advert['description'] ?? null),
            // Autovit's "verified details" mark; absent from the page data = unknown, not "no".
            'verified' => isset($advert['verifiedCar']) ? (bool) $advert['verifiedCar'] : null,
        ];
    }

    /** The seller's free-text description (HTML in the page) as plain text, capped at 5000 characters. */
    private function cleanDescription(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($raw))) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 5000);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAdvert(string $html): array
    {
        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            throw new RuntimeException('Could not find __NEXT_DATA__ in the Autovit ad response — the page structure may have changed.');
        }

        $nextData = json_decode($matches[1], associative: true, flags: JSON_THROW_ON_ERROR);

        return $nextData['props']['pageProps']['advert'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $seller
     */
    private function parseSellerRegisteredYear(array $seller): ?int
    {
        foreach ($seller['featuresBadges'] ?? [] as $badge) {
            if (($badge['code'] ?? null) !== 'registration-date') {
                continue;
            }

            if (preg_match('/\b((?:19|20)\d{2})\b/', $badge['label'] ?? '', $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function parseDamaged(array $details): ?bool
    {
        $value = $this->findValue($details, 'damaged');

        return $value === null ? null : $value === 'Da';
    }

    /**
     * Averages urban + extra-urban consumption when both are present —
     * Autovit doesn't publish a single "combined" figure.
     *
     * @param  array<int, array<string, mixed>>  $details
     */
    private function parseFuelConsumption(array $details): ?float
    {
        $values = array_filter([
            $this->parseConsumptionValue($this->findValue($details, 'urban_consumption')),
            $this->parseConsumptionValue($this->findValue($details, 'extra_urban_consumption')),
        ], fn (?float $value) => $value !== null);

        return $values === [] ? null : array_sum($values) / count($values);
    }

    private function parseConsumptionValue(?string $raw): ?float
    {
        if ($raw === null || ! preg_match('/([\d.]+)/', $raw, $matches)) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function findValue(array $details, string $key): ?string
    {
        foreach ($details as $detail) {
            if (($detail['key'] ?? null) === $key) {
                return $detail['value'] ?? null;
            }
        }

        return null;
    }
}
