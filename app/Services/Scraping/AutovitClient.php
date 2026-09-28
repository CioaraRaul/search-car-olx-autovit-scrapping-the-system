<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches and parses one page of Autovit search results.
 *
 * Deliberately does NOT filter by price or sort by newest-first via the URL —
 * Autovit's robots.txt disallows both (Disallow: *_price* and *[order]=*).
 * Price filtering and sorting happen client-side instead (see ScrapeAutovit).
 */
class AutovitClient
{
    /**
     * Body type values this project uses, mapped to Autovit's own filter codes.
     *
     * @var array<string, string>
     */
    private const BODY_TYPE_MAP = [
        'sedan' => 'sedan',
        'break' => 'combi',
    ];

    /**
     * @param  array<string, string>  $criteria  Current SearchCriterion values, keyed by name.
     * @return array{listings: array<int, array<string, mixed>>, pageSize: int, currentOffset: int, totalCount: int}
     */
    public function fetchPage(array $criteria, int $page): array
    {
        $baseUrl = config('scraping.autovit.base_url');
        $url = $this->buildUrl($baseUrl, $criteria, $page);

        $guard = RobotsTxtGuard::for($baseUrl);

        if (! $guard->isAllowed($url)) {
            throw new RuntimeException("Autovit's robots.txt disallows this URL, refusing to fetch: {$url}");
        }

        $response = Http::withUserAgent(config('scraping.user_agent'))
            ->timeout(20)
            ->get($url);

        $response->throw();

        $advertSearch = $this->findAdvertSearch($this->extractNextData($response->body()));

        return [
            'listings' => array_map(fn (array $edge) => $edge['node'], $advertSearch['edges'] ?? []),
            'pageSize' => $advertSearch['pageInfo']['pageSize'] ?? 0,
            'currentOffset' => $advertSearch['pageInfo']['currentOffset'] ?? 0,
            'totalCount' => $advertSearch['totalCount'] ?? 0,
        ];
    }

    /**
     * @param  array<string, string>  $criteria
     */
    private function buildUrl(string $baseUrl, array $criteria, int $page): string
    {
        $query = [];

        if (isset($criteria['year_min'])) {
            $query['search[filter_float_year:from]'] = $criteria['year_min'];
        }

        if (isset($criteria['km_max'])) {
            $query['search[filter_float_mileage:to]'] = $criteria['km_max'];
        }

        if (isset($criteria['engine_capacity_max'])) {
            $query['search[filter_float_engine_capacity:to]'] = (string) (int) round((float) $criteria['engine_capacity_max'] * 1000);
        }

        if (isset($criteria['body_type'])) {
            $values = array_map('trim', explode(',', $criteria['body_type']));

            foreach (array_values($values) as $i => $value) {
                if (isset(self::BODY_TYPE_MAP[$value])) {
                    $query["search[filter_enum_body_type][{$i}]"] = self::BODY_TYPE_MAP[$value];
                }
            }
        }

        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        return $baseUrl.config('scraping.autovit.search_path').'?'.http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractNextData(string $html): array
    {
        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            throw new RuntimeException('Could not find __NEXT_DATA__ in the Autovit response — the page structure may have changed.');
        }

        return json_decode($matches[1], associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $nextData
     * @return array<string, mixed>
     */
    private function findAdvertSearch(array $nextData): array
    {
        $urqlState = $nextData['props']['pageProps']['urqlState'] ?? [];

        foreach ($urqlState as $entry) {
            $data = $entry['data'] ?? null;

            if (! is_string($data) || ! str_contains($data, 'advertSearch')) {
                continue;
            }

            $decoded = json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR);

            if (isset($decoded['advertSearch'])) {
                return $decoded['advertSearch'];
            }
        }

        throw new RuntimeException('Could not find advertSearch in the Autovit response — the page structure may have changed.');
    }
}
