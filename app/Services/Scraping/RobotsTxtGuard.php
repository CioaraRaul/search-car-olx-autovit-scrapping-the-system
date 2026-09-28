<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches, caches, and parses a site's robots.txt for the `User-agent: *` group,
 * then answers whether a given URL is allowed to be requested.
 *
 * General-purpose: not tied to any single scraped site. Each scraper calls
 * RobotsTxtGuard::for($baseUrl) with its own site's base URL.
 */
class RobotsTxtGuard
{
    /**
     * @param  array<int, string>  $disallow
     * @param  array<int, string>  $allow
     */
    private function __construct(
        private readonly array $disallow,
        private readonly array $allow,
    ) {}

    public static function for(string $baseUrl): self
    {
        $baseUrl = rtrim($baseUrl, '/');
        $cacheKey = 'robots_txt_guard:'.md5($baseUrl);

        $rules = Cache::remember(
            $cacheKey,
            config('scraping.robots_txt_cache_ttl'),
            fn () => self::fetchAndParse($baseUrl),
        );

        return new self($rules['disallow'], $rules['allow']);
    }

    public function isAllowed(string $url): bool
    {
        $path = $this->pathAndQueryOf($url);

        $disallowMatch = $this->longestMatch($this->disallow, $path);

        if ($disallowMatch === null) {
            return true;
        }

        $allowMatch = $this->longestMatch($this->allow, $path);

        return $allowMatch !== null && strlen($allowMatch) >= strlen($disallowMatch);
    }

    /**
     * @return array{disallow: array<int, string>, allow: array<int, string>}
     */
    private static function fetchAndParse(string $baseUrl): array
    {
        try {
            $response = Http::withUserAgent(config('scraping.user_agent'))
                ->timeout(10)
                ->get($baseUrl.'/robots.txt');

            if (! $response->successful()) {
                return ['disallow' => [], 'allow' => []];
            }

            return self::parseForWildcardAgent($response->body());
        } catch (\Throwable) {
            // If robots.txt can't be fetched, fail closed on nothing (no rules known)
            // rather than guessing — the caller still applies its own judgement.
            return ['disallow' => [], 'allow' => []];
        }
    }

    /**
     * @return array{disallow: array<int, string>, allow: array<int, string>}
     */
    private static function parseForWildcardAgent(string $body): array
    {
        $groups = [];
        $currentAgents = [];
        $currentRules = [];
        $groupHasRules = false;

        $flush = function () use (&$groups, &$currentAgents, &$currentRules) {
            if ($currentAgents !== []) {
                $groups[] = ['agents' => $currentAgents, 'rules' => $currentRules];
            }
            $currentAgents = [];
            $currentRules = [];
        };

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($groupHasRules) {
                    $flush();
                    $groupHasRules = false;
                }
                $currentAgents[] = $value;
            } elseif ($field === 'disallow' && $value !== '') {
                $currentRules[] = ['type' => 'disallow', 'pattern' => $value];
                $groupHasRules = true;
            } elseif ($field === 'allow' && $value !== '') {
                $currentRules[] = ['type' => 'allow', 'pattern' => $value];
                $groupHasRules = true;
            }
            // Sitemap, Host, Crawl-delay, Content-Signal, etc. are irrelevant here.
        }
        $flush();

        $disallow = [];
        $allow = [];

        foreach ($groups as $group) {
            if (! in_array('*', $group['agents'], true)) {
                continue;
            }

            foreach ($group['rules'] as $rule) {
                if ($rule['type'] === 'disallow') {
                    $disallow[] = $rule['pattern'];
                } else {
                    $allow[] = $rule['pattern'];
                }
            }
        }

        return ['disallow' => $disallow, 'allow' => $allow];
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function longestMatch(array $patterns, string $path): ?string
    {
        $best = null;

        foreach ($patterns as $pattern) {
            if (! $this->patternMatches($pattern, $path)) {
                continue;
            }

            if ($best === null || strlen($pattern) > strlen($best)) {
                $best = $pattern;
            }
        }

        return $best;
    }

    /**
     * A simplified version of the matching algorithm search engines use:
     * `*` matches any sequence of characters, `$` anchors the end.
     */
    private function patternMatches(string $pattern, string $path): bool
    {
        $endAnchored = str_ends_with($pattern, '$');

        if ($endAnchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $segments = explode('*', $pattern);
        $first = array_shift($segments);

        if (! str_starts_with($path, $first)) {
            return false;
        }

        $cursor = strlen($first);

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $foundAt = strpos($path, $segment, $cursor);

            if ($foundAt === false) {
                return false;
            }

            $cursor = $foundAt + strlen($segment);
        }

        return ! $endAnchored || $cursor === strlen($path);
    }

    /**
     * robots.txt patterns are written in decoded form, but the URLs we build
     * are percent-encoded (e.g. `search[order]` becomes `search%5Border%5D`)
     * — decode before matching so a pattern like `*[order]=*` still matches.
     */
    private function pathAndQueryOf(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return rawurldecode($path.$query);
    }
}
