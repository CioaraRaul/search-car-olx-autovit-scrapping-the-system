<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Fetches and parses one page of OLX search results.
 *
 * Unlike Autovit, OLX's robots.txt has no restriction on price/order URL
 * params, and its search results are plain server-rendered HTML (no JSON
 * blob) — so this class parses the HTML directly with Symfony's DomCrawler
 * instead of extracting an embedded JSON payload.
 */
class OlxClient
{
    /**
     * Body type values this project uses, mapped to OLX's own filter codes.
     *
     * @var array<string, string>
     */
    private const BODY_TYPE_MAP = [
        'sedan' => 'sedan',
        'break' => 'estate-car',
    ];

    /**
     * @param  array<string, string>  $criteria  Current SearchCriterion values, keyed by name.
     * @return array{listings: array<int, array<string, mixed>>}
     */
    public function fetchPage(array $criteria, int $page): array
    {
        $baseUrl = config('scraping.olx.base_url');
        $url = $this->buildUrl($baseUrl, $criteria, $page);

        $guard = RobotsTxtGuard::for($baseUrl);

        if (! $guard->isAllowed($url)) {
            throw new RuntimeException("OLX's robots.txt disallows this URL, refusing to fetch: {$url}");
        }

        $response = Http::withUserAgent(config('scraping.user_agent'))
            ->timeout(20)
            ->get($url);

        $response->throw();

        return ['listings' => $this->extractListings($response->body())];
    }

    /**
     * @param  array<string, string>  $criteria
     */
    private function buildUrl(string $baseUrl, array $criteria, int $page): string
    {
        $query = ['currency' => $criteria['price_currency'] ?? 'EUR'];

        if (isset($criteria['price_max'])) {
            $query['search[filter_float_price:to]'] = $criteria['price_max'];
        }

        if (isset($criteria['year_min'])) {
            $query['search[filter_float_year:from]'] = $criteria['year_min'];
        }

        if (isset($criteria['km_max'])) {
            $query['search[filter_float_rulaj_pana:to]'] = $criteria['km_max'];
        }

        if (isset($criteria['engine_capacity_max'])) {
            $query['search[filter_float_enginesize:to]'] = (string) (int) round((float) $criteria['engine_capacity_max'] * 1000);
        }

        if (isset($criteria['body_type'])) {
            $values = array_map('trim', explode(',', $criteria['body_type']));

            foreach (array_values($values) as $i => $value) {
                if (isset(self::BODY_TYPE_MAP[$value])) {
                    $query["search[filter_enum_car_body][{$i}]"] = self::BODY_TYPE_MAP[$value];
                }
            }
        }

        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        return $baseUrl.config('scraping.olx.search_path').'?'.http_build_query($query);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractListings(string $html): array
    {
        $crawler = new Crawler($html);

        $cards = $crawler->filter('[data-testid="l-card"]');

        $listings = [];

        foreach ($cards as $cardNode) {
            $card = new Crawler($cardNode);
            $node = $this->extractCard($card);

            if ($node !== null) {
                $listings[] = $node;
            }
        }

        return $listings;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractCard(Crawler $card): ?array
    {
        $id = $card->attr('id');

        $titleLink = $card->filter('a[data-testid="card-title-link"]');

        if ($id === null || $titleLink->count() === 0) {
            return null;
        }

        $priceNode = $card->filter('p[data-testid="ad-price"]');
        $locationNode = $card->filter('p[data-testid="location-date"]');
        $mileageIcon = $card->filter('[data-testid="millage-card-param-icon"]');
        $photoNode = $card->filter('img');

        return [
            'id' => $id,
            'title' => trim($titleLink->attr('aria-label') ?? $titleLink->text('', true)),
            'relativeUrl' => $titleLink->attr('href') ?? '',
            'rawPrice' => $priceNode->count() > 0 ? $priceNode->innerText() : '',
            'photoUrl' => $photoNode->count() > 0 ? $photoNode->attr('src') : null,
            'rawLocationDate' => $locationNode->count() > 0 ? $locationNode->text('', true) : '',
            'rawYearMileage' => $mileageIcon->count() > 0 ? $mileageIcon->closest('span')?->text('', true) ?? '' : '',
        ];
    }
}
